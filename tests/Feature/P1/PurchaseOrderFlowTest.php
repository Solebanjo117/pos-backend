<?php

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

it('lists pending receipt orders without ordering by expected date', function () {
    [$buyer, $warehouse, $supplier] = purchaseOrderBuyerContext();

    $first = PurchaseOrder::create([
        'po_number' => 'PO-PENDING-FIRST',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'sent',
        'order_date' => '2026-05-10',
        'expected_date' => '2026-06-01',
        'subtotal' => 0,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
    ]);

    $second = PurchaseOrder::create([
        'po_number' => 'PO-PENDING-SECOND',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'sent',
        'order_date' => '2026-05-11',
        'expected_date' => '2026-05-01',
        'subtotal' => 0,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
    ]);

    $response = $this->actingAs($buyer)
        ->getJson('/api/v1/purchase-orders/pending-receipt');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);
});

it('does not render expected date in purchase order PDF view', function () {
    [$buyer, $warehouse, $supplier] = purchaseOrderBuyerContext();

    $order = PurchaseOrder::create([
        'po_number' => 'PO-PDF-NO-EXPECTED',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'sent',
        'order_date' => '2026-05-10',
        'expected_date' => '2026-06-01',
        'subtotal' => 0,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
    ])->load(['supplier', 'warehouse', 'items']);

    $html = view('pdf.purchase-order', [
        'purchaseOrder' => $order,
        'mode' => 'standard',
        'store_name' => 'HermeX POS',
    ])->render();

    expect($html)->not->toContain('Fecha esperada')
        ->and($html)->not->toContain('01/06/2026');
});

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

it('defaults purchase order item tax rate to store tax rate', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();
    Setting::set('store_tax_rate', 10, 'number', 'store');

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

    expect((int) $order->items[0]->tax_rate)->toBe(10)
        ->and((float) $order->items[0]->tax_amount)->toBe(2.0)
        ->and((float) $order->tax_amount)->toBe(2.0)
        ->and((float) $order->total)->toBe(22.0);
});

it('preserves explicit zero tax rate as exempt for purchase order items', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();
    Setting::set('store_tax_rate', 10, 'number', 'store');

    $response = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'unit_cost' => 10,
                'tax_rate' => 0,
            ],
        ],
    ]);

    $response->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($response->json('data.id'));

    expect((int) $order->items[0]->tax_rate)->toBe(0)
        ->and((float) $order->items[0]->tax_amount)->toBe(0.0)
        ->and((float) $order->tax_amount)->toBe(0.0)
        ->and((float) $order->total)->toBe(20.0);
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

it('filters purchase orders by supplier in header and daily order items', function () {
    [$buyer, $warehouse, $supplier, $firstProduct, $secondProduct] = purchaseOrderBuyerContext();

    $otherSupplier = Supplier::create([
        'name' => 'Proveedor Externo',
        'email' => 'proveedor-externo-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $headerOrder = PurchaseOrder::create([
        'po_number' => 'PO-SUPPLIER-HEADER',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => 10,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 10,
    ]);

    $dailyOrder = PurchaseOrder::create([
        'po_number' => 'PO-SUPPLIER-ITEM',
        'supplier_id' => null,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'open',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => 15,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 15,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $dailyOrder->id,
        'supplier_id' => $supplier->id,
        'product_id' => $firstProduct->id,
        'quantity_ordered' => 1,
        'units_per_package' => 1,
        'unit_cost' => 15,
        'tax_rate' => 0,
    ]);

    PurchaseOrder::create([
        'po_number' => 'PO-SUPPLIER-OTHER',
        'supplier_id' => $otherSupplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => 20,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 20,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $headerOrder->id,
        'supplier_id' => $otherSupplier->id,
        'product_id' => $secondProduct->id,
        'quantity_ordered' => 1,
        'units_per_package' => 1,
        'unit_cost' => 20,
        'tax_rate' => 0,
    ]);

    $response = $this->actingAs($buyer)
        ->getJson("/api/v1/purchase-orders?supplier_id={$supplier->id}");

    $response->assertOk();

    $numbers = collect($response->json('data'))->pluck('po_number');

    expect($numbers)->toContain('PO-SUPPLIER-HEADER')
        ->and($numbers)->toContain('PO-SUPPLIER-ITEM')
        ->and($numbers)->not->toContain('PO-SUPPLIER-OTHER');
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

it('returns complete purchase order detail summaries for operations workbench', function () {
    [$buyer, $warehouse, $supplier, $firstProduct, $secondProduct] = purchaseOrderBuyerContext();

    $created = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'shipping_cost' => 4.50,
        'items' => [
            [
                'product_id' => $firstProduct->id,
                'quantity_ordered' => 2,
                'units_per_package' => 2,
                'unit_cost' => 10,
                'tax_rate' => 7,
                'misc_cost' => 3.25,
            ],
            [
                'product_id' => $secondProduct->id,
                'quantity_ordered' => 1,
                'unit_cost' => 20,
                'tax_amount' => 1.50,
                'misc_cost' => 2.00,
            ],
        ],
    ])->assertCreated();

    $this->actingAs($buyer)
        ->getJson('/api/v1/purchase-orders/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.cost_summary.subtotal', 60)
        ->assertJsonPath('data.cost_summary.tax_total', 4.3)
        ->assertJsonPath('data.cost_summary.misc_total', 5.25)
        ->assertJsonPath('data.cost_summary.shipping_total', 4.5)
        ->assertJsonPath('data.cost_summary.grand_total', 74.05)
        ->assertJsonPath('data.receipt_summary.ordered_packages', 3)
        ->assertJsonPath('data.receipt_summary.pending_packages', 3)
        ->assertJsonPath('data.payment_summary.amount_due', 74.05)
        ->assertJsonPath('data.ap_impact.status', 'open')
        ->assertJsonPath('data.items.0.line_cost_summary.misc_total', 3.25)
        ->assertJsonPath('data.items.0.line_cost_summary.landed_unit_cost', 11.5125);
});

it('shows received inventory impact from purchase order detail', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();

    $created = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'units_per_package' => 3,
                'unit_cost' => 8,
                'tax_amount' => 4.80,
                'misc_cost' => 1.20,
            ],
        ],
    ])->assertCreated();

    $order = PurchaseOrder::with('items')->findOrFail($created->json('data.id'));
    $item = $order->items->first();

    $this->actingAs($buyer)->postJson('/api/v1/purchase-orders/'.$order->id.'/receive', [
        'idempotency_key' => (string) Str::uuid(),
        'items' => [
            [
                'item_id' => $item->id,
                'quantity_received' => 1,
            ],
        ],
    ])->assertOk();

    expect(InventoryLot::where('source_type', 'purchase_order')->where('source_id', $item->id)->count())->toBe(1);

    $this->actingAs($buyer)
        ->getJson('/api/v1/purchase-orders/'.$order->id)
        ->assertOk()
        ->assertJsonPath('data.receipt_summary.received_packages', 1)
        ->assertJsonPath('data.receipt_summary.pending_packages', 1)
        ->assertJsonPath('data.inventory_impact.received_units', 3)
        ->assertJsonPath('data.inventory_impact.landed_value', 27)
        ->assertJsonPath('data.inventory_impact.lots.0.source_item_id', $item->id)
        ->assertJsonPath('data.inventory_impact.lots.0.tax_total', 2.4)
        ->assertJsonPath('data.inventory_impact.lots.0.misc_total', 0.6)
        ->assertJsonPath('data.inventory_impact.lots.0.landed_unit_cost', 9);
});

it('renders purchase order PDF with cost and accounts payable summary', function () {
    [$buyer, $warehouse, $supplier, $product] = purchaseOrderBuyerContext();

    $created = $this->actingAs($buyer)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity_ordered' => 1,
                'unit_cost' => 100,
                'tax_amount' => 7,
                'misc_cost' => 5,
            ],
        ],
    ])->assertCreated();

    $order = PurchaseOrder::with(['supplier', 'warehouse', 'user', 'items.product', 'items.variant.brand', 'payments'])
        ->findOrFail($created->json('data.id'));

    $html = view('pdf.purchase-order', [
        'purchaseOrder' => $order,
        'mode' => 'standard',
        'store_name' => 'HermeX POS',
    ])->render();

    expect($html)->toContain('ITBM')
        ->and($html)->toContain('Misc')
        ->and($html)->toContain('Resumen de pago')
        ->and($html)->toContain('Saldo AP')
        ->and($html)->toContain('$112.00');
});
