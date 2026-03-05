<?php

use App\Http\Controllers\Api\StoreSettingController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------
// Store Settings — admin only
// ---------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/store-settings',        [StoreSettingController::class, 'index']);
    Route::put('/store-settings',        [StoreSettingController::class, 'update']);
    Route::post('/store-settings/logo',  [StoreSettingController::class, 'uploadLogo']);
});
