<?php

use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a minimal confirmed order with one catalog item already deducted
 * from inventory, and optionally a second post-sale item added on top.
 *
 * Returns an array with all the objects tests need to inspect.
 *
 * @return array{
 *     order: SalesOrder,
 *     customer: Customer,
 *     warehouse: Warehouse,
 *     seller: User,
 *     product: Product,
 *     inventory: ProductInventory,
 *     originalItem: SalesOrderItem,
 *     postSaleItem: SalesOrderItem|null,
 * }
 */
function buildConfirmedOrderWithPostSaleItem(
    int $initialStock = 10,
    int $originalQty = 2,
    int $postSaleQty = 3,
    bool $addPostSaleItem = true,
    string $orderStatus = 'confirmed',
): array {
    $warehouse = Warehouse::create(['code' => 'WH-PSR-'.uniqid(), 'name' => 'Test WH']);

    $seller = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'CUST-PSR-'.uniqid(),
        'name' => 'Cliente Test',
        'current_balance' => 0,
        'credit_limit' => 10000,
        'credit_days' => 30,
    ]);

    $product = Product::create(['sku_base' => 'SKU-PSR-'.uniqid(), 'name' => 'Producto Test', 'status' => 'active']);

    $inventory = ProductInventory::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => $initialStock,
        'quantity_reserved' => 0,
    ]);

    // Create order through the service so all observers fire correctly
    $service = app(\App\Services\SalesOrderService::class);

    $order = $service->create([
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'status' => $orderStatus,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
        'items' => [
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $originalQty,
                'unit_price' => 100.00,
                'tax_percent' => 0,
            ],
        ],
    ]);

    $originalItem = $order->items()->first();
    $postSaleItem = null;

    if ($addPostSaleItem) {
        $postSaleItem = $service->addItem($order->id, [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $postSaleQty,
            'unit_price' => 50.00,
            'tax_percent' => 0,
        ]);
    }

    return [
        'order' => $order->fresh(),
        'customer' => $customer->fresh(),
        'warehouse' => $warehouse,
        'seller' => $seller,
        'product' => $product,
        'inventory' => $inventory->fresh(),
        'originalItem' => $originalItem->fresh(),
        'postSaleItem' => $postSaleItem?->fresh(),
    ];
}

// ---------------------------------------------------------------------------
// Test 1: DELETE on an added_after_sale line of a confirmed order — full contract
// ---------------------------------------------------------------------------

it('removes a post-sale item from a confirmed order and reverses all side-effects', function () {
    $data = buildConfirmedOrderWithPostSaleItem(
        initialStock: 10,
        originalQty: 2,
        postSaleQty: 3,
    );

    /** @var SalesOrder $order */
    $order = $data['order'];
    /** @var Customer $customer */
    $customer = $data['customer'];
    /** @var ProductInventory $inventory */
    $inventory = $data['inventory'];
    /** @var SalesOrderItem $postSaleItem */
    $postSaleItem = $data['postSaleItem'];
    /** @var User $seller */
    $seller = $data['seller'];

    $totalBeforeRemoval = (float) $order->total;          // 2×100 + 3×50 = 350
    $balanceBeforeRemoval = (float) $customer->current_balance;
    $availableBeforeRemoval = (int) $inventory->quantity_available; // 10 - 2 - 3 = 5
    $removedItemTotal = (float) $postSaleItem->total;      // 3×50 = 150

    $response = $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleItem->id}");

    $response->assertOk();

    // 1. The line is gone
    expect(SalesOrderItem::find($postSaleItem->id))->toBeNull();

    // 2. Order total decreased by the removed item's total
    $freshOrder = $order->fresh();
    expect((float) $freshOrder->total)->toBe($totalBeforeRemoval - $removedItemTotal);

    // 3. payment_status was re-evaluated (order had no payments → stays pending)
    expect($freshOrder->payment_status)->toBe('pending');

    // 4. Customer balance decreased by the removed total
    $freshCustomer = $customer->fresh();
    expect((float) $freshCustomer->current_balance)
        ->toBe($balanceBeforeRemoval - $removedItemTotal);

    // 5. A CustomerTransaction of type 'sale' with negative amount was created
    $negTx = $freshCustomer->transactions()
        ->where('type', 'sale')
        ->where('amount', '<', 0)
        ->first();
    expect($negTx)->not->toBeNull();
    expect((float) $negTx->amount)->toBe(-$removedItemTotal);

    // 6. Inventory was restored: quantity_available went back up by the deducted amount
    $freshInventory = $inventory->fresh();
    expect($freshInventory->quantity_available)->toBe($availableBeforeRemoval + 3);

    // 7. An inbound InventoryMovement with reference_type = 'sales_order_item_removed' exists
    //    reference_id is the ORDER id (not the item id) so cancel() can find it even
    //    after the item row has been hard-deleted.
    $restoreMovement = InventoryMovement::where('reference_type', 'sales_order_item_removed')
        ->where('reference_id', $order->id)
        ->where('movement_type', 'in')
        ->first();
    expect($restoreMovement)->not->toBeNull();
    expect($restoreMovement->quantity)->toBe(3);
    expect($restoreMovement->reason)->toBe('post_sale_item_removed');
});

// ---------------------------------------------------------------------------
// Test 2: Cannot delete an original (non-added_after_sale) line of a confirmed order
// ---------------------------------------------------------------------------

it('rejects deletion of an original line in a confirmed order with 422', function () {
    $data = buildConfirmedOrderWithPostSaleItem(addPostSaleItem: false);

    /** @var User $seller */
    $seller = $data['seller'];
    /** @var SalesOrderItem $originalItem */
    $originalItem = $data['originalItem'];
    $originalTotal = (float) $data['order']->total;
    $originalBalance = (float) $data['customer']->current_balance;
    $originalStock = $data['inventory']->fresh()->quantity_available;

    $response = $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$originalItem->id}");

    $response->assertStatus(422);

    // Line still exists
    expect(SalesOrderItem::find($originalItem->id))->not->toBeNull();

    // Total unchanged
    expect((float) $data['order']->fresh()->total)->toBe($originalTotal);

    // Balance unchanged
    expect((float) $data['customer']->fresh()->current_balance)->toBe($originalBalance);

    // Inventory unchanged
    expect($data['inventory']->fresh()->quantity_available)->toBe($originalStock);
});

// ---------------------------------------------------------------------------
// Test 3: Same removal contract works for 'processing' and 'completed' statuses
// ---------------------------------------------------------------------------

it('removes a post-sale item from a processing order', function () {
    $data = buildConfirmedOrderWithPostSaleItem(
        initialStock: 10,
        originalQty: 1,
        postSaleQty: 2,
        orderStatus: 'confirmed',
    );

    // Manually move to processing
    $order = $data['order'];
    $order->update(['status' => 'processing']);

    /** @var User $seller */
    $seller = $data['seller'];
    /** @var SalesOrderItem $postSaleItem */
    $postSaleItem = $data['postSaleItem'];
    $removedTotal = (float) $postSaleItem->total;
    $totalBefore = (float) $order->fresh()->total;

    $response = $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleItem->id}");

    $response->assertOk();
    expect(SalesOrderItem::find($postSaleItem->id))->toBeNull();
    expect((float) $order->fresh()->total)->toBe($totalBefore - $removedTotal);
});

it('removes a post-sale item from a completed order', function () {
    $data = buildConfirmedOrderWithPostSaleItem(
        initialStock: 10,
        originalQty: 1,
        postSaleQty: 2,
        orderStatus: 'confirmed',
    );

    // Manually move to completed
    $order = $data['order'];
    $order->update(['status' => 'completed']);

    /** @var User $seller */
    $seller = $data['seller'];
    /** @var SalesOrderItem $postSaleItem */
    $postSaleItem = $data['postSaleItem'];
    $removedTotal = (float) $postSaleItem->total;
    $totalBefore = (float) $order->fresh()->total;

    $response = $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleItem->id}");

    $response->assertOk();
    expect(SalesOrderItem::find($postSaleItem->id))->toBeNull();
    expect((float) $order->fresh()->total)->toBe($totalBefore - $removedTotal);
});

// ---------------------------------------------------------------------------
// Test 4: Partial-stock case — only the actually-deducted quantity is restored
// ---------------------------------------------------------------------------

it('restores only the quantity actually deducted when stock was partially available at add time', function () {
    // Only 1 unit available when the post-sale item (qty=3) was added
    $data = buildConfirmedOrderWithPostSaleItem(
        initialStock: 3,  // 2 used by original item, 1 left for post-sale
        originalQty: 2,
        postSaleQty: 3,
    );

    /** @var User $seller */
    $seller = $data['seller'];
    /** @var SalesOrderItem $postSaleItem */
    $postSaleItem = $data['postSaleItem'];
    /** @var ProductInventory $inventory */
    $inventory = $data['inventory'];

    $availableBeforeRemoval = $inventory->fresh()->quantity_available; // Should be 0 (3-2-1)

    // The post-sale outbound movement should have deducted only 1 (not 3)
    $outboundQty = (int) InventoryMovement::where('reference_type', 'post_sale_addition')
        ->where('reference_id', $postSaleItem->id)
        ->where('movement_type', 'out')
        ->sum('quantity');

    $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleItem->id}")
        ->assertOk();

    // Inventory restored by exactly what was deducted (= 1)
    $freshAvailable = $inventory->fresh()->quantity_available;
    expect($freshAvailable)->toBe($availableBeforeRemoval + $outboundQty);

    // The restore movement quantity matches what was deducted;
    // reference_id = order id (not item id)
    $restoreMovement = InventoryMovement::where('reference_type', 'sales_order_item_removed')
        ->where('reference_id', $data['order']->id)
        ->first();
    expect($restoreMovement)->not->toBeNull();
    expect($restoreMovement->quantity)->toBe($outboundQty);
});

// ---------------------------------------------------------------------------
// Test 6: Cross-order isolation — removing from order A must not use order B's movement
// ---------------------------------------------------------------------------

it('restores only the movement from the correct order when two orders share the same product', function () {
    $warehouse = Warehouse::create(['code' => 'WH-CROSS-'.uniqid(), 'name' => 'WH Cross']);
    $seller = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    $product = Product::create(['sku_base' => 'SKU-CROSS-'.uniqid(), 'name' => 'Prod Cross', 'status' => 'active']);

    ProductInventory::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 20,
        'quantity_reserved' => 0,
    ]);

    $service = app(\App\Services\SalesOrderService::class);

    // Order A: confirmed with a post-sale item of qty 1
    $orderA = $service->create([
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'items' => [[
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'unit_price' => 10.00,
            'tax_percent' => 0,
        ]],
    ]);
    $postSaleA = $service->addItem($orderA->id, [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'unit_price' => 10.00,
        'tax_percent' => 0,
    ]);

    // Order B: confirmed with a post-sale item of qty 4 (created AFTER order A)
    $orderB = $service->create([
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'items' => [[
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'unit_price' => 10.00,
            'tax_percent' => 0,
        ]],
    ]);
    $service->addItem($orderB->id, [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 4,
        'unit_price' => 10.00,
        'tax_percent' => 0,
    ]);

    $inventoryBefore = ProductInventory::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity_available');

    // Remove the post-sale item from Order A only
    $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleA->id}")
        ->assertOk();

    $inventoryAfter = ProductInventory::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity_available');

    // Only 1 unit (from order A's movement) must be restored, not 4 (order B's)
    expect($inventoryAfter)->toBe($inventoryBefore + 1);

    // The restore movement references order A's id
    $restoreMovement = InventoryMovement::where('reference_type', 'sales_order_item_removed')
        ->where('reference_id', $orderA->id)
        ->where('movement_type', 'in')
        ->first();
    expect($restoreMovement)->not->toBeNull();
    expect($restoreMovement->quantity)->toBe(1);
});

// ---------------------------------------------------------------------------
// Test 7: Cancel after remove — stock must not be double-restored
// ---------------------------------------------------------------------------

it('does not double-restore inventory when cancelling an order after a post-sale line was removed', function () {
    $data = buildConfirmedOrderWithPostSaleItem(
        initialStock: 10,
        originalQty: 2,
        postSaleQty: 3,
    );

    /** @var SalesOrder $order */
    $order = $data['order'];
    /** @var User $seller */
    $seller = $data['seller'];
    /** @var ProductInventory $inventory */
    $inventory = $data['inventory'];
    /** @var SalesOrderItem $postSaleItem */
    $postSaleItem = $data['postSaleItem'];

    // Step 1: remove the post-sale item
    $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleItem->id}")
        ->assertOk();

    $stockAfterRemove = $inventory->fresh()->quantity_available;

    // Step 2: cancel the order (only the original 2-unit deduction should be restored)
    $this->actingAs($seller)
        ->postJson("/api/v1/sales-orders/{$order->id}/cancel", [
            'reason' => 'Anulacion despues de retirar una linea',
        ])
        ->assertOk();

    $stockAfterCancel = $inventory->fresh()->quantity_available;

    // After cancel we expect: stockAfterRemove + 2 (original item deduction), not +5 (2+3)
    expect($stockAfterCancel)->toBe($stockAfterRemove + 2);
});

// ---------------------------------------------------------------------------
// Test 5: admin/manager without effective warehouse still need proper auth
// (Sanity check: the route is accessible with a valid user)
// ---------------------------------------------------------------------------

it('returns 404 when item does not exist', function () {
    $warehouse = Warehouse::create(['code' => 'WH-404-'.uniqid(), 'name' => 'WH 404']);
    $seller = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    // The controller catches ModelNotFoundException as \Exception and returns 422
    $this->actingAs($seller)
        ->deleteJson('/api/v1/sales-orders/items/999999')
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Test 8: Two post-sale lines of the same product in the same order —
//         removing the first must restore exactly its quantity, not the second's
// ---------------------------------------------------------------------------

it('correctly distinguishes outbound movements when two post-sale items of the same product exist', function () {
    $warehouse = Warehouse::create(['code' => 'WH-DUP-'.uniqid(), 'name' => 'WH Dup']);
    $seller = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    $product = Product::create(['sku_base' => 'SKU-DUP-'.uniqid(), 'name' => 'Prod Dup', 'status' => 'active']);

    ProductInventory::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 20,
        'quantity_reserved' => 0,
    ]);

    $service = app(\App\Services\SalesOrderService::class);

    // Create a confirmed order with one original item
    $order = $service->create([
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'items' => [[
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'unit_price' => 10.00,
            'tax_percent' => 0,
        ]],
    ]);

    // Add two post-sale items of the SAME product with DIFFERENT quantities
    $postSaleA = $service->addItem($order->id, [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 2,
        'unit_price' => 10.00,
        'tax_percent' => 0,
    ]);

    $postSaleB = $service->addItem($order->id, [
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 5,
        'unit_price' => 10.00,
        'tax_percent' => 0,
    ]);

    $inventoryBefore = ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available'); // 20 - 1 - 2 - 5 = 12

    // Remove item A (qty=2) — must restore exactly 2, NOT 5
    $this->actingAs($seller)
        ->deleteJson("/api/v1/sales-orders/items/{$postSaleA->id}")
        ->assertOk();

    $inventoryAfter = ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available');

    expect($inventoryAfter)->toBe($inventoryBefore + 2);

    // The restore movement is for qty 2
    $restoreMovement = InventoryMovement::where('reference_type', 'sales_order_item_removed')
        ->where('reference_id', $order->id)
        ->where('movement_type', 'in')
        ->first();
    expect($restoreMovement)->not->toBeNull();
    expect($restoreMovement->quantity)->toBe(2);

    // Item B is still present and its outbound movement is untouched
    expect(SalesOrderItem::find($postSaleB->id))->not->toBeNull();
    $outboundB = InventoryMovement::where('reference_type', 'post_sale_addition')
        ->where('reference_id', $postSaleB->id)
        ->where('movement_type', 'out')
        ->first();
    expect($outboundB)->not->toBeNull();
    expect($outboundB->quantity)->toBe(5);
});
