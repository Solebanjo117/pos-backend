<?php

namespace App\Services;

use App\Models\CashierQueueEntry;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseEvent;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CashierQueueService
{
    public function __construct(private readonly OperationalExpenseService $expenseService)
    {
    }

    public function syncPendingSources(): void
    {
        SalesQuote::query()
            ->where('status', 'sent_to_cashier')
            ->whereNull('converted_order_id')
            ->each(fn (SalesQuote $quote) => $this->enqueueSale($quote));

        OperationalExpense::query()
            ->whereIn('status', ['pending', 'scheduled'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->each(fn (OperationalExpense $expense) => $this->enqueueExpense($expense));

        SalesReturn::query()
            ->where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->each(fn (SalesReturn $salesReturn) => $this->enqueueReturn($salesReturn));
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $this->syncPendingSources();

        return CashierQueueEntry::query()
            ->with([
                'source' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        SalesQuote::class => ['customer'],
                        OperationalExpense::class => ['supplier', 'customer', 'beneficiary', 'categoryRecord'],
                        SalesReturn::class => ['customer', 'creditNote'],
                    ]);
                },
                'warehouse',
                'opener',
            ])
            ->whereIn('status', [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED])
            ->when($filters['warehouse_id'] ?? null, fn (Builder $query, int $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['entry_type'] ?? null, fn (Builder $query, string $type) => $query->where('entry_type', $type))
            ->orderByRaw("CASE WHEN status = 'opened' THEN 0 ELSE 1 END")
            ->orderBy('scheduled_for')
            ->orderBy('created_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function enqueueSale(SalesQuote $quote): CashierQueueEntry
    {
        return $this->firstOrCreateEntry(
            [
                'source_type' => SalesQuote::class,
                'source_id' => $quote->id,
                'entry_type' => CashierQueueEntry::TYPE_SALE,
            ],
            [
                'warehouse_id' => $quote->warehouse_id,
                'status' => CashierQueueEntry::STATUS_PENDING,
                'estimated_amount' => $quote->total,
                'scheduled_for' => $quote->sent_to_cashier_at ?? now(),
                'metadata' => ['document_number' => $quote->quote_number],
            ],
        );
    }

    public function enqueueExpense(OperationalExpense $expense): CashierQueueEntry
    {
        return DB::transaction(function () use ($expense): CashierQueueEntry {
            $entry = $this->firstOrCreateEntry(
                [
                    'source_type' => OperationalExpense::class,
                    'source_id' => $expense->id,
                    'entry_type' => CashierQueueEntry::TYPE_EXPENSE,
                ],
                [
                    'warehouse_id' => $expense->warehouse_id,
                    'status' => CashierQueueEntry::STATUS_PENDING,
                    'estimated_amount' => $expense->estimated_amount ?? $expense->amount,
                    'scheduled_for' => $expense->due_at ?? now(),
                    'metadata' => ['category' => $expense->category, 'party' => $expense->party],
                ],
            );

            if ($entry->status !== CashierQueueEntry::STATUS_COMPLETED && $expense->status !== 'queued') {
                $before = $expense->only(['status', 'queued_at', 'due_at', 'estimated_amount']);
                $expense->update(['status' => 'queued', 'queued_at' => now()]);
                OperationalExpenseEvent::create([
                    'operational_expense_id' => $expense->id,
                    'user_id' => auth()->id(),
                    'event_type' => 'queued',
                    'from_status' => $before['status'] ?? null,
                    'to_status' => 'queued',
                    'reason' => 'Enviado a la cola de Caja.',
                    'before' => $before,
                    'after' => $expense->only(['status', 'queued_at', 'due_at', 'estimated_amount']),
                ]);
            }

            return $entry;
        });
    }

    public function enqueueReturn(SalesReturn $salesReturn): CashierQueueEntry
    {
        return $this->firstOrCreateEntry(
            [
                'source_type' => SalesReturn::class,
                'source_id' => $salesReturn->id,
                'entry_type' => CashierQueueEntry::TYPE_RETURN,
            ],
            [
                'warehouse_id' => $salesReturn->warehouse_id,
                'status' => CashierQueueEntry::STATUS_PENDING,
                'estimated_amount' => $salesReturn->total_amount,
                'scheduled_for' => $salesReturn->approved_at ?? now(),
                'metadata' => [
                    'document_number' => $salesReturn->return_number,
                    'refund_method' => $salesReturn->refund_method,
                    'money_refund_amount' => (float) $salesReturn->money_refund_amount,
                    'receivable_applied_amount' => (float) $salesReturn->receivable_applied_amount,
                    'store_credit_amount' => (float) $salesReturn->store_credit_amount,
                ],
            ],
        );
    }

    public function open(CashierQueueEntry $entry, User $user): CashierQueueEntry
    {
        return DB::transaction(function () use ($entry, $user): CashierQueueEntry {
            $entry = CashierQueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status === CashierQueueEntry::STATUS_COMPLETED) {
                return $entry->load(['source', 'warehouse', 'opener']);
            }
            if ($entry->status === CashierQueueEntry::STATUS_OPENED && $entry->opened_by !== $user->id) {
                throw new \InvalidArgumentException('Este registro esta abierto por otro usuario de Caja.');
            }
            if ($entry->status !== CashierQueueEntry::STATUS_PENDING && $entry->status !== CashierQueueEntry::STATUS_OPENED) {
                throw new \InvalidArgumentException('Este registro ya no esta disponible en Caja.');
            }

            $entry->update([
                'status' => CashierQueueEntry::STATUS_OPENED,
                'opened_by' => $user->id,
                'opened_at' => $entry->opened_at ?? now(),
            ]);

            return $entry->fresh(['source', 'warehouse', 'opener']);
        });
    }

    /** @param array<string, mixed> $data */
    public function complete(CashierQueueEntry $entry, array $data, User $user): CashierQueueEntry
    {
        return DB::transaction(function () use ($entry, $data, $user): CashierQueueEntry {
            $entry = CashierQueueEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status === CashierQueueEntry::STATUS_COMPLETED) {
                return $entry->load(['source', 'warehouse', 'completer']);
            }
            if ($entry->status === CashierQueueEntry::STATUS_OPENED && $entry->opened_by !== $user->id) {
                throw new \InvalidArgumentException('Este registro esta abierto por otro usuario de Caja.');
            }
            if (! in_array($entry->status, [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED], true)) {
                throw new \InvalidArgumentException('Este registro ya no se puede completar.');
            }

            if ($entry->status === CashierQueueEntry::STATUS_PENDING) {
                $entry->update([
                    'status' => CashierQueueEntry::STATUS_OPENED,
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                ]);
            }

            if ($entry->entry_type === CashierQueueEntry::TYPE_EXPENSE && $entry->source instanceof OperationalExpense) {
                $expense = $this->expenseService->settleFromQueue($entry->source, $entry, $data, $user);
                $entry->update([
                    'status' => CashierQueueEntry::STATUS_COMPLETED,
                    'final_amount' => $expense->final_amount ?? $expense->amount,
                    'completed_by' => $user->id,
                    'completed_at' => now(),
                    'metadata' => array_merge($entry->metadata ?? [], [
                        'payment_method' => $expense->payment_method,
                        'reference_number' => $expense->reference_number,
                    ]),
                ]);

                return $entry->fresh(['source', 'warehouse', 'completer']);
            }

            if ($entry->entry_type === CashierQueueEntry::TYPE_RETURN && $entry->source instanceof SalesReturn) {
                $salesReturn = app(SalesReturnService::class)->settleFromQueue($entry->source, $entry, $data, $user);
                $entry->update([
                    'status' => CashierQueueEntry::STATUS_COMPLETED,
                    'final_amount' => $salesReturn->total_amount,
                    'completed_by' => $user->id,
                    'completed_at' => now(),
                    'metadata' => array_merge($entry->metadata ?? [], [
                        'settlement_method' => $salesReturn->settlement_method,
                        'reference_number' => $salesReturn->settlement_reference,
                    ]),
                ]);

                return $entry->fresh(['source', 'warehouse', 'completer']);
            }

            throw new \InvalidArgumentException('Este tipo de registro se completa desde su flujo operativo.');
        });
    }

    public function completeForSource(string $sourceType, int $sourceId, User $user, ?float $finalAmount = null): void
    {
        CashierQueueEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED])
            ->update([
                'status' => CashierQueueEntry::STATUS_COMPLETED,
                'final_amount' => $finalAmount,
                'completed_by' => $user->id,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function cancelForSource(string $sourceType, int $sourceId, User $user): void
    {
        CashierQueueEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED])
            ->update([
                'status' => CashierQueueEntry::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $attributes
     */
    private function firstOrCreateEntry(array $identity, array $attributes): CashierQueueEntry
    {
        try {
            return CashierQueueEntry::query()->firstOrCreate($identity, $attributes);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            return CashierQueueEntry::query()->where($identity)->firstOrFail();
        }
    }
}
