<?php

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-SALES',
        'name' => 'Warehouse Sales',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $this->warehouse = $warehouse;
    $this->actingAs($user);
});

it('rejects sales order with non-existent product', function () {
    $this->postJson('/api/v1/sales-orders', [
        'items' => [
            [
                'product_id' => 999999,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 1,
                'unit_price' => 100.00,
            ],
        ],
    ])->assertStatus(422);
});

it('rejects sales order with invalid payment method', function () {
    $this->postJson('/api/v1/sales-orders', [
        'payments' => [
            ['amount' => 10, 'method' => 'crypto'],
        ],
    ])->assertStatus(422);
});

it('creates a normal pos sale with an exact fixed line discount', function () {
    $product = Product::create([
        'name' => 'Producto con descuento fijo',
        'sku_base' => 'DESC-FIJO-1',
        'type' => 'simple',
        'status' => 'active',
    ]);

    $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
        'items' => [[
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'unit_price' => 19.99,
            'discount_percent' => 0,
            'discount_amount' => 7.37,
            'tax_percent' => 7,
        ]],
    ])->assertCreated();

    $order = SalesOrder::query()->latest('id')->firstOrFail();
    $item = $order->items()->firstOrFail();

    expect((float) $item->discount_amount)->toBe(7.37)
        ->and((float) $item->subtotal)->toBe(52.60)
        ->and((float) $item->tax_amount)->toBe(3.68)
        ->and((float) $item->total)->toBe(56.28)
        ->and((float) $order->fresh()->total)->toBe(56.28);
});

it('rejects a fixed line discount above the gross amount', function () {
    $product = Product::create([
        'name' => 'Producto descuento inválido',
        'sku_base' => 'DESC-INVALIDO-1',
        'type' => 'simple',
        'status' => 'active',
    ]);

    $this->postJson('/api/v1/sales-orders', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 10,
            'discount_amount' => 10.01,
            'tax_percent' => 7,
        ]],
    ])->assertJsonValidationErrors('items.0.discount_amount');
});
