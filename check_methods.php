<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "All payment methods:\n";
$all = App\Models\PaymentMethod::all();
foreach ($all as $method) {
    echo "- {$method->code}: {$method->name} (active: " . ($method->is_active ? 'yes' : 'no') . ")\n";
}

echo "\nActive payment methods codes:\n";
$active = App\Models\PaymentMethod::active()->pluck('code')->toArray();
echo implode(', ', $active) . "\n";
