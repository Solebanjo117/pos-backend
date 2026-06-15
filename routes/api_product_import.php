<?php

use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('products/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [ProductImportController::class, 'template']);
    Route::get('/profiles', [ProductImportController::class, 'profiles']);
    Route::post('/profiles', [ProductImportController::class, 'storeProfile'])->middleware('throttle:product-import-mutation');
    Route::post('/metadata', [ProductImportController::class, 'metadata'])->middleware('throttle:product-import-heavy');
    Route::post('/preview', [ProductImportController::class, 'preview'])->middleware('throttle:product-import-heavy');
    Route::post('/validate', [ProductImportController::class, 'validateFile'])->middleware('throttle:product-import-heavy');
    Route::post('/validate-rows', [ProductImportController::class, 'validateRows'])->middleware('throttle:product-import-heavy');
    Route::post('/export-preview', [ProductImportController::class, 'exportPreview'])->middleware('throttle:product-import-export');
    Route::post('/references/batch', [ProductImportController::class, 'batchReferences'])->middleware('throttle:product-import-mutation');
    Route::post('/execute', [ProductImportController::class, 'execute'])->middleware('throttle:product-import-heavy');
    Route::get('/runs', [ProductImportController::class, 'runs']);
    Route::get('/runs/{importRun}/rows', [ProductImportController::class, 'rows']);
    Route::patch('/runs/{importRun}/mapping', [ProductImportController::class, 'updateMapping'])->middleware('throttle:product-import-mutation');
    Route::patch('/runs/{importRun}/rows', [ProductImportController::class, 'updateRows'])->middleware('throttle:product-import-mutation');
    Route::post('/runs/{importRun}/revalidate', [ProductImportController::class, 'revalidateRows'])->middleware('throttle:product-import-heavy');
    Route::get('/runs/{importRun}/errors', [ProductImportController::class, 'errorRows']);
    Route::get('/runs/{importRun}/errors.xlsx', [ProductImportController::class, 'errors'])->middleware('throttle:product-import-export');
    Route::get('/runs/{importRun}/source', [ProductImportController::class, 'source']);
    Route::post('/runs/{importRun}/retry-failed', [ProductImportController::class, 'retryFailed'])->middleware('throttle:product-import-heavy');
    Route::post('/runs/{importRun}/process-chunk', [ProductImportController::class, 'processChunk'])->middleware('throttle:product-import-chunk');
    Route::post('/runs/{importRun}/process-now', [ProductImportController::class, 'processNow'])->middleware('throttle:product-import-chunk');
    Route::post('/runs/{importRun}/cancel', [ProductImportController::class, 'cancel'])->middleware('throttle:product-import-mutation');
    Route::delete('/runs/{importRun}', [ProductImportController::class, 'destroy'])->middleware('throttle:product-import-mutation');
    Route::get('/runs/{importRun}', [ProductImportController::class, 'run']);
    Route::post('/', [ProductImportController::class, 'import'])->middleware('throttle:product-import-heavy');
});
