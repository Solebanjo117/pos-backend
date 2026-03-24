<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sql = "
    SELECT so.id, so.total, so.payment_status, op.method, op.amount
    FROM sales_orders so
    JOIN sales_order_payments op ON op.sales_order_id = so.id
    WHERE so.payment_status = 'paid'
    AND op.method = 'credit'
    GROUP BY so.id, so.total, so.payment_status, op.method, op.amount
";

$results = \Illuminate\Support\Facades\DB::select($sql);
file_put_contents(__DIR__.'/query_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Done. Found " . count($results) . " records.\n";
