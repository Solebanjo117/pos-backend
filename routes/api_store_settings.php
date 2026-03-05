<?php

use App\Http\Controllers\Api\StoreSettingController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------
// Store Settings — admin only
// ---------------------------------------------------------------
// Public route to fetch store name, logo, etc. for the login page
Route::get('/store-settings', [StoreSettingController::class, 'index']);

// Protected routes to modify settings
Route::middleware('auth')->group(function () {
    Route::put('/store-settings',        [StoreSettingController::class, 'update']);
    Route::post('/store-settings/logo',  [StoreSettingController::class, 'uploadLogo']);
});
