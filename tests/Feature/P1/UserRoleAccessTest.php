<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cashier cannot create users endpoint', function () {
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->actingAs($cashier)
        ->postJson('/api/v1/users', [
            'name' => 'New User',
            'username' => 'new.user',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'cashier',
        ])
        ->assertStatus(403);
});

it('admin can access users endpoint', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/users')
        ->assertStatus(200);
});
