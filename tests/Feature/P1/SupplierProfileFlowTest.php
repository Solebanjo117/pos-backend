<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function supplierProfileContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-SUP-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse Supplier 360',
    ]);

    $buyer = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $buyer->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor 360 '.fake()->unique()->randomNumber(4),
        'email' => 'proveedor360-'.fake()->unique()->randomNumber(4).'@example.test',
        'phone' => '555-360',
    ]);

    $otherSupplier = Supplier::create([
        'name' => 'Proveedor Alterno '.fake()->unique()->randomNumber(4),
        'email' => 'proveedor-alt-'.fake()->unique()->randomNumber(4).'@example.test',
    ]);

    $brand = Brand::create([
        'name' => 'Marca 360',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'SUP-360-BASE',
        'system_id' => 'SUP360001',
        'name' => 'Filtro proveedor 360',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'SUP-360-VAR',
        'status' => 'active',
    ]);

    return compact('warehouse', 'buyer', 'supplier', 'otherSupplier', 'brand', 'product', 'variant');
}

it('returns supplier purchase history with direct and item-level purchase data', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'otherSupplier' => $otherSupplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();

    $directOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->subDays(2)->toDateString(),
        'subtotal' => 100,
        'tax_amount' => 7,
        'shipping_cost' => 0,
        'total' => 107,
    ]);
    $directOrder->items()->create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 2,
        'quantity_received' => 2,
        'units_per_package' => 1,
        'unit_cost' => 50,
        'tax_rate' => 7,
        'misc_cost' => 0,
    ]);
    $directOrder->addPayment(40, 'transfer', 'ACH-360', 'Abono proveedor');

    $dailyOrder = PurchaseOrder::create([
        'supplier_id' => null,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->subDay()->toDateString(),
        'subtotal' => 75,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 75,
    ]);
    $dailyOrder->items()->create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 3,
        'quantity_received' => 1,
        'units_per_package' => 1,
        'unit_cost' => 25,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);

    $otherOrder = PurchaseOrder::create([
        'supplier_id' => $otherSupplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => 999,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 999,
    ]);
    $otherOrder->items()->create([
        'supplier_id' => $otherSupplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 1,
        'quantity_received' => 0,
        'units_per_package' => 1,
        'unit_cost' => 999,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);

    $response = $this->actingAs($buyer)->getJson("/api/v1/suppliers/{$supplier->id}/purchase-history?per_page=1");

    $response->assertOk()
        ->assertJsonPath('summary.orders_count', 2)
        ->assertJsonPath('summary.items_count', 2)
        ->assertJsonPath('summary.total_ordered', 182)
        ->assertJsonPath('summary.total_paid', 40)
        ->assertJsonPath('summary.total_due', 67)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $dailyOrder->id)
        ->assertJsonPath('data.0.supplier_items_total', 75)
        ->assertJsonPath('data.0.items.0.product_name', 'Filtro proveedor 360');
});

it('does not attribute daily purchase order payments to item-level suppliers', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'otherSupplier' => $otherSupplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();

    $dailyOrder = PurchaseOrder::create([
        'supplier_id' => null,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'partial',
        'order_date' => now()->toDateString(),
        'subtotal' => 150,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 150,
    ]);

    $dailyOrder->items()->create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 2,
        'quantity_received' => 2,
        'units_per_package' => 1,
        'unit_cost' => 25,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);

    $dailyOrder->items()->create([
        'supplier_id' => $otherSupplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 2,
        'quantity_received' => 2,
        'units_per_package' => 1,
        'unit_cost' => 50,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);

    $dailyOrder->addPayment(100, 'cash', null, 'Pago global de OC diaria');

    $this->actingAs($buyer)
        ->getJson("/api/v1/suppliers/{$supplier->id}/purchase-history")
        ->assertOk()
        ->assertJsonPath('summary.total_paid', 0)
        ->assertJsonPath('summary.total_due', 0)
        ->assertJsonPath('data.0.id', $dailyOrder->id)
        ->assertJsonPath('data.0.is_accounts_payable', false)
        ->assertJsonPath('data.0.document_total', 150)
        ->assertJsonPath('data.0.amount_paid', 0)
        ->assertJsonPath('data.0.amount_due', 0)
        ->assertJsonCount(0, 'data.0.payments')
        ->assertJsonCount(0, 'data.0.payment_summary');
});

it('filters supplier purchase history by search and payment status', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();

    $pendingOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'FAC-BUSCADA',
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->subDay()->toDateString(),
        'subtotal' => 50,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 50,
    ]);
    $pendingOrder->items()->create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 1,
        'quantity_received' => 0,
        'units_per_package' => 1,
        'unit_cost' => 50,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);

    $paidOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'FAC-PAGADA',
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'paid',
        'order_date' => now()->toDateString(),
        'subtotal' => 80,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 80,
    ]);
    $paidOrder->items()->create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 1,
        'quantity_received' => 1,
        'units_per_package' => 1,
        'unit_cost' => 80,
        'tax_rate' => 0,
        'misc_cost' => 0,
    ]);
    $paidOrder->addPayment(80, 'cash');

    $response = $this->actingAs($buyer)->getJson("/api/v1/suppliers/{$supplier->id}/purchase-history?search=FAC-BUSCADA&payment_status=pending");

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $pendingOrder->id)
        ->assertJsonPath('data.0.payment_status', 'pending');
});

it('returns commercial metrics for supplied variants', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();

    ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'supplier_id' => $supplier->id,
        'supplier_cost' => 14,
        'lead_time_days' => 4,
        'minimum_order_quantity' => 2,
        'is_preferred' => true,
    ]);

    foreach ([[10, 20], [5, 24], [0, 30]] as [$daysAgo, $unitCost]) {
        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $buyer->id,
            'status' => $daysAgo === 0 ? 'sent' : 'received',
            'payment_status' => 'pending',
            'order_date' => now()->subDays($daysAgo)->toDateString(),
            'received_date' => $daysAgo === 0 ? null : now()->subDays(max(0, $daysAgo - 2))->toDateString(),
            'subtotal' => $unitCost * 2,
            'tax_amount' => 0,
            'shipping_cost' => 0,
            'total' => $unitCost * 2,
        ]);

        $order->items()->create([
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_ordered' => 2,
            'quantity_received' => $daysAgo === 0 ? 0 : 2,
            'units_per_package' => 1,
            'unit_cost' => $unitCost,
            'tax_rate' => 0,
            'misc_cost' => 0,
        ]);
    }

    $response = $this->actingAs($buyer)->getJson("/api/v1/suppliers/{$supplier->id}/variants");

    $response->assertOk()
        ->assertJsonPath('data.0.last_unit_cost', 30)
        ->assertJsonPath('data.0.previous_unit_cost', 24)
        ->assertJsonPath('data.0.cost_delta', 6)
        ->assertJsonPath('data.0.cost_delta_percent', 25)
        ->assertJsonPath('data.0.purchase_count', 3)
        ->assertJsonPath('data.0.pending_orders_count', 1)
        ->assertJsonPath('data.0.pending_units', 2)
        ->assertJsonPath('data.0.promised_lead_time_days', 4)
        ->assertJsonPath('data.0.average_actual_lead_time_days', 2);
});

it('returns supplier commercial dashboard with uncovered low stock products', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();

    ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'supplier_id' => $supplier->id,
        'supplier_cost' => 14,
        'lead_time_days' => 4,
        'minimum_order_quantity' => 2,
    ]);

    $uncoveredProduct = Product::create([
        'sku_base' => 'SIN-PROV-001',
        'system_id' => 'SINPROV001',
        'name' => 'Articulo sin proveedor',
        'status' => 'active',
    ]);
    $uncoveredVariant = ProductVariant::create([
        'product_id' => $uncoveredProduct->id,
        'brand_id' => $variant->brand_id,
        'sku' => 'SIN-PROV-VAR',
        'status' => 'active',
    ]);
    ProductInventory::create([
        'product_id' => $uncoveredProduct->id,
        'variant_id' => $uncoveredVariant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
        'reorder_point' => 3,
        'reorder_quantity' => 6,
    ]);

    $response = $this->actingAs($buyer)->getJson('/api/v1/suppliers/commercial-dashboard?only_low_stock=true');

    $response->assertOk()
        ->assertJsonPath('data.summary.suppliers_count', 1)
        ->assertJsonPath('data.summary.supplied_variants_count', 1)
        ->assertJsonPath('data.summary.products_without_supplier_count', 1)
        ->assertJsonPath('data.products_without_supplier.0.product_name', 'Articulo sin proveedor')
        ->assertJsonPath('data.products_without_supplier.0.variant_sku', 'SIN-PROV-VAR')
        ->assertJsonPath('data.products_without_supplier.0.needs_reorder', true);
});

it('includes primary and additional product categories in supplier relationships', function () {
    ['buyer' => $buyer, 'supplier' => $supplier, 'product' => $product, 'variant' => $variant] = supplierProfileContext();
    $primary = Category::create(['name' => 'Categoría principal proveedor']);
    $additional = Category::create(['name' => 'Categoría adicional proveedor']);
    $product->update(['category_id' => $primary->id]);
    $product->extraCategories()->attach($additional->id);
    ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'supplier_id' => $supplier->id,
    ]);

    $response = $this->actingAs($buyer)->getJson("/api/v1/suppliers/{$supplier->id}");

    $response->assertOk();
    expect(collect($response->json('relationships.categories'))->pluck('name')->all())
        ->toContain('Categoría principal proveedor', 'Categoría adicional proveedor');
    expect(collect($response->json('relationships.categories'))->pluck('source')->unique()->all())
        ->toBe(['configured']);
});

it('enforces one product-level supplier link when no variant is selected', function () {
    ['supplier' => $supplier, 'product' => $product] = supplierProfileContext();
    ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'supplier_id' => $supplier->id,
    ]);

    expect(fn () => ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => null,
        'supplier_id' => $supplier->id,
    ]))->toThrow(QueryException::class);
});
