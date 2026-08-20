<?php

/**
 * DIAGNÓSTICO: Resumen Diario — Identidad COMPRAS = PAGADO + CxP
 *
 * Ejecutar en producción:
 *   php temp_diagnose_daily_summary.php [date_from] [date_to] [warehouse_id]
 *
 * Ejemplo:
 *   php temp_diagnose_daily_summary.php 2026-08-01 2026-08-17
 *   php temp_diagnose_daily_summary.php 2026-08-01 2026-08-17 2
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FinancialAdjustment;
use App\Models\FinancialDailySnapshot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;

$dateFrom = $argv[1] ?? now()->startOfMonth()->toDateString();
$dateTo = $argv[2] ?? now()->toDateString();
$warehouseId = isset($argv[3]) ? (int) $argv[3] : null;

echo "============================================================\n";
echo "DIAGNÓSTICO: Resumen Diario\n";
echo "Rango: {$dateFrom} → {$dateTo}\n";
echo 'Warehouse: '.($warehouseId ?: 'TODOS')."\n";
echo "============================================================\n\n";

// ─── 1. SNAPSHOT DATA ───────────────────────────────────────────
$snapshots = FinancialDailySnapshot::whereDate('date', '>=', $dateFrom)
    ->whereDate('date', '<=', $dateTo)
    ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
    ->orderBy('date')
    ->get();

echo "1. SNAPSHOT DATA\n";
echo '   Snapshots en rango: '.$snapshots->count()."\n\n";

if ($snapshots->isEmpty()) {
    echo "   NO HAY SNAPSHOTS para este rango. Ejecuta con rebuild=true primero.\n";

    return;
}

// ─── 2. IDENTITY CHECK PER DAY ──────────────────────────────────
echo "2. IDENTIDAD POR DÍA: COMPRAS = PAGADO + CxP\n";
echo str_repeat('-', 120)."\n";
echo sprintf("%-12s %12s %12s %12s %12s %12s %12s %12s\n", 'FECHA', 'COMPRAS', 'PAGADO', 'CxP', 'DIFF', 'PurchAdj', 'SupPay', 'ApAdj');
echo str_repeat('-', 120)."\n";

$totalCompras = 0;
$totalPagado = 0;
$totalCxp = 0;
$problemDays = [];

foreach ($snapshots as $s) {
    $cashPurchases = (float) $s->cash_purchases;
    $bankPurchases = (float) ($s->bank_purchases ?? 0);
    $creditPurchases = (float) $s->credit_purchases;
    $purchaseAdj = (float) ($s->purchase_adjustments ?? 0);
    $supplierPay = (float) $s->supplier_payments;
    $supplierBankPay = (float) ($s->supplier_bank_payments ?? 0);
    $apAdj = (float) ($s->accounts_payable_adjustments ?? 0);

    $compras = $cashPurchases + $bankPurchases + $creditPurchases + $purchaseAdj;
    $pagado = $cashPurchases + $bankPurchases + $supplierPay + $supplierBankPay;
    $cxp = $creditPurchases + $apAdj;
    $diff = round($compras - $pagado - $cxp, 2);

    $totalCompras += $compras;
    $totalPagado += $pagado;
    $totalCxp += $cxp;

    $marker = abs($diff) > 0.005 ? '  ***' : '';
    echo sprintf(
        "%-12s %12s %12s %12s %12s %12s %12s %12s%s\n",
        $s->date->toDateString(),
        number_format($compras, 2),
        number_format($pagado, 2),
        number_format($cxp, 2),
        number_format($diff, 2),
        number_format($purchaseAdj, 2),
        number_format($supplierPay, 2),
        number_format($apAdj, 2),
        $marker
    );

    if (abs($diff) > 0.005) {
        $problemDays[] = [
            'date' => $s->date->toDateString(),
            'diff' => $diff,
            'purchase_adjustments' => $purchaseAdj,
            'accounts_payable_adjustments' => $apAdj,
            'supplier_payments' => $supplierPay,
            'supplier_bank_payments' => $supplierBankPay,
        ];
    }
}

echo str_repeat('-', 120)."\n";
$totalDiff = round($totalCompras - $totalPagado - $totalCxp, 2);
echo sprintf("%-12s %12s %12s %12s %12s\n", 'TOTAL', number_format($totalCompras, 2), number_format($totalPagado, 2), number_format($totalCxp, 2), number_format($totalDiff, 2));
echo "\n";

// ─── 3. PROBLEM DAYS ANALYSIS ───────────────────────────────────
echo "3. DÍAS CON DIFERENCIA\n";
if (empty($problemDays)) {
    echo "   Ninguno — la identidad se mantiene en todos los días.\n\n";
} else {
    foreach ($problemDays as $p) {
        echo "   {$p['date']}: DIFF = \$".number_format($p['diff'], 2)."\n";
        echo '     purchase_adjustments = $'.number_format($p['purchase_adjustments'], 2)."\n";
        echo '     accounts_payable_adjustments = $'.number_format($p['accounts_payable_adjustments'], 2)."\n";
        echo '     supplier_payments = $'.number_format($p['supplier_payments'], 2)."\n";
        echo '     supplier_bank_payments = $'.number_format($p['supplier_bank_payments'], 2)."\n";
        echo "     → La diferencia se explica por: purchase_adjustments - supplier_payments - supplier_bank_payments - accounts_payable_adjustments\n\n";
    }
}

// ─── 4. FINANCIAL ADJUSTMENTS IN RANGE ──────────────────────────
echo "4. FINANCIAL ADJUSTMENTS EN EL RANGO\n";
$adjustments = FinancialAdjustment::whereDate('adjustment_date', '>=', $dateFrom)
    ->whereDate('adjustment_date', '<=', $dateTo)
    ->when($warehouseId, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
    ->orderBy('adjustment_date')
    ->get();

if ($adjustments->isEmpty()) {
    echo "   Ninguno.\n\n";
} else {
    echo '   Count: '.$adjustments->count()."\n";
    echo sprintf("   %-6s %-20s %-12s %-10s %-12s %s\n", 'ID', 'TIPO', 'FECHA', 'MONTO', 'SIGNO', 'RAZÓN');
    echo '   '.str_repeat('-', 100)."\n";
    foreach ($adjustments as $a) {
        echo sprintf("   %-6d %-20s %-12s %-10s %-12s %s\n",
            $a->id,
            $a->type,
            $a->adjustment_date->toDateString(),
            '$'.number_format($a->amount, 2),
            ($a->direction === 'decrease' ? '-' : '+').'$'.number_format(abs($a->signedAmount()), 2),
            $a->reason ?? '-'
        );
    }
    echo "\n";

    // Breakdown by type
    echo "   Por tipo:\n";
    $byType = $adjustments->groupBy('type');
    foreach ($byType as $type => $group) {
        $total = $group->sum(fn ($a) => $a->signedAmount());
        echo "     {$type}: ".$group->count().' registros, total signado = $'.number_format($total, 2)."\n";
    }
    echo "\n";
}

// ─── 5. RAW PURCHASE DATA (CROSS-REFERENCE) ─────────────────────
echo "5. PURCHASES RAW DATA (purchase_orders + purchase_order_payments)\n";
$purchaseOrders = PurchaseOrder::whereNotIn('status', ['cancelled', 'draft'])
    ->whereDate('order_date', '>=', $dateFrom)
    ->whereDate('order_date', '<=', $dateTo)
    ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
    ->with('payments')
    ->get();

$rawTotal = 0;
$rawCash = 0;
$rawBank = 0;
$rawCredit = 0;

foreach ($purchaseOrders as $po) {
    $total = (float) $po->total;
    $rawTotal += $total;

    $sameDayCash = $po->payments
        ->filter(fn ($p) => $p->method !== 'credit' && $p->method !== 'store_credit' && $p->method !== 'supplier_credit')
        ->filter(fn ($p) => $p->paid_at?->toDateString() === $po->order_date?->toDateString())
        ->sum('amount');

    $sameDayBank = 0; // simplified — bank methods handled by PaymentMethod::liquidityBucket

    $rawCash += $sameDayCash;
    $rawCredit += max(0, $total - $sameDayCash);
}

echo '   Purchase orders in range: '.$purchaseOrders->count()."\n";
echo '   Total (raw): $'.number_format($rawTotal, 2)."\n";
echo '   Snapshot COMPRAS total: $'.number_format($totalCompras, 2)."\n";
echo '   Diff (raw vs snapshot): $'.number_format($rawTotal - $totalCompras, 2)."\n\n";

// ─── 6. SUPPLIER PAYMENTS IN RANGE ──────────────────────────────
echo "6. SUPPLIER PAYMENTS (purchase_order_payments)\n";
$supplierPayments = PurchaseOrderPayment::whereBetween('paid_at', [
    $dateFrom.' 00:00:00',
    $dateTo.' 23:59:59',
])
    ->whereNotIn('method', ['credit', 'store_credit', 'supplier_credit'])
    ->get();

echo '   Payments in range: '.$supplierPayments->count()."\n";
echo '   Total (raw): $'.number_format($supplierPayments->sum('amount'), 2)."\n";
echo '   Snapshot supplier_payments + supplier_bank_payments total: $'.number_format($totalPagado - ($totalCompras - $totalCxp - ($snapshots->sum('credit_purchases') + $snapshots->sum(fn ($s) => (float) ($s->accounts_payable_adjustments ?? 0)))), 2)."\n\n";

// ─── 7. AP BALANCE IDENTITY ─────────────────────────────────────
echo "7. AP BALANCE IDENTITY: opening_ap + credit_purchases = supplier_payments + closing_ap\n";
$first = $snapshots->first();
$lastSnap = $snapshots->last();

$openingAp = (float) $first->opening_accounts_payable;
$closingAp = (float) $lastSnap->accounts_payable;
$sumCreditPurchases = (float) $snapshots->sum('credit_purchases');
$sumSupplierPayments = (float) $snapshots->sum('supplier_payments');
$sumSupplierBankPayments = (float) $snapshots->sum(fn ($s) => (float) ($s->supplier_bank_payments ?? 0));
$sumApAdjustments = (float) $snapshots->sum(fn ($s) => (float) ($s->accounts_payable_adjustments ?? 0));

$leftSide = $openingAp + $sumCreditPurchases;
$rightSide = $sumSupplierPayments + $sumSupplierBankPayments + $closingAp - $sumApAdjustments;

echo '   Opening AP: $'.number_format($openingAp, 2)."\n";
echo '   + Credit purchases: $'.number_format($sumCreditPurchases, 2)."\n";
echo '   = Left side: $'.number_format($leftSide, 2)."\n\n";
echo '   Supplier payments: $'.number_format($sumSupplierPayments, 2)."\n";
echo '   + Supplier bank payments: $'.number_format($sumSupplierBankPayments, 2)."\n";
echo '   + Closing AP: $'.number_format($closingAp, 2)."\n";
echo '   - AP adjustments: $'.number_format($sumApAdjustments, 2)."\n";
echo '   = Right side: $'.number_format($rightSide, 2)."\n\n";
echo '   Identity diff: $'.number_format($leftSide - $rightSide, 2)."\n\n";

// ─── 8. CONCLUSIÓN ──────────────────────────────────────────────
echo "============================================================\n";
echo "8. CONCLUSIÓN\n";
echo "============================================================\n\n";

if (empty($problemDays) && abs($totalDiff) < 0.01) {
    echo "   ✅ La identidad COMPRAS = PAGADO + CxP se cumple en este rango.\n";
    echo "   Si ves una diferencia en la UI, verifica que:\n";
    echo "   a) Los snapshots estén actualizados (rebuild=true)\n";
    echo "   b) No haya ajustes manuales tipo 'purchases' o 'accounts_payable'\n";
    echo "   c) El rango de fechas sea el mismo en frontend y backend\n";
} else {
    echo '   ⚠️  La identidad NO se cumple. Diferencia total: $'.number_format($totalDiff, 2)."\n\n";
    echo "   Causa probable:\n";
    foreach ($problemDays as $p) {
        if ($p['purchase_adjustments'] != 0) {
            echo "   - Día {$p['date']}: purchase_adjustments = \$".number_format($p['purchase_adjustments'], 2)."\n";
            echo "     → Un ajuste manual tipo 'purchases' incrementa COMPRAS sin pasar por PAGADO ni CxP\n";
        }
        if ($p['accounts_payable_adjustments'] != 0) {
            echo "   - Día {$p['date']}: accounts_payable_adjustments = \$".number_format($p['accounts_payable_adjustments'], 2)."\n";
            echo "     → Un ajuste manual tipo 'accounts_payable' modifica CxP sin reflejar en COMPRAS ni PAGADO\n";
        }
    }
    echo "\n   Solución: Excluir purchase_adjustments de COMPRAS en el display,\n";
    echo "   o crear un ajuste compensatorio en PAGADO/CxP.\n";
}
