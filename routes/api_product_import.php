<?php

use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('products/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [ProductImportController::class, 'template']);
    Route::post('/preview', [ProductImportController::class, 'preview']);
    Route::post('/', [ProductImportController::class, 'import']);
});
