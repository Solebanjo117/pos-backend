<?php

use App\Http\Controllers\Api\PricingFormulaController;
use Illuminate\Support\Facades\Route;

Route::post('pricing-formulas/preview', [PricingFormulaController::class, 'preview']);
Route::get('pricing-formulas/active', [PricingFormulaController::class, 'active']);
Route::apiResource('pricing-formulas', PricingFormulaController::class);
