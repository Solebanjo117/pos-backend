<?php

namespace App\Services;

class FinancialReconciliationService
{
    private const METRICS = [
        'sales_total' => 'sales',
        'sales_collected' => 'payments_received',
        'receivables_generated' => 'receivables_generated',
        'cogs' => 'cogs',
        'purchase' => 'purchases',
        'purchase_paid' => 'supplier_payments',
        'payables_generated' => 'payables_generated',
        'expenses' => 'expenses',
        'net_profit' => 'profit',
    ];

    public function __construct(
        private readonly FinancialSnapshotService $snapshots,
        private readonly FinancialDrilldownService $drilldowns,
    ) {}

    /** @param array<string, mixed> $filters */
    public function reconcile(array $filters): array
    {
        $summary = $this->snapshots->rangeSummary($filters);
        $results = [];
        $maxDifference = 0.0;

        foreach (self::METRICS as $summaryKey => $metric) {
            $detail = $this->drilldowns->handle([
                ...$filters,
                'metric' => $metric,
                'page' => 1,
                'per_page' => 10,
            ]);
            $expected = round((float) data_get($summary, 'totals.'.$summaryKey, 0), 2);
            $actual = round((float) ($detail['total'] ?? 0), 2);
            $difference = round($expected - $actual, 2);
            $maxDifference = max($maxDifference, abs($difference));
            $results[] = [
                'summary_key' => $summaryKey,
                'metric' => $metric,
                'expected' => $expected,
                'actual' => $actual,
                'difference' => $difference,
                'passed' => abs($difference) <= 0.01,
            ];
        }

        $grossProfitExpected = round(
            (float) data_get($summary, 'totals.sales_total', 0)
            - (float) data_get($summary, 'totals.cogs', 0),
            2,
        );
        $netProfitExpected = round(
            $grossProfitExpected - (float) data_get($summary, 'totals.expenses', 0),
            2,
        );
        $profitDifference = round(
            $netProfitExpected - (float) data_get($summary, 'totals.net_profit', 0),
            2,
        );
        $maxDifference = max($maxDifference, abs($profitDifference));

        return [
            'passed' => $maxDifference <= 0.01,
            'max_difference' => round($maxDifference, 2),
            'metrics' => $results,
            'equations' => [
                'gross_profit' => $grossProfitExpected,
                'net_profit_expected' => $netProfitExpected,
                'net_profit_actual' => round((float) data_get($summary, 'totals.net_profit', 0), 2),
                'difference' => $profitDifference,
                'passed' => abs($profitDifference) <= 0.01,
            ],
        ];
    }
}
