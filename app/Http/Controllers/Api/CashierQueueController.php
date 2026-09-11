<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelCashierQueueEntryRequest;
use App\Http\Requests\CompleteCashierQueueEntryRequest;
use App\Models\CashierQueueEntry;
use App\Models\CashOperationRequest;
use App\Services\CashierQueueService;
use App\Services\CashOperationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashierQueueController extends Controller
{
    public function __construct(private readonly CashierQueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'entry_type' => ['nullable', Rule::in([
                CashierQueueEntry::TYPE_SALE,
                CashierQueueEntry::TYPE_EXPENSE,
                CashierQueueEntry::TYPE_RETURN,
                CashierQueueEntry::TYPE_CASH_IN,
                CashierQueueEntry::TYPE_CASH_OUT,
                CashierQueueEntry::TYPE_FUNDING,
            ])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $entries = $this->queue->paginate($filters);

        return response()->json([
            'data' => collect($entries->items())->map(fn (CashierQueueEntry $entry): array => $this->serialize($entry))->values(),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function open(CashierQueueEntry $cashierQueueEntry, Request $request): JsonResponse
    {
        try {
            $entry = $this->queue->open($cashierQueueEntry, $request->user(), $request->integer('warehouse_id'));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($entry)]);
    }

    public function complete(CashierQueueEntry $cashierQueueEntry, CompleteCashierQueueEntryRequest $request): JsonResponse
    {
        try {
            $entry = $this->queue->complete(
                $cashierQueueEntry,
                $request->validated(),
                $request->user(),
                $request->integer('warehouse_id'),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Movimiento procesado correctamente.', 'data' => $this->serialize($entry)]);
    }

    public function cancel(
        CashierQueueEntry $cashierQueueEntry,
        CancelCashierQueueEntryRequest $request,
        CashOperationRequestService $service,
    ): JsonResponse {
        try {
            $entry = $service->cancel(
                $cashierQueueEntry,
                $request->user(),
                $request->validated('reason'),
                $request->integer('warehouse_id'),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Solicitud cancelada.', 'data' => $this->serialize($entry)]);
    }

    private function serialize(CashierQueueEntry $entry): array
    {
        $source = $entry->source;
        $sourceData = match (true) {
            $source instanceof \App\Models\SalesQuote => [
                'id' => $source->id,
                'document_number' => $source->quote_number,
                'description' => 'Cotizacion por atender',
                'party' => $source->customer?->name ?? 'Publico general',
                'amount' => (float) $source->total,
                'seller_name' => $source->user?->name,
                'notes' => $source->notes,
                'created_at' => $source->created_at?->toISOString(),
                'order_number' => $source->convertedOrder?->order_number,
                'prepared_at' => $source->convertedOrder?->prepared_at?->toISOString(),
                'preparation_status' => $source->convertedOrder?->prepared_at ? 'prepared' : 'pending',
                'items' => $source->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->variant?->sku ?? $item->product_sku,
                    'quantity' => (int) $item->quantity,
                ])->values(),
            ],
            $source instanceof \App\Models\OperationalExpense => [
                'id' => $source->id,
                'document_number' => 'G-'.$source->id,
                'description' => $source->description ?: $source->category_label,
                'party' => data_get($source->party, 'name', 'Sin beneficiario'),
                'amount' => (float) ($source->estimated_amount ?? $source->amount),
                'planned_payment_method' => $source->planned_payment_method,
                'reference_number' => $source->reference_number,
                'created_at' => $source->created_at?->toISOString(),
                'notes' => $source->notes,
                'requested_by' => data_get($entry->metadata, 'requested_by'),
                'category' => $source->category_label,
            ],
            $source instanceof CashOperationRequest => [
                'id' => $source->id,
                'document_number' => 'CJ-'.$source->id,
                'description' => match ($source->operation_type) {
                    CashOperationRequest::TYPE_CASH_IN => 'Agregar efectivo',
                    CashOperationRequest::TYPE_CASH_OUT => 'Retirar efectivo',
                    CashOperationRequest::TYPE_FUNDING => 'Aportación de fondos',
                    default => 'Operación de caja',
                },
                'party' => $source->requester?->name ?? 'Usuario',
                'amount' => (float) $source->amount,
                'created_at' => $source->requested_at?->toISOString(),
                'notes' => $source->notes,
                'requested_by' => $source->requester?->only(['id', 'name']),
                'category' => $source->fundingCategory?->name,
                'operation_type' => $source->operation_type,
            ],
            $source instanceof \App\Models\SalesReturn => [
                'id' => $source->id,
                'document_number' => $source->return_number,
                'description' => 'Reembolso de devolucion',
                'party' => $source->customer?->name ?? 'Cliente general',
                'amount' => (float) $source->money_refund_amount,
                'refund_method' => $source->refund_method,
                'financial_account_id' => $source->financial_account_id,
                'financial_account' => $source->financialAccount?->only(['id', 'name', 'masked_number']),
                'money_refund_amount' => (float) $source->money_refund_amount,
                'receivable_applied_amount' => (float) $source->receivable_applied_amount,
                'store_credit_amount' => (float) $source->store_credit_amount,
            ],
            default => null,
        };

        return [
            'id' => $entry->id,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'warehouse_id' => $entry->warehouse_id,
            'warehouse' => $entry->warehouse,
            'estimated_amount' => (float) $entry->estimated_amount,
            'final_amount' => $entry->final_amount !== null ? (float) $entry->final_amount : null,
            'scheduled_for' => $entry->scheduled_for?->toISOString(),
            'opened_by' => $entry->opened_by,
            'opened_at' => $entry->opened_at?->toISOString(),
            'fixed_cash_amount' => (bool) data_get($entry->metadata, 'fixed_cash_amount', false),
            'can_cancel' => $this->canCancel($entry, request()->user()),
            'source' => $sourceData,
        ];
    }

    private function canCancel(CashierQueueEntry $entry, ?\App\Models\User $user): bool
    {
        if (! $user || ! in_array($entry->status, [CashierQueueEntry::STATUS_PENDING, CashierQueueEntry::STATUS_OPENED], true)) {
            return false;
        }
        if (! (bool) data_get($entry->metadata, 'fixed_cash_amount', false)) {
            return false;
        }

        $requestedBy = (int) data_get($entry->metadata, 'requested_by.id', 0);

        return $user->hasRole(['admin', 'manager'])
            || ($entry->status === CashierQueueEntry::STATUS_PENDING && $requestedBy === $user->id)
            || ($entry->status === CashierQueueEntry::STATUS_OPENED && $entry->opened_by === $user->id);
    }
}
