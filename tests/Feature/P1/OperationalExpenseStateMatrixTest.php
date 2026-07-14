<?php

use App\Models\CashierQueueEntry;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-EXP-MATRIX', 'name' => 'Matriz gastos']);
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->admin->warehouses()->attach($this->warehouse->id);
    PaymentMethod::query()->updateOrCreate(['code' => 'transfer'], [
        'name' => 'Transferencia',
        'is_active' => true,
        'is_system' => true,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_BANK,
        'available_in_supplier_payments' => true,
        'sort_order' => 2,
    ]);
    $this->actingAs($this->admin);
});

function expenseMatrixPayload(int $warehouseId, string $status, float $amount = 50): array
{
    return [
        'warehouse_id' => $warehouseId,
        'expense_date' => '2026-07-13',
        'category' => 'software',
        'description' => 'ERP mensual',
        'amount' => $amount,
        'estimated_amount' => $amount,
        'status' => $status,
        'payment_method' => $status === 'paid' ? 'transfer' : null,
        'planned_payment_method' => 'transfer',
        'due_at' => '2026-07-13 10:00:00',
    ];
}

it('keeps draft pending and scheduled expenses editable', function (): void {
    foreach (['draft', 'pending', 'scheduled'] as $status) {
        $expense = $this->postJson('/api/v1/operational-expenses', expenseMatrixPayload($this->warehouse->id, $status))
            ->assertCreated()
            ->assertJsonPath('data.capabilities.can_edit', true)
            ->json('data');

        $this->putJson('/api/v1/operational-expenses/'.$expense['id'], [
            'amount' => 55,
            'description' => 'ERP mensual actualizado',
            'change_reason' => 'Correccion antes del pago',
        ])->assertOk()
            ->assertJsonPath('data.amount', '55.00');
    }
});

it('locks queued expenses and allows only cancellation from that state', function (): void {
    $expenseId = $this->postJson('/api/v1/operational-expenses', expenseMatrixPayload($this->warehouse->id, 'pending'))
        ->assertCreated()
        ->json('data.id');

    $this->postJson("/api/v1/operational-expenses/{$expenseId}/send-to-cashier")
        ->assertOk()
        ->assertJsonPath('data.entry_type', CashierQueueEntry::TYPE_EXPENSE)
        ->assertJsonPath('data.status', CashierQueueEntry::STATUS_PENDING);

    expect(OperationalExpense::findOrFail($expenseId)->status)->toBe('queued')
        ->and(OperationalExpense::findOrFail($expenseId)->capabilities['can_edit'])->toBeFalse()
        ->and(OperationalExpense::findOrFail($expenseId)->capabilities['can_cancel'])->toBeTrue();

    $this->putJson("/api/v1/operational-expenses/{$expenseId}", ['amount' => 60])
        ->assertUnprocessable();

    $entry = CashierQueueEntry::query()->where('source_id', $expenseId)->firstOrFail();
    $this->deleteJson("/api/v1/operational-expenses/{$expenseId}")
        ->assertOk();

    expect(OperationalExpense::findOrFail($expenseId)->status)->toBe('cancelled')
        ->and($entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_CANCELLED);
    $this->putJson("/api/v1/operational-expenses/{$expenseId}", ['amount' => 61])
        ->assertUnprocessable();
});

it('keeps paid expenses immutable and reverses them instead of deleting history', function (): void {
    $expenseId = $this->postJson('/api/v1/operational-expenses', expenseMatrixPayload($this->warehouse->id, 'paid'))
        ->assertCreated()
        ->assertJsonPath('data.capabilities.can_edit', false)
        ->assertJsonPath('data.capabilities.can_reverse', true)
        ->json('data.id');

    $this->putJson("/api/v1/operational-expenses/{$expenseId}", ['amount' => 60])
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/operational-expenses/{$expenseId}")
        ->assertOk();

    $expense = OperationalExpense::findOrFail($expenseId);
    expect($expense->status)->toBe('reversed')
        ->and($expense->reversed_at)->not->toBeNull()
        ->and($expense->events()->where('event_type', 'reversed')->exists())->toBeTrue();
    $this->putJson("/api/v1/operational-expenses/{$expenseId}", ['amount' => 70])
        ->assertUnprocessable();
});
