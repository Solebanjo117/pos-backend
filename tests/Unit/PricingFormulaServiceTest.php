<?php

use App\Services\PricingFormulaService;

test('nested parentheses: ROUND(costo * (1.5 + 0.5))', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('ROUND(costo * (1.5 + 0.5))', 100);
    expect($result)->toBe(200.0);
});

test('deeply nested parentheses: ROUND((costo + 50) * (2 + (1 / 2)))', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('ROUND((costo + 50) * (2 + (1 / 2)))', 100);
    expect($result)->toBe(375.0); // (100 + 50) * (2 + 0.5) = 150 * 2.5 = 375
});

test('division by zero throws exception', function () {
    $service = new PricingFormulaService;
    $service->evaluate('costo / 0', 100);
})->throws(\InvalidArgumentException::class, 'División por cero');

test('division by very small number throws exception', function () {
    $service = new PricingFormulaService;
    $service->evaluate('costo / 0.0000000001', 100);
})->throws(\InvalidArgumentException::class, 'División por cero');

test('no double ITBM when formula uses ITBM explicitly', function () {
    $service = new PricingFormulaService;
    $result = $service->calculate('costo + costo * ITBM', 100.0, true, 7.0);
    expect($result)->toBe(107.0); // Not 114.49!
});

test('ITBM applied externally when formula does NOT use ITBM', function () {
    $service = new PricingFormulaService;
    $result = $service->calculate('costo * 1.5', 100.0, true, 7.0);
    expect($result)->toBe(160.50); // 150 * 1.07 = 160.50
});

test('ITBM not applied when appliesItbm is false', function () {
    $service = new PricingFormulaService;
    $result = $service->calculate('costo * 1.5', 100.0, false, 7.0);
    expect($result)->toBe(150.0); // No ITBM applied
});

test('formula with ITBM respects custom itbmPercent', function () {
    $service = new PricingFormulaService;
    $result = $service->calculate('costo + costo * ITBM', 100.0, true, 10.0);
    expect($result)->toBe(110.0); // Uses 10% not 7%
});

test('complex formula: ROUNDUP((costo * (1 + ITBM)) * 1.5)', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('ROUNDUP((costo * (1 + ITBM)) * 1.5)', 100, 7.0);
    expect($result)->toBe(161.0); // (100 * 1.07) * 1.5 = 160.5 → roundup = 161
});

test('multiple nested functions: ROUND(CEIL(costo * 1.23))', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('ROUND(CEIL(costo * 1.23))', 100);
    expect($result)->toBe(123.0);
});

test('function with negative result: ROUND(costo - 150)', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('ROUND(costo - 150)', 100);
    expect($result)->toBe(-50.0);
});

test('empty formula returns cost', function () {
    $service = new PricingFormulaService;
    $result = $service->evaluate('', 100);
    expect($result)->toBe(100.0);
});
