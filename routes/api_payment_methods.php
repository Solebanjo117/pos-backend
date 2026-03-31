<?php

use App\Http\Controllers\Api\PaymentMethodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment Methods Routes
|--------------------------------------------------------------------------
*/

// Public to all authenticated users - list active methods
Route::get('/payment-methods', [PaymentMethodController::class, 'index']);

// Admin/manager only - full management
Route::middleware('role:admin,manager')->group(function () {
    Route::get('/payment-methods/all', [PaymentMethodController::class, 'all']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
    Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
    Route::post('/payment-methods/reorder', [PaymentMethodController::class, 'reorder']);
});
