<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a complete operational detail for a sales quote', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-QUOTE-DETAIL',
        'name' => 'Warehouse Quote Detail',
    ]);

    $seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'CUST-QUOTE-DETAIL',
        'name' => 'Cliente Cotizacion Detalle',
        'phone' => '555-1000',
        'email' => 'cliente@example.test',
    ]);

    $product = Product::create([
        'name' => 'Producto Cotizado',
        'type' => 'simple',
        'status' => 'active',
        'sku_base' => 'PROD-COT',
    ]);
    $brand = Brand::create([
        'name' => 'Marca Cotizada',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'VAR-COT-001',
        'name' => 'Variante Cotizada',
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
    ]);

    $order = SalesOrder::create([
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'date' => now()->toDateString(),
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => null,
        'sent_to_warehouse_at' => now()->subMinutes(30),
        'subtotal' => 0,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
    ]);

    $quote = SalesQuote::create([
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'date' => now()->subDay()->toDateString(),
        'valid_until' => now()->addDays(7)->toDateString(),
        'status' => 'sent_to_deposit',
        'converted_order_id' => $order->id,
        'notes' => 'Nota visible de cotizacion',
        'internal_notes' => 'Nota interna de cotizacion',
        'sent_to_cashier_at' => now()->subHour(),
    ]);

    $quote->items()->create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 5,
        'unit_price' => 20,
        'discount_percent' => 10,
        'tax_percent' => 7,
        'notes' => 'Traer de vitrina',
    ]);

    $quote->items()->create([
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'unit_price' => 15,
        'discount_percent' => 0,
        'tax_percent' => 0,
        'is_custom_line' => true,
        'custom_description' => 'Servicio de instalacion',
    ]);

    $response = $this->actingAs($seller)
        ->getJson("/api/v1/sales-quotes/{$quote->id}/operational-detail");

    $response->assertOk()
        ->assertJsonPath('data.quote.id', $quote->id)
        ->assertJsonPath('data.quote.quote_number', $quote->quote_number)
        ->assertJsonPath('data.quote.customer.name', 'Cliente Cotizacion Detalle')
        ->assertJsonPath('data.quote.warehouse.id', $warehouse->id)
        ->assertJsonPath('data.items.0.product.id', $product->id)
        ->assertJsonPath('data.items.0.variant.sku', 'VAR-COT-001')
        ->assertJsonPath('data.items.0.stock.available_quantity', 2)
        ->assertJsonPath('data.items.0.stock.missing_quantity', 3)
        ->assertJsonPath('data.items.1.is_custom_line', true)
        ->assertJsonPath('data.workflow.status', 'sent_to_deposit')
        ->assertJsonPath('data.workflow.actions.can_send_to_deposit', false)
        ->assertJsonPath('data.workflow.actions.can_send_to_cashier', true)
        ->assertJsonPath('data.stock_context.warnings.0.type', 'stock_shortage')
        ->assertJsonPath('data.payment_context.expected_total', 111.3)
        ->assertJsonPath('data.payment_context.is_collected', false)
        ->assertJsonPath('data.payment_context.converted_order_id', $order->id)
        ->assertJsonPath('data.payment_context.converted_order_payment_status', 'pending')
        ->assertJsonPath('data.converted_order_context.id', $order->id)
        ->assertJsonPath('data.documents.0.type', 'ticket')
        ->assertJsonPath('data.documents.0.mode', 'standard')
        ->assertJsonPath('data.documents.1.type', 'proforma')
        ->assertJsonPath('data.documents.1.mode', 'standard');

    expect(collect($response->json('data.timeline'))->pluck('type')->all())
        ->toContain('created', 'sent_to_deposit', 'sent_to_cashier');

    $timelineDates = collect($response->json('data.timeline'))
        ->pluck('occurred_at')
        ->filter()
        ->values()
        ->all();

    expect($timelineDates)->toBe(collect($timelineDates)->sort()->values()->all());
});
