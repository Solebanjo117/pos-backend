<?php

use App\Http\Controllers\Api\CustomerBulkController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerImportController;
use App\Http\Controllers\Api\CustomerTypeController;
use App\Http\Controllers\Api\CustomerVehicleController;
use Illuminate\Support\Facades\Route;

// TODO: Add auth:sanctum middleware back after authentication is implemented
Route::prefix('customers')->middleware('role:admin,manager,seller,cashier')->group(function () {
    Route::get('/', [CustomerController::class, 'index']);
    Route::get('/export', [CustomerController::class, 'export']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/search', [CustomerController::class, 'search']);
    Route::get('/{customer}/vehicles', [CustomerVehicleController::class, 'index']);
    Route::post('/{customer}/vehicles', [CustomerVehicleController::class, 'store']);
    Route::put('/{customer}/vehicles/{vehicle}', [CustomerVehicleController::class, 'update']);
    Route::delete('/{customer}/vehicles/{vehicle}', [CustomerVehicleController::class, 'destroy']);
    Route::get('/{customer}/sales-history', [CustomerController::class, 'salesHistory']);
    Route::get('/{customer}', [CustomerController::class, 'show']);
    Route::put('/{customer}', [CustomerController::class, 'update']);
    Route::get('/{id}/orders', [CustomerController::class, 'orders']);

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/bulk-action', [CustomerBulkController::class, 'customers']);
        Route::delete('/{customer}', [CustomerController::class, 'destroy']);
        Route::get('/{id}/transactions', [CustomerController::class, 'transactions']);
        Route::post('/{customer}/adjust-balance', [CustomerController::class, 'adjustBalance']);
    });
});

Route::prefix('customers/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [CustomerImportController::class, 'template']);
    Route::post('/preview', [CustomerImportController::class, 'preview']);
    Route::post('/execute', [CustomerImportController::class, 'execute']);
});

Route::prefix('customer-types')->middleware('role:admin,manager,seller,cashier')->group(function () {
    Route::get('/', [CustomerTypeController::class, 'index']);
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/bulk-action', [CustomerBulkController::class, 'customerTypes']);
        Route::post('/', [CustomerTypeController::class, 'store']);
        Route::put('/{customerType}', [CustomerTypeController::class, 'update']);
        Route::delete('/{customerType}', [CustomerTypeController::class, 'destroy']);
    });
});
