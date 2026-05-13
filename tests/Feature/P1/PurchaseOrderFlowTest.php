<?php

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects purchase order creation with empty payload', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-PO',
        'name' => 'Warehouse PO',
    ]);

    $buyer = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $buyer->warehouses()->attach($warehouse->id);

    $this->actingAs($buyer)
        ->postJson('/api/v1/purchase-orders', [])
        ->assertStatus(422);
});

function purchaseOrderBuyerContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-PO-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse PO',
    ]);

    $buyer = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $buyer->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor OC',
        'email' => 'proveedor-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $firstProduct = Product::create([
        'sku_base' => 'PO-A-'.fake()->unique()->randomNumber(4),
        'name' => 'Articulo OC A',
        'status' => 'active',
    ]);

    $secondProduct = Product::create([
        'sku_base' => 'PO-B-'.fake()->unique()->randomNumber(4),
        'name' => 'Articulo OC B',
        'status' => 'active',
    ]);

    return [$buyer, $warehouse, $supplier, $firstProduct, $secondProduct];
}

it('calculates purchase order item taxes and header totals from line tax rates', function () {
    [$buyer, $warehouse, $supplier, $firstProduct, $secondProduct] = purchaseOrderBuyerContext();

    $response = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'shipping_cost' => 3,
        'items' => [
            [
                'product_id' => $firstProduct->id,
                'quantity_ordered' => 2,
                'units_per_package' => 3,
                'unit_cost' => 10,
                'tax_rate' => 7,
            ],
            [
                'product_id' => $secondProduct->id,
                'quantity_ordered' => 1,
                'units_per_package' => 4,
                'unit_cost' => 5,
                'tax_rate' => 0,
            ],
        ],
    ]);

    $response->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));

    expect((float) $order->subtotal)->toBe(80.0)
        ->and((float) $order->tax_amount)->toBe(4.2)
        ->and((float) $order->total)->toBe(87.2)
        ->and((int) $order->items[0]->tax_rate)->toBe(7)
        ->and((float) $order->items[0]->tax_amount)->toBe(4.2)
        ->and((int) $order->items[1]->tax_rate)->toBe(0)
        ->and((float) $order->items[1]->tax_amount)->toBe(0.0);
});

it('defaults purchase order item tax rate to seven percent', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();

    $response = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'unit_cost' => 10,
            ],
        ],
    ]);

    $response->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));

    expect((int) $order->items[0]->tax_rate)->toBe(7)
        ->and((float) $order->items[0]->tax_amount)->toBe(1.4)
        ->and((float) $order->tax_amount)->toBe(1.4)
        ->and((float) $order->total)->toBe(21.4);
});

it('recalculates line taxes when updating a draft purchase order', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();

    $created = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 1,
                'unit_cost' => 10,
                'tax_rate' => 7,
            ],
        ],
    ]);

    $created->assertCreated();

    $updated = $this->actingAs($buyer)->putJson('/api/v1/purchase-orders/'.$created->json('data.id'), [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'shipping_cost' => 2,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 3,
                'unit_cost' => 10,
                'tax_rate' => 0,
            ],
        ],
    ]);

    $updated->assertOk();

    $order = PurchaseOrder::with('items')->findOrFail($created->json('data.id'));

    expect((float) $order->subtotal)->toBe(30.0)
        ->and((float) $order->tax_amount)->toBe(0.0)
        ->and((float) $order->total)->toBe(32.0)
        ->and((int) $order->items[0]->tax_rate)->toBe(0)
        ->and((float) $order->items[0]->tax_amount)->toBe(0.0);
});

it('respects manual tax_amount when explicitly provided per line', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();

    $response = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'unit_cost' => 50,
                'tax_amount' => 5.00, // Manual override instead of auto 7% = 7.00
            ],
        ],
    ]);

    $response->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));

    // subtotal = 2 * 1 * 50 = 100
    expect((float) $order->items[0]->subtotal)->toBe(100.0)
        // Manual tax_amount kept as 5.00 (not auto 7.00)
        ->and((float) $order->items[0]->tax_amount)->toBe(5.0)
        ->and((float) $order->tax_amount)->toBe(5.0)
        // total = 100 + 5 = 105 (no shipping, no misc)
        ->and((float) $order->total)->toBe(105.0);
});

it('includes misc_cost per line in purchase order totals', function () {
    [$buyer, $warehouse, $supplier, $firstProduct, $secondProduct] = purchaseOrderBuyerContext();

    $response = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $firstProduct->id,
                'quantity_ordered' => 1,
                'unit_cost' => 100,
                'tax_amount' => 7.00,
                'misc_cost' => 10.00,
            ],
            [
                'product_id' => $secondProduct->id,
                'quantity_ordered' => 2,
                'unit_cost' => 50,
                'tax_amount' => 0.00,
                'misc_cost' => 5.00,
            ],
        ],
    ]);

    $response->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));

    // item1: subtotal=100, tax=7, misc=10, line_total=117
    // item2: subtotal=100, tax=0, misc=5, line_total=105
    expect((float) $order->subtotal)->toBe(200.0)
        ->and((float) $order->tax_amount)->toBe(7.0)
        // PO header total = subtotal + tax + misc_sum = 200 + 7 + 15 = 222
        ->and((float) $order->total)->toBe(222.0)
        ->and((float) $order->items[0]->misc_cost)->toBe(10.0)
        ->and((float) $order->items[1]->misc_cost)->toBe(5.0)
        // Accessor total on each item
        ->and((float) $order->items[0]->total)->toBe(117.0)
        ->and((float) $order->items[1]->total)->toBe(105.0);
});
