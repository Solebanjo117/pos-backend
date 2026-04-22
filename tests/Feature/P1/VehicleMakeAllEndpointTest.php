<?php

use App\Models\User;
use App\Models\VehicleMake;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin);
});

it('returns all vehicle makes from the all endpoint', function () {
    VehicleMake::create(['name' => 'TOYOTA']);
    VehicleMake::create(['name' => 'HONDA']);

    $response = $this->getJson('/api/v1/vehicle-makes/all');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['id', 'name'],
            ],
        ])
        ->assertJsonCount(2, 'data');

    $names = collect($response->json('data'))->pluck('name')->values()->all();

    expect($names)->toBe(['HONDA', 'TOYOTA']);
});
