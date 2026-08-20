<?php

use App\Models\Product;
use App\Models\ProductPricing;

$products = Product::with(['variants.pricing'])->get();
$migrated = 0;
$deleted = 0;

foreach ($products as $product) {
    $hasPricing = ProductPricing::where('product_id', $product->id)->exists();
    if ($hasPricing) {
        continue;
    }

    $firstPricing = null;
    foreach ($product->variants as $variant) {
        if ($variant->pricing) {
            $firstPricing = $variant->pricing;
            break;
        }
    }

    if ($firstPricing) {
        $firstPricing->product_id = $product->id;
        $firstPricing->save();
        $migrated++;
    }
}

$deleted = ProductPricing::whereNull('product_id')->delete();

echo "Migrated $migrated product pricings, deleted $deleted duplicates.\n";
