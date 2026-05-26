<?php

use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('products/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [ProductImportController::class, 'template']);
    Route::get('/profiles', [ProductImportController::class, 'profiles']);
    Route::post('/profiles', [ProductImportController::class, 'storeProfile']);
    Route::post('/preview', [ProductImportController::class, 'preview']);
    Route::post('/validate', [ProductImportController::class, 'validateFile']);
    Route::post('/execute', [ProductImportController::class, 'execute']);
    Route::get('/runs', [ProductImportController::class, 'runs']);
    Route::get('/runs/{importRun}/errors.xlsx', [ProductImportController::class, 'errors']);
    Route::post('/runs/{importRun}/retry-failed', [ProductImportController::class, 'retryFailed']);
    Route::post('/runs/{importRun}/process-now', [ProductImportController::class, 'processNow']);
    Route::post('/runs/{importRun}/cancel', [ProductImportController::class, 'cancel']);
    Route::get('/runs/{importRun}', [ProductImportController::class, 'run']);
    Route::post('/', [ProductImportController::class, 'import']);
});
