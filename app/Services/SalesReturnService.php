<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public const METHOD_RECEIVABLE = 'receivable';

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForOrder(SalesOrder $order, array $data, User $user): SalesReturn
    {
        return DB::transaction(function () use ($order, $data, $user): SalesReturn {
            $lockedOrder = SalesOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedOrder->status, ['confirmed', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden devolver ordenes confirmadas o completadas.',
                ]);
            }

            $lockedOrder->loadMissing('items.product');

            $returnItems = $this->normalizeReturnItems($data['items'] ?? []);
            $this->validateRefundMethodForOrder($lockedOrder, (string) $data['refund_method']);
            $this->validateReturnItems($lockedOrder, $returnItems);

            $firstItem = $lockedOrder->items->firstWhere('id', $returnItems[0]['sales_order_item_id'] ?? null)
                ?? $lockedOrder->items->first();

            $salesReturn = SalesReturn::create([
                'sales_order_id' => $lockedOrder->id,
                'warehouse_id' => $firstItem?->warehouse_id ?? $user->current_warehouse_id,
                'customer_id' => $lockedOrder->customer_id,
                'user_id' => $user->id,
                'reason' => $data['reason'] ?? null,
                'refund_method' => $data['refund_method'],
                'refund_notes' => $data['refund_notes'] ?? null,
                'status' => 'pending',
            ]);

            $totalAmount = 0.0;

            foreach ($returnItems as $returnItem) {
                $orderItem = $lockedOrder->items->firstWhere('id', $returnItem['sales_order_item_id']);
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

            $salesReturn->update(['total_amount' => $this->money($totalAmount)]);

            return $salesReturn->load('items.product', 'items.variant');
        });
    }

    public function approve(SalesReturn $salesReturn, User $user): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $user): SalesReturn {
            $lockedReturn = SalesReturn::query()
                ->whereKey($salesReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReturn->status !== 'pending') {
                throw ValidationException::withMessages([
                    'return' => 'Esta devolucion ya fue procesada.',
                ]);
            }

            $lockedReturn->loadMissing('items.salesOrderItem', 'items.product', 'items.variant', 'salesOrder');

            $order = SalesOrder::query()
                ->whereKey($lockedReturn->sales_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order->loadMissing('items.product', 'payments', 'returns');
            $this->validateReturnItems($order, $this->itemsFromReturn($lockedReturn), $lockedReturn->id);

            $split = $this->financialSplit($lockedReturn, $order);

            if ($split['money_refund_amount'] > 0 && $this->paymentBucket($lockedReturn->refund_method) === PaymentMethod::LIQUIDITY_CASH) {
                $this->assertOpenSession($user, 'Debe tener una sesion de caja abierta para aprobar un reembolso en efectivo.');
            }

            $this->restoreInventory($lockedReturn, $user);
            $this->applyCustomerCredit($lockedReturn, $split['receivable_applied_amount'] + $split['store_credit_amount'], $user);
            $this->createRefundPayment($lockedReturn, $split['money_refund_amount'], $user);

            $lockedReturn->approve($user, $split);
            $this->updateOrderPaymentStatus($order);

            return $lockedReturn->fresh()->load('items.product', 'items.variant');
        });
    }

    public function reject(SalesReturn $salesReturn, User $user): SalesReturn
    {
        return DB::transaction(function () use ($salesReturn, $user): SalesReturn {
            $lockedReturn = SalesReturn::query()
                ->whereKey($salesReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedReturn->reject($user)) {
                throw ValidationException::withMessages([
                    'return' => 'Esta devolucion ya fue procesada.',
                ]);
            }

            return $lockedReturn->fresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReturnItems(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $itemId = (int) ($item['sales_order_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            if (! isset($grouped[$itemId])) {
                $grouped[$itemId] = [
                    'sales_order_item_id' => $itemId,
                    'quantity' => 0,
                    'user_id' => $item['user_id'] ?? null,
                    'seller_id' => $item['seller_id'] ?? null,
                ];
            }

            $grouped[$itemId]['quantity'] += (int) ($item['quantity'] ?? 0);
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, array{sales_order_item_id: int, quantity: int}>
     */
    private function itemsFromReturn(SalesReturn $salesReturn): array
    {
        return $salesReturn->items
            ->map(fn (SalesReturnItem $item): array => [
                'sales_order_item_id' => (int) $item->sales_order_item_id,
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function validateReturnItems(SalesOrder $order, array $items, ?int $excludeReturnId = null): void
    {
        $errors = [];

        if ($items === []) {
            $errors[] = 'Seleccione al menos un articulo para devolver.';
        }

        foreach ($items as $returnItem) {
            $itemId = (int) ($returnItem['sales_order_item_id'] ?? 0);
            $quantity = (int) ($returnItem['quantity'] ?? 0);
            $orderItem = $order->items->firstWhere('id', $itemId);

            if ($quantity <= 0) {
                $errors[] = 'La cantidad a devolver debe ser al menos 1.';

                continue;
            }

            if (! $orderItem) {
                $errors[] = "Linea #{$itemId} no pertenece a esta orden.";

                continue;
            }

            if ($orderItem->is_custom_line) {
                $errors[] = "La linea '{$orderItem->custom_description}' es miscelanea y no puede devolverse.";

                continue;
            }

            $alreadyReturned = SalesReturnItem::query()
                ->where('sales_order_item_id', $itemId)
                ->whereHas('salesReturn', function ($query) use ($order, $excludeReturnId): void {
                    $query->where('sales_order_id', $order->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->when($excludeReturnId, fn ($query) => $query->where('id', '!=', $excludeReturnId));
                })
                ->lockForUpdate()
                ->get(['quantity'])
                ->sum('quantity');

            $maxReturnable = (int) $orderItem->quantity - (int) $alreadyReturned;

            if ($quantity > $maxReturnable) {
                $label = $orderItem->product?->name ?? "Producto #{$orderItem->product_id}";
                $errors[] = "{$label}: maximo devolvible es {$maxReturnable}, solicitado {$quantity}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'items' => $errors,
            ]);
        }
    }

    private function validateRefundMethodForOrder(SalesOrder $order, string $method): void
    {
        $allowed = array_values(array_unique(array_merge(
            PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS),
            ['balance', 'store_credit', self::METHOD_RECEIVABLE],
        )));

        if (! in_array($method, $allowed, true)) {
            throw ValidationException::withMessages([
                'refund_method' => 'El metodo de reembolso seleccionado no es valido.',
            ]);
        }

        if (in_array($method, ['balance', 'store_credit', self::METHOD_RECEIVABLE], true) && ! $order->customer_id) {
            throw ValidationException::withMessages([
                'refund_method' => 'Esta devolucion requiere un cliente para ajustar CxC o saldo a favor.',
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

        $subtotal = $this->money($unitSubtotal * $returnQty);
        $tax = $this->money($unitTax * $returnQty);
        $total = $this->money($unitTotal * $returnQty);

        return [
            'unit_subtotal' => $this->money($unitSubtotal),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total > 0 ? $total : $this->money($subtotal + $tax),
        ];
    }

    /**
     * @return array{receivable_applied_amount: float, money_refund_amount: float, store_credit_amount: float}
     */
    private function financialSplit(SalesReturn $salesReturn, SalesOrder $order): array
    {
        $total = $this->money($salesReturn->total_amount);
        $receivableOpen = $this->orderReceivableBeforeReturn($order, $salesReturn);
        $receivableApplied = $this->money(min($total, $receivableOpen));
        $remaining = $this->money(max(0, $total - $receivableApplied));
        $method = (string) $salesReturn->refund_method;
        $moneyRefund = 0.0;
        $storeCredit = 0.0;

        if ($remaining > 0) {
            if (in_array($method, PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS), true)) {
                $moneyRefund = $remaining;
            } else {
                $storeCredit = $remaining;
            }
        }

        if (($receivableApplied + $storeCredit) > 0 && ! $salesReturn->customer_id) {
            throw ValidationException::withMessages([
                'refund_method' => 'Esta devolucion requiere un cliente para ajustar CxC o saldo a favor.',
            ]);
        }

        return [
            'receivable_applied_amount' => $this->money($receivableApplied),
            'money_refund_amount' => $this->money($moneyRefund),
            'store_credit_amount' => $this->money($storeCredit),
        ];
    }

    private function orderReceivableBeforeReturn(SalesOrder $order, SalesReturn $salesReturn): float
    {
        $approvedReturned = (float) SalesReturn::query()
            ->where('sales_order_id', $order->id)
            ->where('status', 'approved')
            ->where('id', '!=', $salesReturn->id)
            ->sum('total_amount');

        $paid = (float) SalesOrderPayment::query()
            ->where('sales_order_id', $order->id)
            ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
            ->sum('amount');

        return $this->money(max(0, (float) $order->total - $approvedReturned - $paid));
    }

    private function restoreInventory(SalesReturn $salesReturn, User $user): void
    {
        foreach ($salesReturn->items as $item) {
            app(InventoryFifoService::class)->receiveStock([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $salesReturn->warehouse_id,
                'quantity' => $item->quantity,
                'unit_cost_base' => (float) ($item->salesOrderItem?->unit_cost ?? 0),
                'landed_unit_cost' => (float) ($item->salesOrderItem?->unit_cost ?? 0),
                'reference_type' => 'sales_return',
                'reference_id' => $salesReturn->id,
                'source_type' => 'sales_return',
                'source_id' => $salesReturn->id,
                'notes' => "Devolucion {$salesReturn->return_number} - Orden {$salesReturn->salesOrder?->order_number}",
                'user_id' => $user->id,
                'received_at' => now(),
            ]);
        }
    }

    private function applyCustomerCredit(SalesReturn $salesReturn, float $amount, User $user): void
    {
        $amount = $this->money($amount);
        if ($amount <= 0 || ! $salesReturn->customer_id) {
            return;
        }

        $customer = Customer::query()
            ->whereKey($salesReturn->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            throw ValidationException::withMessages([
                'customer' => 'No se encontro el cliente de la devolucion.',
            ]);
        }

        $balanceBefore = (float) $customer->current_balance;
        $customer->current_balance = $this->money($balanceBefore - $amount);
        $customer->save();

        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'credit_note',
            'reference_type' => 'sales_return',
            'reference_id' => $salesReturn->id,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $customer->current_balance,
            'description' => "Devolucion {$salesReturn->return_number}",
            'transaction_date' => now(),
            'created_by' => $user->id,
        ]);
    }

    private function createRefundPayment(SalesReturn $salesReturn, float $amount, User $user): void
    {
        $amount = $this->money($amount);
        if ($amount <= 0) {
            return;
        }

        SalesOrderPayment::create([
            'sales_order_id' => $salesReturn->sales_order_id,
            'sales_return_id' => $salesReturn->id,
            'amount' => -1 * abs($amount),
            'method' => $salesReturn->refund_method,
            'notes' => "Reembolso por devolucion {$salesReturn->return_number}".($salesReturn->refund_notes ? " - {$salesReturn->refund_notes}" : ''),
            'paid_at' => now(),
            'created_by' => $user->id,
        ]);
    }

    private function updateOrderPaymentStatus(SalesOrder $order): void
    {
        $amountPaid = (float) $order->payments()
            ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
            ->sum('amount');

        $totalReturned = (float) SalesReturn::query()
            ->where('sales_order_id', $order->id)
            ->where('status', 'approved')
            ->sum('total_amount');

        if ($totalReturned >= (float) $order->total) {
            $order->payment_status = 'refunded';
        } else {
            $effectiveTotal = max(0, (float) $order->total - $totalReturned);

            if ($amountPaid <= 0) {
                $order->payment_status = 'pending';
            } elseif ($amountPaid >= $effectiveTotal) {
                $order->payment_status = 'paid';
            } else {
                $order->payment_status = 'partial';
            }
        }

        $order->save();
    }

    private function paymentBucket(?string $method): ?string
    {
        return PaymentMethod::liquidityBucket($method);
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

    private function money(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
