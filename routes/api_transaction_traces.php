<?php

use App\Http\Controllers\Api\TransactionTraceController;
use Illuminate\Support\Facades\Route;

Route::get('/transaction-traces/{type}/{id}', [TransactionTraceController::class, 'show'])
    ->whereNumber('id');
