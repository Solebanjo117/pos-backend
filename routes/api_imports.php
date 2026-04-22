<?php

use App\Http\Controllers\Api\BrandImportController;
use App\Http\Controllers\Api\CategoryImportController;
use App\Http\Controllers\Api\SupplierImportController;
use App\Http\Controllers\Api\VehicleImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('categories/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [CategoryImportController::class, 'template']);
    Route::post('/preview', [CategoryImportController::class, 'preview']);
    Route::post('/execute', [CategoryImportController::class, 'execute']);
});

Route::prefix('brands/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [BrandImportController::class, 'template']);
    Route::post('/preview', [BrandImportController::class, 'preview']);
    Route::post('/execute', [BrandImportController::class, 'execute']);
});

Route::prefix('suppliers/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [SupplierImportController::class, 'template']);
    Route::post('/preview', [SupplierImportController::class, 'preview']);
    Route::post('/execute', [SupplierImportController::class, 'execute']);
});

Route::prefix('vehicles/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [VehicleImportController::class, 'template']);
    Route::post('/preview', [VehicleImportController::class, 'preview']);
    Route::post('/execute', [VehicleImportController::class, 'execute']);
});
