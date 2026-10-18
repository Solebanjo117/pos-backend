<?php

use App\Http\Controllers\Api\CashDepositController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\FinancialTransferController;
use Illuminate\Support\Facades\Route;

Route::get('/money-accounts/options', [FinancialAccountController::class, 'options'])
    ->middleware('role:admin,manager,buyer,cashier');

Route::middleware('role:admin,manager')->group(function (): void {
    Route::get('/financial-accounts', [FinancialAccountController::class, 'index']);
    Route::get('/financial-accounts/ledger-options', [FinancialAccountController::class, 'ledgerAccounts']);
    Route::get('/financial-transfers', [FinancialTransferController::class, 'index']);
    Route::post('/financial-transfers', [FinancialTransferController::class, 'store']);
    Route::get('/financial-transfers/{financialTransfer}', [FinancialTransferController::class, 'show']);
    Route::get('/financial-transfers/{financialTransfer}/receipt', [FinancialTransferController::class, 'receipt']);
});

Route::middleware('role:admin')->group(function (): void {
    Route::post('/financial-accounts', [FinancialAccountController::class, 'store']);
    Route::patch('/financial-accounts/{financialAccount}', [FinancialAccountController::class, 'update']);
    Route::post('/financial-transfers/{financialTransfer}/reverse', [FinancialTransferController::class, 'reverse']);
});

Route::middleware(['role:admin,manager', 'warehouse_context'])->group(function (): void {
    Route::get('cash/deposit-sources', [CashDepositController::class, 'sources']);
    Route::post('cash/deposits', [CashDepositController::class, 'store']);
});

Route::middleware(['role:admin,manager,cashier', 'warehouse_context'])->group(function () {
    Route::get('cash/availability', [\App\Http\Controllers\Api\WarehouseCashController::class, 'index']);
    Route::post('cash/reconciliation', [\App\Http\Controllers\Api\WarehouseCashController::class, 'store']);
});

Route::middleware(['role:admin,manager,cashier', 'warehouse_context'])->group(function () {
    Route::get('cash/payment-movements', [\App\Http\Controllers\Api\OperationalPaymentController::class, 'index']);
    Route::get('cash/payment-summary', [\App\Http\Controllers\Api\OperationalPaymentController::class, 'summary']);
    Route::post('cash/payments/{scope}/{payment}/account', [\App\Http\Controllers\Api\OperationalPaymentController::class, 'assign']);
});
