<?php

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
