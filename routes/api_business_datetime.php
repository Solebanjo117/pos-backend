<?php

use App\Http\Controllers\Api\BusinessDatetimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('business-datetime')->middleware('role:admin')->group(function (): void {
    Route::get('/{recordType}/{recordId}', [BusinessDatetimeController::class, 'show']);
    Route::patch('/{recordType}/{recordId}', [BusinessDatetimeController::class, 'update']);
});
