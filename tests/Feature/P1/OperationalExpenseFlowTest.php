<?php

use App\Models\CashMovement;
use App\Models\OperationalExpense;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationalExpenseAdminContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-GO-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Gastos',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    return [$admin, $warehouse];
}

it('creates and filters operational expenses', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'rent',
        'description' => 'Alquiler del local',
        'amount' => 850.50,
        'payment_method' => 'transfer',
        'reference_number' => 'REC-001',
        'warehouse_id' => $warehouse->id,
        'notes' => 'Pago mensual',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.category', 'rent')
        ->assertJsonPath('data.category_label', 'Alquiler')
        ->assertJsonPath('data.warehouse.id', $warehouse->id);

    $this->assertDatabaseHas('operational_expenses', [
        'category' => 'rent',
        'reference_number' => 'REC-001',
        'warehouse_id' => $warehouse->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/operational-expenses?date_from=2026-05-01&date_to=2026-05-31&category=rent&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_amount', 850.50)
        ->assertJsonCount(1, 'data');
});

it('registers cash operational expenses as cash drawer withdrawals', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'electricity',
        'description' => 'Luz del local',
        'amount' => 45.25,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.cash_movement.pos_session_id', $session->id);

    $expense = OperationalExpense::firstOrFail();
    $movement = CashMovement::firstOrFail();

    expect($expense->cash_movement_id)->toBe($movement->id)
        ->and($movement->type)->toBe('out')
        ->and((float) $movement->amount)->toBe(45.25)
        ->and($movement->reason)->toBe('Gasto operativo: Luz del local');
});

it('syncs and deletes cash movements while the operational expense session is open', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'maintenance',
        'description' => 'Mantenimiento',
        'amount' => 20,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated();

    $expense = OperationalExpense::firstOrFail();

    $this->actingAs($admin)->putJson("/api/v1/operational-expenses/{$expense->id}", [
        'amount' => 25,
        'description' => 'Mantenimiento local',
    ])->assertOk();

    expect((float) CashMovement::firstOrFail()->amount)->toBe(25.0)
        ->and(CashMovement::firstOrFail()->reason)->toBe('Gasto operativo: Mantenimiento local');

    $this->actingAs($admin)->deleteJson("/api/v1/operational-expenses/{$expense->id}")
        ->assertOk();

    expect(OperationalExpense::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);
});

it('rejects cash operational expense changes after the linked cash session is closed', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'maintenance',
        'description' => 'Mantenimiento',
        'amount' => 20,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated();

    $expense = OperationalExpense::firstOrFail();
    $session->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $this->actingAs($admin)->putJson("/api/v1/operational-expenses/{$expense->id}", [
        'amount' => 30,
    ])->assertUnprocessable();

    $this->actingAs($admin)->deleteJson("/api/v1/operational-expenses/{$expense->id}")
        ->assertUnprocessable();

    expect(OperationalExpense::count())->toBe(1)
        ->and(CashMovement::count())->toBe(1);
});

it('validates operational expense payloads', function () {
    [$admin] = operationalExpenseAdminContext();

    $this->actingAs($admin)
        ->postJson('/api/v1/operational-expenses', [
            'expense_date' => 'not-a-date',
            'category' => 'invalid',
            'amount' => -1,
            'payment_method' => 'bitcoin',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expense_date', 'category', 'amount', 'payment_method']);
});

it('includes global and warehouse operational expenses in financial net profit', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();
    $otherWarehouse = Warehouse::create([
        'code' => 'W-OTHER',
        'name' => 'Otro Almacen',
    ]);

    $product = Product::create([
        'sku_base' => 'FIN-GO-1',
        'name' => 'Producto Reporte',
        'status' => 'active',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-GO-001',
        'user_id' => $admin->id,
        'date' => '2026-05-12',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 20,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-05-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'rent',
        'description' => 'Gasto global',
        'amount' => 10,
        'payment_method' => 'transfer',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'maintenance',
        'description' => 'Gasto de almacen',
        'amount' => 5,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'software',
        'description' => 'Otro almacen',
        'amount' => 99,
        'payment_method' => 'transfer',
        'warehouse_id' => $otherWarehouse->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_revenue', 100)
        ->assertJsonPath('summary.total_cogs', 40)
        ->assertJsonPath('summary.total_operational_expenses', 15)
        ->assertJsonPath('summary.gross_profit', 60)
        ->assertJsonPath('summary.net_profit', 45)
        ->assertJsonPath('operational_expenses.by_category.0.category', 'maintenance')
        ->assertJsonPath('operational_expenses.timeline.0.amount', 15);
});
