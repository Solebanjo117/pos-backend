<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function printableSalesOrder(): array
{
    $warehouse = Warehouse::create(['code' => 'W-PRINT', 'name' => 'Impresión POS']);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);
    $product = Product::factory()->create(['name' => 'Producto imprimible']);
    $variant = ProductVariant::factory()->for($product)->create([
        'sku' => 'PRINT-001',
        'is_default' => true,
    ]);
    $order = SalesOrder::create([
        'order_number' => 'SO-2026-0001',
        'user_id' => $admin->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 10,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 10,
        'payment_status' => 'paid',
        'payment_method' => 'cash',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 10,
        'unit_cost' => 4,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 10,
        'total' => 10,
    ]);

    return compact('admin', 'order');
}

it('streams the POS ticket and proforma as PDF', function (): void {
    $fixture = printableSalesOrder();

    foreach (['ticket?type=client', 'proforma?type=internal', 'note'] as $document) {
        $this->actingAs($fixture['admin'])
            ->get("/api/v1/sales-orders/{$fixture['order']->id}/{$document}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

it('rejects unsupported print formats before rendering a document', function (): void {
    $fixture = printableSalesOrder();

    $this->actingAs($fixture['admin'])
        ->getJson("/api/v1/sales-orders/{$fixture['order']->id}/ticket?type=unknown")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->actingAs($fixture['admin'])
        ->getJson('/api/v1/sales-quotes/999/ticket?mode=unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mode');
});

it('does not expose printable documents to guests', function (): void {
    $fixture = printableSalesOrder();

    $this->getJson("/api/v1/sales-orders/{$fixture['order']->id}/ticket")
        ->assertUnauthorized();
});
