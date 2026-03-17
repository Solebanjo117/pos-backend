<?php

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 200 or 404 for current pos session and never 500', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-POS',
        'name' => 'Warehouse POS',
    ]);
    $user = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $response = $this->actingAs($user)->getJson('/api/v1/pos-sessions/current');

    expect(in_array($response->status(), [200, 404], true))->toBeTrue();
});
