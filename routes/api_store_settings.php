<?php

use App\Http\Controllers\Api\StoreSettingController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------
// Store Settings — admin only
// ---------------------------------------------------------------
// Public route to fetch store name, logo, etc. for the login page
Route::get('/store-settings', [StoreSettingController::class, 'index']);

// Pricing formula — public read (for product forms to apply formula)
Route::get('/pricing-formula/presets', [StoreSettingController::class, 'formulaPresets']);
Route::post('/pricing-formula/preview', [StoreSettingController::class, 'previewFormula']);

// Protected routes to modify settings
Route::middleware('auth')->group(function () {
    Route::put('/store-settings', [StoreSettingController::class, 'update']);
    Route::post('/store-settings/logo', [StoreSettingController::class, 'uploadLogo']);
});
