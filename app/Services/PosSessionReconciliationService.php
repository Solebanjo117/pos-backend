<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PosSessionReconciliationService
{
    /**
     * Métodos informativos: no representan dinero contado/cobrado en caja.
     *
     * @var array<int, string>
     */
    private const NON_COUNTABLE_METHODS = ['credit', 'store_credit'];

    /**
     * @param  array<string, mixed>  $closingDetails
     * @return array<string, mixed>
     */
    public function build(PosSession $session, ?Carbon $until = null, array $closingDetails = [], ?float $declaredCash = null): array
    {
        $until ??= $session->closed_at;

        $payments = $this->paymentsByMethod($session, $until);
        $movementsIn = $this->cashMovementTotal($session, 'in');
        $movementsOut = $this->cashMovementTotal($session, 'out');
        $ordersSummary = $this->ordersSummary($session);
        $declaredCounts = $this->declaredCounts($closingDetails, $declaredCash);
        $methods = $this->methodsForRows($payments);

        $rows = $methods->map(function (array $method) use ($session, $payments, $movementsIn, $movementsOut, $declaredCounts): array {
            $code = $method['code'];
            $methodPayments = $payments[$code] ?? ['payments' => 0.0, 'withdrawals' => 0.0];
            $isCountable = (bool) ($method['is_countable'] ?? ! in_array($code, self::NON_COUNTABLE_METHODS, true));
            $isInformational = (bool) ($method['is_informational'] ?? ! $isCountable);

            $startAdds = $code === 'cash'
                ? $this->money((float) $session->opening_amount + $movementsIn)
                : 0.0;

            $withdrawals = $this->money((float) $methodPayments['withdrawals']);

            if ($code === 'cash') {
                $withdrawals = $this->money($withdrawals - $movementsOut);
            }

            $expected = $this->money($startAdds + (float) $methodPayments['payments'] + $withdrawals);
            $declared = $isCountable ? $this->money((float) ($declaredCounts[$code] ?? 0.0)) : 0.0;
            $difference = $isCountable ? $this->money($declared - $expected) : 0.0;

            return [
                'code' => $code,
                'label' => $method['label'],
                'start_adds' => $startAdds,
                'payments' => $this->money((float) $methodPayments['payments']),
                'withdrawals' => $withdrawals,
                'expected_total' => $expected,
                'declared_total' => $declared,
                'difference' => $difference,
                'is_countable' => $isCountable,
                'is_informational' => $isInformational,
            ];
        })->values()->all();

        $summary = $this->summary($session, $rows, $ordersSummary, $movementsIn, $movementsOut);

        return [
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, array{payments: float, withdrawals: float}>
     */
    private function paymentsByMethod(PosSession $session, ?Carbon $until): array
    {
        $query = SalesOrderPayment::query()
            ->where('created_by', $session->user_id)
            ->where('paid_at', '>=', $session->opened_at);

        if ($until) {
            $query->where('paid_at', '<=', $until);
        }

        $salesPayments = $query
            ->selectRaw('method, SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) as payments, SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as withdrawals')
            ->groupBy('method')
            ->get()
            ->mapWithKeys(fn (SalesOrderPayment $payment): array => [
                $payment->method => [
                    'payments' => $this->money((float) $payment->payments),
                    'withdrawals' => $this->money((float) $payment->withdrawals),
                ],
            ])
            ->all();

        $purchaseWithdrawals = PurchaseOrderPayment::query()
            ->where('pos_session_id', $session->id)
            ->where(function ($query): void {
                $query->where('method', '!=', 'cash')
                    ->orWhereNull('cash_movement_id');
            })
            ->selectRaw('method, SUM(amount) as withdrawals')
            ->groupBy('method')
            ->get();

        foreach ($purchaseWithdrawals as $payment) {
            $method = $payment->method;
            $salesPayments[$method] ??= ['payments' => 0.0, 'withdrawals' => 0.0];
            $salesPayments[$method]['withdrawals'] = $this->money(
                (float) $salesPayments[$method]['withdrawals'] - (float) $payment->withdrawals
            );
        }

        $operationalExpenseWithdrawals = OperationalExpense::query()
            ->where('pos_session_id', $session->id)
            ->where('payment_method', '!=', 'cash')
            ->selectRaw('payment_method, SUM(amount) as withdrawals')
            ->groupBy('payment_method')
            ->get();

        foreach ($operationalExpenseWithdrawals as $expense) {
            $method = $expense->payment_method;

            if (! $method) {
                continue;
            }

            $salesPayments[$method] ??= ['payments' => 0.0, 'withdrawals' => 0.0];
            $salesPayments[$method]['withdrawals'] = $this->money(
                (float) $salesPayments[$method]['withdrawals'] - (float) $expense->withdrawals
            );
        }

        return $salesPayments;
    }

    private function cashMovementTotal(PosSession $session, string $type): float
    {
        return $this->money((float) CashMovement::query()
            ->where('pos_session_id', $session->id)
            ->where('type', $type)
            ->sum('amount'));
    }

    /**
     * @return array{total_discounts: float, shipping_revenue: float}
     */
    private function ordersSummary(PosSession $session): array
    {
        $query = SalesOrder::query()
            ->where('pos_session_id', $session->id)
            ->whereIn('status', ['completed', 'confirmed', 'processing']);

        return [
            'total_discounts' => $this->money((float) (clone $query)->sum('discount_amount')),
            'shipping_revenue' => $this->money((float) (clone $query)->sum('shipping_cost')),
        ];
    }

    /**
     * @param  array<string, array{payments: float, withdrawals: float}>  $payments
     * @return Collection<int, array{code: string, label: string, sort_order: int, is_active: bool, is_countable: bool, is_informational: bool}>
     */
    private function methodsForRows(array $payments): Collection
    {
        $paymentCodes = array_keys($payments);
        $specialMethods = self::NON_COUNTABLE_METHODS;

        $methods = PaymentMethod::query()
            ->where(function ($query) use ($paymentCodes): void {
                $query->where('is_active', true);

                if ($paymentCodes !== []) {
                    $query->orWhereIn('code', $paymentCodes);
                }
            })
            ->get()
            ->filter(function (PaymentMethod $method) use ($payments, $specialMethods): bool {
                if ($method->code === 'cash') {
                    return true;
                }

                if (in_array($method->code, $specialMethods, true)) {
                    return isset($payments[$method->code]);
                }

                return $method->is_active || isset($payments[$method->code]);
            })
            ->map(fn (PaymentMethod $method): array => [
                'code' => $method->code,
                'label' => $method->name,
                'sort_order' => (int) $method->sort_order,
                'is_active' => (bool) $method->is_active,
                'is_countable' => ! in_array($method->code, self::NON_COUNTABLE_METHODS, true),
                'is_informational' => in_array($method->code, self::NON_COUNTABLE_METHODS, true),
            ]);

        foreach ($paymentCodes as $code) {
            if ($methods->contains('code', $code)) {
                continue;
            }

            if (in_array($code, $specialMethods, true) && ! isset($payments[$code])) {
                continue;
            }

            $methods->push([
                'code' => $code,
                'label' => PaymentMethod::getNameByCode($code),
                'sort_order' => 999,
                'is_active' => false,
                'is_countable' => ! in_array($code, self::NON_COUNTABLE_METHODS, true),
                'is_informational' => in_array($code, self::NON_COUNTABLE_METHODS, true),
            ]);
        }

        if (! $methods->contains('code', 'cash')) {
            $methods->push([
                'code' => 'cash',
                'label' => PaymentMethod::getNameByCode('cash'),
                'sort_order' => 0,
                'is_active' => true,
                'is_countable' => true,
                'is_informational' => false,
            ]);
        }

        return $methods
            ->unique('code')
            ->sort(function (array $a, array $b): int {
                if ($a['code'] === 'cash') {
                    return -1;
                }

                if ($b['code'] === 'cash') {
                    return 1;
                }

                if ($a['sort_order'] !== $b['sort_order']) {
                    return $a['sort_order'] <=> $b['sort_order'];
                }

                return $a['label'] <=> $b['label'];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $closingDetails
     * @return array<string, float>
     */
    private function declaredCounts(array $closingDetails, ?float $declaredCash): array
    {
        $counts = [];

        foreach (($closingDetails['payment_method_counts'] ?? []) as $code => $amount) {
            if (! is_string($code) || trim($code) === '') {
                continue;
            }

            $counts[$code] = $this->money((float) $amount);
        }

        if ($declaredCash !== null) {
            $counts['cash'] = $this->money($declaredCash);
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{total_discounts: float, shipping_revenue: float}  $ordersSummary
     * @return array<string, mixed>
     */
    private function summary(PosSession $session, array $rows, array $ordersSummary, float $movementsIn, float $movementsOut): array
    {
        $rowsCollection = collect($rows);
        $countableRows = $rowsCollection
            ->filter(fn (array $row): bool => (bool) ($row['is_countable'] ?? ! in_array((string) ($row['code'] ?? ''), self::NON_COUNTABLE_METHODS, true)))
            ->values();
        $informationalRows = $rowsCollection
            ->filter(fn (array $row): bool => (bool) ($row['is_informational'] ?? in_array((string) ($row['code'] ?? ''), self::NON_COUNTABLE_METHODS, true)))
            ->values();

        $cashRow = $countableRows->firstWhere('code', 'cash') ?? [];
        $electronicRows = $countableRows->where('code', '!=', 'cash')->values();

        $electronicBreakdown = $electronicRows
            ->mapWithKeys(fn (array $row): array => [$row['code'] => $this->money((float) $row['expected_total'])])
            ->all();

        $informationalBreakdown = $informationalRows
            ->mapWithKeys(fn (array $row): array => [$row['code'] => $this->money((float) ($row['expected_total'] ?? 0.0))])
            ->all();

        $positiveElectronicSales = $electronicRows->sum(fn (array $row): float => (float) $row['payments']);
        $positiveTotalSales = $countableRows->sum(fn (array $row): float => (float) $row['payments']);
        $expectedTotal = $countableRows->sum(fn (array $row): float => (float) $row['expected_total']);
        $declaredTotal = $countableRows->sum(fn (array $row): float => (float) $row['declared_total']);
        $accountsReceivableGenerated = $informationalRows
            ->where('code', 'credit')
            ->sum(fn (array $row): float => (float) ($row['expected_total'] ?? 0.0));
        $storeCreditApplied = $informationalRows
            ->where('code', 'store_credit')
            ->sum(fn (array $row): float => (float) ($row['expected_total'] ?? 0.0));
        $informationalTotal = $informationalRows
            ->sum(fn (array $row): float => (float) ($row['expected_total'] ?? 0.0));

        return [
            'opening_amount' => $this->money((float) $session->opening_amount),
            'cash_sales' => $this->money((float) ($cashRow['payments'] ?? 0)),
            'electronic_sales' => $this->money($positiveElectronicSales),
            'total_sales' => $this->money($positiveTotalSales),
            'total_discounts' => $ordersSummary['total_discounts'],
            'shipping_revenue' => $ordersSummary['shipping_revenue'],
            'cash_movements_in' => $movementsIn,
            'cash_movements_out' => $movementsOut,
            'expected_cash' => $this->money((float) ($cashRow['expected_total'] ?? 0)),
            'declared_cash' => $this->money((float) ($cashRow['declared_total'] ?? 0)),
            'difference' => $this->money((float) ($cashRow['difference'] ?? 0)),
            'expected_total' => $this->money($expectedTotal),
            'declared_total' => $this->money($declaredTotal),
            'total_difference' => $this->money($declaredTotal - $expectedTotal),
            'total_billed' => $this->money($positiveTotalSales),
            'electronic_breakdown' => $electronicBreakdown,
            'informational_breakdown' => $informationalBreakdown,
            'accounts_receivable_generated' => $this->money($accountsReceivableGenerated),
            'store_credit_applied' => $this->money($storeCreditApplied),
            'informational_total' => $this->money($informationalTotal),
            'reconciliation_rows' => $rows,
        ];
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }
}
