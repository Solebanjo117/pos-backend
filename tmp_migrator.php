<?php
use App\Models\ProductPricing;

$pricings = ProductPricing::whereNull('product_id')->get();
$count = 0;
foreach($pricings as $p) {
    if ($p->variant) {
        $p->product_id = $p->variant->product_id;
        $p->save();
        $count++;
    }
}
echo "Migrated $count rows\n";
