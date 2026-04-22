<?php

use App\Http\Controllers\Api\BrandController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Brand API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('brands')->group(function () {
    Route::middleware('role:admin,manager,buyer,seller,cashier,assistant')->group(function () {
        Route::get('/all', [BrandController::class, 'all']);
        Route::get('/', [BrandController::class, 'index']);
        Route::get('/{id}', [BrandController::class, 'show']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/', [BrandController::class, 'store']);
        Route::put('/{id}', [BrandController::class, 'update']);
        Route::delete('/{id}', [BrandController::class, 'destroy']);
        Route::post('/{id}/suppliers', [BrandController::class, 'attachSupplier']);
        Route::delete('/{id}/suppliers/{supplierId}', [BrandController::class, 'detachSupplier']);
    });
});
