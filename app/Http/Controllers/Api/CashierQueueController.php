<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierQueueEntry;
use App\Models\PaymentMethod;
use App\Services\CashierQueueService;
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
            $entry = $this->queue->open($cashierQueueEntry, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($entry)]);
    }

    public function complete(CashierQueueEntry $cashierQueueEntry, Request $request): JsonResponse
    {
        $data = $request->validate([
            'final_amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'variance_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($cashierQueueEntry->entry_type === CashierQueueEntry::TYPE_EXPENSE) {
            $request->validate([
                'final_amount' => ['required', 'numeric', 'min:0.01'],
                'payment_method' => ['required', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS))],
            ]);
        }

        if (
            $cashierQueueEntry->entry_type === CashierQueueEntry::TYPE_RETURN
            && (float) data_get($cashierQueueEntry->metadata, 'money_refund_amount', 0) > 0
        ) {
            $request->validate([
                'payment_method' => ['required', 'string', Rule::in(PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS))],
            ]);
        }

        try {
            $entry = $this->queue->complete($cashierQueueEntry, $data, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Movimiento procesado correctamente.', 'data' => $this->serialize($entry)]);
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
            ],
            $source instanceof \App\Models\OperationalExpense => [
                'id' => $source->id,
                'document_number' => 'G-'.$source->id,
                'description' => $source->description ?: $source->category_label,
                'party' => data_get($source->party, 'name', 'Sin beneficiario'),
                'amount' => (float) ($source->estimated_amount ?? $source->amount),
                'planned_payment_method' => $source->planned_payment_method,
                'reference_number' => $source->reference_number,
            ],
            $source instanceof \App\Models\SalesReturn => [
                'id' => $source->id,
                'document_number' => $source->return_number,
                'description' => 'Reembolso de devolucion',
                'party' => $source->customer?->name ?? 'Cliente general',
                'amount' => (float) $source->money_refund_amount,
                'refund_method' => $source->refund_method,
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
            'source' => $sourceData,
        ];
    }
}
