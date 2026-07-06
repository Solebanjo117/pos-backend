<?php

namespace App\Services;

use App\Models\AccountLedgerEntry;
use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountLedgerService
{
    /**
     * @return array<string, mixed>
     */
    public function receivablesSummary(array $filters = []): array
    {
        $query = Customer::query()
            ->where('current_balance', '>', 0);

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $summaryRows = (clone $query)
            ->orderByDesc('current_balance')
            ->get()
            ->map(fn (Customer $customer): array => $this->receivableCustomerRow($customer, $filters))
            ->filter(fn (array $customer): bool => $customer['open_documents_count'] > 0)
            ->values();

        $customers = $query->orderByDesc('current_balance')
            ->paginate($filters['per_page'] ?? 50);

        $rows = $customers->getCollection()
            ->map(fn (Customer $customer): array => $this->receivableCustomerRow($customer, $filters))
            ->filter(fn (array $customer): bool => $customer['open_documents_count'] > 0)
            ->values();

        return [
            'data' => $rows,
            'summary' => $this->receivablesDashboardSummary($summaryRows, $filters),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerStatement(Customer $customer, array $filters = []): array
    {
        $documents = $this->openSalesOrders($customer, $filters)
            ->map(fn (SalesOrder $order): array => [
                'id' => $order->id,
                'document_type' => 'sales_order',
                'document_number' => $order->order_number,
                'date' => optional($order->date)->toDateString(),
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'amount_due' => (float) $order->amount_due,
                'due_date' => $this->salesOrderDueDate($order, $customer)?->toDateString(),
                'days_overdue' => $this->salesOrderDaysOverdue($order, $customer),
                'payment_status' => $order->payment_status,
                'status_label' => $this->receivableDocumentStatusLabel($order, $customer),
                'payment_summary' => $order->payments
                    ->groupBy('method')
                    ->map(fn ($payments, string $method): array => [
                        'method' => $method,
                        'method_label' => $payments->first()->formatted_method,
                        'amount' => round((float) $payments->sum('amount'), 2),
                        'count' => $payments->count(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();

        return [
            'account' => [
                'id' => $customer->id,
                'type' => 'customer',
                'name' => $customer->name,
                'code' => $customer->code,
                'current_balance' => (float) $customer->current_balance,
                'credit_limit' => (float) $customer->credit_limit,
                'available_credit' => $customer->getAvailableCredit(),
            ],
            'summary' => [
                'total_open_balance' => round((float) $documents->sum('amount_due'), 2),
                'total_billed' => round((float) $documents->sum('total'), 2),
                'total_paid' => round((float) $documents->sum('amount_paid'), 2),
                'open_documents_count' => $documents->count(),
                'aging_buckets' => $this->customerReceivableAgingBuckets($this->openSalesOrders($customer, $filters)),
            ],
            'documents' => $documents,
            'entries' => $this->ledgerEntries('customer', $customer->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordCustomerReceipt(Customer $customer, array $data): array
    {
        return DB::transaction(function () use ($customer, $data): array {
            $amount = round((float) $data['amount'], 2);
            $applications = $this->normalizeCustomerApplications($customer, $data['applications'] ?? [], $amount);
            $appliedTotal = round(array_sum(array_column($applications, 'amount')), 2);
            $session = $this->openSessionForCurrentWarehouse();

            if (! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar cobros.');
            }

            if ($appliedTotal > $amount + 0.01) {
                throw new \InvalidArgumentException('El monto aplicado excede el monto del recibo.');
            }

            $batch = $this->createPaymentBatch('customer', $customer->id, 'incoming', $amount, array_merge($data, [
                'pos_session_id' => $session->id,
            ]));

            foreach ($applications as $application) {
                $order = SalesOrder::with(['payments', 'customer'])->findOrFail($application['document_id']);
                $order->addPayment(
                    amount: (float) $application['amount'],
                    method: $data['method'],
                    notes: $data['notes'] ?? "Recibo {$batch->batch_number}",
                    paidAt: $data['paid_at'] ?? now(),
                    referenceNumber: $data['reference_number'] ?? $batch->batch_number,
                );

                $batch->applications()->create([
                    'document_type' => 'sales_order',
                    'document_id' => $order->id,
                    'document_number' => $order->order_number,
                    'amount' => $application['amount'],
                ]);
            }

            $customer->refresh();
            $entry = $this->createLedgerEntry([
                'account_type' => 'customer',
                'account_id' => $customer->id,
                'movement_type' => 'receipt',
                'payment_batch_id' => $batch->id,
                'credit' => $amount,
                'balance_after' => (float) $customer->current_balance,
                'occurred_at' => $data['paid_at'] ?? now(),
                'reference_number' => $data['reference_number'] ?? $batch->batch_number,
                'notes' => $data['notes'] ?? null,
            ]);

            activity()
                ->performedOn($customer)
                ->withProperties(['batch_id' => $batch->id, 'ledger_entry_id' => $entry->id])
                ->log('Cobro de cuenta por cobrar registrado');

            return [
                'batch' => $batch->load('applications'),
                'ledger_entry' => $entry,
                'statement' => $this->customerStatement($customer->fresh()),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payablesSummary(array $filters = []): array
    {
        $suppliers = Supplier::whereHas('purchaseOrders')
            ->get()
            ->map(function (Supplier $supplier) use ($filters): array {
                $orders = $this->openPurchaseOrders($supplier, $filters);
                $totalOrdersAmount = $orders->sum('total');
                $totalPaid = $orders->sum(fn (PurchaseOrder $order): float => (float) $order->amount_paid);
                $totalDebt = max(0, (float) $totalOrdersAmount - (float) $totalPaid);

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'pending_orders_count' => $orders->count(),
                    'total_orders_amount' => round((float) $totalOrdersAmount, 2),
                    'total_paid' => round((float) $totalPaid, 2),
                    'total_debt' => round($totalDebt, 2),
                ];
            })
            ->filter(fn (array $supplier): bool => $supplier['total_debt'] > 0);

        if (! empty($filters['search'])) {
            $search = Str::lower((string) $filters['search']);
            $suppliers = $suppliers->filter(fn (array $supplier): bool => str_contains(Str::lower($supplier['name']), $search));
        }

        return ['data' => $suppliers->sortByDesc('total_debt')->values()];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierStatement(Supplier $supplier, array $filters = []): array
    {
        $documents = $this->openPurchaseOrders($supplier, $filters)
            ->map(fn (PurchaseOrder $order): array => [
                'id' => $order->id,
                'document_type' => 'purchase_order',
                'document_number' => $order->po_number,
                'supplier_invoice_number' => $order->supplier_invoice_number,
                'date' => optional($order->order_date)->toDateString(),
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'amount_due' => (float) $order->amount_due,
                'payment_status' => $order->payment_status,
            ])
            ->values();

        return [
            'account' => [
                'id' => $supplier->id,
                'type' => 'supplier',
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
            ],
            'summary' => [
                'open_documents_count' => $documents->count(),
                'total_open_balance' => round((float) $documents->sum('amount_due'), 2),
                'total_documents_amount' => round((float) $documents->sum('total'), 2),
                'total_paid' => round((float) $documents->sum('amount_paid'), 2),
            ],
            'documents' => $documents,
            'entries' => $this->ledgerEntries('supplier', $supplier->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordSupplierPayment(Supplier $supplier, array $data): array
    {
        return DB::transaction(function () use ($supplier, $data): array {
            $amount = round((float) $data['amount'], 2);
            $applications = $this->normalizeSupplierApplications($supplier, $data['applications'] ?? [], $amount);
            $appliedTotal = round(array_sum(array_column($applications, 'amount')), 2);
            $session = $this->resolveSupplierPaymentSession($applications, $data['pos_session_id'] ?? null);

            if ($appliedTotal > $amount + 0.01) {
                throw new \InvalidArgumentException('El monto aplicado excede el monto del pago.');
            }

            if (! $session) {
                throw new \InvalidArgumentException('Debe existir una sesion de caja abierta para esta sucursal antes de registrar pagos de compra.');
            }

            $cashMovement = null;
            if (($data['method'] ?? null) === 'cash') {
                $cashReason = 'Pago proveedor '.$supplier->name;
                if (count($applications) === 1) {
                    $cashOrder = PurchaseOrder::query()->find($applications[0]['document_id']);
                    $cashReason = $cashOrder ? 'Pago OC '.$cashOrder->po_number : $cashReason;
                }

                $cashMovement = CashMovement::create([
                    'pos_session_id' => $session->id,
                    'user_id' => Auth::id(),
                    'type' => 'out',
                    'amount' => $amount,
                    'reason' => $cashReason,
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            $batch = $this->createPaymentBatch('supplier', $supplier->id, 'outgoing', $amount, array_merge($data, [
                'pos_session_id' => $session->id,
                'cash_movement_id' => $cashMovement?->id,
            ]));

            foreach ($applications as $application) {
                $order = PurchaseOrder::with('payments')->findOrFail($application['document_id']);
                $payment = $order->addPayment(
                    (float) $application['amount'],
                    $data['method'],
                    $data['reference_number'] ?? $batch->batch_number,
                    $data['notes'] ?? "Pago {$batch->batch_number}",
                );

                if (! empty($data['paid_at'])) {
                    $payment->update(['paid_at' => $data['paid_at']]);
                }

                $payment->update([
                    'pos_session_id' => $session->id,
                    'cash_movement_id' => $cashMovement?->id,
                ]);

                $batch->applications()->create([
                    'document_type' => 'purchase_order',
                    'document_id' => $order->id,
                    'document_number' => $order->po_number,
                    'amount' => $application['amount'],
                ]);
            }

            $entry = $this->createLedgerEntry([
                'account_type' => 'supplier',
                'account_id' => $supplier->id,
                'movement_type' => 'payment',
                'payment_batch_id' => $batch->id,
                'debit' => $amount,
                'balance_after' => $this->supplierBalance($supplier),
                'occurred_at' => $data['paid_at'] ?? now(),
                'reference_number' => $data['reference_number'] ?? $batch->batch_number,
                'notes' => $data['notes'] ?? null,
            ]);

            activity()
                ->performedOn($supplier)
                ->withProperties(['batch_id' => $batch->id, 'ledger_entry_id' => $entry->id])
                ->log('Pago de cuenta por pagar registrado');

            return [
                'batch' => $batch->load('applications'),
                'ledger_entry' => $entry,
                'statement' => $this->supplierStatement($supplier->fresh()),
            ];
        });
    }

    public function reverseSupplierPaymentForPurchaseOrderPayment(PurchaseOrder $purchaseOrder, PurchaseOrderPayment $payment): void
    {
        DB::transaction(function () use ($purchaseOrder, $payment): void {
            $batch = AccountPaymentBatch::query()
                ->where('account_type', 'supplier')
                ->where('account_id', $purchaseOrder->supplier_id)
                ->where('direction', 'outgoing')
                ->where('method', $payment->method)
                ->where('amount', $payment->amount)
                ->whereHas('applications', function ($query) use ($purchaseOrder, $payment): void {
                    $query->where('document_type', 'purchase_order')
                        ->where('document_id', $purchaseOrder->id)
                        ->where('amount', $payment->amount);
                })
                ->latest('id')
                ->first();

            if (! $batch) {
                $payment->cashMovement?->delete();
                $payment->delete();
                $purchaseOrder->recalculatePaymentStatus();

                return;
            }

            if ($batch->applications()->count() > 1) {
                throw new \InvalidArgumentException('Este pago pertenece a una aplicacion multiple. Reversa el pago desde credito compra.');
            }

            if ($batch->posSession && $batch->posSession->status !== 'open') {
                throw new \InvalidArgumentException('No se puede reversar un pago ligado a una caja cerrada.');
            }

            if ($batch->cashMovement?->posSession && $batch->cashMovement->posSession->status !== 'open') {
                throw new \InvalidArgumentException('No se puede reversar un movimiento de una caja cerrada.');
            }

            $batch->ledgerEntries()->delete();
            $batch->applications()->delete();
            $batch->cashMovement?->delete();
            $payment->delete();
            $batch->delete();
            $purchaseOrder->recalculatePaymentStatus();

            activity()
                ->performedOn($purchaseOrder)
                ->withProperties(['payment_id' => $payment->id, 'batch_id' => $batch->id])
                ->log('Pago de cuenta por pagar reversado');
        });
    }

    private function createPaymentBatch(string $accountType, int $accountId, string $direction, float $amount, array $data): AccountPaymentBatch
    {
        $context = $accountType === 'supplier'
            ? PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS
            : PaymentMethod::CONTEXT_CUSTOMER_RECEIPTS;
        $method = (string) ($data['method'] ?? '');

        if (! in_array($method, PaymentMethod::realMoneyCodes($context), true)) {
            throw new \InvalidArgumentException('El metodo debe ser un pago real.');
        }

        return AccountPaymentBatch::create([
            'account_type' => $accountType,
            'account_id' => $accountId,
            'pos_session_id' => $data['pos_session_id'] ?? null,
            'cash_movement_id' => $data['cash_movement_id'] ?? null,
            'direction' => $direction,
            'batch_number' => $this->nextBatchNumber($accountType),
            'amount' => $amount,
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
            'created_by' => Auth::id(),
        ]);
    }

    private function openSessionForCurrentWarehouse(): ?PosSession
    {
        $warehouseId = Auth::user()?->current_warehouse_id;

        if (! $warehouseId) {
            return null;
        }

        return PosSession::query()
            ->where('user_id', Auth::id())
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'open')
            ->latest()
            ->first();
    }

    /**
     * @param  array<int, array{document_id: int, amount: float}>  $applications
     */
    private function openSessionForSupplierApplications(array $applications): ?PosSession
    {
        $warehouseIds = PurchaseOrder::query()
            ->whereIn('id', collect($applications)->pluck('document_id')->all())
            ->pluck('warehouse_id')
            ->unique()
            ->values();

        if ($warehouseIds->count() !== 1) {
            return null;
        }

        return PosSession::query()
            ->where('user_id', Auth::id())
            ->where('warehouse_id', $warehouseIds->first())
            ->where('status', 'open')
            ->latest()
            ->first();
    }

    /**
     * @param  array<int, array{document_id: int, amount: float}>  $applications
     */
    private function resolveSupplierPaymentSession(array $applications, mixed $posSessionId = null): ?PosSession
    {
        if (! $posSessionId) {
            return $this->openSessionForSupplierApplications($applications);
        }

        $session = PosSession::query()
            ->where('id', (int) $posSessionId)
            ->where('user_id', Auth::id())
            ->where('status', 'open')
            ->first();

        if (! $session) {
            return null;
        }

        $warehouseIds = PurchaseOrder::query()
            ->whereIn('id', collect($applications)->pluck('document_id')->all())
            ->pluck('warehouse_id')
            ->unique()
            ->values();

        if ($warehouseIds->count() !== 1 || (int) $warehouseIds->first() !== (int) $session->warehouse_id) {
            return null;
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLedgerEntry(array $attributes): AccountLedgerEntry
    {
        return AccountLedgerEntry::create(array_merge([
            'debit' => 0,
            'credit' => 0,
            'created_by' => Auth::id(),
        ], $attributes));
    }

    private function nextBatchNumber(string $accountType): string
    {
        $prefix = $accountType === 'customer' ? 'REC' : 'PAG';

        return $prefix.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }

    /**
     * @return array<int, array{document_id: int, amount: float}>
     */
    private function normalizeCustomerApplications(Customer $customer, array $applications, float $amount): array
    {
        if (! empty($applications)) {
            return collect($applications)
                ->map(fn (array $application): array => [
                    'document_id' => (int) $application['document_id'],
                    'amount' => round((float) $application['amount'], 2),
                ])
                ->values()
                ->all();
        }

        return $this->autoApplyDocuments($this->openSalesOrders($customer), $amount);
    }

    /**
     * @return array<int, array{document_id: int, amount: float}>
     */
    private function normalizeSupplierApplications(Supplier $supplier, array $applications, float $amount): array
    {
        if (! empty($applications)) {
            return collect($applications)
                ->map(fn (array $application): array => [
                    'document_id' => (int) $application['document_id'],
                    'amount' => round((float) $application['amount'], 2),
                ])
                ->values()
                ->all();
        }

        return $this->autoApplyDocuments($this->openPurchaseOrders($supplier), $amount);
    }

    /**
     * @param  Collection<int, SalesOrder|PurchaseOrder>  $documents
     * @return array<int, array{document_id: int, amount: float}>
     */
    private function autoApplyDocuments(Collection $documents, float $amount): array
    {
        $remaining = $amount;
        $applications = [];

        foreach ($documents as $document) {
            if ($remaining <= 0) {
                break;
            }

            $due = (float) $document->amount_due;
            if ($due <= 0) {
                continue;
            }

            $applied = round(min($due, $remaining), 2);
            $applications[] = [
                'document_id' => $document->id,
                'amount' => $applied,
            ];
            $remaining = round($remaining - $applied, 2);
        }

        if (empty($applications)) {
            throw new \InvalidArgumentException('No hay documentos pendientes para aplicar el pago.');
        }

        return $applications;
    }

    /**
     * @return Collection<int, SalesOrder>
     */
    private function openSalesOrders(Customer $customer, array $filters = []): Collection
    {
        return $customer->salesOrders()
            ->with(['payments', 'returns'])
            ->whereIn('payment_status', ['pending', 'partial'])
            ->when(! empty($filters['date_from']), function ($query) use ($filters): void {
                $query->whereDate('date', '>=', $filters['date_from']);
            })
            ->when(! empty($filters['date_to']), function ($query) use ($filters): void {
                $query->whereDate('date', '<=', $filters['date_to']);
            })
            ->when(! empty($filters['warehouse_id']), function ($query) use ($filters): void {
                $query->whereHas('items', function ($query) use ($filters): void {
                    $query->where('warehouse_id', $filters['warehouse_id']);
                });
            })
            ->orderBy('date')
            ->get()
            ->filter(fn (SalesOrder $order): bool => (float) $order->amount_due > 0)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function receivableCustomerRow(Customer $customer, array $filters): array
    {
        $documents = $this->openSalesOrders($customer, $filters);
        $openBalance = round((float) $documents->sum(fn (SalesOrder $order): float => (float) $order->amount_due), 2);
        $maxDaysOverdue = $this->maxReceivableDaysOverdue($documents);

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'initials' => $this->customerInitials($customer->name),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'current_balance' => $openBalance,
            'credit_limit' => (float) $customer->credit_limit,
            'available_credit' => $customer->getAvailableCredit(),
            'credit_used_percent' => $this->creditUsedPercent($openBalance, (float) $customer->credit_limit),
            'open_documents_count' => $documents->count(),
            'total_billed' => round((float) $documents->sum('total'), 2),
            'total_paid' => round((float) $documents->sum(fn (SalesOrder $order): float => (float) $order->amount_paid), 2),
            'last_payment_days' => $this->lastCustomerPaymentDays($customer),
            'max_days_overdue' => $maxDaysOverdue,
            'reliability_score' => $this->receivableReliabilityScore($maxDaysOverdue),
            'can_register_payment' => $openBalance > 0,
            'status' => $this->receivableCustomerStatus($documents),
            'aging_buckets' => $this->customerReceivableAgingBuckets($documents),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function receivablesDashboardSummary(\Illuminate\Support\Collection $rows, array $filters): array
    {
        $aging = $this->emptyReceivableAgingBuckets();

        foreach ($rows as $row) {
            foreach ($row['aging_buckets'] as $key => $bucket) {
                $aging[$key]['amount'] = round($aging[$key]['amount'] + (float) $bucket['amount'], 2);
                $aging[$key]['count'] += (int) $bucket['count'];
            }
        }

        $totalReceivable = round((float) $rows->sum('current_balance'), 2);
        $creditsGranted = $this->receivableCreditsGranted($filters);
        $collectionsReceived = $this->receivableCollectionsReceived($filters);
        $monthlyTrend = $this->receivableMonthlyTrend($filters);

        return [
            'total_receivable' => $totalReceivable,
            'active_customers' => $rows->count(),
            'open_documents_count' => (int) $rows->sum('open_documents_count'),
            'credits_granted' => $creditsGranted,
            'collections_received' => $collectionsReceived,
            'aging_buckets' => $aging,
            'aging_segments' => $this->receivableAgingSegments($aging, $totalReceivable),
            'portfolio_metrics' => $this->receivablePortfolioMetrics($aging, $totalReceivable, $creditsGranted, $collectionsReceived, $monthlyTrend),
            'monthly_trend' => $monthlyTrend,
            'recent_movements' => $this->receivableRecentMovements($filters),
            'over_90_amount' => $aging['90_plus']['amount'],
            'over_90_count' => $aging['90_plus']['count'],
        ];
    }

    private function receivableCreditsGranted(array $filters): float
    {
        $query = SalesOrder::query()
            ->where('payment_method', 'credit')
            ->whereNotIn('status', ['cancelled']);

        $this->applyReceivableOrderFilters($query, $filters);

        return round((float) $query->sum('total'), 2);
    }

    private function receivableCollectionsReceived(array $filters): float
    {
        $query = AccountPaymentBatch::query()
            ->where('account_type', 'customer')
            ->where('direction', 'incoming');

        if (! empty($filters['date_from'])) {
            $query->whereDate('paid_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('paid_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['warehouse_id'])) {
            $orderIds = SalesOrder::query()
                ->whereHas('items', function ($query) use ($filters): void {
                    $query->where('warehouse_id', $filters['warehouse_id']);
                })
                ->pluck('id');

            $query->whereHas('applications', function ($query) use ($orderIds): void {
                $query->where('document_type', 'sales_order')
                    ->whereIn('document_id', $orderIds);
            });
        }

        return round((float) $query->sum('amount'), 2);
    }

    private function applyReceivableOrderFilters($query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('items', function ($query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            });
        }
    }

    /**
     * @param  array<string, array{label: string, amount: float, count: int}>  $aging
     * @return array<int, array<string, mixed>>
     */
    private function receivableAgingSegments(array $aging, float $totalReceivable): array
    {
        $recoveryByBucket = [
            'current' => 99,
            '1_30' => 95,
            '31_60' => 85,
            '61_90' => 70,
            '90_plus' => 55,
        ];

        $riskByBucket = [
            'current' => 'Bajo',
            '1_30' => 'Medio',
            '31_60' => 'Alto',
            '61_90' => 'Alto',
            '90_plus' => 'Critico',
        ];

        return collect($aging)
            ->map(fn (array $bucket, string $key): array => [
                'key' => $key,
                'label' => $bucket['label'],
                'amount' => round((float) $bucket['amount'], 2),
                'count' => (int) $bucket['count'],
                'percentage' => $totalReceivable > 0 ? round(((float) $bucket['amount'] / $totalReceivable) * 100, 2) : 0.0,
                'expected_recovery_percent' => $recoveryByBucket[$key],
                'risk' => $riskByBucket[$key],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{label: string, amount: float, count: int}>  $aging
     * @param  array<int, array{month: string, credits: float, collections: float}>  $monthlyTrend
     * @return array<string, mixed>
     */
    private function receivablePortfolioMetrics(array $aging, float $totalReceivable, float $creditsGranted, float $collectionsReceived, array $monthlyTrend): array
    {
        $segments = $this->receivableAgingSegments($aging, $totalReceivable);
        $expectedRecovery = collect($segments)->sum(
            fn (array $segment): float => (float) $segment['amount'] * ((float) $segment['expected_recovery_percent'] / 100)
        );
        $latestMonth = collect($monthlyTrend)->last() ?? ['credits' => 0.0, 'collections' => 0.0];
        $latestCredits = (float) $latestMonth['credits'];
        $latestCollections = (float) $latestMonth['collections'];
        $healthyBalance = (float) ($aging['current']['amount'] + $aging['1_30']['amount']);

        return [
            'dso_days' => ($creditsGranted + $collectionsReceived) > 0
                ? (int) round(($totalReceivable / ($creditsGranted + $collectionsReceived)) * 30)
                : 0,
            'expected_recovery_amount' => round($expectedRecovery, 2),
            'expected_recovery_percent' => $totalReceivable > 0 ? round(($expectedRecovery / $totalReceivable) * 100, 2) : 0.0,
            'portfolio_health_percent' => $totalReceivable > 0 ? round(($healthyBalance / $totalReceivable) * 100, 2) : 100.0,
            'collection_efficiency_percent' => $latestCredits > 0 ? round(($latestCollections / $latestCredits) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array<int, array{month: string, credits: float, collections: float}>
     */
    private function receivableMonthlyTrend(array $filters): array
    {
        $end = CarbonImmutable::today()->startOfMonth();
        $start = $end->subMonths(5);

        return collect(range(0, 5))
            ->map(function (int $offset) use ($start, $filters): array {
                $month = $start->addMonths($offset);
                $monthStart = $month->startOfMonth();
                $monthEnd = $month->endOfMonth();

                return [
                    'month' => Str::of($monthStart->locale('es')->isoFormat('MMM'))->replace('.', '')->ucfirst()->toString(),
                    'credits' => $this->receivableCreditsForPeriod($monthStart, $monthEnd, $filters),
                    'collections' => $this->receivableCollectionsForPeriod($monthStart, $monthEnd, $filters),
                ];
            })
            ->values()
            ->all();
    }

    private function receivableCreditsForPeriod(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, array $filters): float
    {
        $query = SalesOrder::query()
            ->where('payment_method', 'credit')
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('date', '>=', $dateFrom->toDateString())
            ->whereDate('date', '<=', $dateTo->toDateString());

        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('items', function ($query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            });
        }

        return round((float) $query->sum('total'), 2);
    }

    private function receivableCollectionsForPeriod(CarbonImmutable $dateFrom, CarbonImmutable $dateTo, array $filters): float
    {
        $query = AccountPaymentBatch::query()
            ->where('account_type', 'customer')
            ->where('direction', 'incoming')
            ->whereDate('paid_at', '>=', $dateFrom->toDateString())
            ->whereDate('paid_at', '<=', $dateTo->toDateString());

        $this->applyReceivableBatchWarehouseFilter($query, $filters);

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableRecentMovements(array $filters): array
    {
        $creditMovements = $this->receivableRecentCreditMovements($filters);
        $paymentMovements = $this->receivableRecentPaymentMovements($filters);

        return collect($creditMovements)
            ->merge($paymentMovements)
            ->sortByDesc('occurred_at')
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableRecentCreditMovements(array $filters): array
    {
        $query = SalesOrder::query()
            ->with(['customer:id,code,name', 'user:id,name'])
            ->where('payment_method', 'credit')
            ->whereNotIn('status', ['cancelled'])
            ->latest('date')
            ->latest('id')
            ->limit(20);

        $this->applyReceivableOrderFilters($query, $filters);

        return $query->get()
            ->map(fn (SalesOrder $order): array => [
                'id' => 'credit-'.$order->id,
                'type' => 'credit',
                'description' => 'Credito otorgado',
                'customer' => [
                    'id' => $order->customer?->id,
                    'name' => $order->customer?->name,
                    'code' => $order->customer?->code,
                ],
                'document_number' => $order->order_number,
                'amount' => round((float) $order->total, 2),
                'method' => 'credit',
                'method_label' => 'Credito',
                'reference_number' => $order->order_number,
                'processed_by' => $order->user?->name,
                'occurred_at' => $order->date?->startOfDay()->toISOString(),
                'status' => 'Completado',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableRecentPaymentMovements(array $filters): array
    {
        $query = AccountPaymentBatch::query()
            ->with(['creator:id,name', 'applications'])
            ->where('account_type', 'customer')
            ->where('direction', 'incoming')
            ->latest('paid_at')
            ->latest('id')
            ->limit(20);

        if (! empty($filters['date_from'])) {
            $query->whereDate('paid_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('paid_at', '<=', $filters['date_to']);
        }

        $this->applyReceivableBatchWarehouseFilter($query, $filters);

        $batches = $query->get();
        $customers = Customer::query()
            ->whereIn('id', $batches->pluck('account_id')->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        return $batches
            ->map(function (AccountPaymentBatch $batch) use ($customers): array {
                $customer = $customers->get($batch->account_id);

                return [
                    'id' => 'payment-'.$batch->id,
                    'type' => 'payment',
                    'description' => 'Cobro recibido',
                    'customer' => [
                        'id' => $customer?->id,
                        'name' => $customer?->name,
                        'code' => $customer?->code,
                    ],
                    'document_number' => $batch->applications->first()?->document_number,
                    'amount' => round((float) $batch->amount, 2),
                    'method' => $batch->method,
                    'method_label' => PaymentMethod::getNameByCode($batch->method),
                    'reference_number' => $batch->reference_number ?? $batch->batch_number,
                    'processed_by' => $batch->creator?->name,
                    'occurred_at' => $batch->paid_at?->toISOString(),
                    'status' => 'Completado',
                ];
            })
            ->values()
            ->all();
    }

    private function applyReceivableBatchWarehouseFilter($query, array $filters): void
    {
        if (empty($filters['warehouse_id'])) {
            return;
        }

        $orderIds = SalesOrder::query()
            ->whereHas('items', function ($query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            })
            ->pluck('id');

        $query->whereHas('applications', function ($query) use ($orderIds): void {
            $query->where('document_type', 'sales_order')
                ->whereIn('document_id', $orderIds);
        });
    }

    private function customerInitials(string $name): string
    {
        $words = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->values();

        if ($words->isEmpty()) {
            return 'CL';
        }

        return $words
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    private function creditUsedPercent(float $openBalance, float $creditLimit): float
    {
        if ($creditLimit <= 0) {
            return $openBalance > 0 ? 100.0 : 0.0;
        }

        return round(min(100, ($openBalance / $creditLimit) * 100), 1);
    }

    private function maxReceivableDaysOverdue(Collection $documents): int
    {
        return (int) ($documents
            ->map(fn (SalesOrder $order): int => $this->salesOrderDaysOverdue($order, $order->customer))
            ->max() ?? 0);
    }

    private function receivableReliabilityScore(int $maxDaysOverdue): int
    {
        return match (true) {
            $maxDaysOverdue <= 0 => 100,
            $maxDaysOverdue <= 30 => 85,
            $maxDaysOverdue <= 60 => 70,
            $maxDaysOverdue <= 90 => 50,
            default => 25,
        };
    }

    /**
     * @return array<string, array{label: string, amount: float, count: int}>
     */
    private function customerReceivableAgingBuckets(Collection $documents): array
    {
        $buckets = $this->emptyReceivableAgingBuckets();

        foreach ($documents as $document) {
            $days = $this->salesOrderDaysOverdue($document, $document->customer);
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };

            $buckets[$key]['amount'] = round($buckets[$key]['amount'] + (float) $document->amount_due, 2);
            $buckets[$key]['count']++;
        }

        return $buckets;
    }

    /**
     * @return array<string, array{label: string, amount: float, count: int}>
     */
    private function emptyReceivableAgingBuckets(): array
    {
        return [
            'current' => ['label' => 'Corriente', 'amount' => 0.0, 'count' => 0],
            '1_30' => ['label' => '1-30 dias', 'amount' => 0.0, 'count' => 0],
            '31_60' => ['label' => '31-60 dias', 'amount' => 0.0, 'count' => 0],
            '61_90' => ['label' => '61-90 dias', 'amount' => 0.0, 'count' => 0],
            '90_plus' => ['label' => '+90 dias', 'amount' => 0.0, 'count' => 0],
        ];
    }

    private function receivableCustomerStatus(Collection $documents): string
    {
        $maxDays = $documents
            ->map(fn (SalesOrder $order): int => $this->salesOrderDaysOverdue($order, $order->customer))
            ->max() ?? 0;

        return match (true) {
            $maxDays > 30 => 'Vencido',
            $maxDays > 0 => 'Por Vencer',
            default => 'Corriente',
        };
    }

    private function receivableDocumentStatusLabel(SalesOrder $order, Customer $customer): string
    {
        $days = $this->salesOrderDaysOverdue($order, $customer);

        return match (true) {
            $days > 30 => 'Vencido',
            $days > 0 => 'Por vencer',
            default => 'Corriente',
        };
    }

    private function salesOrderDueDate(SalesOrder $order, ?Customer $customer): ?CarbonImmutable
    {
        if (! $order->date) {
            return null;
        }

        return CarbonImmutable::parse($order->date)->addDays((int) ($customer?->credit_days ?? 0));
    }

    private function salesOrderDaysOverdue(SalesOrder $order, ?Customer $customer): int
    {
        $dueDate = $this->salesOrderDueDate($order, $customer);

        if (! $dueDate) {
            return 0;
        }

        return (int) max(0, $dueDate->diffInDays(CarbonImmutable::today(), false));
    }

    private function lastCustomerPaymentDays(Customer $customer): ?int
    {
        $lastPayment = AccountPaymentBatch::query()
            ->where('account_type', 'customer')
            ->where('account_id', $customer->id)
            ->where('direction', 'incoming')
            ->latest('paid_at')
            ->value('paid_at');

        if (! $lastPayment) {
            return null;
        }

        return (int) CarbonImmutable::parse($lastPayment)->diffInDays(CarbonImmutable::today());
    }

    /**
     * @return Collection<int, PurchaseOrder>
     */
    private function openPurchaseOrders(Supplier $supplier, array $filters = []): Collection
    {
        return $supplier->purchaseOrders()
            ->with('payments')
            ->whereIn('payment_status', ['pending', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->when(! empty($filters['date_from']), function ($query) use ($filters): void {
                $query->whereDate('order_date', '>=', $filters['date_from']);
            })
            ->when(! empty($filters['date_to']), function ($query) use ($filters): void {
                $query->whereDate('order_date', '<=', $filters['date_to']);
            })
            ->when(! empty($filters['warehouse_id']), function ($query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            })
            ->orderBy('order_date')
            ->get()
            ->filter(fn (PurchaseOrder $order): bool => (float) $order->amount_due > 0)
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ledgerEntries(string $accountType, int $accountId): array
    {
        return AccountLedgerEntry::query()
            ->with(['paymentBatch.applications', 'creator:id,name'])
            ->where('account_type', $accountType)
            ->where('account_id', $accountId)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get()
            ->map(fn (AccountLedgerEntry $entry): array => [
                'id' => $entry->id,
                'movement_type' => $entry->movement_type,
                'document_type' => $entry->document_type,
                'document_id' => $entry->document_id,
                'document_number' => $entry->document_number,
                'payment_batch_id' => $entry->payment_batch_id,
                'batch_number' => $entry->paymentBatch?->batch_number,
                'business_datetime' => $entry->paymentBatch?->business_datetime?->toIso8601String(),
                'debit' => (float) $entry->debit,
                'credit' => (float) $entry->credit,
                'balance_after' => (float) $entry->balance_after,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'created_at' => $entry->paymentBatch?->created_at?->toIso8601String(),
                'reference_number' => $entry->reference_number,
                'notes' => $entry->notes,
                'created_by' => $entry->creator?->name,
                'applications' => $entry->paymentBatch?->applications ?? [],
            ])
            ->values()
            ->all();
    }

    private function supplierBalance(Supplier $supplier): float
    {
        return round((float) $this->openPurchaseOrders($supplier->fresh())->sum(fn (PurchaseOrder $order): float => (float) $order->amount_due), 2);
    }
}
