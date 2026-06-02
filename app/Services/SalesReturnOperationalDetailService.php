<?php

namespace App\Services;

use App\Models\AccountLedgerEntry;
use App\Models\CustomerTransaction;
use App\Models\InventoryMovement;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use Illuminate\Support\Collection;

class SalesReturnOperationalDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function get(SalesReturn $salesReturn): array
    {
        $return = $salesReturn->load([
            'salesOrder.customer:id,code,name,email,phone,current_balance',
            'salesOrder.customerVehicle.model.make',
            'salesOrder.customerVehicle.chassis',
            'salesOrder.customerVehicle.engine',
            'salesOrder.items',
            'customer:id,code,name,email,phone,current_balance',
            'user:id,name,email',
            'warehouse:id,code,name',
            'items.product:id,sku_base,name',
            'items.variant.brand:id,name',
            'items.salesOrderItem',
            'items.seller:id,name',
        ]);

        $customerTransactions = $this->customerTransactions($return);
        $ledgerEntries = $this->ledgerEntries($return);
        $refundPayment = $this->refundPayment($return);
        $inventoryMovements = $this->inventoryMovements($return);

        return [
            'return' => $this->returnRow($return),
            'sales_order' => $this->salesOrderRow($return),
            'items' => $return->items->map(fn (SalesReturnItem $item): array => $this->itemRow($item))->values()->all(),
            'refund_context' => $this->refundContext($return, $refundPayment, $customerTransactions->first()),
            'documents' => $this->documents($return),
            'inventory_context' => [
                'movements' => $inventoryMovements->map(fn (InventoryMovement $movement): array => $this->inventoryMovementRow($movement))->values()->all(),
            ],
            'ar_context' => [
                'customer_transactions' => $customerTransactions->map(fn (CustomerTransaction $transaction): array => $this->customerTransactionRow($transaction))->values()->all(),
                'ledger_entries' => $ledgerEntries->map(fn (AccountLedgerEntry $entry): array => $this->ledgerEntryRow($entry))->values()->all(),
            ],
            'workflow' => $this->workflow($return),
            'timeline' => $this->timeline($return, $refundPayment, $customerTransactions, $inventoryMovements)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnRow(SalesReturn $return): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'status' => $return->status,
            'status_label' => $return->status_label,
            'reason' => $return->reason,
            'refund_method' => $return->refund_method,
            'refund_notes' => $return->refund_notes,
            'total_amount' => (float) $return->total_amount,
            'created_at' => $return->created_at?->toISOString(),
            'updated_at' => $return->updated_at?->toISOString(),
            'user' => $return->user ? [
                'id' => $return->user->id,
                'name' => $return->user->name,
                'email' => $return->user->email,
            ] : null,
            'customer' => $return->customer ? [
                'id' => $return->customer->id,
                'code' => $return->customer->code,
                'name' => $return->customer->name,
                'email' => $return->customer->email,
                'phone' => $return->customer->phone,
                'current_balance' => (float) $return->customer->current_balance,
            ] : null,
            'warehouse' => $return->warehouse ? [
                'id' => $return->warehouse->id,
                'code' => $return->warehouse->code,
                'name' => $return->warehouse->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function salesOrderRow(SalesReturn $return): ?array
    {
        $order = $return->salesOrder;

        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status,
            'date' => $order->date?->toDateString(),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'discount_amount' => (float) $order->discount_amount,
            'total' => (float) $order->total,
            'href' => "/sales-orders/{$order->id}",
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'code' => $order->customer->code,
                'name' => $order->customer->name,
                'href' => "/customers/{$order->customer->id}",
            ] : null,
            'customer_vehicle' => $order->customerVehicle ? [
                'id' => $order->customerVehicle->id,
                'plate_number' => $order->customerVehicle->plate_number ?? null,
                'vin' => $order->customerVehicle->vin ?? null,
                'year' => $order->customerVehicle->year ?? null,
                'model' => $order->customerVehicle->model?->name,
                'make' => $order->customerVehicle->model?->make?->name,
                'chassis' => $order->customerVehicle->chassis?->name,
                'engine' => $order->customerVehicle->engine?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(SalesReturnItem $item): array
    {
        $orderItem = $item->salesOrderItem;
        $soldQuantity = max((int) ($orderItem?->quantity ?? 0), 0);
        $quantity = (int) $item->quantity;
        $unitTax = $soldQuantity > 0 ? round((float) ($orderItem?->tax_amount ?? 0) / $soldQuantity, 2) : 0.0;
        $taxAmount = round($unitTax * $quantity, 2);
        $lineTotal = round((float) $item->subtotal + $taxAmount, 2);

        return [
            'id' => $item->id,
            'sales_order_item_id' => $item->sales_order_item_id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'product_name' => $item->product?->name,
            'product_sku' => $item->product?->sku_base,
            'variant_sku' => $item->variant?->sku,
            'brand_name' => $item->variant?->brand?->name,
            'quantity_sold' => $soldQuantity,
            'quantity_returned_total' => $this->approvedReturnedQuantity($item),
            'quantity' => $quantity,
            'unit_price' => (float) $item->unit_price,
            'subtotal' => (float) $item->subtotal,
            'tax_amount' => $taxAmount,
            'total' => $lineTotal,
            'unit_cost' => $orderItem?->unit_cost === null ? null : (float) $orderItem->unit_cost,
            'warehouse_id' => $orderItem?->warehouse_id,
            'seller_name' => $item->seller?->name,
        ];
    }

    private function approvedReturnedQuantity(SalesReturnItem $item): int
    {
        return (int) SalesReturnItem::query()
            ->where('sales_order_item_id', $item->sales_order_item_id)
            ->whereHas('salesReturn', function ($query): void {
                $query->where('status', 'approved');
            })
            ->sum('quantity');
    }

    /**
     * @return array<string, mixed>
     */
    private function refundContext(SalesReturn $return, ?SalesOrderPayment $payment, ?CustomerTransaction $transaction): array
    {
        return [
            'method' => $return->refund_method,
            'amount' => (float) $return->total_amount,
            'requires_open_session' => in_array($return->refund_method, ['cash', 'card', 'transfer'], true),
            'refund_payment' => $payment ? [
                'id' => $payment->id,
                'method' => $payment->method,
                'method_label' => $payment->formatted_method,
                'amount' => (float) $payment->amount,
                'paid_at' => $payment->paid_at?->toISOString(),
                'notes' => $payment->notes,
                'creator_name' => $payment->creator?->name,
            ] : null,
            'customer_transaction' => $transaction ? $this->customerTransactionRow($transaction) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryMovementRow(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movement_type' => $movement->movement_type,
            'reason' => $movement->reason,
            'product_id' => $movement->product_id,
            'variant_id' => $movement->variant_id,
            'product_name' => $movement->product?->name,
            'product_sku' => $movement->product?->sku_base,
            'variant_sku' => $movement->variant?->sku,
            'brand_name' => $movement->variant?->brand?->name,
            'warehouse_id' => $movement->warehouse_id,
            'warehouse_name' => $movement->warehouse?->name,
            'quantity' => (int) $movement->quantity,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'notes' => $movement->notes,
            'created_at' => $movement->created_at?->toISOString(),
            'user_name' => $movement->user?->name,
            'lot_allocations' => $movement->lotAllocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'sales_order_item_id' => $allocation->sales_order_item_id,
                'inventory_lot_id' => $allocation->inventory_lot_id,
                'batch_number' => $allocation->lot?->batch_number,
                'quantity' => (int) $allocation->quantity,
                'unit_cost' => (float) $allocation->unit_cost,
                'total_cost' => (float) $allocation->total_cost,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerTransactionRow(CustomerTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'type_label' => $transaction->formatted_type,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'amount' => (float) $transaction->amount,
            'balance_before' => (float) $transaction->balance_before,
            'balance_after' => (float) $transaction->balance_after,
            'description' => $transaction->description,
            'transaction_date' => $transaction->transaction_date?->toISOString(),
            'creator_name' => $transaction->creator?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerEntryRow(AccountLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'movement_type' => $entry->movement_type,
            'document_type' => $entry->document_type,
            'document_id' => $entry->document_id,
            'document_number' => $entry->document_number,
            'debit' => (float) $entry->debit,
            'credit' => (float) $entry->credit,
            'balance_after' => (float) $entry->balance_after,
            'occurred_at' => $entry->occurred_at?->toISOString(),
            'reference_number' => $entry->reference_number,
            'notes' => $entry->notes,
            'creator_name' => $entry->creator?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(SalesReturn $return): array
    {
        $isPending = $return->status === 'pending';
        $actions = [
            'can_approve' => $isPending,
            'can_reject' => $isPending,
            'can_open_order' => $return->sales_order_id !== null,
            'can_open_customer' => $return->customer_id !== null,
            'can_open_pos_session' => in_array($return->refund_method, ['cash', 'card', 'transfer'], true),
        ];

        return [
            'can_approve' => $isPending,
            'can_reject' => $isPending,
            'can_open_order' => $return->sales_order_id !== null,
            'can_open_customer' => $return->customer_id !== null,
            'can_open_pos_session' => in_array($return->refund_method, ['cash', 'card', 'transfer'], true),
            'status' => $return->status,
            'status_label' => $return->status_label,
            'actions' => $actions,
            'links' => [
                'return' => "/returns/{$return->id}",
                'sales_order' => $return->sales_order_id ? "/sales-orders/{$return->sales_order_id}" : null,
                'customer' => $return->customer_id ? "/customers/{$return->customer_id}" : null,
            ],
            'blocked_reason' => $isPending ? null : 'Esta devolución ya fue procesada.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(SalesReturn $return): array
    {
        $documents = [
            [
                'type' => 'sales_return',
                'label' => 'Devolucion',
                'id' => $return->id,
                'number' => $return->return_number,
                'status' => $return->status,
                'status_label' => $return->status_label,
                'amount' => (float) $return->total_amount,
                'href' => "/returns/{$return->id}",
            ],
        ];

        if ($return->salesOrder) {
            $documents[] = [
                'type' => 'sales_order',
                'label' => 'Orden de venta',
                'id' => $return->salesOrder->id,
                'number' => $return->salesOrder->order_number,
                'status' => $return->salesOrder->status,
                'status_label' => $return->salesOrder->status_label,
                'amount' => (float) $return->salesOrder->total,
                'href' => "/sales-orders/{$return->salesOrder->id}",
            ];
        }

        return $documents;
    }

    private function refundPayment(SalesReturn $return): ?SalesOrderPayment
    {
        return SalesOrderPayment::query()
            ->with('creator:id,name')
            ->where('sales_order_id', $return->sales_order_id)
            ->where('amount', '<', 0)
            ->where('notes', 'like', "%{$return->return_number}%")
            ->latest('paid_at')
            ->first();
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    private function inventoryMovements(SalesReturn $return): Collection
    {
        return InventoryMovement::query()
            ->with(['product:id,sku_base,name', 'variant.brand:id,name', 'warehouse:id,code,name', 'user:id,name', 'lotAllocations.lot'])
            ->where('reference_type', 'sales_return')
            ->where('reference_id', $return->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, CustomerTransaction>
     */
    private function customerTransactions(SalesReturn $return): Collection
    {
        if (! $return->customer_id) {
            return collect();
        }

        return CustomerTransaction::query()
            ->with('creator:id,name')
            ->where('customer_id', $return->customer_id)
            ->where('reference_type', 'sales_return')
            ->where('reference_id', $return->id)
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * @return Collection<int, AccountLedgerEntry>
     */
    private function ledgerEntries(SalesReturn $return): Collection
    {
        if (! $return->customer_id) {
            return collect();
        }

        return AccountLedgerEntry::query()
            ->with('creator:id,name')
            ->where('account_type', 'customer')
            ->where('account_id', $return->customer_id)
            ->where('document_type', 'sales_return')
            ->where('document_id', $return->id)
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * @param  Collection<int, CustomerTransaction>  $transactions
     * @param  Collection<int, InventoryMovement>  $movements
     * @return Collection<int, array<string, mixed>>
     */
    private function timeline(SalesReturn $return, ?SalesOrderPayment $payment, Collection $transactions, Collection $movements): Collection
    {
        $events = collect([
            [
                'type' => 'created',
                'label' => 'Devolución creada',
                'occurred_at' => $return->created_at?->toISOString(),
                'source' => 'sales_return',
                'href' => "/returns/{$return->id}",
                'actor_name' => $return->user?->name,
                'amount' => (float) $return->total_amount,
            ],
        ]);

        if ($return->status !== 'pending') {
            $events->push([
                'type' => $return->status,
                'label' => $return->status === 'approved' ? 'Devolución aprobada' : 'Devolución rechazada',
                'occurred_at' => $return->updated_at?->toISOString(),
                'source' => 'sales_return',
                'href' => "/returns/{$return->id}",
                'actor_name' => $return->user?->name,
                'amount' => (float) $return->total_amount,
            ]);
        }

        if ($payment) {
            $events->push([
                'type' => 'refund_payment',
                'label' => 'Reembolso registrado',
                'occurred_at' => $payment->paid_at?->toISOString(),
                'source' => 'sales_order_payment',
                'href' => $return->sales_order_id ? "/sales-orders/{$return->sales_order_id}" : null,
                'actor_name' => $payment->creator?->name,
                'amount' => (float) $payment->amount,
            ]);
        }

        foreach ($transactions as $transaction) {
            $events->push([
                'type' => 'customer_transaction',
                'label' => $transaction->formatted_type,
                'occurred_at' => $transaction->transaction_date?->toISOString(),
                'source' => 'customer_transaction',
                'href' => $return->customer_id ? "/customers/{$return->customer_id}" : null,
                'actor_name' => $transaction->creator?->name,
                'amount' => (float) $transaction->amount,
            ]);
        }

        foreach ($movements as $movement) {
            $events->push([
                'type' => 'inventory_movement',
                'label' => 'Entrada de inventario',
                'occurred_at' => $movement->created_at?->toISOString(),
                'source' => 'inventory_movement',
                'href' => "/returns/{$return->id}",
                'actor_name' => $movement->user?->name,
                'amount' => null,
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => filled($event['occurred_at'] ?? null))
            ->sortBy(fn (array $event): string => $event['occurred_at'] ?? '')
            ->values();
    }
}
