<?php

use App\Models\CashierQueueEntry;
use App\Models\CashMovement;
use App\Models\JournalEntry;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseCategory;
use App\Models\OperationalExpenseEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function integratedQueueContext(): array
{
    \App\Models\PaymentMethod::create(['code' => 'cash', 'name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash', 'available_in_supplier_payments' => true]);
    \App\Models\PaymentMethod::create(['code' => 'transfer', 'name' => 'Transferencia', 'is_active' => true, 'liquidity_bucket' => 'bank', 'requires_financial_account' => false, 'available_in_supplier_payments' => true]);
    $warehouse = Warehouse::create([
        'code' => 'W-QUEUE-'.fake()->unique()->randomNumber(5),
        'name' => 'Caja unificada',
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $otherCashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);

    foreach ([$admin, $cashier, $otherCashier] as $user) {
        $user->warehouses()->attach($warehouse->id);
    }

    $category = OperationalExpenseCategory::create([
        'name' => 'Servicios digitales',
        'slug' => 'servicios-digitales',
        'expense_kind' => 'operational',
        'affects_cash_flow' => true,
        'affects_income_statement' => true,
        'is_active' => true,
    ]);

    return [$warehouse, $admin, $cashier, $otherCashier, $category];
}

it('queues a due expense, locks it to one cashier and settles it idempotently', function (): void {
    [$warehouse, $admin, $cashier, $otherCashier, $category] = integratedQueueContext();

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'warehouse_id' => $warehouse->id,
        'expense_date' => now()->toDateString(),
        'category_id' => $category->id,
        'description' => 'Hosting mensual',
        'amount' => 100,
        'estimated_amount' => 100,
        'status' => 'scheduled',
        'due_at' => now()->subMinute()->toISOString(),
        'planned_payment_method' => 'transfer',
        'affects_cash_flow' => true,
        'affects_income_statement' => true,
    ])->assertCreated();

    $expense = OperationalExpense::findOrFail($response->json('data.id'));

    $this->actingAs($cashier)
        ->getJson('/api/v1/cashier-queue?warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.entry_type', CashierQueueEntry::TYPE_EXPENSE);

    $entry = CashierQueueEntry::query()->where('source_id', $expense->id)->firstOrFail();
    $this->assertDatabaseHas('operational_expenses', ['id' => $expense->id, 'status' => 'queued']);

    $this->actingAs($cashier)
        ->getJson('/api/v1/cashier-queue?warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
    expect(CashierQueueEntry::query()->where('source_id', $expense->id)->count())->toBe(1);

    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier-queue/{$entry->id}/open")
        ->assertOk();

    $payload = [
        'final_amount' => 110,
        'payment_method' => 'transfer',
        'reference_number' => 'ACH-1001',
        'variance_reason' => 'Ajuste del proveedor',
    ];

    $this->actingAs($otherCashier)
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", $payload)
        ->assertUnprocessable();

    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", $payload)
        ->assertOk();

    $this->assertDatabaseHas('operational_expenses', [
        'id' => $expense->id,
        'status' => 'paid',
        'amount' => 110,
        'final_amount' => 110,
        'variance_amount' => 10,
        'payment_method' => 'transfer',
        'reference_number' => 'ACH-1001',
    ]);
    expect(CashMovement::count())->toBe(0)
        ->and(OperationalExpenseEvent::query()->where('operational_expense_id', $expense->id)->where('event_type', 'queued')->count())->toBe(1)
        ->and(OperationalExpenseEvent::query()->where('operational_expense_id', $expense->id)->where('event_type', 'paid')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'operational_expense')->where('source_id', $expense->id)->where('status', 'posted')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'operational_expense_payment')->where('source_id', $expense->id)->where('status', 'posted')->count())->toBe(1);

    $this->actingAs($cashier)
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", $payload)
        ->assertOk();

    expect(OperationalExpenseEvent::query()->where('operational_expense_id', $expense->id)->where('event_type', 'paid')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'operational_expense_payment')->where('source_id', $expense->id)->where('status', 'posted')->count())->toBe(1);
});

it('does not require or move cash when the expense cash-flow flag is disabled', function (): void {
    [$warehouse, $admin, , , $category] = integratedQueueContext();

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'warehouse_id' => $warehouse->id,
        'expense_date' => now()->toDateString(),
        'category_id' => $category->id,
        'description' => 'Ajuste contable sin flujo',
        'amount' => 25,
        'status' => 'paid',
        'payment_method' => 'cash',
        'affects_cash_flow' => false,
        'affects_income_statement' => true,
    ])->assertCreated();

    expect(CashMovement::count())->toBe(0)
        ->and($response->json('data.cash_movement_id'))->toBeNull()
        ->and(JournalEntry::query()->where('source_type', 'operational_expense_payment')->count())->toBe(0);
});

it('generates shared numeric commercial folios and prefixed purchase order folios', function (): void {
    $sequences = app(DocumentSequenceService::class);

    expect($sequences->nextCommercialFolio())->toBe('0000000001')
        ->and($sequences->nextCommercialFolio())->toBe('0000000002')
        ->and($sequences->nextPurchaseOrderFolio())->toBe('PO-0000000001')
        ->and($sequences->nextPurchaseOrderFolio())->toBe('PO-0000000002');
});

it('generates date scoped quote folios without consuming the commercial sequence', function (): void {
    $sequences = app(DocumentSequenceService::class);

    expect($sequences->nextQuoteFolio(CarbonImmutable::parse('2026-09-07 08:00:00', 'America/Panama')))
        ->toBe('202609070001')
        ->and($sequences->nextQuoteFolio(CarbonImmutable::parse('2026-09-07 18:00:00', 'America/Panama')))
        ->toBe('202609070002')
        ->and($sequences->nextQuoteFolio(CarbonImmutable::parse('2026-09-08 08:00:00', 'America/Panama')))
        ->toBe('202609080001')
        ->and($sequences->nextCommercialFolio())
        ->toBe('0000000001');
});
