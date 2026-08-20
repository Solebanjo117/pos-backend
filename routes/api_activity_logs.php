<?php

use App\Http\Controllers\Api\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Activity Log Routes (admin only)
|--------------------------------------------------------------------------
*/

Route::middleware(['role:admin'])->group(function () {
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
});
