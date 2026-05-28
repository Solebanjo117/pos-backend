<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PosSessionOperationalDetailService
{
    /**
     * @param  Collection<int, SalesOrder>  $salesOrders
     * @param  Collection<int, array<string, mixed>>  $purchasePayments
     * @param  Collection<int, array<string, mixed>>  $accountPayments
     * @param  Collection<int, OperationalExpense>  $operationalExpenses
     * @return array{documents: array<int, array<string, mixed>>, timeline: array<int, array<string, mixed>>}
     */
    public function build(
        PosSession $session,
        Collection $salesOrders,
        Collection $purchasePayments,
        Collection $accountPayments,
        Collection $operationalExpenses
    ): array {
        $customers = $this->customersFor($accountPayments);
        $suppliers = $this->suppliersFor($accountPayments);

        $documents = collect()
            ->merge($salesOrders->map(fn (SalesOrder $order): array => $this->saleDocument($order)))
            ->merge($accountPayments->map(
                fn (array $payment): array => $this->accountPaymentDocument(
                    $payment,
                    $customers->get((int) ($payment['account_id'] ?? 0)),
                    $suppliers->get((int) ($payment['account_id'] ?? 0))
                )
            ))
            ->merge($purchasePayments->map(fn (array $payment): array => $this->purchasePaymentDocument($payment)))
            ->merge($operationalExpenses->map(fn (OperationalExpense $expense): array => $this->operationalExpenseDocument($expense)))
            ->merge($session->cashMovements->map(fn (CashMovement $movement): array => $this->cashMovementDocument($movement)))
            ->sort($this->sortByOccurredAt(...))
            ->values();

        return [
            'documents' => $documents->all(),
            'timeline' => $this->timeline($session, $documents),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleDocument(SalesOrder $order): array
    {
        return [
            'id' => 'sale-'.$order->id,
            'type' => 'sale',
            'type_label' => 'Venta',
            'document_id' => $order->id,
            'document_number' => $order->order_number,
            'amount' => $this->money($order->total),
            'method' => $order->payment_method,
            'method_label' => $this->methodLabel($order->payment_method),
            'direction' => 'in',
            'occurred_at' => $this->date($order->created_at),
            'actor_id' => $order->user_id,
            'actor_name' => $order->user?->name,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            'supplier_id' => null,
            'supplier_name' => null,
            'reference' => $order->order_number,
            'notes' => null,
            'href' => "/sales-orders/{$order->id}",
            'is_cash_counted' => $this->isCashCounted($order->payment_method),
            'source' => 'sales_order',
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function accountPaymentDocument(array $payment, ?Customer $customer, ?Supplier $supplier): array
    {
        $accountType = (string) ($payment['account_type'] ?? '');
        $customer = $accountType === 'customer' ? $customer : null;
        $supplier = $accountType === 'supplier' ? $supplier : null;
        $type = $accountType === 'supplier' ? 'ap_payment' : 'ar_receipt';
        $direction = (string) ($payment['direction'] ?? ($accountType === 'supplier' ? 'outgoing' : 'incoming'));

        return [
            'id' => $type.'-'.$payment['id'],
            'type' => $type,
            'type_label' => $type === 'ap_payment' ? 'Pago AP' : 'Cobro AR',
            'document_id' => $payment['id'],
            'document_number' => $payment['batch_number'] ?? null,
            'amount' => $this->money($payment['amount'] ?? 0),
            'method' => $payment['method'] ?? null,
            'method_label' => $this->methodLabel($payment['method'] ?? null),
            'direction' => $direction === 'outgoing' ? 'out' : 'in',
            'occurred_at' => $this->date($payment['paid_at'] ?? $payment['created_at'] ?? null),
            'actor_id' => $payment['creator']['id'] ?? null,
            'actor_name' => $payment['creator']['name'] ?? null,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'reference' => $payment['reference_number'] ?? $payment['batch_number'] ?? null,
            'notes' => $payment['notes'] ?? null,
            'href' => $customer ? "/ar?customer_id={$customer->id}" : ($supplier ? "/accounts-payable?supplier_id={$supplier->id}" : null),
            'is_cash_counted' => $this->isCashCounted($payment['method'] ?? null),
            'source' => 'account_payment_batch',
            'applications' => $this->list($payment['applications'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function purchasePaymentDocument(array $payment): array
    {
        $purchaseOrder = $payment['purchase_order'] ?? null;
        $supplier = $purchaseOrder['supplier'] ?? null;

        return [
            'id' => 'ap-payment-'.$payment['id'],
            'type' => 'ap_payment',
            'type_label' => 'Pago AP',
            'document_id' => $payment['id'],
            'document_number' => $purchaseOrder['po_number'] ?? $payment['reference_number'] ?? null,
            'amount' => $this->money($payment['amount'] ?? 0),
            'method' => $payment['method'] ?? null,
            'method_label' => $this->methodLabel($payment['method'] ?? null),
            'direction' => 'out',
            'occurred_at' => $this->date($payment['paid_at'] ?? $payment['created_at'] ?? null),
            'actor_id' => $payment['user']['id'] ?? null,
            'actor_name' => $payment['user']['name'] ?? null,
            'customer_id' => null,
            'customer_name' => null,
            'supplier_id' => $supplier['id'] ?? null,
            'supplier_name' => $supplier['name'] ?? null,
            'reference' => $payment['reference_number'] ?? $purchaseOrder['po_number'] ?? null,
            'notes' => $payment['notes'] ?? null,
            'href' => isset($supplier['id']) ? "/accounts-payable?supplier_id={$supplier['id']}" : null,
            'is_cash_counted' => $this->isCashCounted($payment['method'] ?? null),
            'source' => 'purchase_order_payment',
            'purchase_order_id' => $payment['purchase_order_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalExpenseDocument(OperationalExpense $expense): array
    {
        return [
            'id' => 'operational-expense-'.$expense->id,
            'type' => 'operational_expense',
            'type_label' => 'Gasto operativo',
            'document_id' => $expense->id,
            'document_number' => $expense->reference_number,
            'amount' => $this->money($expense->amount),
            'method' => $expense->payment_method,
            'method_label' => $expense->payment_method_label,
            'direction' => 'out',
            'occurred_at' => $this->date($expense->created_at ?? $expense->expense_date),
            'actor_id' => $expense->created_by,
            'actor_name' => $expense->creator?->name,
            'customer_id' => null,
            'customer_name' => null,
            'supplier_id' => null,
            'supplier_name' => null,
            'reference' => $expense->description,
            'notes' => $expense->notes,
            'href' => '/operational-expenses',
            'is_cash_counted' => $this->isCashCounted($expense->payment_method),
            'source' => 'operational_expense',
            'category' => $expense->category,
            'category_label' => $expense->category_label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashMovementDocument(CashMovement $movement): array
    {
        return [
            'id' => 'cash-movement-'.$movement->id,
            'type' => 'cash_movement',
            'type_label' => $movement->type === 'out' ? 'Retiro de caja' : 'Ingreso de caja',
            'document_id' => $movement->id,
            'document_number' => null,
            'amount' => $this->money($movement->amount),
            'method' => 'cash',
            'method_label' => $this->methodLabel('cash'),
            'direction' => $movement->type === 'out' ? 'out' : 'in',
            'occurred_at' => $this->date($movement->created_at),
            'actor_id' => $movement->user_id,
            'actor_name' => $movement->user?->name,
            'customer_id' => null,
            'customer_name' => null,
            'supplier_id' => null,
            'supplier_name' => null,
            'reference' => $movement->reason,
            'notes' => $movement->notes,
            'href' => null,
            'is_cash_counted' => true,
            'source' => 'cash_movement',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function timeline(PosSession $session, Collection $documents): array
    {
        $events = collect([
            [
                'id' => 'opening-'.$session->id,
                'type' => 'opening',
                'label' => 'Apertura de caja',
                'amount' => $this->money($session->opening_amount),
                'direction' => 'in',
                'occurred_at' => $this->date($session->opened_at),
                'href' => null,
                'source' => 'pos_session',
            ],
        ]);

        $events = $events->merge($documents->map(fn (array $document): array => [
            'id' => 'event-'.$document['id'],
            'type' => $document['type'],
            'label' => $document['type_label'],
            'amount' => $document['amount'],
            'direction' => $document['direction'],
            'occurred_at' => $document['occurred_at'],
            'href' => $document['href'],
            'source' => $document['source'],
            'document' => [
                'id' => $document['document_id'],
                'number' => $document['document_number'],
                'reference' => $document['reference'],
            ],
        ]));

        if ($session->closed_at) {
            $events->push([
                'id' => 'closing-'.$session->id,
                'type' => 'closing',
                'label' => 'Cierre de caja',
                'amount' => $this->money($session->closing_amount),
                'direction' => 'out',
                'occurred_at' => $this->date($session->closed_at),
                'href' => null,
                'source' => 'pos_session',
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => filled($event['occurred_at'] ?? null))
            ->sort($this->sortByOccurredAt(...))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sortByOccurredAt(array $left, array $right): int
    {
        $dateComparison = ((string) ($left['occurred_at'] ?? '')) <=> ((string) ($right['occurred_at'] ?? ''));

        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        return ((string) ($left['id'] ?? '')) <=> ((string) ($right['id'] ?? ''));
    }

    private function methodLabel(?string $method): ?string
    {
        return $method ? PaymentMethod::getNameByCode($method) : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountPayments
     * @return Collection<int, Customer>
     */
    private function customersFor(Collection $accountPayments): Collection
    {
        $ids = $accountPayments
            ->filter(fn (array $payment): bool => ($payment['account_type'] ?? null) === 'customer')
            ->pluck('account_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Customer::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accountPayments
     * @return Collection<int, Supplier>
     */
    private function suppliersFor(Collection $accountPayments): Collection
    {
        $ids = $accountPayments
            ->filter(fn (array $payment): bool => ($payment['account_type'] ?? null) === 'supplier')
            ->pluck('account_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Supplier::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        if ($value instanceof Collection) {
            return $value->values()->all();
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    private function isCashCounted(?string $method): bool
    {
        return $method === 'cash';
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toJSON();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->toJSON();
        }

        return null;
    }
}
