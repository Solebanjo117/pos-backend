<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FinancialAdjustment;
use Illuminate\Support\Facades\DB;

echo "=== ALL FINANCIAL ADJUSTMENTS BY TYPE ===\n";
$all = DB::table('financial_adjustments')
    ->select('type', DB::raw('count(*) as cnt'), DB::raw('sum(amount) as total'))
    ->groupBy('type')
    ->get();
foreach ($all as $row) {
    echo "{$row->type}: count={$row->cnt} total=".number_format($row->total, 2)."\n";
}

echo "\n=== PURCHASE ADJUSTMENTS DETAIL ===\n";
$purchAdj = FinancialAdjustment::where('type', 'purchase')->get();
echo 'Count: '.$purchAdj->count()."\n";
foreach ($purchAdj as $a) {
    echo "  ID={$a->id} date={$a->adjustment_date} amount={$a->amount} signed=".$a->signedAmount()." warehouse={$a->warehouse_id}\n";
}

echo "\n=== ACCOUNTS PAYABLE ADJUSTMENTS DETAIL ===\n";
$apAdj = FinancialAdjustment::where('type', 'accounts_payable')->get();
echo 'Count: '.$apAdj->count()."\n";
foreach ($apAdj as $a) {
    echo "  ID={$a->id} date={$a->adjustment_date} amount={$a->amount} signed=".$a->signedAmount()." warehouse={$a->warehouse_id}\n";
}

echo "\n=== ALL ADJUSTMENTS DETAIL ===\n";
$allAdj = FinancialAdjustment::orderBy('adjustment_date')->get();
echo 'Total: '.$allAdj->count()."\n";
foreach ($allAdj as $a) {
    echo "  ID={$a->id} type={$a->type} date={$a->adjustment_date} amount={$a->amount} signed=".$a->signedAmount()." warehouse={$a->warehouse_id} financial_account={$a->financial_account_id}\n";
}
