<?php

use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'role:admin,manager,buyer,seller,assistant'])
    ->get('/transactions', [TransactionController::class, 'index']);
