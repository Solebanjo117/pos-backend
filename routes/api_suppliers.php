<?php

use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Supplier API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('suppliers')->middleware('role:admin,manager,buyer')->group(function () {
    // Dropdown (all active, no pagination)
    Route::get('/all', [SupplierController::class, 'all']);

    // CRUD
    Route::get('/', [SupplierController::class, 'index']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::get('/commercial-dashboard', [SupplierController::class, 'commercialDashboard']);
    Route::get('/{id}', [SupplierController::class, 'show']);
    Route::put('/{id}', [SupplierController::class, 'update']);
    Route::delete('/{id}', [SupplierController::class, 'destroy']);

    // Sub-resources
    Route::get('/{supplier}/purchase-history', [SupplierController::class, 'purchaseHistory']);
    Route::get('/{id}/purchase-orders', [SupplierController::class, 'purchaseOrders']);
    Route::get('/{id}/variants', [SupplierController::class, 'variants']);
    Route::post('/{id}/variants', [SupplierController::class, 'attachVariant']);
    Route::put('/{id}/variants/{variantId}', [SupplierController::class, 'updateVariant']);
    Route::delete('/{id}/variants/{variantId}', [SupplierController::class, 'detachVariant']);
    Route::get('/{id}/brands', [SupplierController::class, 'brands']);
    Route::post('/{id}/brands', [SupplierController::class, 'attachBrand']);
    Route::delete('/{id}/brands/{brandId}', [SupplierController::class, 'detachBrand']);
});
