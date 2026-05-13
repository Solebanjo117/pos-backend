<?php

use App\Http\Controllers\Api\AccountsReceivableController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounts-receivable')->middleware('role:admin,manager,seller,cashier')->group(function (): void {
    Route::get('/', [AccountsReceivableController::class, 'index']);
    Route::get('/{customer}/statement', [AccountsReceivableController::class, 'statement']);
    Route::post('/{customer}/receipts', [AccountsReceivableController::class, 'storeReceipt'])
        ->middleware('role:admin,manager,cashier');
});
