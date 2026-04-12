<?php

use App\Http\Controllers\Api\DataResetController;
use Illuminate\Support\Facades\Route;

Route::middleware('role:admin')->group(function () {
    Route::post('/data/reset', [DataResetController::class, 'reset']);
});
