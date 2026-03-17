<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects supplier creation without name', function () {
    $buyer = User::factory()->create([
        'role' => 'buyer',
        'is_active' => true,
    ]);

    $this->actingAs($buyer)
        ->postJson('/api/v1/suppliers', [
            'email' => 'supplier@example.com',
        ])
        ->assertStatus(422);
});
