<?php

use App\Http\Controllers\Api\BackupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backup Routes (admin only)
|--------------------------------------------------------------------------
*/

Route::middleware('role:admin')->group(function () {
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups/trigger', [BackupController::class, 'trigger']);
    Route::get('/backups/{filename}/download', [BackupController::class, 'download']);
});
