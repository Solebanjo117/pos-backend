<?php

use App\Http\Controllers\Api\CashierQueueController;
use App\Http\Controllers\Api\CashOperationRequestController;
use App\Http\Controllers\Api\FundingCategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('cashier-queue')->middleware(['role:admin,manager,cashier', 'warehouse_context'])->group(function (): void {
    Route::get('/', [CashierQueueController::class, 'index']);
    Route::post('/{cashierQueueEntry}/open', [CashierQueueController::class, 'open']);
    Route::post('/{cashierQueueEntry}/complete', [CashierQueueController::class, 'complete']);
    Route::post('/{cashierQueueEntry}/cancel', [CashierQueueController::class, 'cancel']);
});

Route::post('/cash-operation-requests', [CashOperationRequestController::class, 'store'])
    ->middleware(['role:admin,manager,cashier', 'warehouse_context']);

Route::prefix('funding-categories')->middleware(['role:admin,manager', 'warehouse_context'])->group(function (): void {
    Route::get('/', [FundingCategoryController::class, 'index']);
    Route::post('/', [FundingCategoryController::class, 'store']);
    Route::patch('/{fundingCategory}', [FundingCategoryController::class, 'update']);
});
