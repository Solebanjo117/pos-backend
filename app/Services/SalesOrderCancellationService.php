<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\ProductInventory;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderCancellationService
{
    public function __construct(private readonly SalesReturnService $returns)
    {
    }

    /** @param array{reason: string, refund_method?: string|null, notes?: string|null} $data */
    public function cancel(SalesOrder $salesOrder, array $data, User $user): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $data, $user): SalesOrder {
            $order = SalesOrder::query()
                ->with(['items', 'payments', 'returns.items'])
                ->lockForUpdate()
                ->findOrFail($salesOrder->id);

            if ($order->status === 'cancelled') {
                return $order;
            }

            if ($order->returns->contains('status', 'pending')) {
                throw ValidationException::withMessages([
                    'order' => 'Resuelva la devolucion pendiente antes de anular esta factura.',
                ]);
            }

            $reason = trim($data['reason']);
            $return = null;

            if (in_array($order->status, ['draft', 'pending'], true)) {
                $this->releaseReservations($order);
            } else {
                $items = $this->remainingItems($order);
                if ($items !== []) {
                    $refundMethod = $this->resolveRefundMethod($order, $data['refund_method'] ?? null);
                    $return = $this->returns->createForOrder($order, [
                        'reason' => 'Anulacion: '.$reason,
                        'refund_method' => $refundMethod,
                        'refund_notes' => $data['notes'] ?? null,
                        'items' => $items,
                    ], $user);
                    $return = $this->returns->approve($return, $user, true);
                }
            }

            $audit = $order->audit_logs ?? [];
            $audit[] = [
                'action' => 'order_cancelled',
                'metadata' => [
                    'reason' => $reason,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'sales_return_id' => $return?->id,
                    'settlement_status' => $return?->settlement_status,
                ],
                'timestamp' => now()->toISOString(),
            ];

            $order->update([
                'status' => 'cancelled',
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancellation_return_id' => $return?->id,
                'audit_logs' => $audit,
            ]);

            return $order->fresh([
                'customer', 'user', 'items.product', 'items.variant', 'payments',
                'returns.creditNote', 'cancellationReturn.creditNote', 'canceller',
            ]);
        });
    }

    /** @return array<int, array{sales_order_item_id: int, quantity: int, user_id: int}> */
    private function remainingItems(SalesOrder $order): array
    {
        $returned = $order->returns
            ->whereIn('status', ['pending', 'approved'])
            ->flatMap->items
            ->groupBy('sales_order_item_id')
            ->map->sum('quantity');

        return $order->items
            ->map(function ($item) use ($returned, $order): ?array {
                $remaining = max(0, (int) $item->quantity - (int) ($returned[$item->id] ?? 0));

                return $remaining > 0 ? [
                    'sales_order_item_id' => (int) $item->id,
                    'quantity' => $remaining,
                    'user_id' => (int) $order->user_id,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveRefundMethod(SalesOrder $order, ?string $requested): string
    {
        $requested = trim((string) $requested);
        if ($requested !== '') {
            return $requested;
        }

        if ($order->customer_id && $order->amount_due > 0) {
            return SalesReturnService::METHOD_RECEIVABLE;
        }

        $lastRealPayment = $order->payments
            ->last(fn ($payment): bool => ! PaymentMethod::isDeferredCode($payment->method));

        if ($lastRealPayment) {
            return (string) $lastRealPayment->method;
        }

        if ($order->customer_id) {
            return 'store_credit';
        }

        return SalesReturnService::METHOD_VOID;
    }

    private function releaseReservations(SalesOrder $order): void
    {
        foreach ($order->items as $item) {
            if ($item->is_custom_line) {
                continue;
            }

            $inventory = ProductInventory::query()
                ->where('warehouse_id', $item->warehouse_id)
                ->when($item->variant_id, fn ($query) => $query->where('variant_id', $item->variant_id))
                ->when(! $item->variant_id, fn ($query) => $query->where('product_id', $item->product_id))
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                continue;
            }

            $reserved = min((int) $item->quantity, max(0, (int) $inventory->quantity_reserved));
            if ($reserved > 0) {
                $inventory->decrement('quantity_reserved', $reserved);
                $inventory->increment('quantity_available', $reserved);
            }
        }
    }
}
