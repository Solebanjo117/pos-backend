<?php

use App\Http\Controllers\Api\AccountsPayableController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounts-payable')->middleware('role:admin,manager,buyer')->group(function () {
    Route::get('/', [AccountsPayableController::class, 'index']);
});
