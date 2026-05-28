<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productVariantProfileContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'WH-PROFILE-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Perfil Producto',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor Perfil',
        'email' => 'proveedor-perfil-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $customer = Customer::create([
        'code' => 'CL-PROFILE-'.fake()->unique()->randomNumber(4),
        'name' => 'Cliente Perfil',
        'email' => 'cliente-perfil-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $brand = Brand::create([
        'name' => 'Marca Perfil',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'PROFILE-'.fake()->unique()->randomNumber(4),
        'name' => 'Filtro Perfil 360',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'PROFILE-VAR-'.fake()->unique()->randomNumber(4),
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductPricing::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'base_price' => 100,
        'cost_base' => 75,
        'cost_miscellaneous' => 0,
        'cost_tax_rate' => 0,
        'sale_tax_rate' => 0,
    ]);

    $variant->suppliers()->attach($supplier->id, [
        'product_id' => $product->id,
        'supplier_cost' => 74,
        'lead_time_days' => 3,
        'minimum_order_quantity' => 1,
        'is_preferred' => true,
    ]);

    $make = VehicleMake::create(['name' => 'Toyota']);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => 'Corolla',
        'year_from' => 2000,
        'year_to' => 2008,
    ]);
    $engine = VehicleEngine::create([
        'model_id' => $model->id,
        'make_id' => $make->id,
        'engine_code' => '1ZZ',
        'displacement' => '1.8L',
        'fuel_type' => 'Gasolina',
    ]);

    ProductVehicleCompatibility::create([
        'product_id' => $product->id,
        'scope_type' => 'engine',
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_id' => $engine->id,
        'notes' => 'Aplicacion principal',
    ]);

    return compact('warehouse', 'user', 'supplier', 'customer', 'product', 'variant');
}

it('exposes a 360 product variant profile without mixing configured and current lot prices', function () {
    $context = productVariantProfileContext();
    $this->actingAs($context['user']);

    $purchaseOrder = app(PurchaseOrderService::class)->create([
        'supplier_id' => $context['supplier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'quantity_ordered' => 5,
            'unit_cost' => 75,
            'tax_amount' => 0,
            'misc_cost' => 0,
        ]],
    ]);

    app(PurchaseOrderService::class)->receiveItems($purchaseOrder->id, [[
        'item_id' => $purchaseOrder->items()->firstOrFail()->id,
        'quantity_received' => 5,
        'sale_price' => 140,
    ]]);

    ProductPricing::query()
        ->where('variant_id', $context['variant']->id)
        ->update(['base_price' => 100]);

    $quote = SalesQuote::create([
        'customer_id' => $context['customer']->id,
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'date' => now(),
        'status' => 'sent',
    ]);

    SalesQuoteItem::create([
        'sales_quote_id' => $quote->id,
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 1,
        'unit_price' => 140,
        'tax_percent' => 0,
    ]);

    app(SalesOrderService::class)->create([
        'customer_id' => $context['customer']->id,
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 2,
            'unit_price' => 140,
            'tax_percent' => 0,
        ]],
    ]);

    $activeLot = InventoryLot::query()
        ->where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->firstOrFail();

    $this->getJson("/api/v1/products/{$context['product']->id}/profile?warehouse_id={$context['warehouse']->id}&variant_id={$context['variant']->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $context['product']->id)
        ->assertJsonPath('data.variants.0.id', $context['variant']->id)
        ->assertJsonPath('data.variants.0.pricing.configuration.base_price', 100)
        ->assertJsonPath('data.variants.0.pricing.current.active_lot_id', $activeLot->id)
        ->assertJsonPath('data.variants.0.pricing.current.current_sale_price', 140)
        ->assertJsonPath('data.variants.0.pricing.current.fifo_sale_price_current', 140)
        ->assertJsonPath('data.variants.0.inventory.summary.stock_available', 3)
        ->assertJsonPath('data.variants.0.lots.0.sale_price', 140)
        ->assertJsonPath('data.variants.0.movements.summary.total_movements', 2)
        ->assertJsonPath('data.variants.0.sales.summary.orders_count', 1)
        ->assertJsonPath('data.variants.0.sales.summary.units_sold', 2)
        ->assertJsonPath('data.variants.0.sales.recent.0.customer.name', 'Cliente Perfil')
        ->assertJsonPath('data.variants.0.quotes.summary.quotes_count', 1)
        ->assertJsonPath('data.variants.0.quotes.recent.0.customer.name', 'Cliente Perfil')
        ->assertJsonPath('data.variants.0.purchases.summary.orders_count', 1)
        ->assertJsonPath('data.variants.0.purchases.recent.0.supplier.name', 'Proveedor Perfil')
        ->assertJsonPath('data.variants.0.suppliers.0.name', 'Proveedor Perfil')
        ->assertJsonPath('data.customers.0.name', 'Cliente Perfil')
        ->assertJsonPath('data.suppliers.0.name', 'Proveedor Perfil')
        ->assertJsonPath('data.compatibility.0.make', 'Toyota')
        ->assertJsonPath('data.compatibility.0.model', 'Corolla')
        ->assertJsonPath('data.compatibility.0.engine', '1.8L 1ZZ Gasolina');
});

it('uses the current user warehouse when product profile omits warehouse filter', function () {
    $context = productVariantProfileContext();

    $this->actingAs($context['user'])
        ->getJson("/api/v1/products/{$context['product']->id}/profile?variant_id={$context['variant']->id}")
        ->assertOk()
        ->assertJsonPath('data.warehouse_id', $context['warehouse']->id)
        ->assertJsonPath('data.variants.0.id', $context['variant']->id);
});

it('rejects product profile requests without an effective warehouse', function () {
    $context = productVariantProfileContext();
    $user = $context['user'];
    $user->forceFill(['current_warehouse_id' => null])->save();
    $user->warehouses()->detach();

    $this->actingAs($user)
        ->getJson("/api/v1/products/{$context['product']->id}/profile")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['warehouse_id']);
});
