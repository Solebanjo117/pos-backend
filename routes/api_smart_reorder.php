<?php

use App\Http\Controllers\Api\SmartReorderController;
use Illuminate\Support\Facades\Route;

Route::prefix('smart-reorder')->group(function () {
    Route::get('/low-stock', [SmartReorderController::class, 'getLowStock']);
    Route::get('/stats', [SmartReorderController::class, 'getStats']);
    Route::post('/batch-create', [SmartReorderController::class, 'batchCreate']);
});
