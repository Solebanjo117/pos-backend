<?php

use App\Models\ProductPricing;

test('calculated_price evaluates pricing_formula using cost and not base_price', function () {
    $pricing = new ProductPricing([
        'cost' => 50,
        'base_price' => 100,
        'pricing_formula' => 'costo * 2',
        'tax_rate' => 0,
    ]);

    expect($pricing->calculated_price)->toBe(100.0);
});

test('calculated_price falls back to base_price context when cost is missing', function () {
    $pricing = new ProductPricing([
        'cost' => null,
        'base_price' => 100,
        'pricing_formula' => 'costo * 2',
        'tax_rate' => 0,
    ]);

    expect($pricing->calculated_price)->toBe(200.0);
});
