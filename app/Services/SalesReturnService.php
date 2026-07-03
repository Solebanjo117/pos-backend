<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createForOrder(SalesOrder $order, array $data, User $user): SalesReturn
    {
        if (! in_array($order->status, ['confirmed', 'completed'], true)) {
            throw ValidationException::withMessages([
                'order' => 'Solo se pueden devolver órdenes confirmadas o completadas.',
            ]);
        }

        $this->assertOpenSession($user, 'Debe tener una sesión de caja abierta para registrar una devolución.');

        $order->loadMissing('items.product');
        $this->validateReturnItems($order, $data['items']);

        return DB::transaction(function () use ($order, $data, $user): SalesReturn {
            $salesReturn = SalesReturn::create([
                'sales_order_id' => $order->id,
                'warehouse_id' => $order->items->first()->warehouse_id ?? $user->current_warehouse_id,
                'customer_id' => $order->customer_id,
                'user_id' => $user->id,
                'reason' => $data['reason'] ?? null,
                'refund_method' => $data['refund_method'],
                'refund_notes' => $data['refund_notes'] ?? null,
                'status' => 'pending',
            ]);

            $totalAmount = 0.0;

            foreach ($data['items'] as $returnItem) {
                $orderItem = $order->items->firstWhere('id', $returnItem['sales_order_item_id']);
                $returnQty = (int) $returnItem['quantity'];
                $amounts = $this->lineRefundAmounts($orderItem, $returnQty);

                $salesReturn->items()->create([
                    'sales_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'variant_id' => $orderItem->variant_id,
                    'quantity' => $returnQty,
                    'unit_price' => $amounts['unit_subtotal'],
                    'subtotal' => $amounts['subtotal'],
                    'user_id' => $returnItem['user_id'] ?? $user->id,
                    'seller_id' => $returnItem['seller_id'] ?? $orderItem->seller_id,
                ]);

                $totalAmount += $amounts['total'];
            }

            $salesReturn->update(['total_amount' => round($totalAmount, 2)]);

            return $salesReturn->load('items.product', 'items.variant');
        });
    }

    public function approve(SalesReturn $salesReturn, User $user): SalesReturn
    {
        if ($salesReturn->status !== 'pending') {
            throw ValidationException::withMessages([
                'return' => 'Esta devolución ya fue procesada.',
            ]);
        }

        if (in_array($salesReturn->refund_method, PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS), true)) {
            $this->assertOpenSession($user, 'Debe tener una sesión de caja abierta para aprobar un reembolso directo.');
        }

        return DB::transaction(function () use ($salesReturn): SalesReturn {
            $salesReturn->loadMissing('items.salesOrderItem', 'salesOrder');
            $salesReturn->approve();

            return $salesReturn->fresh()->load('items.product', 'items.variant');
        });
    }

    public function reject(SalesReturn $salesReturn): SalesReturn
    {
        if (! $salesReturn->reject()) {
            throw ValidationException::withMessages([
                'return' => 'Esta devolución ya fue procesada.',
            ]);
        }

        return $salesReturn->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validateReturnItems(SalesOrder $order, array $items): void
    {
        $errors = [];

        foreach ($items as $returnItem) {
            $orderItem = $order->items->firstWhere('id', $returnItem['sales_order_item_id']);

            if (! $orderItem) {
                $errors[] = "Línea #{$returnItem['sales_order_item_id']} no pertenece a esta orden.";

                continue;
            }

            if ($orderItem->is_custom_line) {
                $errors[] = "La línea '{$orderItem->custom_description}' es miscelánea y no puede devolverse.";

                continue;
            }

            $alreadyReturned = SalesReturnItem::whereHas('salesReturn', function ($query) use ($order): void {
                $query->where('sales_order_id', $order->id)->whereIn('status', ['pending', 'approved']);
            })->where('sales_order_item_id', $returnItem['sales_order_item_id'])
                ->sum('quantity');

            $maxReturnable = (int) $orderItem->quantity - (int) $alreadyReturned;

            if ((int) $returnItem['quantity'] > $maxReturnable) {
                $label = $orderItem->product?->name ?? "Producto #{$orderItem->product_id}";
                $errors[] = "{$label}: máximo devolvible es {$maxReturnable}, solicitado {$returnItem['quantity']}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'items' => $errors,
            ]);
        }
    }

    /**
     * @return array{unit_subtotal: float, subtotal: float, tax: float, total: float}
     */
    private function lineRefundAmounts($orderItem, int $returnQty): array
    {
        $orderedQty = max((int) $orderItem->quantity, 1);
        $lineSubtotal = (float) ($orderItem->subtotal ?? 0);
        $lineTax = (float) ($orderItem->tax_amount ?? 0);
        $lineTotal = (float) ($orderItem->total ?? 0);

        if ($lineTotal <= 0) {
            $lineTotal = $lineSubtotal + $lineTax;
        }

        $unitSubtotal = $lineSubtotal > 0 ? ($lineSubtotal / $orderedQty) : 0.0;
        $unitTax = $lineTax > 0 ? ($lineTax / $orderedQty) : 0.0;
        $unitTotal = $lineTotal > 0 ? ($lineTotal / $orderedQty) : 0.0;

        if ($unitSubtotal <= 0 && $unitTotal <= 0) {
            $discountPercent = (float) ($orderItem->discount_percent ?? 0);
            $unitSubtotal = (float) $orderItem->unit_price * (1 - ($discountPercent / 100));
            $unitTax = $unitSubtotal * ((float) ($orderItem->tax_percent ?? 0) / 100);
            $unitTotal = $unitSubtotal + $unitTax;
        }

        $subtotal = round($unitSubtotal * $returnQty, 2);
        $tax = round($unitTax * $returnQty, 2);
        $total = round($unitTotal * $returnQty, 2);

        return [
            'unit_subtotal' => round($unitSubtotal, 2),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total > 0 ? $total : $subtotal + $tax,
        ];
    }

    private function assertOpenSession(User $user, string $message): void
    {
        $hasSession = PosSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->exists();

        if (! $hasSession) {
            throw ValidationException::withMessages([
                'pos_session' => $message,
            ]);
        }
    }
}
