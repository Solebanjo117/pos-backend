<?php

namespace Tests\Feature\P0;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_middleware_returns_unauthorized_when_guest(): void
    {
        $this->getJson('/api/v1/backups')->assertStatus(401);
    }

    public function test_role_middleware_returns_forbidden_when_role_missing(): void
    {
        $user = User::factory()->create(['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($user);

        $this->getJson('/api/v1/backups')->assertStatus(403);
    }
}
