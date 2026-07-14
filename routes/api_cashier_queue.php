<?php

use App\Http\Controllers\Api\CashierQueueController;
use Illuminate\Support\Facades\Route;

Route::prefix('cashier-queue')->middleware(['role:admin,manager,cashier', 'warehouse_context'])->group(function (): void {
    Route::get('/', [CashierQueueController::class, 'index']);
    Route::post('/{cashierQueueEntry}/open', [CashierQueueController::class, 'open']);
    Route::post('/{cashierQueueEntry}/complete', [CashierQueueController::class, 'complete']);
});
