<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\CashierQueueEntry;
use App\Models\CashOperationRequest;
use App\Models\OperationalExpenseEvent;
use App\Models\User;
use App\Support\RegionalDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CashOperationRequestService
{
    public function __construct(
        private readonly OperationalExpenseService $expenseService,
        private readonly CashierQueueService $queueService,
        private readonly CashMovementService $cashMovementService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): array
    {
        $requestHash = $this->requestHash($data, $user);
        $existing = CashOperationRequest::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $this->idempotentResult($existing, $requestHash, true);
        }

        try {
            return DB::transaction(function () use ($data, $user, $requestHash): array {
                $request = CashOperationRequest::query()->create([
                    'warehouse_id' => $data['warehouse_id'],
                    'requested_by' => $user->id,
                    'operation_type' => $data['operation_type'],
                    'amount' => round((float) $data['amount'], 2),
                    'notes' => $data['notes'] ?? null,
                    'funding_category_id' => $data['funding_category_id'] ?? null,
                    'status' => CashOperationRequest::STATUS_PENDING,
                    'requested_at' => now(),
                    'idempotency_key' => $data['idempotency_key'],
                    'request_hash' => $requestHash,
                ]);

                if ($request->operation_type === CashOperationRequest::TYPE_EXPENSE) {
                    $expense = $this->expenseService->create([
                        'warehouse_id' => $request->warehouse_id,
                        'expense_date' => CarbonImmutable::now(RegionalDateRange::timezone())->toDateString(),
                        'business_datetime' => now(),
                        'category_id' => $data['expense_category_id'],
                        'description' => $request->notes,
                        'amount' => $request->amount,
                        'estimated_amount' => $request->amount,
                        'status' => 'pending',
                        'due_at' => now(),
                        'planned_payment_method' => 'cash',
                        'affects_cash_flow' => true,
                        'affects_income_statement' => true,
                    ]);
                    $entry = $this->queueService->enqueueExpense($expense);
                    $entry->update(['metadata' => array_merge($entry->metadata ?? [], [
                        'cash_operation_request_id' => $request->id,
                        'requested_by' => $user->only(['id', 'name']),
                        'fixed_cash_amount' => true,
                    ])]);
                    $request->update([
                        'operational_expense_id' => $expense->id,
                        'queue_entry_id' => $entry->id,
                    ]);
                } else {
                    $entry = $this->queueService->enqueueCashOperation($request);
                    $request->update(['queue_entry_id' => $entry->id]);
                }

                activity('cash_operations')
                    ->performedOn($request)
                    ->causedBy($user)
                    ->withProperties(['operation_type' => $request->operation_type, 'amount' => (float) $request->amount])
                    ->log('Solicitud enviada a Caja');

                return $this->idempotentResult($request->fresh(), $requestHash, false);
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            $existing = CashOperationRequest::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->idempotentResult($existing, $requestHash, true);
        }
    }

    public function complete(CashOperationRequest $request, CashierQueueEntry $entry, User $user): CashOperationRequest
    {
        return DB::transaction(function () use ($request, $user): CashOperationRequest {
            $request = CashOperationRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status === CashOperationRequest::STATUS_COMPLETED) {
                return $request;
            }
            if ($request->status !== CashOperationRequest::STATUS_PENDING) {
                throw new \InvalidArgumentException('Esta solicitud ya no se puede procesar.');
            }

            $isInbound = in_array($request->operation_type, [CashOperationRequest::TYPE_CASH_IN, CashOperationRequest::TYPE_FUNDING], true);
            $category = $request->fundingCategory?->name;
            $movement = $this->cashMovementService->record($user, $request->warehouse_id, [
                'type' => $isInbound ? 'in' : 'out',
                'subtype' => $request->operation_type,
                'amount' => $request->amount,
                'reason' => $request->operation_type === CashOperationRequest::TYPE_FUNDING
                    ? 'Aportación de fondos: '.$category
                    : ($isInbound ? 'Agregar efectivo' : 'Retirar efectivo'),
                'notes' => $request->notes,
                'business_datetime' => now(),
                'source_type' => 'cash_operation_request',
                'source_id' => $request->id,
                'idempotency_key' => $request->idempotency_key,
            ]);

            $capitalMovement = null;
            if ($request->operation_type === CashOperationRequest::TYPE_FUNDING) {
                $capitalMovement = CapitalMovement::query()->firstOrCreate(
                    ['idempotency_key' => $request->idempotency_key],
                    [
                        'warehouse_id' => $request->warehouse_id,
                        'user_id' => $user->id,
                        'movement_type' => CapitalMovement::TYPE_IN,
                        'amount' => $request->amount,
                        'movement_date' => CarbonImmutable::now(RegionalDateRange::timezone())->toDateString(),
                        'reason' => $category ?: 'Aportación de fondos',
                        'notes' => $request->notes,
                        'source_type' => 'cash_operation_request',
                        'source_id' => $request->id,
                        'cash_movement_id' => $movement->id,
                        'business_datetime' => now(),
                        'request_hash' => hash('sha256', 'cash-operation-request:'.$request->id),
                    ],
                );
            }

            $request->update([
                'status' => CashOperationRequest::STATUS_COMPLETED,
                'cash_movement_id' => $movement->id,
                'capital_movement_id' => $capitalMovement?->id,
                'processed_by' => $user->id,
                'processed_at' => now(),
            ]);

            activity('cash_operations')->performedOn($request)->causedBy($user)->log('Solicitud procesada en Caja');

            return $request->fresh(['requester', 'fundingCategory', 'cashMovement', 'capitalMovement']);
        });
    }

    public function markExpenseCompleted(CashierQueueEntry $entry, User $user): void
    {
        $request = CashOperationRequest::query()->where('queue_entry_id', $entry->id)->lockForUpdate()->first();
        if (! $request || $request->status === CashOperationRequest::STATUS_COMPLETED) {
            return;
        }

        $request->update([
            'status' => CashOperationRequest::STATUS_COMPLETED,
            'cash_movement_id' => $request->operationalExpense?->cash_movement_id,
            'processed_by' => $user->id,
            'processed_at' => now(),
        ]);
        activity('cash_operations')->performedOn($request)->causedBy($user)->log('Solicitud de gasto procesada en Caja');
    }

    public function cancel(CashierQueueEntry $entry, User $user, string $reason, int $warehouseId): CashierQueueEntry
    {
        return DB::transaction(function () use ($entry, $user, $reason, $warehouseId): CashierQueueEntry {
            $entry = CashierQueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->warehouse_id !== $warehouseId) {
                throw new \InvalidArgumentException('La solicitud no pertenece a la sucursal activa.');
            }
            $request = CashOperationRequest::query()->where('queue_entry_id', $entry->id)->lockForUpdate()->first();
            if (! $request) {
                throw new \InvalidArgumentException('Esta operación no pertenece a los formularios de Caja.');
            }
            if (! in_array($entry->status, [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED], true)) {
                throw new \InvalidArgumentException('Esta solicitud ya no se puede cancelar.');
            }

            $privileged = $user->hasRole(['admin', 'manager']);
            $allowed = $privileged
                || ($entry->status === CashierQueueEntry::STATUS_PENDING && $request->requested_by === $user->id)
                || ($entry->status === CashierQueueEntry::STATUS_OPENED && $entry->opened_by === $user->id);
            if (! $allowed) {
                throw new \InvalidArgumentException('No tiene permiso para cancelar esta solicitud.');
            }

            $entry->update([
                'status' => CashierQueueEntry::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'metadata' => array_merge($entry->metadata ?? [], ['cancellation_reason' => $reason]),
            ]);
            $request->update([
                'status' => CashOperationRequest::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if ($request->operationalExpense) {
                $expense = $request->operationalExpense;
                $before = $expense->only(['status', 'cancelled_at']);
                $expense->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                OperationalExpenseEvent::query()->create([
                    'operational_expense_id' => $expense->id,
                    'user_id' => $user->id,
                    'event_type' => 'cancelled',
                    'from_status' => $before['status'],
                    'to_status' => 'cancelled',
                    'reason' => $reason,
                    'before' => $before,
                    'after' => $expense->only(['status', 'cancelled_at']),
                ]);
            }

            activity('cash_operations')->performedOn($request)->causedBy($user)->withProperties(['reason' => $reason])->log('Solicitud cancelada en Caja');

            return $entry->fresh(['source', 'warehouse', 'opener']);
        });
    }

    /** @param array<string, mixed> $data */
    private function requestHash(array $data, User $user): string
    {
        return hash('sha256', json_encode([
            'requested_by' => $user->id,
            'warehouse_id' => (int) $data['warehouse_id'],
            'operation_type' => $data['operation_type'],
            'amount' => number_format(round((float) $data['amount'], 2), 2, '.', ''),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'expense_category_id' => $data['expense_category_id'] ?? null,
            'funding_category_id' => $data['funding_category_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function idempotentResult(CashOperationRequest $request, string $requestHash, bool $replayed): array
    {
        if (! hash_equals($request->request_hash, $requestHash)) {
            throw new \InvalidArgumentException('La clave de idempotencia ya fue utilizada con datos diferentes.');
        }

        return [
            'request' => $request->load(['warehouse', 'requester', 'fundingCategory', 'operationalExpense.categoryRecord', 'queueEntry']),
            'replayed' => $replayed,
        ];
    }
}
