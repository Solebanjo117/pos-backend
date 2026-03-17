<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns inventory movements listing', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/inventory-movements')
        ->assertStatus(200);
});
