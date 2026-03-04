<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BackupController;

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
