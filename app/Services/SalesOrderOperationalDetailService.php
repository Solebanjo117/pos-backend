<?php

namespace App\Services;

use App\Models\AccountLedgerEntry;
use App\Models\CustomerTransaction;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use Illuminate\Support\Collection;

class SalesOrderOperationalDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function get(SalesOrder $salesOrder): array
    {
        $order = $salesOrder->load([
            'customer:id,code,name,email,phone,current_balance,credit_limit,credit_days',
            'customerVehicle.model.make',
            'customerVehicle.chassis',
            'customerVehicle.engine',
            'referredByCustomer:id,code,name',
            'user:id,name,email',
            'preparedBy:id,name',
            'posSession:id,user_id,warehouse_id,opened_at,closed_at,status',
            'posSession.user:id,name',
            'posSession.warehouse:id,code,name',
            'items.product:id,sku_base,name,status',
            'items.variant.brand:id,name',
            'items.warehouse:id,code,name',
            'items.seller:id,name',
            'payments.creator:id,name',
            'returns.items.product:id,sku_base,name',
            'returns.items.variant.brand:id,name',
            'returns.items.seller:id,name',
            'returns.user:id,name',
            'returns.warehouse:id,code,name',
        ]);

        $returnIds = $order->returns->pluck('id')->all();
        $itemIds = $order->items->pluck('id')->all();
        $customerTransactions = $this->customerTransactions($order, $returnIds);
        $ledgerEntries = $this->ledgerEntries($order);
        $inventoryMovements = $this->inventoryMovements($order, $itemIds, $returnIds);
        $items = $order->items->map(fn ($item): array => $this->itemRow($item))->values();

        return [
            'order' => $this->orderRow($order),
            'items' => $items->all(),
            'payments' => $this->payments($order)->all(),
            'payment_summary' => $this->paymentSummary($order)->all(),
            'payment_context' => $this->paymentContext($order),
            'documents' => $this->documents($order),
            'ar_context' => $this->arContext($order, $customerTransactions, $ledgerEntries),
            'returns' => $this->returns($order)->all(),
            'inventory_context' => [
                'movements' => $inventoryMovements->map(fn (InventoryMovement $movement): array => $this->inventoryMovementRow($movement))->values()->all(),
            ],
            'profit_summary' => $this->profitSummary($items),
            'workflow' => $this->workflow($order),
            'timeline' => $this->timeline($order, $customerTransactions, $ledgerEntries, $inventoryMovements)->all(),
            'audit' => collect($order->audit_logs ?? [])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'document_type' => 'sales_order',
            'order_number' => $order->order_number,
            'date' => $order->date?->toDateString(),
            'status' => $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_amount,
            'shipping_cost' => (float) $order->shipping_cost,
            'total' => (float) $order->total,
            'notes' => $order->notes,
            'internal_notes' => $order->internal_notes,
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
            'sent_to_warehouse_at' => $order->sent_to_warehouse_at?->toISOString(),
            'prepared_at' => $order->prepared_at?->toISOString(),
            'prepared_by' => $order->preparedBy ? [
                'id' => $order->preparedBy->id,
                'name' => $order->preparedBy->name,
            ] : null,
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'code' => $order->customer->code,
                'name' => $order->customer->name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
                'current_balance' => (float) $order->customer->current_balance,
                'credit_limit' => (float) $order->customer->credit_limit,
                'credit_days' => $order->customer->credit_days,
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
            'referred_by_customer' => $order->referredByCustomer ? [
                'id' => $order->referredByCustomer->id,
                'code' => $order->referredByCustomer->code,
                'name' => $order->referredByCustomer->name,
            ] : null,
            'pos_session' => $order->posSession ? [
                'id' => $order->posSession->id,
                'status' => $order->posSession->status,
                'opened_at' => $order->posSession->opened_at?->toISOString(),
                'closed_at' => $order->posSession->closed_at?->toISOString(),
                'user_name' => $order->posSession->user?->name,
                'warehouse_id' => $order->posSession->warehouse_id,
                'warehouse_name' => $order->posSession->warehouse?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow($item): array
    {
        $quantity = max((int) $item->quantity, 0);
        $unitCost = (float) ($item->unit_cost ?? 0);
        $totalCost = round($unitCost * $quantity, 2);
        $grossProfit = round((float) $item->subtotal - $totalCost, 2);
        $grossMargin = (float) $item->subtotal > 0 ? round(($grossProfit / (float) $item->subtotal) * 100, 2) : null;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'product_name' => $item->product_name,
            'product_sku' => $item->product_sku,
            'variant_sku' => $item->variant?->sku,
            'brand_name' => $item->variant?->brand?->name,
            'warehouse_id' => $item->warehouse_id,
            'warehouse_name' => $item->warehouse?->name,
            'seller_id' => $item->seller_id,
            'seller_name' => $item->seller?->name,
            'quantity' => $quantity,
            'unit_price' => (float) $item->unit_price,
            'unit_cost' => $item->unit_cost === null ? null : $unitCost,
            'discount_percent' => (float) $item->discount_percent,
            'discount_amount' => (float) $item->discount_amount,
            'tax_percent' => (float) $item->tax_percent,
            'tax_amount' => (float) $item->tax_amount,
            'subtotal' => (float) $item->subtotal,
            'total' => (float) $item->total,
            'total_cost' => $totalCost,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $grossMargin,
            'notes' => $item->notes,
            'is_custom_line' => (bool) $item->is_custom_line,
            'custom_description' => $item->custom_description,
            'added_after_sale' => (bool) $item->added_after_sale,
        ];
    }

    private function payments(SalesOrder $order): Collection
    {
        return $order->payments
            ->map(fn ($payment): array => [
                'id' => $payment->id,
                'method' => $payment->method,
                'method_label' => $payment->formatted_method,
                'amount' => (float) $payment->amount,
                'is_refund' => (float) $payment->amount < 0,
                'paid_at' => $payment->paid_at?->toISOString(),
                'reference_number' => $payment->reference_number,
                'notes' => $payment->notes,
                'creator_name' => $payment->creator?->name,
            ])
            ->values();
    }

    private function paymentSummary(SalesOrder $order): Collection
    {
        return $order->payments
            ->groupBy('method')
            ->map(fn (Collection $payments, string $method): array => [
                'method' => $method,
                'method_label' => $payments->first()->formatted_method,
                'amount' => round((float) $payments->sum('amount'), 2),
                'count' => $payments->count(),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentContext(SalesOrder $order): array
    {
        $realPaidAmount = round((float) $order->payments
            ->reject(fn ($payment): bool => PaymentMethod::isDeferredCode($payment->method))
            ->sum('amount'), 2);
        $creditAmount = round((float) $order->payments
            ->where('method', 'credit')
            ->sum('amount'), 2);
        $refundedAmount = round((float) $order->payments
            ->filter(fn ($payment): bool => (float) $payment->amount < 0)
            ->sum(fn ($payment): float => abs((float) $payment->amount)), 2);
        $approvedReturnsAmount = round((float) $order->returns
            ->where('status', 'approved')
            ->sum('total_amount'), 2);
        $amountDue = round(max(0, (float) $order->total - $approvedReturnsAmount - $realPaidAmount), 2);

        return [
            'payment_status' => $order->payment_status,
            'total' => (float) $order->total,
            'real_paid_amount' => $realPaidAmount,
            'credit_amount' => $creditAmount,
            'refunded_amount' => $refundedAmount,
            'total_returned' => $approvedReturnsAmount,
            'amount_due' => $amountDue,
            'summary_by_method' => $this->paymentSummary($order)->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(SalesOrder $order): array
    {
        return [
            [
                'type' => 'ticket',
                'label' => 'Ticket',
                'mode' => 'internal',
                'href' => "/api/v1/sales-orders/{$order->id}/ticket?type=internal",
            ],
            [
                'type' => 'note',
                'label' => 'Nota',
                'mode' => 'standard',
                'href' => "/api/v1/sales-orders/{$order->id}/note",
            ],
            [
                'type' => 'proforma',
                'label' => 'Proforma',
                'mode' => 'internal',
                'href' => "/api/v1/sales-orders/{$order->id}/proforma?type=internal",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(SalesOrder $order): array
    {
        $canSendToWarehouse = $order->sent_to_warehouse_at === null
            && ! in_array($order->status, ['cancelled'], true);
        $canMarkPrepared = $order->sent_to_warehouse_at !== null
            && $order->prepared_at === null
            && ! in_array($order->status, ['cancelled'], true);

        return [
            'status' => $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status,
            'actions' => [
                'can_edit' => $order->canBeEdited(),
                'can_confirm' => $order->canBeConfirmed(),
                'can_cancel' => $order->canBeCancelled(),
                'can_send_to_warehouse' => $canSendToWarehouse,
                'can_mark_prepared' => $canMarkPrepared,
                'can_add_items' => in_array($order->status, ['confirmed', 'processing', 'completed'], true),
                'can_add_payment' => $order->payment_status !== 'paid' && $order->status !== 'cancelled',
                'can_create_return' => $order->status !== 'cancelled' && $order->items->isNotEmpty(),
                'can_open_customer' => $order->customer_id !== null,
                'can_open_pos_session' => $order->pos_session_id !== null,
            ],
            'links' => [
                'order' => "/sales-orders/{$order->id}",
                'edit' => $order->canBeEdited() ? "/sales-orders/{$order->id}/edit" : null,
                'customer' => $order->customer_id ? "/customers/{$order->customer_id}" : null,
                'pos_session' => $order->pos_session_id ? "/pos-sessions?session={$order->pos_session_id}" : null,
            ],
            'blocked_reason' => $order->status === 'cancelled' ? 'Esta orden fue cancelada.' : null,
        ];
    }

    /**
     * @param  Collection<int, CustomerTransaction>  $customerTransactions
     * @param  Collection<int, AccountLedgerEntry>  $ledgerEntries
     * @return array<string, mixed>
     */
    private function arContext(SalesOrder $order, Collection $customerTransactions, Collection $ledgerEntries): array
    {
        $amountPaid = round((float) $order->payments
            ->reject(fn ($payment): bool => PaymentMethod::isDeferredCode($payment->method))
            ->sum('amount'), 2);
        $totalReturned = round((float) $order->returns->where('status', 'approved')->sum('total_amount'), 2);
        $amountDue = round(max(0, (float) $order->total - $totalReturned - $amountPaid), 2);

        return [
            'customer_id' => $order->customer_id,
            'amount_paid' => $amountPaid,
            'amount_due' => $amountDue,
            'total_returned' => $totalReturned,
            'customer_transactions' => $customerTransactions->map(fn (CustomerTransaction $transaction): array => [
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
            ])->values()->all(),
            'ledger_entries' => $ledgerEntries->map(fn (AccountLedgerEntry $entry): array => [
                'id' => $entry->id,
                'movement_type' => $entry->movement_type,
                'document_type' => $entry->document_type,
                'document_id' => $entry->document_id,
                'document_number' => $entry->document_number,
                'batch_number' => $entry->paymentBatch?->batch_number,
                'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit,
                'balance_after' => (float) $entry->balance_after,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'reference_number' => $entry->reference_number,
                'notes' => $entry->notes,
                'creator_name' => $entry->creator?->name,
                'applications' => $entry->paymentBatch?->applications?->values()?->all() ?? [],
            ])->values()->all(),
        ];
    }

    private function returns(SalesOrder $order): Collection
    {
        return $order->returns
            ->sortByDesc('created_at')
            ->map(fn ($return): array => [
                'id' => $return->id,
                'return_number' => $return->return_number,
                'status' => $return->status,
                'status_label' => $return->status_label,
                'reason' => $return->reason,
                'refund_method' => $return->refund_method,
                'refund_notes' => $return->refund_notes,
                'total_amount' => (float) $return->total_amount,
                'created_at' => $return->created_at?->toISOString(),
                'processed_by_name' => $return->user?->name,
                'warehouse_name' => $return->warehouse?->name,
                'items' => $return->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'sales_order_item_id' => $item->sales_order_item_id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product?->name,
                    'product_sku' => $item->product?->sku_base,
                    'variant_sku' => $item->variant?->sku,
                    'brand_name' => $item->variant?->brand?->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'seller_name' => $item->seller?->name,
                ])->values()->all(),
            ])
            ->values();
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $returnIds
     * @return Collection<int, InventoryMovement>
     */
    private function inventoryMovements(SalesOrder $order, array $itemIds, array $returnIds): Collection
    {
        return InventoryMovement::query()
            ->with(['product:id,sku_base,name', 'variant.brand:id,name', 'warehouse:id,code,name', 'destinationWarehouse:id,code,name', 'user:id,name', 'lotAllocations.lot'])
            ->where(function ($query) use ($order, $itemIds, $returnIds): void {
                $query->where(function ($query) use ($order): void {
                    $query->where('reference_type', 'sale')
                        ->where('reference_id', $order->id);
                })->orWhere(function ($query) use ($order): void {
                    $query->where('reference_type', 'sale_cancelled')
                        ->where('reference_id', $order->id);
                });

                if ($itemIds !== []) {
                    $query->orWhere(function ($query) use ($itemIds): void {
                        $query->whereIn('reference_type', ['post_sale_addition', 'sales_order_item_removed'])
                            ->whereIn('reference_id', $itemIds);
                    });
                }

                if ($returnIds !== []) {
                    $query->orWhere(function ($query) use ($returnIds): void {
                        $query->where('reference_type', 'sales_return')
                            ->whereIn('reference_id', $returnIds);
                    });
                }
            })
            ->orderBy('created_at')
            ->get();
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
            'destination_warehouse_id' => $movement->destination_warehouse_id,
            'destination_warehouse_name' => $movement->destinationWarehouse?->name,
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
     * @param  array<int, int>  $returnIds
     * @return Collection<int, CustomerTransaction>
     */
    private function customerTransactions(SalesOrder $order, array $returnIds): Collection
    {
        if (! $order->customer_id) {
            return collect();
        }

        return CustomerTransaction::query()
            ->with('creator:id,name')
            ->where('customer_id', $order->customer_id)
            ->where(function ($query) use ($order, $returnIds): void {
                $query->where(function ($query) use ($order): void {
                    $query->whereIn('reference_type', ['sales_order', 'sales_order_modification', 'sales_order_cancelled'])
                        ->where('reference_id', $order->id);
                });

                if ($returnIds !== []) {
                    $query->orWhere(function ($query) use ($returnIds): void {
                        $query->where('reference_type', 'sales_return')
                            ->whereIn('reference_id', $returnIds);
                    });
                }
            })
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * @return Collection<int, AccountLedgerEntry>
     */
    private function ledgerEntries(SalesOrder $order): Collection
    {
        if (! $order->customer_id) {
            return collect();
        }

        return AccountLedgerEntry::query()
            ->with(['paymentBatch.applications', 'creator:id,name'])
            ->where('account_type', 'customer')
            ->where('account_id', $order->customer_id)
            ->where(function ($query) use ($order): void {
                $query->where(function ($query) use ($order): void {
                    $query->where('document_type', 'sales_order')
                        ->where('document_id', $order->id);
                })->orWhereHas('paymentBatch.applications', function ($query) use ($order): void {
                    $query->where('document_type', 'sales_order')
                        ->where('document_id', $order->id);
                });
            })
            ->orderByDesc('occurred_at')
            ->get();
    }

    /**
     * @param  Collection<int, CustomerTransaction>  $customerTransactions
     * @param  Collection<int, AccountLedgerEntry>  $ledgerEntries
     * @param  Collection<int, InventoryMovement>  $inventoryMovements
     * @return Collection<int, array<string, mixed>>
     */
    private function timeline(
        SalesOrder $order,
        Collection $customerTransactions,
        Collection $ledgerEntries,
        Collection $inventoryMovements
    ): Collection {
        $events = collect([
            [
                'type' => 'created',
                'label' => 'Orden creada',
                'occurred_at' => $order->created_at?->toISOString(),
                'source' => 'sales_order',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $order->user?->name,
                'amount' => (float) $order->total,
            ],
        ]);

        if ($order->sent_to_warehouse_at) {
            $events->push([
                'type' => 'sent_to_warehouse',
                'label' => 'Enviada a bodega',
                'occurred_at' => $order->sent_to_warehouse_at?->toISOString(),
                'source' => 'sales_order',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $order->user?->name,
                'amount' => null,
            ]);
        }

        if ($order->prepared_at) {
            $events->push([
                'type' => 'prepared',
                'label' => 'Orden preparada',
                'occurred_at' => $order->prepared_at?->toISOString(),
                'source' => 'sales_order',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $order->preparedBy?->name,
                'amount' => null,
            ]);
        }

        foreach ($order->payments as $payment) {
            $amount = (float) $payment->amount;
            $events->push([
                'type' => $amount < 0 ? 'refund_payment' : 'payment',
                'label' => $amount < 0 ? 'Reembolso registrado' : 'Pago registrado',
                'occurred_at' => $payment->paid_at?->toISOString(),
                'source' => 'sales_order_payment',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $payment->creator?->name,
                'amount' => $amount,
            ]);
        }

        foreach ($order->returns as $return) {
            $events->push([
                'type' => 'sales_return',
                'label' => $return->status === 'approved' ? 'Devolucion aprobada' : 'Devolucion registrada',
                'occurred_at' => $return->created_at?->toISOString(),
                'source' => 'sales_return',
                'href' => "/returns/{$return->id}",
                'actor_name' => $return->user?->name,
                'amount' => (float) $return->total_amount,
            ]);
        }

        foreach ($inventoryMovements as $movement) {
            $events->push([
                'type' => 'inventory_movement',
                'label' => $movement->movement_type === 'in' ? 'Entrada de inventario' : 'Salida de inventario',
                'occurred_at' => $movement->created_at?->toISOString(),
                'source' => 'inventory_movement',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $movement->user?->name,
                'amount' => null,
            ]);
        }

        foreach ($customerTransactions as $transaction) {
            $events->push([
                'type' => 'customer_transaction',
                'label' => $transaction->formatted_type,
                'occurred_at' => $transaction->transaction_date?->toISOString(),
                'source' => 'customer_transaction',
                'href' => $order->customer_id ? "/customers/{$order->customer_id}" : null,
                'actor_name' => $transaction->creator?->name,
                'amount' => (float) $transaction->amount,
            ]);
        }

        foreach ($ledgerEntries as $entry) {
            $events->push([
                'type' => 'ledger_entry',
                'label' => $entry->movement_type,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'source' => 'account_ledger_entry',
                'href' => $order->customer_id ? "/customers/{$order->customer_id}" : null,
                'actor_name' => $entry->creator?->name,
                'amount' => round((float) $entry->debit - (float) $entry->credit, 2),
            ]);
        }

        foreach (collect($order->audit_logs ?? [])->values() as $auditLog) {
            $events->push([
                'type' => 'audit',
                'label' => (string) ($auditLog['action'] ?? 'Auditoria'),
                'occurred_at' => $auditLog['timestamp'] ?? null,
                'source' => 'sales_order_audit',
                'href' => "/sales-orders/{$order->id}",
                'actor_name' => $auditLog['metadata']['user_name'] ?? null,
                'amount' => isset($auditLog['metadata']['total']) ? (float) $auditLog['metadata']['total'] : null,
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => filled($event['occurred_at'] ?? null))
            ->sortBy(fn (array $event): string => $event['occurred_at'])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function profitSummary(Collection $items): array
    {
        $subtotal = round((float) $items->sum('subtotal'), 2);
        $totalCost = round((float) $items->sum('total_cost'), 2);
        $grossProfit = round($subtotal - $totalCost, 2);

        return [
            'subtotal' => $subtotal,
            'total_cost' => $totalCost,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $subtotal > 0 ? round(($grossProfit / $subtotal) * 100, 2) : null,
            'has_missing_costs' => $items->contains(fn (array $item): bool => $item['unit_cost'] === null),
        ];
    }
}
