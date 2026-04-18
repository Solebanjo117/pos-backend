<?php

use App\Http\Controllers\Api\ChangelogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Changelog Routes
|--------------------------------------------------------------------------
*/

Route::get('/changelog', [ChangelogController::class, 'index']);
