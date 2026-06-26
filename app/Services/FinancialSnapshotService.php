<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\CapitalMovement;
use App\Models\FinancialAdjustment;
use App\Models\FinancialDailySnapshot;
use App\Models\OperationalExpense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinancialSnapshotService
{
    private const SALES_STATUSES = ['confirmed', 'processing', 'completed'];

    private const PURCHASE_EXCLUDED_STATUSES = ['cancelled', 'draft'];

    private const NON_CASH_METHODS = ['credit', 'store_credit', 'supplier_credit'];

    public function computeDay(string|Carbon $date, ?int $warehouseId = null, bool $force = false): FinancialDailySnapshot
    {
        $day = Carbon::parse($date)->startOfDay();
        $dateString = $day->toDateString();
        $existing = $this->snapshotQuery($warehouseId)
            ->whereDate('date', $dateString)
            ->first();

        if ($existing && $existing->is_locked && ! $force) {
            return $existing;
        }

        // Reuse cached snapshots for closed past days to avoid recomputing the
        // whole history on every request. Past days are immutable in normal
        // operation; backdated writes (adjustments, capital movements, etc.)
        // force a rebuild from their date forward, so correctness is preserved.
        // Today (and any future day) is always recomputed because new
        // transactions can still land in it.
        if (
            $existing
            && ! $force
            && $existing->computed_at !== null
            && $day->lt(Carbon::today())
        ) {
            return $existing;
        }

        $opening = $this->openingBalancesFor($day, $warehouseId);
        $flows = $this->flowTotals($day, $day, $warehouseId);
        $payload = $this->snapshotPayload($dateString, $warehouseId, $opening, $flows);

        // Update the existing row by primary key when found. Relying on
        // updateOrCreate(['date' => $dateString]) is unreliable because the
        // stored value is a datetime (e.g. "2026-06-24 00:00:00"), so the
        // string equality match can miss and trigger a duplicate insert.
        if ($existing) {
            $existing->fill($payload)->save();

            return $existing->fresh();
        }

        return FinancialDailySnapshot::query()
            ->create(array_merge([
                'warehouse_id' => $warehouseId,
                'date' => $dateString,
            ], $payload))
            ->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function rangeSummary(array $filters, bool $forceRebuild = false): array
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();

        $this->rebuildRange($from->toDateString(), $to->toDateString(), $warehouseId, $forceRebuild);

        $snapshots = $this->snapshotQuery($warehouseId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get();

        $rows = $snapshots
            ->map(fn (FinancialDailySnapshot $snapshot): array => $this->dailyRow($snapshot))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => $this->rangeTotals($snapshots),
            'period' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'warehouse_id' => $warehouseId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function weeklySummary(array $filters, bool $forceRebuild = false): array
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $anchor = Carbon::parse($filters['week_start'] ?? $filters['date'] ?? now()->toDateString());
        $from = $anchor->copy()->startOfWeek(Carbon::MONDAY);
        $to = $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $summary = $this->rangeSummary([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'warehouse_id' => $warehouseId,
        ], $forceRebuild);

        $summary['week'] = [
            'week_start' => $from->toDateString(),
            'week_end' => $to->toDateString(),
        ];

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function balanceSheet(array $filters): array
    {
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $dateTo = Carbon::parse($filters['date_to'] ?? now()->toDateString())->startOfDay();

        $snapshot = $this->computeDay($dateTo, $warehouseId);
        $cash = $this->money($snapshot->closing_cash);
        $inventory = $this->money($snapshot->closing_inventory);
        $accountsReceivable = $this->money($snapshot->accounts_receivable);
        $accountsPayable = $this->money($snapshot->accounts_payable);
        $totalAssets = $this->money($cash + $inventory + $accountsReceivable);
        $totalLiabilities = $this->money($accountsPayable);

        return [
            'assets' => [
                'cash' => $cash,
                'cash_initial' => $this->money($snapshot->opening_cash),
                'inventory' => $inventory,
                'inventory_initial' => $this->money($snapshot->opening_inventory),
            ],
            'liabilities' => [
                'accounts_payable' => $accountsPayable,
                'accounts_receivable' => $accountsReceivable,
            ],
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'balance' => $this->money($totalAssets - $totalLiabilities),
            ],
            'as_of' => $dateTo->toDateString(),
            'warehouse_id' => $warehouseId,
        ];
    }

    public function rebuildRange(?string $dateFrom, ?string $dateTo, ?int $warehouseId = null, bool $force = false): int
    {
        $from = $dateFrom
            ? Carbon::parse($dateFrom)->startOfDay()
            : Carbon::parse($this->firstFinancialDate($warehouseId) ?? now()->toDateString())->startOfDay();
        $to = $dateTo ? Carbon::parse($dateTo)->startOfDay() : now()->startOfDay();
        $count = 0;

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $this->computeDay($cursor, $warehouseId, $force);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    public function storeCapitalMovement(array $data, ?int $userId = null): array
    {
        $movement = CapitalMovement::query()->create([
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'user_id' => $userId,
            'movement_type' => $data['movement_type'],
            'amount' => $this->money($data['amount']),
            'movement_date' => $data['movement_date'],
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
        ]);

        $this->rebuildRange($movement->movement_date->toDateString(), now()->toDateString(), $movement->warehouse_id, true);

        return $this->capitalMovementRow($movement->fresh());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCapitalMovements(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        return CapitalMovement::query()
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', (int) $warehouseId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_date', '<=', $date))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CapitalMovement $movement): array => $this->capitalMovementRow($movement))
            ->all();
    }

    private function openingBalancesFor(Carbon $day, ?int $warehouseId): array
    {
        $previous = $this->snapshotQuery($warehouseId)
            ->whereDate('date', '<', $day->toDateString())
            ->orderByDesc('date')
            ->first();

        if ($previous) {
            return [
                'cash' => $this->money($previous->closing_cash),
                'inventory' => $this->money($previous->closing_inventory),
                'accounts_receivable' => $this->money($previous->accounts_receivable),
                'accounts_payable' => $this->money($previous->accounts_payable),
            ];
        }

        $flows = $this->flowTotals(null, $day->copy()->subDay(), $warehouseId);

        return [
            'cash' => $this->money(
                $flows['cash_sales']
                - $flows['cash_purchases']
                - $flows['expenses']
                + $flows['investment_in']
                - $flows['investment_out']
                + $flows['customer_payments']
                - $flows['supplier_payments']
                + $flows['cash_adjustments']
            ),
            'inventory' => $this->money(
                $flows['cash_purchases']
                + $flows['credit_purchases']
                - $flows['cogs_cash_sales']
                - $flows['cogs_credit_sales']
                + $flows['inventory_adjustments']
            ),
            'accounts_receivable' => $this->money($flows['credit_sales'] - $flows['customer_payments']),
            'accounts_payable' => $this->money($flows['credit_purchases'] - $flows['supplier_payments']),
        ];
    }

    private function flowTotals(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();

        if ($from && $from->gt($to)) {
            return $flows;
        }

        foreach ([
            $this->salesOriginFlows($from, $to, $warehouseId),
            $this->purchaseOriginFlows($from, $to, $warehouseId),
            $this->expenseFlows($from, $to, $warehouseId),
            $this->customerPaymentFlows($from, $to, $warehouseId),
            $this->supplierPaymentFlows($from, $to, $warehouseId),
            $this->capitalFlows($from, $to, $warehouseId),
            $this->adjustmentFlows($from, $to, $warehouseId),
        ] as $partial) {
            foreach ($partial as $key => $value) {
                $flows[$key] = $this->money($flows[$key] + $value);
            }
        }

        return $flows;
    }

    private function salesOriginFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $orders = SalesOrder::query()
            ->with(['items', 'payments'])
            ->whereIn('status', self::SALES_STATUSES)
            ->whereDate('date', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('date', '>=', $from->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
            ->get();

        foreach ($orders as $order) {
            $orderDate = $order->date?->toDateString();
            if (! $orderDate) {
                continue;
            }

            $scopedTotal = $this->salesOrderScopedTotal($order, $warehouseId);
            if ($scopedTotal <= 0) {
                continue;
            }

            $ratio = $this->salesOrderWarehouseRatio($order, $warehouseId, $scopedTotal);
            $sameDayRealPayments = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => $this->isRealPaymentMethod($payment->method)
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio);
            $creditMarkers = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => $payment->method === 'credit')
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio);

            $cashSales = min($scopedTotal, $sameDayRealPayments);
            $creditSales = $creditMarkers > 0
                ? min(max($scopedTotal - $cashSales, 0), $creditMarkers)
                : max($scopedTotal - $cashSales, 0);
            $cogs = $this->salesOrderScopedCogs($order, $warehouseId);
            $creditRatio = $scopedTotal > 0 ? min(1, $creditSales / $scopedTotal) : 0;
            $cogsCredit = $this->money($cogs * $creditRatio);

            $flows['cash_sales'] = $this->money($flows['cash_sales'] + $cashSales);
            $flows['credit_sales'] = $this->money($flows['credit_sales'] + $creditSales);
            $flows['cogs_credit_sales'] = $this->money($flows['cogs_credit_sales'] + $cogsCredit);
            $flows['cogs_cash_sales'] = $this->money($flows['cogs_cash_sales'] + max(0, $cogs - $cogsCredit));
        }

        return $flows;
    }

    private function purchaseOriginFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $orders = PurchaseOrder::query()
            ->with('payments')
            ->whereNotIn('status', self::PURCHASE_EXCLUDED_STATUSES)
            ->whereDate('order_date', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('order_date', '>=', $from->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->get();

        foreach ($orders as $order) {
            $orderDate = $order->order_date?->toDateString();
            if (! $orderDate) {
                continue;
            }

            $total = $this->money($order->total);
            $sameDayRealPayments = $order->payments
                ->filter(fn (PurchaseOrderPayment $payment): bool => $this->isRealPaymentMethod($payment->method)
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (PurchaseOrderPayment $payment): float => (float) $payment->amount);
            $cashPurchases = min($total, $sameDayRealPayments);

            $flows['cash_purchases'] = $this->money($flows['cash_purchases'] + $cashPurchases);
            $flows['credit_purchases'] = $this->money($flows['credit_purchases'] + max(0, $total - $cashPurchases));
        }

        return $flows;
    }

    private function expenseFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $flows['expenses'] = $this->money(
            OperationalExpense::query()
                ->whereDate('expense_date', '<=', $to->toDateString())
                ->when($from, fn (Builder $query) => $query->whereDate('expense_date', '>=', $from->toDateString()))
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('warehouse_id')
                    ->orWhere('warehouse_id', $warehouseId)))
                ->sum('amount')
        );

        return $flows;
    }

    private function customerPaymentFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $batches = AccountPaymentBatch::query()
            ->with('applications')
            ->where('account_type', 'customer')
            ->where('direction', 'incoming')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->get();

        foreach ($batches as $batch) {
            $paidDate = $batch->paid_at?->toDateString();
            $applied = 0.0;

            foreach ($batch->applications as $application) {
                if ($application->document_type !== 'sales_order') {
                    continue;
                }

                $order = SalesOrder::query()->with('items')->find($application->document_id);
                if (! $order || $order->date?->toDateString() === $paidDate) {
                    continue;
                }

                $scopedTotal = $this->salesOrderScopedTotal($order, $warehouseId);
                if ($scopedTotal <= 0) {
                    continue;
                }

                $ratio = $this->salesOrderWarehouseRatio($order, $warehouseId, $scopedTotal);
                $applied += (float) $application->amount * $ratio;
            }

            if ($batch->applications->isEmpty() && $this->batchBelongsToWarehouse($batch, $warehouseId)) {
                $applied += (float) $batch->amount;
            }

            $flows['customer_payments'] = $this->money($flows['customer_payments'] + $applied);
        }

        $flows['customer_payments'] = $this->money(
            $flows['customer_payments'] + $this->directCustomerlessPayments($from, $to, $warehouseId)
        );

        return $flows;
    }

    private function supplierPaymentFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $batches = AccountPaymentBatch::query()
            ->with('applications')
            ->where('account_type', 'supplier')
            ->where('direction', 'outgoing')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->get();

        foreach ($batches as $batch) {
            $paidDate = $batch->paid_at?->toDateString();
            $applied = 0.0;

            foreach ($batch->applications as $application) {
                if ($application->document_type !== 'purchase_order') {
                    continue;
                }

                $order = PurchaseOrder::query()->find($application->document_id);
                if (! $order || $order->order_date?->toDateString() === $paidDate) {
                    continue;
                }

                if ($warehouseId && (int) $order->warehouse_id !== $warehouseId) {
                    continue;
                }

                $applied += (float) $application->amount;
            }

            if ($batch->applications->isEmpty() && $this->batchBelongsToWarehouse($batch, $warehouseId)) {
                $applied += (float) $batch->amount;
            }

            $flows['supplier_payments'] = $this->money($flows['supplier_payments'] + $applied);
        }

        $flows['supplier_payments'] = $this->money(
            $flows['supplier_payments'] + $this->directSupplierlessPayments($from, $to, $warehouseId)
        );

        return $flows;
    }

    private function capitalFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $movements = CapitalMovement::query()
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('movement_date', '>=', $from->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('warehouse_id')
                ->orWhere('warehouse_id', $warehouseId)))
            ->get();

        foreach ($movements as $movement) {
            $key = $movement->movement_type === CapitalMovement::TYPE_IN ? 'investment_in' : 'investment_out';
            $flows[$key] = $this->money($flows[$key] + (float) $movement->amount);
        }

        return $flows;
    }

    private function adjustmentFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $adjustments = FinancialAdjustment::query()
            ->whereDate('adjustment_date', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('adjustment_date', '>=', $from->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('warehouse_id')
                ->orWhere('warehouse_id', $warehouseId)))
            ->get();

        foreach ($adjustments as $adjustment) {
            $key = $adjustment->type === FinancialAdjustment::TYPE_CASH ? 'cash_adjustments' : 'inventory_adjustments';
            $flows[$key] = $this->money($flows[$key] + (float) $adjustment->amount);
        }

        return $flows;
    }

    private function directCustomerlessPayments(?Carbon $from, Carbon $to, ?int $warehouseId): float
    {
        $payments = SalesOrderPayment::query()
            ->with('salesOrder.items')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->whereHas('salesOrder', fn (Builder $query) => $query->whereNull('customer_id'))
            ->get();
        $total = 0.0;

        foreach ($payments as $payment) {
            $order = $payment->salesOrder;
            if (! $order || $payment->paid_at?->toDateString() === $order->date?->toDateString()) {
                continue;
            }

            $scopedTotal = $this->salesOrderScopedTotal($order, $warehouseId);
            if ($scopedTotal <= 0) {
                continue;
            }

            $total += (float) $payment->amount * $this->salesOrderWarehouseRatio($order, $warehouseId, $scopedTotal);
        }

        return $this->money($total);
    }

    private function directSupplierlessPayments(?Carbon $from, Carbon $to, ?int $warehouseId): float
    {
        $payments = PurchaseOrderPayment::query()
            ->with('purchaseOrder')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->whereHas('purchaseOrder', function (Builder $query) use ($warehouseId): void {
                $query->whereNull('supplier_id')
                    ->when($warehouseId, fn (Builder $scope) => $scope->where('warehouse_id', $warehouseId));
            })
            ->get();
        $total = 0.0;

        foreach ($payments as $payment) {
            $order = $payment->purchaseOrder;
            if (! $order || $payment->paid_at?->toDateString() === $order->order_date?->toDateString()) {
                continue;
            }

            $total += (float) $payment->amount;
        }

        return $this->money($total);
    }

    private function snapshotPayload(string $date, ?int $warehouseId, array $opening, array $flows): array
    {
        $closingCash = $this->money(
            $opening['cash']
            + $flows['cash_sales']
            - $flows['cash_purchases']
            - $flows['expenses']
            + $flows['investment_in']
            - $flows['investment_out']
            + $flows['customer_payments']
            - $flows['supplier_payments']
            + $flows['cash_adjustments']
        );
        $closingInventory = $this->money(
            $opening['inventory']
            + $flows['cash_purchases']
            + $flows['credit_purchases']
            - $flows['cogs_cash_sales']
            - $flows['cogs_credit_sales']
            + $flows['inventory_adjustments']
        );
        $accountsPayable = $this->money($opening['accounts_payable'] + $flows['credit_purchases'] - $flows['supplier_payments']);
        $accountsReceivable = $this->money($opening['accounts_receivable'] + $flows['credit_sales'] - $flows['customer_payments']);
        $grossProfit = $this->money($flows['cash_sales'] + $flows['credit_sales'] - $flows['cogs_cash_sales'] - $flows['cogs_credit_sales']);

        return array_merge($flows, [
            'warehouse_id' => $warehouseId,
            'date' => $date,
            'opening_cash' => $opening['cash'],
            'opening_inventory' => $opening['inventory'],
            'opening_accounts_receivable' => $opening['accounts_receivable'],
            'opening_accounts_payable' => $opening['accounts_payable'],
            'closing_cash' => $closingCash,
            'closing_inventory' => $closingInventory,
            'accounts_receivable' => $accountsReceivable,
            'accounts_payable' => $accountsPayable,
            'gross_profit' => $grossProfit,
            'net_profit' => $this->money($grossProfit - $flows['expenses']),
            'net_investment' => $this->money($flows['investment_in'] - $flows['investment_out']),
            'computed_at' => now(),
            'version' => 1,
            'validation_warnings' => [],
        ]);
    }

    private function dailyRow(FinancialDailySnapshot $snapshot): array
    {
        $sales = $this->money($snapshot->cash_sales + $snapshot->credit_sales);
        $purchases = $this->money($snapshot->cash_purchases + $snapshot->credit_purchases);
        $cogs = $this->money($snapshot->cogs_cash_sales + $snapshot->cogs_credit_sales);

        return [
            'date' => $snapshot->date?->toDateString(),
            'cash' => $this->money($snapshot->closing_cash),
            'inventory' => $this->money($snapshot->closing_inventory),
            'purchase' => $purchases,
            'expenses' => $this->money($snapshot->expenses),
            'sales' => $sales,
            'cogs' => $cogs,
            'profit' => $this->money($snapshot->net_profit),
            'credit_payable' => $this->money($snapshot->accounts_payable),
            'credit_receivable' => $this->money($snapshot->accounts_receivable),
            'cogs_credit' => $this->money($snapshot->cogs_credit_sales),
            'investment' => $this->money($snapshot->net_investment),
            'opening_cash' => $this->money($snapshot->opening_cash),
            'opening_inventory' => $this->money($snapshot->opening_inventory),
            'opening_accounts_receivable' => $this->money($snapshot->opening_accounts_receivable),
            'opening_accounts_payable' => $this->money($snapshot->opening_accounts_payable),
            'cash_sales' => $this->money($snapshot->cash_sales),
            'credit_sales' => $this->money($snapshot->credit_sales),
            'cash_purchases' => $this->money($snapshot->cash_purchases),
            'credit_purchases' => $this->money($snapshot->credit_purchases),
            'customer_payments' => $this->money($snapshot->customer_payments),
            'supplier_payments' => $this->money($snapshot->supplier_payments),
            'cogs_cash_sales' => $this->money($snapshot->cogs_cash_sales),
            'cogs_credit_sales' => $this->money($snapshot->cogs_credit_sales),
            'investment_in' => $this->money($snapshot->investment_in),
            'investment_out' => $this->money($snapshot->investment_out),
            'cash_adjustments' => $this->money($snapshot->cash_adjustments),
            'inventory_adjustments' => $this->money($snapshot->inventory_adjustments),
            'closing_cash' => $this->money($snapshot->closing_cash),
            'closing_inventory' => $this->money($snapshot->closing_inventory),
            'accounts_receivable' => $this->money($snapshot->accounts_receivable),
            'accounts_payable' => $this->money($snapshot->accounts_payable),
            'gross_profit' => $this->money($snapshot->gross_profit),
            'net_profit' => $this->money($snapshot->net_profit),
            'net_investment' => $this->money($snapshot->net_investment),
            'is_locked' => (bool) $snapshot->is_locked,
            'computed_at' => $snapshot->computed_at?->toISOString(),
        ];
    }

    private function rangeTotals(Collection $snapshots): array
    {
        $last = $snapshots->last();
        $first = $snapshots->first();

        return [
            'cash' => $this->money($last?->closing_cash ?? 0),
            'inventory' => $this->money($last?->closing_inventory ?? 0),
            'purchase' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_purchases + (float) $snapshot->credit_purchases)),
            'expenses' => $this->money($snapshots->sum('expenses')),
            'sales' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_sales + (float) $snapshot->credit_sales)),
            'cogs' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cogs_cash_sales + (float) $snapshot->cogs_credit_sales)),
            'profit' => $this->money($snapshots->sum('net_profit')),
            'credit_payable' => $this->money($last?->accounts_payable ?? 0),
            'credit_receivable' => $this->money($last?->accounts_receivable ?? 0),
            'cogs_credit' => $this->money($snapshots->sum('cogs_credit_sales')),
            'investment' => $this->money($snapshots->sum('net_investment')),
            'opening_cash' => $this->money($first?->opening_cash ?? 0),
            'opening_inventory' => $this->money($first?->opening_inventory ?? 0),
            'opening_accounts_receivable' => $this->money($first?->opening_accounts_receivable ?? 0),
            'opening_accounts_payable' => $this->money($first?->opening_accounts_payable ?? 0),
            'closing_cash' => $this->money($last?->closing_cash ?? 0),
            'closing_inventory' => $this->money($last?->closing_inventory ?? 0),
            'accounts_receivable' => $this->money($last?->accounts_receivable ?? 0),
            'accounts_payable' => $this->money($last?->accounts_payable ?? 0),
            'cash_sales' => $this->money($snapshots->sum('cash_sales')),
            'credit_sales' => $this->money($snapshots->sum('credit_sales')),
            'cash_purchases' => $this->money($snapshots->sum('cash_purchases')),
            'credit_purchases' => $this->money($snapshots->sum('credit_purchases')),
            'customer_payments' => $this->money($snapshots->sum('customer_payments')),
            'supplier_payments' => $this->money($snapshots->sum('supplier_payments')),
            'cogs_cash_sales' => $this->money($snapshots->sum('cogs_cash_sales')),
            'cogs_credit_sales' => $this->money($snapshots->sum('cogs_credit_sales')),
            'investment_in' => $this->money($snapshots->sum('investment_in')),
            'investment_out' => $this->money($snapshots->sum('investment_out')),
            'net_investment' => $this->money($snapshots->sum('net_investment')),
            'gross_profit' => $this->money($snapshots->sum('gross_profit')),
            'net_profit' => $this->money($snapshots->sum('net_profit')),
            'cash_adjustments' => $this->money($snapshots->sum('cash_adjustments')),
            'inventory_adjustments' => $this->money($snapshots->sum('inventory_adjustments')),
        ];
    }

    private function emptyFlows(): array
    {
        return [
            'cash_sales' => 0.0,
            'credit_sales' => 0.0,
            'cash_purchases' => 0.0,
            'credit_purchases' => 0.0,
            'customer_payments' => 0.0,
            'supplier_payments' => 0.0,
            'expenses' => 0.0,
            'cogs_cash_sales' => 0.0,
            'cogs_credit_sales' => 0.0,
            'investment_in' => 0.0,
            'investment_out' => 0.0,
            'cash_adjustments' => 0.0,
            'inventory_adjustments' => 0.0,
        ];
    }

    private function salesOrderScopedTotal(SalesOrder $order, ?int $warehouseId): float
    {
        if (! $warehouseId) {
            return $this->money($order->total);
        }

        return $this->money($order->items
            ->where('warehouse_id', $warehouseId)
            ->sum(fn ($item): float => (float) $item->total));
    }

    private function salesOrderScopedCogs(SalesOrder $order, ?int $warehouseId): float
    {
        $items = $warehouseId ? $order->items->where('warehouse_id', $warehouseId) : $order->items;

        return $this->money($items->sum(fn ($item): float => (float) $item->unit_cost * (int) $item->quantity));
    }

    private function salesOrderWarehouseRatio(SalesOrder $order, ?int $warehouseId, float $scopedTotal): float
    {
        if (! $warehouseId) {
            return 1.0;
        }

        $orderTotal = max(0.01, (float) $order->total);

        return min(1, max(0, $scopedTotal / $orderTotal));
    }

    private function batchBelongsToWarehouse(AccountPaymentBatch $batch, ?int $warehouseId): bool
    {
        if (! $warehouseId) {
            return true;
        }

        return (int) ($batch->posSession?->warehouse_id ?? 0) === $warehouseId;
    }

    private function isRealPaymentMethod(?string $method): bool
    {
        return $method !== null && ! in_array($method, self::NON_CASH_METHODS, true);
    }

    private function snapshotQuery(?int $warehouseId): Builder
    {
        $query = FinancialDailySnapshot::query();

        return $warehouseId
            ? $query->where('warehouse_id', $warehouseId)
            : $query->whereNull('warehouse_id');
    }

    private function capitalMovementRow(CapitalMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movement_type' => $movement->movement_type,
            'movement_type_label' => $movement->movement_type_label,
            'amount' => $this->money($movement->amount),
            'movement_date' => $movement->movement_date?->toDateString(),
            'reason' => $movement->reason,
            'notes' => $movement->notes,
            'warehouse_id' => $movement->warehouse_id,
            'user_id' => $movement->user_id,
        ];
    }

    private function firstFinancialDate(?int $warehouseId): ?string
    {
        $dates = collect([
            SalesOrder::query()
                ->when($warehouseId, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
                ->min('date'),
            PurchaseOrder::query()
                ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
                ->min('order_date'),
            OperationalExpense::query()
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
                ->min('expense_date'),
            CapitalMovement::query()
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
                ->min('movement_date'),
        ])->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        return Carbon::parse($dates->min())->toDateString();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
