<?php

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Supplier;
use App\Models\SupplyListItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use App\Services\InventoryTransferService;
use App\Services\ProductService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fifoCatalogContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'WH-FIFO-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen FIFO',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor FIFO',
        'email' => 'fifo-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $brand = Brand::create([
        'name' => 'Marca FIFO',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'FIFO-'.fake()->unique()->randomNumber(4),
        'name' => 'Aceite FIFO',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'FIFO-VAR-'.fake()->unique()->randomNumber(4),
        'status' => 'active',
        'is_default' => true,
    ]);

    return compact('warehouse', 'user', 'supplier', 'product', 'variant');
}

function receiveFifoPurchase(array $context, int $quantity, float $unitCost, ?float $taxAmount = null, float $miscCost = 0, ?float $salePrice = null): PurchaseOrder
{
    /** @var PurchaseOrderService $service */
    $service = app(PurchaseOrderService::class);

    $po = $service->create([
        'supplier_id' => $context['supplier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'quantity_ordered' => $quantity,
            'unit_cost' => $unitCost,
            'tax_amount' => $taxAmount,
            'misc_cost' => $miscCost,
        ]],
    ]);

    $received = [
        'item_id' => $po->items()->firstOrFail()->id,
        'quantity_received' => $quantity,
    ];

    if ($salePrice !== null) {
        $received['sale_price'] = $salePrice;
    }

    return $service->receiveItems($po->id, [$received]);
}

it('receives purchase lots and consumes the oldest lot first on sale', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 10, 5.35, 0, 0);
    receiveFifoPurchase($context, 10, 6.42, 0, 0);

    $lots = InventoryLot::where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->orderBy('id')
        ->get();

    expect($lots)->toHaveCount(2)
        ->and((float) $lots[0]->landed_unit_cost)->toBe(5.35)
        ->and((float) $lots[1]->landed_unit_cost)->toBe(6.42);

    $order = app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 12,
            'unit_price' => 9.99,
            'tax_percent' => 0,
        ]],
    ]);

    $allocations = InventoryLotAllocation::with('lot')
        ->whereHas('movement', fn ($query) => $query
            ->where('reference_type', 'sale')
            ->where('reference_id', $order->id))
        ->orderBy('id')
        ->get();

    expect($allocations)->toHaveCount(2)
        ->and((int) $allocations[0]->quantity)->toBe(10)
        ->and((float) $allocations[0]->unit_cost)->toBe(5.35)
        ->and((int) $allocations[1]->quantity)->toBe(2)
        ->and((float) $allocations[1]->unit_cost)->toBe(6.42)
        ->and((int) $lots[0]->fresh()->quantity_available)->toBe(0)
        ->and((int) $lots[1]->fresh()->quantity_available)->toBe(8);
});

it('moves active fifo cost and sale price to the next purchase lot after the oldest lot is consumed', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 10, 5.35, 0, 0, 9.99);
    receiveFifoPurchase($context, 10, 6.42, 0, 0, 12.49);

    app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 10,
            'unit_price' => 9.99,
            'tax_percent' => 0,
        ]],
    ]);

    $this->getJson("/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/inventory-history?warehouse_id={$context['warehouse']->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.current_cost', 6.42)
        ->assertJsonPath('data.summary.current_sale_price', 12.49)
        ->assertJsonPath('data.summary.fifo_sale_price_current', 12.49)
        ->assertJsonPath('data.summary.active_lot_id', fn ($value) => is_int($value))
        ->assertJsonPath('data.lots.0.sale_price', 12.49);

    $this->getJson("/api/v1/products/{$context['product']->id}?warehouse_id={$context['warehouse']->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.inventory.0.current_cost', 6.42)
        ->assertJsonPath('data.variants.0.inventory.0.current_sale_price', 12.49)
        ->assertJsonPath('data.variants.0.inventory.0.fifo_sale_price_current', 12.49);
});

it('exposes neutral inventory history for a product variant by warehouse with legacy fifo aliases', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 10, 5.35, 0, 0);
    receiveFifoPurchase($context, 10, 6.42, 0, 0);

    app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 12,
            'unit_price' => 9.99,
            'tax_percent' => 0,
        ]],
    ]);

    $response = $this->getJson(
        "/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/inventory-history?warehouse_id={$context['warehouse']->id}"
    );

    $response->assertOk()
        ->assertJsonPath('data.summary.stock_available', 8)
        ->assertJsonPath('data.summary.current_cost', 6.42)
        ->assertJsonPath('data.summary.inventory_value', 51.36)
        ->assertJsonPath('data.summary.fifo_cost_current', 6.42)
        ->assertJsonPath('data.summary.open_lots_count', 1)
        ->assertJsonPath('data.lots.0.quantity_available', 8)
        ->assertJsonPath('data.lots.0.landed_unit_cost', 6.42)
        ->assertJsonPath('data.purchase_movements.0.quantity', 10)
        ->assertJsonPath('data.purchase_movements.0.source_lots.0.quantity', 10)
        ->assertJsonPath('data.purchase_movements.0.fifo_lots.0.quantity', 10)
        ->assertJsonCount(3, 'data.movement_timeline')
        ->assertJsonPath('data.sale_movements.0.quantity', 12)
        ->assertJsonPath('data.sale_movements.0.lot_allocations.0.quantity', 10)
        ->assertJsonPath('data.sale_movements.0.lot_allocations.1.quantity', 2)
        ->assertJsonPath('data.sale_movements.0.fifo_allocations.0.quantity', 10)
        ->assertJsonPath('data.sale_movements.0.fifo_allocations.1.quantity', 2);

    $saleTimeline = collect($response->json('data.movement_timeline'))
        ->firstWhere('reference_type', 'sale');
    expect($saleTimeline)->not->toBeNull()
        ->and($saleTimeline['quantity_before'])->toBe(20)
        ->and($saleTimeline['quantity_after'])->toBe(8)
        ->and($saleTimeline['user']['id'])->toBe($context['user']->id);

    $this->getJson(
        "/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/fifo-history?warehouse_id={$context['warehouse']->id}"
    )
        ->assertOk()
        ->assertJsonPath('data.summary.current_cost', 6.42)
        ->assertJsonPath('data.summary.fifo_cost_current', 6.42);
});

it('returns not found when requesting fifo history for a variant from another product', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $otherProduct = Product::create([
        'sku_base' => 'FIFO-OTHER-'.fake()->unique()->randomNumber(4),
        'name' => 'Otro Producto FIFO',
        'status' => 'active',
    ]);

    $this->getJson(
        "/api/v1/products/{$otherProduct->id}/variants/{$context['variant']->id}/fifo-history?warehouse_id={$context['warehouse']->id}"
    )->assertNotFound();
});

it('validates fifo history warehouse and applies date filters', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 10, 5.35, 0, 0);

    $this->getJson(
        "/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/fifo-history?warehouse_id={$context['warehouse']->id}&date_from=2026-05-16&date_to=2026-05-15"
    )->assertJsonValidationErrors('date_to');

    $tomorrow = now()->addDay()->toDateString();

    $this->getJson(
        "/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/fifo-history?warehouse_id={$context['warehouse']->id}&date_from={$tomorrow}"
    )
        ->assertOk()
        ->assertJsonCount(0, 'data.lots')
        ->assertJsonCount(0, 'data.purchase_movements')
        ->assertJsonPath('data.summary.stock_available', 10);
});

it('restores fifo lots when a confirmed sale is cancelled', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 5, 4.00, 0, 0);

    $order = app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 3,
            'unit_price' => 8,
            'tax_percent' => 0,
        ]],
    ]);

    app(SalesOrderService::class)->cancelOrder($order->id);

    $lot = InventoryLot::where('variant_id', $context['variant']->id)->firstOrFail();
    $inventory = ProductInventory::where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(5)
        ->and((int) $inventory->quantity_available)->toBe(5);
});

it('preserves fifo cost when stock is transferred to another warehouse', function () {
    $context = fifoCatalogContext();
    $to = Warehouse::create(['code' => 'WH-FIFO-TO', 'name' => 'Destino FIFO']);
    $context['user']->warehouses()->attach($to->id);
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 6, 7.25, 0, 0);

    $service = app(InventoryTransferService::class);
    $transfer = $service->create([
        'from_warehouse_id' => $context['warehouse']->id,
        'to_warehouse_id' => $to->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'quantity' => 4,
        ]],
    ], $context['user']->id);

    $service->receive($service->dispatch($transfer), $context['user']->id);

    $originLot = InventoryLot::where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->firstOrFail();
    $destinationLot = InventoryLot::where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $to->id)
        ->firstOrFail();

    expect((int) $originLot->quantity_available)->toBe(2)
        ->and((int) $destinationLot->quantity_available)->toBe(4)
        ->and((float) $destinationLot->landed_unit_cost)->toBe(7.25);
});

it('manual waste consumes fifo lots', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 4, 3.00, 0, 0);

    $this->postJson('/api/v1/inventory/waste', [
        'variant_id' => $context['variant']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 2,
        'reason' => 'Dañado',
    ])->assertCreated();

    $lot = InventoryLot::where('variant_id', $context['variant']->id)->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(2)
        ->and(InventoryLotAllocation::whereHas('movement', fn ($query) => $query->where('reference_type', 'waste'))->count())->toBe(1);
});

it('creates an opening fifo lot when inventory is imported directly', function () {
    $context = fifoCatalogContext();

    ProductInventory::create([
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity_available' => 8,
        'quantity_reserved' => 0,
    ]);

    app(InventoryFifoService::class)->ensureOpeningLotForInventory(
        $context['product']->id,
        $context['variant']->id,
        $context['warehouse']->id,
        8,
        2.50,
    );

    $lot = InventoryLot::where('variant_id', $context['variant']->id)->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(8)
        ->and($lot->source_type)->toBe('opening_balance')
        ->and((float) $lot->landed_unit_cost)->toBe(2.50);
});

it('approved sales returns create inbound fifo lots instead of adjusting aggregate stock directly', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 5, 4.75, 0, 0);

    $order = app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 2,
            'unit_price' => 9,
            'tax_percent' => 0,
        ]],
    ]);

    $salesOrderItem = $order->items()->firstOrFail();
    $salesOrderItem->forceFill(['unit_cost' => 4.75])->save();
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => $order->total,
        'method' => 'cash',
        'paid_at' => now(),
        'created_by' => $context['user']->id,
    ]);

    $return = SalesReturn::create([
        'sales_order_id' => $order->id,
        'warehouse_id' => $context['warehouse']->id,
        'user_id' => $context['user']->id,
        'status' => 'pending',
        'reason' => 'Cliente devolvio producto',
        'total_amount' => 9,
        'refund_method' => 'cash',
    ]);

    SalesReturnItem::create([
        'sales_return_id' => $return->id,
        'sales_order_item_id' => $salesOrderItem->id,
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'quantity' => 1,
        'unit_price' => 9,
        'subtotal' => 9,
    ]);

    expect(app(SalesReturnService::class)->approve($return, $context['user'])->status)->toBe('approved');

    $returnLot = InventoryLot::query()
        ->where('source_type', 'sales_return')
        ->where('source_id', $return->id)
        ->firstOrFail();

    $inventory = ProductInventory::query()
        ->where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->firstOrFail();

    expect((int) $returnLot->quantity_available)->toBe(1)
        ->and((float) $returnLot->landed_unit_cost)->toBe(4.75)
        ->and((int) $inventory->quantity_available)->toBe(4);
});

it('cancelling a partially received purchase order reverses only its own fifo lots', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $po = app(PurchaseOrderService::class)->create([
        'supplier_id' => $context['supplier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'quantity_ordered' => 10,
            'unit_cost' => 5.00,
            'tax_amount' => 0,
            'misc_cost' => 0,
        ]],
    ]);

    app(PurchaseOrderService::class)->receiveItems($po->id, [[
        'item_id' => $po->items()->firstOrFail()->id,
        'quantity_received' => 6,
    ]]);

    app(PurchaseOrderService::class)->cancel($po->id);

    $lot = InventoryLot::where('source_type', 'purchase_order')
        ->where('source_id', $po->items()->firstOrFail()->id)
        ->firstOrFail();
    $inventory = ProductInventory::where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(0)
        ->and((int) $inventory->quantity_available)->toBe(0)
        ->and(InventoryLotAllocation::whereHas('movement', fn ($query) => $query
            ->where('reference_type', 'purchase_order_cancelled')
            ->where('reference_id', $po->id))->sum('quantity'))->toBe(6);
});

it('cancelling a partially received purchase order fails when its received lot was consumed', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $po = app(PurchaseOrderService::class)->create([
        'supplier_id' => $context['supplier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'quantity_ordered' => 10,
            'unit_cost' => 5.00,
            'tax_amount' => 0,
            'misc_cost' => 0,
        ]],
    ]);

    app(PurchaseOrderService::class)->receiveItems($po->id, [[
        'item_id' => $po->items()->firstOrFail()->id,
        'quantity_received' => 6,
    ]]);

    app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 2,
            'unit_price' => 8,
            'tax_percent' => 0,
        ]],
    ]);

    expect(fn () => app(PurchaseOrderService::class)->cancel($po->id))
        ->toThrow(\Exception::class, 'stock insuficiente');
});

it('manual absolute inventory adjustment up creates only a delta fifo lot', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 5, 3.25, 0, 0);

    $this->putJson("/api/v1/inventory/products/{$context['product']->id}/warehouses/{$context['warehouse']->id}", [
        'variant_id' => $context['variant']->id,
        'stock_available' => 8,
    ])->assertOk();

    $adjustmentLot = InventoryLot::where('source_type', 'manual_adjustment')
        ->where('variant_id', $context['variant']->id)
        ->firstOrFail();

    expect((int) $adjustmentLot->quantity_available)->toBe(3)
        ->and((int) InventoryLot::where('variant_id', $context['variant']->id)->sum('quantity_available'))->toBe(8);
});

it('manual absolute inventory adjustment down consumes fifo by delta', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 5, 3.25, 0, 0);

    $this->putJson("/api/v1/inventory/products/{$context['product']->id}/warehouses/{$context['warehouse']->id}", [
        'variant_id' => $context['variant']->id,
        'stock_available' => 2,
    ])->assertOk();

    $lot = InventoryLot::where('variant_id', $context['variant']->id)->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(2)
        ->and(InventoryLotAllocation::whereHas('movement', fn ($query) => $query
            ->where('reference_type', 'manual_adjustment'))->sum('quantity'))->toBe(3);
});

it('supply list pre-applied stock creates an inbound fifo lot', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/supply-list', [
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'supplier_id' => $context['supplier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity_needed' => 4,
        'unit_cost' => 6.10,
        'tax_rate' => 0,
    ])->assertCreated();

    $item = SupplyListItem::firstOrFail();
    $lot = InventoryLot::where('source_type', 'supply_list')
        ->where('source_id', $item->id)
        ->firstOrFail();

    expect((int) $lot->quantity_available)->toBe(4)
        ->and((float) $lot->landed_unit_cost)->toBe(6.10);
});

it('creating product with initial inventory creates an idempotent opening fifo lot', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $product = app(ProductService::class)->createProduct([
        'sku_base' => 'FIFO-PRODUCT-'.fake()->unique()->randomNumber(4),
        'name' => 'Producto inicial FIFO',
        'brand_id' => $context['variant']->brand_id,
        'pricing' => [
            'base_price' => 10,
            'cost_base' => 4.40,
            'cost_tax_rate' => 0,
            'sale_tax_rate' => 0,
        ],
        'initial_inventory' => [[
            'warehouse_id' => $context['warehouse']->id,
            'quantity_available' => 7,
            'reorder_point' => 1,
            'reorder_quantity' => 2,
        ]],
    ]);

    $variant = $product->variants()->firstOrFail();

    app(InventoryFifoService::class)->ensureOpeningLotForInventory(
        $product->id,
        $variant->id,
        $context['warehouse']->id,
        7,
        4.40,
    );

    expect((int) InventoryLot::where('variant_id', $variant->id)->sum('quantity_available'))->toBe(7)
        ->and(InventoryLot::where('variant_id', $variant->id)->count())->toBe(1);
});

it('adding items to an existing order through the controller consumes fifo and stores weighted unit cost', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 2, 4.00, 0, 0);
    receiveFifoPurchase($context, 3, 6.00, 0, 0);

    $order = SalesOrder::create([
        'user_id' => $context['user']->id,
        'date' => now(),
        'status' => 'confirmed',
        'subtotal' => 0,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
        'payment_status' => 'pending',
        'payment_method' => 'cash',
    ]);

    $this->patchJson("/api/v1/sales-orders/{$order->id}/add-items", [
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'qty' => 3,
            'price' => 10,
            'tax_percent' => 0,
        ]],
    ])->assertOk();

    $item = $order->items()->firstOrFail();

    expect(InventoryLotAllocation::where('sales_order_item_id', $item->id)->sum('quantity'))->toBe(3)
        ->and((float) $item->fresh()->unit_cost)->toBe(4.67);
});

it('public inventory movement endpoints ignore skip fifo and still use fifo lots', function () {
    $context = fifoCatalogContext();
    $destination = Warehouse::create([
        'code' => 'WH-FIFO-DEST-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen FIFO Destino',
    ]);

    $this->actingAs($context['user']);

    $this->postJson('/api/v1/inventory-movements/inbound', [
        'product_id' => $context['product']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 6,
        'reference_type' => 'adjustment',
        'skip_fifo' => true,
    ])->assertCreated();

    expect(InventoryLot::where('product_id', $context['product']->id)
        ->whereNull('variant_id')
        ->where('warehouse_id', $context['warehouse']->id)
        ->sum('quantity_available'))->toBe(6);

    $this->postJson('/api/v1/inventory-movements/outbound', [
        'product_id' => $context['product']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 2,
        'reference_type' => 'adjustment',
        'skip_fifo' => true,
    ])->assertCreated();

    expect(InventoryLotAllocation::whereHas('movement', fn ($query) => $query
        ->where('product_id', $context['product']->id)
        ->whereNull('variant_id')
        ->where('reference_type', 'adjustment')
        ->where('movement_type', 'out'))->sum('quantity'))->toBe(2);

    $this->postJson('/api/v1/inventory-movements/transfer', [
        'product_id' => $context['product']->id,
        'warehouse_id' => $context['warehouse']->id,
        'destination_warehouse_id' => $destination->id,
        'quantity' => 2,
        'skip_fifo' => true,
    ])->assertCreated();

    expect(InventoryLot::where('product_id', $context['product']->id)
        ->whereNull('variant_id')
        ->where('warehouse_id', $destination->id)
        ->where('source_type', 'transfer')
        ->sum('quantity_available'))->toBe(2);
});

it('bulk inventory movement endpoints do not allow skip fifo to bypass lots', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/inventory-movements/inbound/bulk', [
        'items' => [[
            'product_id' => $context['product']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 5,
            'skip_fifo' => true,
        ]],
    ])->assertCreated();

    expect(InventoryLot::where('product_id', $context['product']->id)
        ->whereNull('variant_id')
        ->where('warehouse_id', $context['warehouse']->id)
        ->sum('quantity_available'))->toBe(5);

    $this->postJson('/api/v1/inventory-movements/outbound/bulk', [
        'items' => [[
            'product_id' => $context['product']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 3,
            'skip_fifo' => true,
        ]],
    ])->assertCreated();

    expect(InventoryLotAllocation::whereHas('movement', fn ($query) => $query
        ->where('product_id', $context['product']->id)
        ->whereNull('variant_id')
        ->where('movement_type', 'out'))->sum('quantity'))->toBe(3);
});

it('exposes source lots on inbound inventory movement detail with legacy fifo alias', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    $movement = app(InventoryFifoService::class)->receiveStock([
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 7,
        'unit_cost_base' => 4.25,
        'tax_amount' => 2.10,
        'misc_cost' => 1.05,
        'source_type' => 'manual_inbound',
        'user_id' => $context['user']->id,
    ]);

    $this->getJson("/api/v1/inventory-movements/{$movement->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $context['product']->id)
        ->assertJsonPath('data.variant.id', $context['variant']->id)
        ->assertJsonPath('data.warehouse.id', $context['warehouse']->id)
        ->assertJsonPath('data.source_lots.0.received_quantity', 7)
        ->assertJsonPath('data.source_lots.0.quantity_available', 7)
        ->assertJsonPath('data.source_lots.0.unit_cost_base', 4.25)
        ->assertJsonPath('data.source_lots.0.tax_per_unit', 0.30)
        ->assertJsonPath('data.source_lots.0.misc_per_unit', 0.15)
        ->assertJsonPath('data.source_lots.0.landed_unit_cost', 4.70)
        ->assertJsonPath('data.fifo_lots.0.received_quantity', 7)
        ->assertJsonPath('data.fifo_lots.0.quantity_available', 7)
        ->assertJsonPath('data.fifo_lots.0.unit_cost_base', 4.25)
        ->assertJsonPath('data.fifo_lots.0.tax_per_unit', 0.30)
        ->assertJsonPath('data.fifo_lots.0.misc_per_unit', 0.15)
        ->assertJsonPath('data.fifo_lots.0.landed_unit_cost', 4.70);
});

it('exposes lot allocations on outbound inventory movement detail with legacy fifo alias', function () {
    $context = fifoCatalogContext();
    $this->actingAs($context['user']);

    receiveFifoPurchase($context, 5, 3.50, 0, 0);

    $order = app(SalesOrderService::class)->create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 3,
            'unit_price' => 9,
            'tax_percent' => 0,
        ]],
    ]);

    $movement = \App\Models\InventoryMovement::where('reference_type', 'sale')
        ->where('reference_id', $order->id)
        ->firstOrFail();

    $this->getJson("/api/v1/inventory-movements/{$movement->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $context['product']->id)
        ->assertJsonPath('data.variant.id', $context['variant']->id)
        ->assertJsonPath('data.warehouse.id', $context['warehouse']->id)
        ->assertJsonPath('data.lot_allocations.0.quantity', 3)
        ->assertJsonPath('data.lot_allocations.0.unit_cost', 3.50)
        ->assertJsonPath('data.lot_allocations.0.total_cost', 10.50)
        ->assertJsonPath('data.fifo_allocations.0.quantity', 3)
        ->assertJsonPath('data.fifo_allocations.0.unit_cost', 3.50)
        ->assertJsonPath('data.fifo_allocations.0.total_cost', 10.50);
});
