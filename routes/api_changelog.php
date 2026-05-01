<?php

use App\Http\Controllers\Api\ChangelogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Changelog Routes
|--------------------------------------------------------------------------
*/

Route::get('/changelog', [ChangelogController::class, 'index']);

Route::middleware(['role:admin,manager'])->group(function () {
    Route::post('/changelog/entries', [ChangelogController::class, 'store']);
    Route::patch('/changelog/entries/{changelogEntry}/publish', [ChangelogController::class, 'publish']);
});
