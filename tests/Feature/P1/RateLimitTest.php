<?php

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throttles api after 120 requests per minute', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-RATE',
        'name' => 'Warehouse Rate',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $this->actingAs($user);

    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/products');
    }

    $this->getJson('/api/v1/products')->assertStatus(429);
});
