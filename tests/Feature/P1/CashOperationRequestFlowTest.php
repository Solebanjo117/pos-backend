<?php

use App\Models\CapitalMovement;
use App\Models\CashierQueueEntry;
use App\Models\CashMovement;
use App\Models\CashOperationRequest;
use App\Models\FinancialAccountMovement;
use App\Models\FundingCategory;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseCategory;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function cashOperationContext(): array
{
    $warehouse = Warehouse::query()->create(['code' => 'W-CASH-REQUEST', 'name' => 'Caja solicitudes']);
    $admin = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $cashier = User::factory()->create(['role' => 'cashier', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $otherCashier = User::factory()->create(['role' => 'cashier', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    foreach ([$admin, $cashier, $otherCashier] as $user) {
        $user->warehouses()->attach($warehouse->id);
    }

    $expenseCategory = OperationalExpenseCategory::query()->create([
        'name' => 'Mensajería', 'slug' => 'mensajeria', 'expense_kind' => 'operational',
        'affects_cash_flow' => true, 'affects_income_statement' => true, 'is_active' => true,
    ]);
    $fundingCategory = FundingCategory::query()->create([
        'name' => 'Aporte de socio', 'slug' => 'aporte-de-socio', 'is_active' => true,
    ]);

    return compact('warehouse', 'admin', 'cashier', 'otherCashier', 'expenseCategory', 'fundingCategory');
}

it('queues cash operations without requiring a session or moving money', function (string $type): void {
    $context = cashOperationContext();
    $payload = [
        'operation_type' => $type,
        'amount' => 25.50,
        'notes' => 'Solicitud operativa',
        'idempotency_key' => (string) Str::uuid(),
    ];
    if ($type === CashOperationRequest::TYPE_FUNDING) {
        $payload['funding_category_id'] = $context['fundingCategory']->id;
    }

    $response = $this->actingAs($context['admin'])->postJson('/api/v1/cash-operation-requests', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', CashOperationRequest::STATUS_PENDING);

    expect(CashMovement::query()->count())->toBe(0)
        ->and(CapitalMovement::query()->count())->toBe(0)
        ->and(CashierQueueEntry::query()->where('id', $response->json('data.queue_entry_id'))->value('status'))->toBe('pending');
})->with([
    CashOperationRequest::TYPE_CASH_IN,
    CashOperationRequest::TYPE_CASH_OUT,
    CashOperationRequest::TYPE_FUNDING,
]);

it('replays the same request and rejects reuse with different data', function (): void {
    $context = cashOperationContext();
    $payload = ['operation_type' => 'cash_in', 'amount' => 15, 'idempotency_key' => (string) Str::uuid()];

    $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', $payload)->assertCreated();
    $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', $payload)
        ->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', [...$payload, 'amount' => 16])
        ->assertConflict();

    expect(CashOperationRequest::query()->count())->toBe(1)
        ->and(CashierQueueEntry::query()->count())->toBe(1);
});

it('creates and settles a fixed cash expense from the queue', function (): void {
    $context = cashOperationContext();
    $response = $this->actingAs($context['admin'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => 'expense', 'expense_category_id' => $context['expenseCategory']->id,
        'amount' => 30, 'notes' => 'Entrega local', 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));
    expect(OperationalExpense::query()->findOrFail($request->operational_expense_id)->status)->toBe('queued')
        ->and(CashMovement::query()->count())->toBe(0);

    PosSession::query()->create([
        'user_id' => $context['cashier']->id, 'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(), 'opening_amount' => 100, 'status' => 'open',
    ]);
    $this->actingAs($context['cashier'])->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/complete", [
        'final_amount' => 99, 'payment_method' => 'transfer',
    ])->assertOk();

    expect($request->fresh()->status)->toBe('completed')
        ->and($request->operationalExpense->fresh()->status)->toBe('paid')
        ->and((float) $request->operationalExpense->fresh()->amount)->toBe(30.0)
        ->and(CashMovement::query()->where('type', 'out')->where('amount', 30)->count())->toBe(1);
});

it('settles funding once without duplicating legacy liquidity', function (): void {
    $context = cashOperationContext();
    $response = $this->actingAs($context['admin'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => 'funding', 'funding_category_id' => $context['fundingCategory']->id,
        'amount' => 40, 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));
    PosSession::query()->create([
        'user_id' => $context['cashier']->id, 'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(), 'opening_amount' => 10, 'status' => 'open',
    ]);

    $url = "/api/v1/cashier-queue/{$request->queue_entry_id}/complete";
    $this->actingAs($context['cashier'])->postJson($url)->assertOk();
    $this->actingAs($context['cashier'])->postJson($url)->assertOk();

    expect(CashMovement::query()->where('source_id', $request->id)->count())->toBe(1)
        ->and(CapitalMovement::query()->where('source_id', $request->id)->count())->toBe(1)
        ->and(FinancialAccountMovement::query()->where('source_type', CapitalMovement::class)->count())->toBe(0)
        ->and($request->fresh()->capitalMovement?->cash_movement_id)->toBe($request->fresh()->cash_movement_id);
});

it('confirms ordinary cash requests without creating financial documents', function (string $operationType, string $movementType): void {
    $context = cashOperationContext();
    PosSession::query()->create([
        'user_id' => $context['cashier']->id, 'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(), 'opening_amount' => 100, 'status' => 'open',
    ]);
    $response = $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => $operationType, 'amount' => 25, 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/complete")
        ->assertOk();

    $completedRequest = $request->fresh();
    expect($completedRequest->status)->toBe('completed')
        ->and($completedRequest->cashMovement?->type)->toBe($movementType)
        ->and(OperationalExpense::query()->count())->toBe(0)
        ->and(CapitalMovement::query()->count())->toBe(0);
})->with([
    ['cash_in', 'in'],
    ['cash_out', 'out'],
]);

it('requires an open session and sufficient cash while keeping the request pending', function (): void {
    $context = cashOperationContext();
    $response = $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => 'cash_out', 'amount' => 20, 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));

    $this->actingAs($context['cashier'])->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/complete")->assertUnprocessable();
    PosSession::query()->create([
        'user_id' => $context['cashier']->id, 'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(), 'opening_amount' => 10, 'status' => 'open',
    ]);
    $this->actingAs($context['cashier'])->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/complete")->assertUnprocessable();

    expect($request->fresh()->status)->toBe('pending')
        ->and($request->queueEntry->fresh()->status)->toBe('pending')
        ->and(CashMovement::query()->count())->toBe(0);
});

it('cancels only new-form requests with a reason and proper ownership', function (): void {
    $context = cashOperationContext();
    $response = $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => 'cash_in', 'amount' => 12, 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));
    $url = "/api/v1/cashier-queue/{$request->queue_entry_id}/cancel";

    $this->actingAs($context['otherCashier'])->postJson($url, ['reason' => 'Sin autorización'])->assertUnprocessable();
    $this->actingAs($context['cashier'])->postJson($url, [])->assertUnprocessable();
    $this->actingAs($context['cashier'])->postJson($url, ['reason' => 'Monto incorrecto'])->assertOk();

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($request->queueEntry->fresh()->status)->toBe('cancelled')
        ->and(CashMovement::query()->count())->toBe(0);
});

it('keeps expense and funding requests restricted to managers and admins', function (string $type): void {
    $context = cashOperationContext();
    $payload = ['operation_type' => $type, 'amount' => 10, 'idempotency_key' => (string) Str::uuid()];
    $payload[$type === 'expense' ? 'expense_category_id' : 'funding_category_id'] =
        $type === 'expense' ? $context['expenseCategory']->id : $context['fundingCategory']->id;

    $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', $payload)->assertForbidden();
})->with(['expense', 'funding']);

it('blocks direct queue actions from another warehouse', function (): void {
    $context = cashOperationContext();
    $response = $this->actingAs($context['cashier'])->postJson('/api/v1/cash-operation-requests', [
        'operation_type' => 'cash_in', 'amount' => 12, 'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();
    $request = CashOperationRequest::query()->findOrFail($response->json('data.id'));

    $otherWarehouse = Warehouse::query()->create(['code' => 'W-OTHER', 'name' => 'Otra sucursal']);
    $otherCashier = User::factory()->create([
        'role' => 'cashier', 'current_warehouse_id' => $otherWarehouse->id, 'is_active' => true,
    ]);
    $otherCashier->warehouses()->attach($otherWarehouse->id);

    $this->actingAs($otherCashier)
        ->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/open")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La solicitud no pertenece a la sucursal activa.');
    $this->actingAs($otherCashier)
        ->postJson("/api/v1/cashier-queue/{$request->queue_entry_id}/cancel", ['reason' => 'Intento cruzado'])
        ->assertUnprocessable();

    expect($request->queueEntry->fresh()->status)->toBe('pending');
});

it('manages funding categories without exposing maintenance to cashiers', function (): void {
    $context = cashOperationContext();

    $response = $this->actingAs($context['admin'])->postJson('/api/v1/funding-categories', [
        'name' => 'Aporte extraordinario',
    ])->assertCreated();
    $categoryId = $response->json('data.id');

    $this->actingAs($context['admin'])
        ->patchJson("/api/v1/funding-categories/{$categoryId}", ['name' => 'Aporte especial', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Aporte especial')
        ->assertJsonPath('data.is_active', false);
    $this->actingAs($context['cashier'])->getJson('/api/v1/funding-categories?all=1')->assertForbidden();

    expect(FundingCategory::query()->findOrFail($categoryId)->is_active)->toBeFalse();
});
