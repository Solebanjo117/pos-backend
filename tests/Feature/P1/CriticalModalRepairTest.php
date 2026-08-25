<?php

use App\Models\CapitalMovement;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function criticalRepairUser(string $role, ?Warehouse $warehouse = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'current_warehouse_id' => $warehouse?->id,
        'is_active' => true,
        'can_manage_financial_adjustments' => $role === 'admin',
    ]);

    if ($warehouse) {
        $user->warehouses()->attach($warehouse->id);
    }

    return $user;
}

it('prevents opening a second cash session for the same user and warehouse', function (): void {
    $warehouse = Warehouse::query()->create(['code' => 'W-CONCURRENCY', 'name' => 'Caja concurrencia']);
    $cashier = criticalRepairUser('cashier', $warehouse);

    $payload = ['warehouse_id' => $warehouse->id, 'opening_amount' => 100];

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/open', $payload)->assertCreated();
    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/open', $payload)
        ->assertConflict()
        ->assertJsonPath('message', 'Ya existe una sesion abierta para este usuario en esta sucursal.');

    expect(PosSession::query()->where('user_id', $cashier->id)->where('status', 'open')->count())->toBe(1);
});

it('serializes cash movements against the requested warehouse session', function (): void {
    $firstWarehouse = Warehouse::query()->create(['code' => 'W-CASH-A', 'name' => 'Caja A']);
    $secondWarehouse = Warehouse::query()->create(['code' => 'W-CASH-B', 'name' => 'Caja B']);
    $admin = criticalRepairUser('admin', $firstWarehouse);
    $admin->warehouses()->attach($secondWarehouse->id);

    $firstSession = PosSession::query()->create([
        'user_id' => $admin->id,
        'warehouse_id' => $firstWarehouse->id,
        'opened_at' => now(),
        'opening_amount' => 10,
        'status' => 'open',
    ]);
    $secondSession = PosSession::query()->create([
        'user_id' => $admin->id,
        'warehouse_id' => $secondWarehouse->id,
        'opened_at' => now(),
        'opening_amount' => 50,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/pos-sessions/current/movements', [
        'warehouse_id' => $secondWarehouse->id,
        'type' => 'in',
        'subtype' => 'withdrawal',
        'amount' => 25,
        'reason' => 'Cambio para caja B',
    ])->assertCreated()
        ->assertJsonPath('data.pos_session_id', $secondSession->id)
        ->assertJsonPath('data.balance_before', '50.00')
        ->assertJsonPath('data.balance_after', '75.00');

    expect(CashMovement::query()->where('pos_session_id', $firstSession->id)->count())->toBe(0);
});

it('retires direct customer balance writes without changing the balance', function (): void {
    $admin = criticalRepairUser('admin');
    $customer = Customer::query()->create([
        'code' => 'CLI-NO-DIRECT',
        'name' => 'Cliente protegido',
        'current_balance' => 45,
    ]);

    $this->actingAs($admin)->postJson("/api/v1/customers/{$customer->id}/adjust-balance", [
        'amount' => 20,
        'reason' => 'No debe aplicarse directamente',
    ])->assertStatus(410)
        ->assertJsonPath('message', 'Los ajustes directos de saldo fueron retirados. Use un ajuste financiero de Cuentas por cobrar con documento y contrapartida.')
        ->assertJsonPath('redirect', "/finance/accounting/adjustments?create=1&type=accounts_receivable&customer_id={$customer->id}");

    expect((float) $customer->fresh()->current_balance)->toBe(45.0);
});

it('loads financial adjustment options from the real product variant schema', function (): void {
    $admin = criticalRepairUser('admin');
    $product = Product::factory()->create(['name' => 'Producto ajustable', 'status' => 'active']);
    $variant = ProductVariant::factory()->for($product)->create(['sku' => 'AJ-OPT-001']);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/adjustments/options')
        ->assertOk()
        ->assertJsonFragment([
            'value' => $variant->id,
            'label' => 'AJ-OPT-001',
        ]);
});

it('creates a capital movement once for identical idempotent requests', function (): void {
    $warehouse = Warehouse::query()->create(['code' => 'W-CAPITAL', 'name' => 'Capital']);
    $admin = criticalRepairUser('admin', $warehouse);
    $payload = [
        'warehouse_id' => $warehouse->id,
        'movement_type' => 'in',
        'amount' => 125.50,
        'reason' => 'Aporte inicial',
        'movement_date' => now()->toDateString(),
        'notes' => 'Prueba idempotente',
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
    ];

    $this->actingAs($admin)->postJson('/api/v1/reports/capital-movements', $payload)
        ->assertCreated()->assertJsonPath('replayed', false);
    $this->actingAs($admin)->postJson('/api/v1/reports/capital-movements', $payload)
        ->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($admin)->postJson('/api/v1/reports/capital-movements', [
        ...$payload,
        'amount' => 126,
    ])->assertConflict()
        ->assertJsonPath('message', 'La clave de idempotencia ya fue utilizada con datos diferentes.');

    expect(CapitalMovement::query()->count())->toBe(1);
});

it('rolls back capital creation when snapshot rebuilding fails', function (): void {
    $warehouse = Warehouse::query()->create(['code' => 'W-CAP-ROLLBACK', 'name' => 'Capital rollback']);
    $admin = criticalRepairUser('admin', $warehouse);
    $this->partialMock(FinancialSnapshotService::class, function ($mock): void {
        $mock->shouldReceive('computeDay')->andThrow(new RuntimeException('Fallo controlado'));
    });

    $this->actingAs($admin)->postJson('/api/v1/reports/capital-movements', [
        'warehouse_id' => $warehouse->id,
        'movement_type' => 'out',
        'amount' => 10,
        'reason' => 'Rollback',
        'movement_date' => now()->toDateString(),
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
    ])->assertStatus(500);

    expect(CapitalMovement::query()->count())->toBe(0);
});

it('aligns product customer and supplier mutations with effective roles', function (): void {
    $warehouse = Warehouse::query()->create(['code' => 'W-PERMISSIONS', 'name' => 'Permisos']);
    $seller = criticalRepairUser('seller', $warehouse);
    $buyer = criticalRepairUser('buyer', $warehouse);
    $customer = Customer::query()->create(['code' => 'CLI-PERM', 'name' => 'Cliente permisos']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor permisos', 'status' => 'active']);

    $this->actingAs($seller)->postJson('/api/v1/products', ['name' => 'No autorizado'])
        ->assertForbidden();
    $this->actingAs($seller)->putJson("/api/v1/customers/{$customer->id}", ['name' => 'No autorizado'])
        ->assertForbidden();
    $customerType = CustomerType::query()->where('is_active', true)->firstOrFail();
    $this->actingAs($seller)->postJson('/api/v1/customers', [
        'customer_type_id' => $customerType->id,
        'name' => 'Cliente rápido',
    ])
        ->assertCreated();
    $this->actingAs($buyer)->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Proveedor actualizado'])
        ->assertOk();
    $this->actingAs($buyer)->deleteJson("/api/v1/suppliers/{$supplier->id}")
        ->assertForbidden();
});
