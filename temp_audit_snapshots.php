<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FinancialDailySnapshot;

$last = FinancialDailySnapshot::orderByDesc('date')->first();
if (! $last) {
    echo "NO SNAPSHOTS FOUND\n";

    return;
}

$wid = $last->warehouse_id;
$toDate = $last->date->toDateString();

echo "Last snapshot: {$toDate} (warehouse: {$wid})\n";
echo 'Computed at: '.($last->computed_at?->toString() ?? 'null')."\n\n";

$snapshots = FinancialDailySnapshot::whereDate('date', '>=', '2026-01-01')
    ->whereDate('date', '<=', $toDate)
    ->when($wid, fn ($q) => $q->where('warehouse_id', $wid))
    ->orderBy('date')
    ->get();

echo 'Snapshots found: '.$snapshots->count()."\n\n";

// Per-day identity check
$totalDiff = 0;
$problemDays = [];
$allDays = [];

foreach ($snapshots as $s) {
    $compras = (float) $s->cash_purchases + (float) $s->bank_purchases + (float) $s->credit_purchases + (float) ($s->purchase_adjustments ?? 0);
    $pagado = (float) $s->cash_purchases + (float) $s->bank_purchases + (float) $s->supplier_payments + (float) ($s->supplier_bank_payments ?? 0);
    $cxp = (float) $s->credit_purchases + (float) ($s->accounts_payable_adjustments ?? 0);
    $diff = round($compras - $pagado - $cxp, 2);

    $allDays[] = [
        'date' => $s->date->toDateString(),
        'compras' => $compras,
        'pagado' => $pagado,
        'cxp' => $cxp,
        'diff' => $diff,
        'cash_purchases' => (float) $s->cash_purchases,
        'bank_purchases' => (float) ($s->bank_purchases ?? 0),
        'credit_purchases' => (float) $s->credit_purchases,
        'purchase_adjustments' => (float) ($s->purchase_adjustments ?? 0),
        'supplier_payments' => (float) $s->supplier_payments,
        'supplier_bank_payments' => (float) ($s->supplier_bank_payments ?? 0),
        'ap_adjustments' => (float) ($s->accounts_payable_adjustments ?? 0),
    ];

    if (abs($diff) > 0.005) {
        $totalDiff += $diff;
        $problemDays[] = $s->date->toDateString();
    }
}

// Show days with non-zero purchases OR non-zero diff
echo "=== DAYS WITH PURCHASES OR DISCREPANCY ===\n";
foreach ($allDays as $d) {
    if ($d['compras'] > 0.005 || abs($d['diff']) > 0.005 || $d['purchase_adjustments'] != 0 || $d['ap_adjustments'] != 0) {
        $marker = abs($d['diff']) > 0.005 ? ' *** DISCREPANCY ***' : '';
        echo "{$d['date']}: COMPRAS={$d['compras']} PAGADO={$d['pagado']} CxP={$d['cxp']} DIFF={$d['diff']}{$marker}\n";
        if ($d['purchase_adjustments'] != 0 || $d['ap_adjustments'] != 0) {
            echo "  purchase_adjustments={$d['purchase_adjustments']} ap_adjustments={$d['ap_adjustments']}\n";
        }
    }
}

echo "\n=== SUMMARY ===\n";
echo 'Problem days: '.count($problemDays)."\n";
echo 'Total discrepancy: '.number_format($totalDiff, 2)."\n";

// AP balance identity
$first = $snapshots->first();
$lastSnap = $snapshots->last();
echo "\n=== AP BALANCE IDENTITY ===\n";
echo 'Opening AP (first day): '.number_format((float) $first->opening_accounts_payable, 2)."\n";
echo 'Closing AP (last day): '.number_format((float) $lastSnap->accounts_payable, 2)."\n";
echo 'Sum credit_purchases: '.number_format((float) $snapshots->sum('credit_purchases'), 2)."\n";
echo 'Sum supplier_payments: '.number_format((float) $snapshots->sum('supplier_payments'), 2)."\n";
echo 'Sum supplier_bank_payments: '.number_format((float) $snapshots->sum(fn ($s) => (float) ($s->supplier_bank_payments ?? 0)), 2)."\n";
echo 'Sum ap_adjustments: '.number_format((float) $snapshots->sum(fn ($s) => (float) ($s->accounts_payable_adjustments ?? 0)), 2)."\n";

$expectedAp = (float) $first->opening_accounts_payable
    + (float) $snapshots->sum('credit_purchases')
    - (float) $snapshots->sum('supplier_payments')
    - (float) $snapshots->sum(fn ($s) => (float) ($s->supplier_bank_payments ?? 0))
    + (float) $snapshots->sum(fn ($s) => (float) ($s->accounts_payable_adjustments ?? 0));
echo 'Expected closing AP: '.number_format($expectedAp, 2)."\n";
echo 'Actual closing AP: '.number_format((float) $lastSnap->accounts_payable, 2)."\n";
echo 'AP diff: '.number_format((float) $lastSnap->accounts_payable - $expectedAp, 2)."\n";
