<?php

use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Supplier API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('suppliers')->group(function () {
    // Dropdown (all active, no pagination)
    Route::get('/all', [SupplierController::class, 'all']);

    // CRUD
    Route::get('/', [SupplierController::class, 'index']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::get('/{id}', [SupplierController::class, 'show']);
    Route::put('/{id}', [SupplierController::class, 'update']);
    Route::delete('/{id}', [SupplierController::class, 'destroy']);

    // Sub-resources
    Route::get('/{id}/purchase-orders', [SupplierController::class, 'purchaseOrders']);
});
