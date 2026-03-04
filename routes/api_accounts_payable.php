<?php

use App\Http\Controllers\Api\AccountsPayableController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounts-payable')->group(function () {
    Route::get('/', [AccountsPayableController::class, 'index']);
});
