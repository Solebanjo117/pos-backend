<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = \App\Http\Requests\StoreProductRequest::create("/api/v1/products", "POST", [
    "name" => "",
    "sku_base" => "",
    "sku" => "",
    "brand_id" => 1,
    "pricing" => [
        "base_price" => 10,
        "tax_rate" => 7,
    ]
]);
$validator = validator($request->all(), (new \App\Http\Requests\StoreProductRequest())->rules());
if ($validator->fails()) {
    echo json_encode($validator->errors());
} else {
    echo "Validation Passed\n";
}
