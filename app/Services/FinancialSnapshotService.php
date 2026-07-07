<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\FinancialAdjustment;
use App\Models\FinancialDailySnapshot;
use App\Models\InventoryLot;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FinancialSnapshotService
{
    private const SALES_STATUSES = ['confirmed', 'processing', 'completed'];

    private const PURCHASE_EXCLUDED_STATUSES = ['cancelled', 'draft'];

    private const NON_CASH_METHODS = ['credit', 'store_credit', 'supplier_credit'];

    private const CURRENT_VERSION = 3;

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

        if (
            $existing
            && ! $force
            && $this->snapshotIsFresh($existing, $day, $warehouseId)
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
            'warnings' => $this->rangeWarnings($snapshots),
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
        $bank = $this->money($snapshot->closing_bank ?? 0);
        $inventory = $this->money($snapshot->closing_inventory);
        $accountsReceivable = $this->money($snapshot->accounts_receivable);
        $accountsPayable = $this->money($snapshot->accounts_payable);
        $totalAssets = $this->money($cash + $bank + $inventory + $accountsReceivable);
        $totalLiabilities = $this->money($accountsPayable);

        return [
            'assets' => [
                'cash' => $cash,
                'cash_initial' => $this->money($snapshot->opening_cash),
                'bank' => $bank,
                'bank_initial' => $this->money($snapshot->opening_bank ?? 0),
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
            'warnings' => $snapshot->validation_warnings ?? [],
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
                'bank' => $this->money($previous->closing_bank ?? 0),
                'inventory' => $this->inventoryValueForDate($day->copy()->subDay(), $warehouseId),
                'accounts_receivable' => $this->money($previous->accounts_receivable),
                'accounts_payable' => $this->money($previous->accounts_payable),
            ];
        }

        $flows = $this->flowTotals(null, $day->copy()->subDay(), $warehouseId);

        return [
            'cash' => $this->money(
                $flows['cash_sales']
                - $flows['cash_purchases']
                - $flows['cash_expenses']
                + $flows['investment_in']
                - $flows['investment_out']
                + $flows['customer_payments']
                - $flows['supplier_payments']
                + $flows['cash_adjustments']
            ),
            'bank' => $this->money(
                $flows['bank_sales']
                - $flows['bank_purchases']
                - $flows['bank_expenses']
                + $flows['customer_bank_payments']
                - $flows['supplier_bank_payments']
                + $flows['bank_adjustments']
            ),
            'inventory' => $this->inventoryValueForDate($day->copy()->subDay(), $warehouseId),
            'accounts_receivable' => $this->money($flows['credit_sales'] - $flows['customer_payments'] - $flows['customer_bank_payments'] + $flows['accounts_receivable_adjustments']),
            'accounts_payable' => $this->money($flows['credit_purchases'] - $flows['supplier_payments'] - $flows['supplier_bank_payments'] + $flows['accounts_payable_adjustments']),
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
            $this->salesReturnFlows($from, $to, $warehouseId),
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
            $sameDayCashPayments = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => $this->paymentBucket($payment->method) === 'cash'
                    && (float) $payment->amount > 0
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio);
            $sameDayBankPayments = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => $this->paymentBucket($payment->method) === 'bank'
                    && (float) $payment->amount > 0
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio);
            $creditMarkers = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => in_array($payment->method, self::NON_CASH_METHODS, true))
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio);

            $cashSales = min($scopedTotal, $sameDayCashPayments);
            $bankSales = min(max($scopedTotal - $cashSales, 0), $sameDayBankPayments);
            $creditSales = $creditMarkers > 0
                ? min(max($scopedTotal - $cashSales - $bankSales, 0), $creditMarkers)
                : max($scopedTotal - $cashSales - $bankSales, 0);
            $cogs = $this->salesOrderScopedCogs($order, $warehouseId);
            $creditRatio = $scopedTotal > 0 ? min(1, $creditSales / $scopedTotal) : 0;
            $bankRatio = $scopedTotal > 0 ? min(1, $bankSales / $scopedTotal) : 0;
            $cogsCredit = $this->money($cogs * $creditRatio);
            $cogsBank = $this->money($cogs * $bankRatio);

            $flows['cash_sales'] = $this->money($flows['cash_sales'] + $cashSales);
            $flows['bank_sales'] = $this->money($flows['bank_sales'] + $bankSales);
            $flows['credit_sales'] = $this->money($flows['credit_sales'] + $creditSales);
            $flows['cogs_credit_sales'] = $this->money($flows['cogs_credit_sales'] + $cogsCredit);
            $flows['cogs_bank_sales'] = $this->money($flows['cogs_bank_sales'] + $cogsBank);
            $flows['cogs_cash_sales'] = $this->money($flows['cogs_cash_sales'] + max(0, $cogs - $cogsCredit - $cogsBank));
        }

        return $flows;
    }

    private function salesReturnFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $returns = SalesReturn::query()
            ->with(['items.salesOrderItem', 'salesOrder.items'])
            ->where('status', 'approved')
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->where(function (Builder $query) use ($from, $to): void {
                $query->where(function (Builder $approved) use ($from, $to): void {
                    $approved->whereNotNull('approved_at')
                        ->whereDate('approved_at', '<=', $to->toDateString())
                        ->when($from, fn (Builder $scope) => $scope->whereDate('approved_at', '>=', $from->toDateString()));
                })->orWhere(function (Builder $legacy) use ($from, $to): void {
                    $legacy->whereNull('approved_at')
                        ->whereDate('updated_at', '<=', $to->toDateString())
                        ->when($from, fn (Builder $scope) => $scope->whereDate('updated_at', '>=', $from->toDateString()));
                });
            })
            ->get();

        foreach ($returns as $return) {
            $total = $this->money($return->total_amount);
            if ($total <= 0) {
                continue;
            }

            $cogs = $this->money($return->items->sum(fn ($item): float => (float) ($item->salesOrderItem?->unit_cost ?? 0) * (int) $item->quantity));
            $split = $this->returnSplitAmounts($return);
            $moneyBucket = $this->paymentBucket($return->refund_method);
            $moneyAmount = $split['money'];
            $receivableAmount = $split['receivable'];
            $storeCreditAmount = $split['store_credit'];

            if ($moneyAmount > 0 && $moneyBucket === 'cash') {
                $flows['cash_sales'] = $this->money($flows['cash_sales'] - $moneyAmount);
                $flows['cogs_cash_sales'] = $this->money($flows['cogs_cash_sales'] - ($cogs * ($moneyAmount / $total)));
            } elseif ($moneyAmount > 0 && $moneyBucket === 'bank') {
                $flows['bank_sales'] = $this->money($flows['bank_sales'] - $moneyAmount);
                $flows['cogs_bank_sales'] = $this->money($flows['cogs_bank_sales'] - ($cogs * ($moneyAmount / $total)));
            }

            if ($receivableAmount > 0) {
                $flows['credit_sales'] = $this->money($flows['credit_sales'] - $receivableAmount);
                $flows['cogs_credit_sales'] = $this->money($flows['cogs_credit_sales'] - ($cogs * ($receivableAmount / $total)));
            }

            if ($storeCreditAmount > 0) {
                $flows['sales_adjustments'] = $this->money($flows['sales_adjustments'] - $storeCreditAmount);
                $flows['cogs_credit_sales'] = $this->money($flows['cogs_credit_sales'] - ($cogs * ($storeCreditAmount / $total)));
            }
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
            $sameDayCashPayments = $order->payments
                ->filter(fn (PurchaseOrderPayment $payment): bool => $this->paymentBucket($payment->method) === 'cash'
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (PurchaseOrderPayment $payment): float => (float) $payment->amount);
            $sameDayBankPayments = $order->payments
                ->filter(fn (PurchaseOrderPayment $payment): bool => $this->paymentBucket($payment->method) === 'bank'
                    && $payment->paid_at?->toDateString() === $orderDate)
                ->sum(fn (PurchaseOrderPayment $payment): float => (float) $payment->amount);
            $cashPurchases = min($total, $sameDayCashPayments);
            $bankPurchases = min(max($total - $cashPurchases, 0), $sameDayBankPayments);

            $flows['cash_purchases'] = $this->money($flows['cash_purchases'] + $cashPurchases);
            $flows['bank_purchases'] = $this->money($flows['bank_purchases'] + $bankPurchases);
            $flows['credit_purchases'] = $this->money($flows['credit_purchases'] + max(0, $total - $cashPurchases - $bankPurchases));
        }

        return $flows;
    }

    private function expenseFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $expenses = OperationalExpense::query()
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('expense_date', '>=', $from->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('warehouse_id')
                ->orWhere('warehouse_id', $warehouseId)))
            ->get();

        foreach ($expenses as $expense) {
            $amount = $this->money($expense->amount);
            $affectsIncomeStatement = (bool) ($expense->affects_income_statement ?? true);
            $affectsCashFlow = (bool) ($expense->affects_cash_flow ?? true);

            if ($affectsIncomeStatement) {
                $flows['expenses'] = $this->money($flows['expenses'] + $amount);
            }

            if (! $affectsCashFlow) {
                continue;
            }

            $bucket = $this->paymentBucket($expense->payment_method);
            if ($bucket === 'cash') {
                $flows['cash_expenses'] = $this->money($flows['cash_expenses'] + $amount);
            } elseif ($bucket === 'bank') {
                $flows['bank_expenses'] = $this->money($flows['bank_expenses'] + $amount);
            }
        }

        return $flows;
    }

    private function customerPaymentFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $payments = SalesOrderPayment::query()
            ->with('salesOrder.items')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->where('amount', '>', 0)
            ->get();

        foreach ($payments as $payment) {
            $order = $payment->salesOrder;
            if (! $order || ! in_array($order->status, self::SALES_STATUSES, true)) {
                continue;
            }

            if ($payment->paid_at?->toDateString() === $order->date?->toDateString()) {
                continue;
            }

            $scopedTotal = $this->salesOrderScopedTotal($order, $warehouseId);
            if ($scopedTotal <= 0) {
                continue;
            }

            $amount = $this->money((float) $payment->amount * $this->salesOrderWarehouseRatio($order, $warehouseId, $scopedTotal));
            $bucket = $this->paymentBucket($payment->method);

            if ($bucket === 'cash') {
                $flows['customer_payments'] = $this->money($flows['customer_payments'] + $amount);
            } elseif ($bucket === 'bank') {
                $flows['customer_bank_payments'] = $this->money($flows['customer_bank_payments'] + $amount);
            }
        }

        return $flows;
    }

    private function supplierPaymentFlows(?Carbon $from, Carbon $to, ?int $warehouseId): array
    {
        $flows = $this->emptyFlows();
        $payments = PurchaseOrderPayment::query()
            ->with('purchaseOrder')
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from->toDateString()))
            ->whereNotIn('method', self::NON_CASH_METHODS)
            ->get();

        foreach ($payments as $payment) {
            $order = $payment->purchaseOrder;
            if (! $order || in_array($order->status, self::PURCHASE_EXCLUDED_STATUSES, true)) {
                continue;
            }

            if ($payment->paid_at?->toDateString() === $order->order_date?->toDateString()) {
                continue;
            }

            if ($warehouseId && (int) $order->warehouse_id !== $warehouseId) {
                continue;
            }

            $amount = $this->money($payment->amount);
            $bucket = $this->paymentBucket($payment->method);

            if ($bucket === 'cash') {
                $flows['supplier_payments'] = $this->money($flows['supplier_payments'] + $amount);
            } elseif ($bucket === 'bank') {
                $flows['supplier_bank_payments'] = $this->money($flows['supplier_bank_payments'] + $amount);
            }
        }

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
            $key = FinancialAdjustment::flowKeyForType((string) $adjustment->type);
            if ($key !== null && array_key_exists($key, $flows)) {
                $flows[$key] = $this->money($flows[$key] + (float) $adjustment->amount);
            }
        }

        return $flows;
    }

    private function snapshotPayload(string $date, ?int $warehouseId, array $opening, array $flows): array
    {
        $closingCash = $this->money(
            $opening['cash']
            + $flows['cash_sales']
            - $flows['cash_purchases']
            - $flows['cash_expenses']
            + $flows['investment_in']
            - $flows['investment_out']
            + $flows['customer_payments']
            - $flows['supplier_payments']
            + $flows['cash_adjustments']
        );
        $closingBank = $this->money(
            $opening['bank']
            + $flows['bank_sales']
            - $flows['bank_purchases']
            - $flows['bank_expenses']
            + $flows['customer_bank_payments']
            - $flows['supplier_bank_payments']
            + $flows['bank_adjustments']
        );
        $closingInventory = $this->inventoryValueForDate(Carbon::parse($date), $warehouseId);
        $accountsPayable = $this->money($opening['accounts_payable'] + $flows['credit_purchases'] - $flows['supplier_payments'] - $flows['supplier_bank_payments'] + $flows['accounts_payable_adjustments']);
        $accountsReceivable = $this->money($opening['accounts_receivable'] + $flows['credit_sales'] - $flows['customer_payments'] - $flows['customer_bank_payments'] + $flows['accounts_receivable_adjustments']);
        $grossProfit = $this->money(
            $flows['cash_sales']
            + $flows['bank_sales']
            + $flows['credit_sales']
            + $flows['sales_adjustments']
            - $flows['cogs_cash_sales']
            - $flows['cogs_bank_sales']
            - $flows['cogs_credit_sales']
        );
        $expenses = $this->money($flows['expenses'] + $flows['expense_adjustments']);

        return array_merge($flows, [
            'warehouse_id' => $warehouseId,
            'date' => $date,
            'opening_cash' => $opening['cash'],
            'opening_bank' => $opening['bank'],
            'opening_inventory' => $opening['inventory'],
            'opening_accounts_receivable' => $opening['accounts_receivable'],
            'opening_accounts_payable' => $opening['accounts_payable'],
            'closing_cash' => $closingCash,
            'closing_bank' => $closingBank,
            'closing_inventory' => $closingInventory,
            'accounts_receivable' => $accountsReceivable,
            'accounts_payable' => $accountsPayable,
            'gross_profit' => $grossProfit,
            'net_profit' => $this->money($grossProfit - $expenses),
            'net_investment' => $this->money($flows['investment_in'] - $flows['investment_out'] + $flows['investment_adjustments']),
            'computed_at' => now(),
            'version' => self::CURRENT_VERSION,
            'validation_warnings' => $this->snapshotWarnings(Carbon::parse($date), $warehouseId),
        ]);
    }

    private function dailyRow(FinancialDailySnapshot $snapshot): array
    {
        $salesCollected = $this->money($snapshot->cash_sales + ($snapshot->bank_sales ?? 0) + $snapshot->customer_payments + ($snapshot->customer_bank_payments ?? 0));
        $sales = $this->money($snapshot->cash_sales + ($snapshot->bank_sales ?? 0) + $snapshot->credit_sales + ($snapshot->sales_adjustments ?? 0));
        $purchasesPaid = $this->money($snapshot->cash_purchases + ($snapshot->bank_purchases ?? 0) + $snapshot->supplier_payments + ($snapshot->supplier_bank_payments ?? 0));
        $purchases = $this->money($snapshot->cash_purchases + ($snapshot->bank_purchases ?? 0) + $snapshot->credit_purchases + ($snapshot->purchase_adjustments ?? 0));
        $expenses = $this->money($snapshot->expenses + ($snapshot->expense_adjustments ?? 0));
        $cogs = $this->money($snapshot->cogs_cash_sales + ($snapshot->cogs_bank_sales ?? 0) + $snapshot->cogs_credit_sales);

        return [
            'date' => $snapshot->date?->toDateString(),
            'cash' => $this->money($snapshot->closing_cash),
            'bank' => $this->money($snapshot->closing_bank ?? 0),
            'liquid_assets' => $this->money($snapshot->closing_cash + ($snapshot->closing_bank ?? 0)),
            'inventory' => $this->money($snapshot->closing_inventory),
            'purchase' => $purchases,
            'purchase_paid' => $purchasesPaid,
            'purchase_pending' => $this->money($snapshot->accounts_payable),
            'expenses' => $expenses,
            'sales' => $sales,
            'sales_total' => $sales,
            'sales_collected' => $salesCollected,
            'sales_pending' => $this->money($snapshot->accounts_receivable),
            'cogs' => $cogs,
            'profit' => $this->money($snapshot->net_profit),
            'credit_payable' => $this->money($snapshot->accounts_payable),
            'credit_receivable' => $this->money($snapshot->accounts_receivable),
            'cogs_credit' => $this->money($snapshot->cogs_credit_sales),
            'investment' => $this->money($snapshot->net_investment),
            'opening_cash' => $this->money($snapshot->opening_cash),
            'opening_bank' => $this->money($snapshot->opening_bank ?? 0),
            'opening_inventory' => $this->money($snapshot->opening_inventory),
            'opening_accounts_receivable' => $this->money($snapshot->opening_accounts_receivable),
            'opening_accounts_payable' => $this->money($snapshot->opening_accounts_payable),
            'cash_sales' => $this->money($snapshot->cash_sales),
            'bank_sales' => $this->money($snapshot->bank_sales ?? 0),
            'credit_sales' => $this->money($snapshot->credit_sales),
            'cash_purchases' => $this->money($snapshot->cash_purchases),
            'bank_purchases' => $this->money($snapshot->bank_purchases ?? 0),
            'credit_purchases' => $this->money($snapshot->credit_purchases),
            'customer_payments' => $this->money($snapshot->customer_payments),
            'customer_bank_payments' => $this->money($snapshot->customer_bank_payments ?? 0),
            'supplier_payments' => $this->money($snapshot->supplier_payments),
            'supplier_bank_payments' => $this->money($snapshot->supplier_bank_payments ?? 0),
            'cash_expenses' => $this->money($snapshot->cash_expenses ?? 0),
            'bank_expenses' => $this->money($snapshot->bank_expenses ?? 0),
            'cogs_cash_sales' => $this->money($snapshot->cogs_cash_sales),
            'cogs_bank_sales' => $this->money($snapshot->cogs_bank_sales ?? 0),
            'cogs_credit_sales' => $this->money($snapshot->cogs_credit_sales),
            'investment_in' => $this->money($snapshot->investment_in),
            'investment_out' => $this->money($snapshot->investment_out),
            'cash_adjustments' => $this->money($snapshot->cash_adjustments),
            'bank_adjustments' => $this->money($snapshot->bank_adjustments ?? 0),
            'inventory_adjustments' => $this->money($snapshot->inventory_adjustments),
            'sales_adjustments' => $this->money($snapshot->sales_adjustments ?? 0),
            'purchase_adjustments' => $this->money($snapshot->purchase_adjustments ?? 0),
            'expense_adjustments' => $this->money($snapshot->expense_adjustments ?? 0),
            'investment_adjustments' => $this->money($snapshot->investment_adjustments ?? 0),
            'accounts_receivable_adjustments' => $this->money($snapshot->accounts_receivable_adjustments ?? 0),
            'accounts_payable_adjustments' => $this->money($snapshot->accounts_payable_adjustments ?? 0),
            'manual_adjustments' => $this->money($snapshot->manual_adjustments ?? 0),
            'closing_cash' => $this->money($snapshot->closing_cash),
            'closing_bank' => $this->money($snapshot->closing_bank ?? 0),
            'closing_inventory' => $this->money($snapshot->closing_inventory),
            'accounts_receivable' => $this->money($snapshot->accounts_receivable),
            'accounts_payable' => $this->money($snapshot->accounts_payable),
            'gross_profit' => $this->money($snapshot->gross_profit),
            'net_profit' => $this->money($snapshot->net_profit),
            'net_investment' => $this->money($snapshot->net_investment),
            'validation_warnings' => $snapshot->validation_warnings ?? [],
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
            'bank' => $this->money($last?->closing_bank ?? 0),
            'liquid_assets' => $this->money(($last?->closing_cash ?? 0) + ($last?->closing_bank ?? 0)),
            'inventory' => $this->money($last?->closing_inventory ?? 0),
            'purchase' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_purchases + (float) ($snapshot->bank_purchases ?? 0) + (float) $snapshot->credit_purchases + (float) ($snapshot->purchase_adjustments ?? 0))),
            'purchase_paid' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_purchases + (float) ($snapshot->bank_purchases ?? 0) + (float) $snapshot->supplier_payments + (float) ($snapshot->supplier_bank_payments ?? 0))),
            'purchase_pending' => $this->money($last?->accounts_payable ?? 0),
            'expenses' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->expenses + (float) ($snapshot->expense_adjustments ?? 0))),
            'sales' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_sales + (float) ($snapshot->bank_sales ?? 0) + (float) $snapshot->credit_sales + (float) ($snapshot->sales_adjustments ?? 0))),
            'sales_total' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_sales + (float) ($snapshot->bank_sales ?? 0) + (float) $snapshot->credit_sales + (float) ($snapshot->sales_adjustments ?? 0))),
            'sales_collected' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cash_sales + (float) ($snapshot->bank_sales ?? 0) + (float) $snapshot->customer_payments + (float) ($snapshot->customer_bank_payments ?? 0))),
            'sales_pending' => $this->money($last?->accounts_receivable ?? 0),
            'cogs' => $this->money($snapshots->sum(fn (FinancialDailySnapshot $snapshot): float => (float) $snapshot->cogs_cash_sales + (float) ($snapshot->cogs_bank_sales ?? 0) + (float) $snapshot->cogs_credit_sales)),
            'profit' => $this->money($snapshots->sum('net_profit')),
            'credit_payable' => $this->money($last?->accounts_payable ?? 0),
            'credit_receivable' => $this->money($last?->accounts_receivable ?? 0),
            'cogs_credit' => $this->money($snapshots->sum('cogs_credit_sales')),
            'investment' => $this->money($snapshots->sum('net_investment')),
            'opening_cash' => $this->money($first?->opening_cash ?? 0),
            'opening_bank' => $this->money($first?->opening_bank ?? 0),
            'opening_inventory' => $this->money($first?->opening_inventory ?? 0),
            'opening_accounts_receivable' => $this->money($first?->opening_accounts_receivable ?? 0),
            'opening_accounts_payable' => $this->money($first?->opening_accounts_payable ?? 0),
            'closing_cash' => $this->money($last?->closing_cash ?? 0),
            'closing_bank' => $this->money($last?->closing_bank ?? 0),
            'closing_inventory' => $this->money($last?->closing_inventory ?? 0),
            'accounts_receivable' => $this->money($last?->accounts_receivable ?? 0),
            'accounts_payable' => $this->money($last?->accounts_payable ?? 0),
            'cash_sales' => $this->money($snapshots->sum('cash_sales')),
            'bank_sales' => $this->money($snapshots->sum('bank_sales')),
            'credit_sales' => $this->money($snapshots->sum('credit_sales')),
            'cash_purchases' => $this->money($snapshots->sum('cash_purchases')),
            'bank_purchases' => $this->money($snapshots->sum('bank_purchases')),
            'credit_purchases' => $this->money($snapshots->sum('credit_purchases')),
            'customer_payments' => $this->money($snapshots->sum('customer_payments')),
            'customer_bank_payments' => $this->money($snapshots->sum('customer_bank_payments')),
            'supplier_payments' => $this->money($snapshots->sum('supplier_payments')),
            'supplier_bank_payments' => $this->money($snapshots->sum('supplier_bank_payments')),
            'cash_expenses' => $this->money($snapshots->sum('cash_expenses')),
            'bank_expenses' => $this->money($snapshots->sum('bank_expenses')),
            'cogs_cash_sales' => $this->money($snapshots->sum('cogs_cash_sales')),
            'cogs_bank_sales' => $this->money($snapshots->sum('cogs_bank_sales')),
            'cogs_credit_sales' => $this->money($snapshots->sum('cogs_credit_sales')),
            'investment_in' => $this->money($snapshots->sum('investment_in')),
            'investment_out' => $this->money($snapshots->sum('investment_out')),
            'net_investment' => $this->money($snapshots->sum('net_investment')),
            'gross_profit' => $this->money($snapshots->sum('gross_profit')),
            'net_profit' => $this->money($snapshots->sum('net_profit')),
            'cash_adjustments' => $this->money($snapshots->sum('cash_adjustments')),
            'bank_adjustments' => $this->money($snapshots->sum('bank_adjustments')),
            'inventory_adjustments' => $this->money($snapshots->sum('inventory_adjustments')),
            'sales_adjustments' => $this->money($snapshots->sum('sales_adjustments')),
            'purchase_adjustments' => $this->money($snapshots->sum('purchase_adjustments')),
            'expense_adjustments' => $this->money($snapshots->sum('expense_adjustments')),
            'investment_adjustments' => $this->money($snapshots->sum('investment_adjustments')),
            'accounts_receivable_adjustments' => $this->money($snapshots->sum('accounts_receivable_adjustments')),
            'accounts_payable_adjustments' => $this->money($snapshots->sum('accounts_payable_adjustments')),
            'manual_adjustments' => $this->money($snapshots->sum('manual_adjustments')),
        ];
    }

    private function emptyFlows(): array
    {
        return [
            'cash_sales' => 0.0,
            'bank_sales' => 0.0,
            'credit_sales' => 0.0,
            'cash_purchases' => 0.0,
            'bank_purchases' => 0.0,
            'credit_purchases' => 0.0,
            'customer_payments' => 0.0,
            'customer_bank_payments' => 0.0,
            'supplier_payments' => 0.0,
            'supplier_bank_payments' => 0.0,
            'expenses' => 0.0,
            'cash_expenses' => 0.0,
            'bank_expenses' => 0.0,
            'cogs_cash_sales' => 0.0,
            'cogs_bank_sales' => 0.0,
            'cogs_credit_sales' => 0.0,
            'investment_in' => 0.0,
            'investment_out' => 0.0,
            'cash_adjustments' => 0.0,
            'bank_adjustments' => 0.0,
            'inventory_adjustments' => 0.0,
            'sales_adjustments' => 0.0,
            'purchase_adjustments' => 0.0,
            'expense_adjustments' => 0.0,
            'investment_adjustments' => 0.0,
            'accounts_receivable_adjustments' => 0.0,
            'accounts_payable_adjustments' => 0.0,
            'manual_adjustments' => 0.0,
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

    private function paymentBucket(?string $method): ?string
    {
        return PaymentMethod::liquidityBucket($method);
    }

    /**
     * @return array{receivable: float, money: float, store_credit: float}
     */
    private function returnSplitAmounts(SalesReturn $return): array
    {
        $receivable = $this->money($return->receivable_applied_amount ?? 0);
        $money = $this->money($return->money_refund_amount ?? 0);
        $storeCredit = $this->money($return->store_credit_amount ?? 0);

        if (($receivable + $money + $storeCredit) <= 0) {
            $total = $this->money($return->total_amount);
            if (in_array($return->refund_method, ['balance', 'store_credit', 'receivable'], true)) {
                $storeCredit = $total;
            } elseif ($this->paymentBucket($return->refund_method) !== null) {
                $money = $total;
            }
        }

        return [
            'receivable' => $receivable,
            'money' => $money,
            'store_credit' => $storeCredit,
        ];
    }

    private function snapshotIsFresh(FinancialDailySnapshot $snapshot, Carbon $day, ?int $warehouseId): bool
    {
        if ((int) $snapshot->version < self::CURRENT_VERSION || $snapshot->computed_at === null) {
            return false;
        }

        if ($day->gte(Carbon::today())) {
            return false;
        }

        $latestChange = $this->latestFinancialSourceChangeAt($day, $warehouseId);

        return $latestChange === null || $latestChange->lte($snapshot->computed_at);
    }

    private function latestFinancialSourceChangeAt(Carbon $day, ?int $warehouseId): ?Carbon
    {
        $date = $day->toDateString();
        $timestamps = collect([
            $this->latestTimestamp(SalesOrder::query()
                ->whereDate('date', $date)
                ->when($warehouseId, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(SalesOrderPayment::query()
                ->whereDate('paid_at', $date)
                ->when($warehouseId, fn (Builder $query) => $query->whereHas('salesOrder.items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(SalesReturn::query()
                ->where('status', 'approved')
                ->where(function (Builder $query) use ($date): void {
                    $query->whereDate('approved_at', $date)
                        ->orWhere(function (Builder $legacy) use ($date): void {
                            $legacy->whereNull('approved_at')->whereDate('updated_at', $date);
                        });
                })
                ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))),
            $this->latestTimestamp(PurchaseOrder::query()
                ->whereDate('order_date', $date)
                ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))),
            $this->latestTimestamp(PurchaseOrderPayment::query()
                ->whereDate('paid_at', $date)
                ->when($warehouseId, fn (Builder $query) => $query->whereHas('purchaseOrder', fn (Builder $purchase) => $purchase->where('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(OperationalExpense::query()
                ->whereDate('expense_date', $date)
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(CapitalMovement::query()
                ->whereDate('movement_date', $date)
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(FinancialAdjustment::query()
                ->whereDate('adjustment_date', $date)
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))),
            $this->latestTimestamp(InventoryLot::query()
                ->whereDate('received_at', '<=', $date)
                ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))),
        ])->filter();

        if ($timestamps->isEmpty()) {
            return null;
        }

        return $timestamps->sortBy(fn (Carbon $timestamp): int => $timestamp->getTimestamp())->last();
    }

    private function latestTimestamp(Builder $query): ?Carbon
    {
        $timestamps = collect([
            (clone $query)->max('updated_at'),
            (clone $query)->max('created_at'),
        ])->filter();

        if ($timestamps->isEmpty()) {
            return null;
        }

        return Carbon::parse($timestamps->max());
    }

    private function inventoryValueForDate(Carbon $day, ?int $warehouseId): float
    {
        $cutoff = $day->copy()->endOfDay();
        $lotValue = InventoryLot::query()
            ->where('received_at', '<=', $cutoff)
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->selectRaw('COALESCE(SUM(quantity_available * landed_unit_cost), 0) as value')
            ->value('value');

        return $this->money((float) $lotValue + $this->inventoryAdjustmentBalanceAsOf($day, $warehouseId));
    }

    private function inventoryAdjustmentBalanceAsOf(Carbon $day, ?int $warehouseId): float
    {
        return $this->money(
            FinancialAdjustment::query()
                ->where('type', FinancialAdjustment::TYPE_INVENTORY)
                ->whereDate('adjustment_date', '<=', $day->toDateString())
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
                ->sum('amount')
        );
    }

    private function snapshotWarnings(Carbon $day, ?int $warehouseId): array
    {
        $warnings = [];

        if ($day->lt(Carbon::today())) {
            $warnings[] = 'inventory_historical_value_uses_current_fifo_lot_availability';
        }

        return $warnings;
    }

    private function rangeWarnings(Collection $snapshots): array
    {
        return $snapshots
            ->flatMap(fn (FinancialDailySnapshot $snapshot): array => $snapshot->validation_warnings ?? [])
            ->unique()
            ->values()
            ->all();
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
            'business_datetime' => $movement->business_datetime?->toIso8601String(),
            'created_at' => $movement->created_at?->toIso8601String(),
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
