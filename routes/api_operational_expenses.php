<?php

use App\Http\Controllers\Api\OperationalExpenseController;
use App\Http\Controllers\Api\OperationalExpenseCategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('operational-expense-categories')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/', [OperationalExpenseCategoryController::class, 'index']);
    Route::post('/', [OperationalExpenseCategoryController::class, 'store']);
    Route::patch('/{operationalExpenseCategory}', [OperationalExpenseCategoryController::class, 'update']);
});

Route::prefix('operational-expenses')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/', [OperationalExpenseController::class, 'index']);
    Route::post('/', [OperationalExpenseController::class, 'store']);
    Route::get('/{operationalExpense}', [OperationalExpenseController::class, 'show']);
    Route::put('/{operationalExpense}', [OperationalExpenseController::class, 'update']);
    Route::delete('/{operationalExpense}', [OperationalExpenseController::class, 'destroy']);
});
