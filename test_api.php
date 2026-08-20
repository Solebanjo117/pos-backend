<?php

$url = 'http://api.pos-frontend.test/api/v1/products';
$data = [
    'name' => '',
    'sku_base' => '',
    'sku' => '',
    'brand_id' => 1,
    'pricing' => [
        'base_price' => 10,
        'tax_rate' => 7,
    ],
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
