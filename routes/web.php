<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['status' => 'POS Backend API Running'];
});

// Fallback for 404s to json instead of Inertia
Route::fallback(function(){
    return response()->json(['message' => 'Not Found'], 404);
});
