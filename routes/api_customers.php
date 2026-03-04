<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Support\Facades\Route;

// TODO: Add auth:sanctum middleware back after authentication is implemented
Route::prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/search', [CustomerController::class, 'search']);
    Route::get('/{customer}', [CustomerController::class, 'show']);
    Route::put('/{customer}', [CustomerController::class, 'update']);
    Route::delete('/{customer}', [CustomerController::class, 'destroy']);
    
    Route::get('/{id}/transactions', [CustomerController::class, 'transactions']);
    Route::get('/{id}/orders', [CustomerController::class, 'orders']);
    Route::post('/{customer}/adjust-balance', [CustomerController::class, 'adjustBalance']);
});
