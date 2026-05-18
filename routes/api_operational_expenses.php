<?php

use App\Http\Controllers\Api\OperationalExpenseController;
use Illuminate\Support\Facades\Route;

Route::prefix('operational-expenses')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/', [OperationalExpenseController::class, 'index']);
    Route::post('/', [OperationalExpenseController::class, 'store']);
    Route::get('/{operationalExpense}', [OperationalExpenseController::class, 'show']);
    Route::put('/{operationalExpense}', [OperationalExpenseController::class, 'update']);
    Route::delete('/{operationalExpense}', [OperationalExpenseController::class, 'destroy']);
});
