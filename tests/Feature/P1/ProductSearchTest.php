<?php

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create([
        'code' => 'W-PROD',
        'name' => 'Warehouse Products',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $this->actingAs($user);
});

it('returns products list', function () {
    $this->getJson('/api/v1/products')->assertStatus(200);
});

it('accepts search by name', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Search uses ILIKE and is not supported by sqlite in test environment.');
    }

    $this->getJson('/api/v1/products?search=test')->assertStatus(200);
});
