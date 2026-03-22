<?php

require 'bootstrap/app.php';

$app->boot();

echo "Brands: " . \DB::table('brands')->count() . "\n";
echo "Products: " . \DB::table('products')->count() . "\n";
echo "Product Variants: " . \DB::table('product_variants')->count() . "\n";
echo "Warehouses: " . \DB::table('warehouses')->count() . "\n";
echo "\nBrand list:\n";
$brands = \DB::table('brands')->select('id', 'name')->get();
foreach ($brands as $b) {
    echo "  ID {$b->id}: {$b->name}\n";
}
