<?php

use App\Http\Controllers\Api\SmartReorderController;
use Illuminate\Support\Facades\Route;

Route::prefix('smart-reorder')->middleware(['role:admin,manager,buyer', 'warehouse_context'])->group(function () {
    Route::get('/low-stock', [SmartReorderController::class, 'getLowStock']);
    Route::get('/stats', [SmartReorderController::class, 'getStats']);
    Route::post('/batch-create', [SmartReorderController::class, 'batchCreate']);
});
