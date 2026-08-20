<?php

use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Management Routes (admin only)
|--------------------------------------------------------------------------
*/

Route::middleware('role:admin')->group(function () {
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});
// Public to all authenticated users for dropdown assignments
Route::get('/users', [UserController::class, 'index']);
