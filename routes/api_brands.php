<?php

use App\Http\Controllers\Api\BrandController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Brand API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('brands')->group(function () {
    Route::get('/all', [BrandController::class, 'all']);
    Route::get('/', [BrandController::class, 'index']);
    Route::get('/{id}', [BrandController::class, 'show']);
    Route::post('/', [BrandController::class, 'store']);
    Route::put('/{id}', [BrandController::class, 'update']);
    Route::delete('/{id}', [BrandController::class, 'destroy']);
});
