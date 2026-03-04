<?php

use App\Models\User;
use App\Models\Warehouse;
use App\Models\Supplier;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking database records...\n";

$user = User::find(1);
echo "User ID 1: " . ($user ? "Exists" : "MISSING") . "\n";
if (!$user) {
    echo "First User: " . (User::first() ? User::first()->id : "NONE") . "\n";
}

$warehouse = Warehouse::find(1);
echo "Warehouse ID 1: " . ($warehouse ? "Exists" : "MISSING") . "\n";
if (!$warehouse) {
    echo "First Warehouse: " . (Warehouse::first() ? Warehouse::first()->id : "NONE") . "\n";
}

$supplier = Supplier::find(1);
echo "Supplier ID 1: " . ($supplier ? "Exists" : "MISSING") . "\n";
if (!$supplier) {
    echo "First Supplier: " . (Supplier::first() ? Supplier::first()->id : "NONE") . "\n";
}
