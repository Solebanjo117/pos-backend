<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['status' => 'POS Backend API Running'];
});

Route::get('/up', function () {
    return response()->json(['status' => 'ok']);
});

// Fallback for 404s to json instead of Inertia
Route::fallback(function () {
    return response()->json(['message' => 'Not Found'], 404);
});
