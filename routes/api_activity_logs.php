<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ActivityLogController;

/*
|--------------------------------------------------------------------------
| Activity Log Routes (admin only)
|--------------------------------------------------------------------------
*/

Route::middleware(['role:admin'])->group(function () {
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
});
