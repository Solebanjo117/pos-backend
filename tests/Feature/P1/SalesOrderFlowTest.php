<?php

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
