<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

// TODO: Add auth:sanctum middleware back after authentication is implemented
Route::prefix('customers')->middleware('role:admin,manager,seller,cashier')->group(function () {
    Route::get('/', [CustomerController::class, 'index']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/search', [CustomerController::class, 'search']);
    Route::get('/{customer}', [CustomerController::class, 'show']);
    Route::put('/{customer}', [CustomerController::class, 'update']);
    Route::get('/{id}/orders', [CustomerController::class, 'orders']);

    Route::middleware('role:admin,manager')->group(function () {
        Route::delete('/{customer}', [CustomerController::class, 'destroy']);
        Route::get('/{id}/transactions', [CustomerController::class, 'transactions']);
        Route::post('/{customer}/adjust-balance', [CustomerController::class, 'adjustBalance']);
    });
});
