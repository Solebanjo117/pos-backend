<?php

use App\Models\CashMovement;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records deposits and withdrawals with running cash balances', function (): void {
    $warehouse = Warehouse::create(['code' => 'W-CASH-TRACE', 'name' => 'Caja trazable']);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);
    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/current/movements', [
        'type' => 'out',
        'subtype' => 'deposit',
        'amount' => 20,
        'reason' => 'Deposito de seguridad',
        'reference_number' => 'DEP-001',
    ])->assertCreated()
        ->assertJsonPath('data.balance_before', '100.00')
        ->assertJsonPath('data.balance_after', '80.00');

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/current/movements', [
        'type' => 'in',
        'subtype' => 'withdrawal',
        'amount' => 30,
        'reason' => 'Retiro del banco para cambio',
    ])->assertCreated()
        ->assertJsonPath('data.balance_before', '80.00')
        ->assertJsonPath('data.balance_after', '110.00');

    expect(CashMovement::query()->where('subtype', 'deposit')->count())->toBe(1)
        ->and(CashMovement::query()->where('subtype', 'withdrawal')->count())->toBe(1);
});

it('restricts cash adjustments to administrators and validates their direction', function (): void {
    $warehouse = Warehouse::create(['code' => 'W-CASH-ADMIN', 'name' => 'Caja admin']);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);
    foreach ([$cashier, $admin] as $user) {
        PosSession::create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'opened_at' => now(),
            'opening_amount' => 100,
            'status' => 'open',
        ]);
    }

    $payload = [
        'type' => 'in',
        'subtype' => 'adjustment_positive',
        'amount' => 15,
        'reason' => 'Ajuste de cuadre autorizado',
    ];
    $this->actingAs($cashier)
        ->postJson('/api/v1/pos-sessions/current/movements', $payload)
        ->assertForbidden();
    $this->actingAs($admin)
        ->postJson('/api/v1/pos-sessions/current/movements', $payload)
        ->assertCreated()
        ->assertJsonPath('data.balance_after', '115.00');
    $this->actingAs($admin)
        ->postJson('/api/v1/pos-sessions/current/movements', [
            ...$payload,
            'type' => 'out',
        ])
        ->assertUnprocessable();
});
