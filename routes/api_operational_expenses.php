<?php

use App\Http\Controllers\Api\OperationalExpenseController;
use App\Http\Controllers\Api\OperationalExpenseCategoryController;
use App\Http\Controllers\Api\OperationalExpenseBeneficiaryController;
use Illuminate\Support\Facades\Route;

Route::prefix('operational-expense-categories')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/', [OperationalExpenseCategoryController::class, 'index']);
    Route::post('/', [OperationalExpenseCategoryController::class, 'store']);
    Route::patch('/{operationalExpenseCategory}', [OperationalExpenseCategoryController::class, 'update']);
});

Route::prefix('operational-expenses')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/parties', [OperationalExpenseController::class, 'parties']);
    Route::get('/', [OperationalExpenseController::class, 'index']);
    Route::post('/', [OperationalExpenseController::class, 'store']);
    Route::get('/{operationalExpense}', [OperationalExpenseController::class, 'show']);
    Route::put('/{operationalExpense}', [OperationalExpenseController::class, 'update']);
    Route::post('/{operationalExpense}/send-to-cashier', [OperationalExpenseController::class, 'sendToCashier']);
    Route::delete('/{operationalExpense}', [OperationalExpenseController::class, 'destroy']);
});

Route::prefix('operational-expense-beneficiaries')->middleware('role:admin,manager')->group(function (): void {
    Route::get('/', [OperationalExpenseBeneficiaryController::class, 'index']);
    Route::post('/', [OperationalExpenseBeneficiaryController::class, 'store']);
    Route::get('/{operationalExpenseBeneficiary}', [OperationalExpenseBeneficiaryController::class, 'show']);
    Route::patch('/{operationalExpenseBeneficiary}', [OperationalExpenseBeneficiaryController::class, 'update']);
});
