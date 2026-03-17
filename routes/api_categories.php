<?php

use App\Http\Controllers\Api\CategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Category API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('categories')->group(function () {
    Route::middleware('role:admin,manager,buyer,seller,cashier,assistant')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{category}', [CategoryController::class, 'show']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/', [CategoryController::class, 'store']);
        Route::put('/{category}', [CategoryController::class, 'update']);
        Route::delete('/{category}', [CategoryController::class, 'destroy']);
    });
});
