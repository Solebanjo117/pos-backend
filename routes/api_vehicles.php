<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\VehicleMakeController;
use App\Http\Controllers\Api\VehicleModelController;
use App\Http\Controllers\Api\VehicleEngineController;
use App\Http\Controllers\Api\VehicleVinController;
use App\Http\Controllers\Api\ProductCompatibilityController;

/*
|--------------------------------------------------------------------------
| Vehicle API Routes
|--------------------------------------------------------------------------
*/

// Vehicle Makes
Route::apiResource('vehicle-makes', VehicleMakeController::class);

// Vehicle Models
Route::apiResource('vehicle-models', VehicleModelController::class);
Route::get('vehicle-makes/{makeId}/models', [VehicleModelController::class, 'byMake']);

// Vehicle Engines
Route::apiResource('vehicle-engines', VehicleEngineController::class);
Route::get('vehicle-models/{modelId}/engines', [VehicleEngineController::class, 'byModel']);

// Vehicle VINs (nested under engines)
Route::get('vehicle-engines/{engineId}/vins', [VehicleVinController::class, 'index']);
Route::post('vehicle-engines/{engineId}/vins', [VehicleVinController::class, 'store']);
Route::delete('vehicle-engines/{engineId}/vins/{vinId}', [VehicleVinController::class, 'destroy']);

// Product Compatibility
Route::get('products/{productId}/compatibility', [ProductCompatibilityController::class, 'index']);
Route::post('product-compatibility', [ProductCompatibilityController::class, 'store']);
Route::put('product-compatibility/{id}', [ProductCompatibilityController::class, 'update']);
Route::delete('product-compatibility/{id}', [ProductCompatibilityController::class, 'destroy']);

// Search products by vehicle
Route::post('vehicles/search-products', [ProductCompatibilityController::class, 'search']);
