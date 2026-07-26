<?php

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
