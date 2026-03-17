<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin);
});

it('rejects user creation without email', function () {
    $this->postJson('/api/v1/users', [
        'name' => 'Test',
        'username' => 'test.user',
        'role' => 'cashier',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422);
});

it('rejects user creation with invalid role', function () {
    $this->postJson('/api/v1/users', [
        'name' => 'Test',
        'username' => 'test.user.2',
        'email' => 'test@test.com',
        'role' => 'superadmin',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422);
});
