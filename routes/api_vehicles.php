<?php

use App\Http\Controllers\Api\ProductCompatibilityController;
use App\Http\Controllers\Api\VehicleChassisController;
use App\Http\Controllers\Api\VehicleEngineController;
use App\Http\Controllers\Api\VehicleMakeController;
use App\Http\Controllers\Api\VehicleModelController;
use App\Http\Controllers\Api\VehicleVinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vehicle API Routes
|--------------------------------------------------------------------------
*/

Route::get('vehicle-makes/all', [VehicleMakeController::class, 'all']);
Route::apiResource('vehicle-makes', VehicleMakeController::class)->only(['index', 'show'])->whereNumber('vehicle_make');
Route::apiResource('vehicle-makes', VehicleMakeController::class)->only(['store', 'update', 'destroy'])->middleware('role:admin,manager')->whereNumber('vehicle_make');

// Vehicle Models
Route::apiResource('vehicle-models', VehicleModelController::class)->only(['index', 'show']);
Route::apiResource('vehicle-models', VehicleModelController::class)->only(['store', 'update', 'destroy'])->middleware('role:admin,manager');
Route::get('vehicle-makes/{makeId}/models', [VehicleModelController::class, 'byMake']);

// Vehicle Engines
Route::apiResource('vehicle-engines', VehicleEngineController::class)->only(['index', 'show']);
Route::apiResource('vehicle-engines', VehicleEngineController::class)->only(['store', 'update', 'destroy'])->middleware('role:admin,manager');
Route::get('vehicle-models/{modelId}/engines', [VehicleEngineController::class, 'byModel']);

// Vehicle Chassis
Route::apiResource('vehicle-chassis', VehicleChassisController::class)->only(['index', 'show']);
Route::apiResource('vehicle-chassis', VehicleChassisController::class)->only(['store', 'update', 'destroy'])->middleware('role:admin,manager');
Route::get('vehicle-models/{modelId}/chassis', [VehicleChassisController::class, 'byModel']);

// Vehicle VINs (new contract: nested under models)
Route::get('vehicle-models/{modelId}/vins', [VehicleVinController::class, 'indexByModel']);
Route::post('vehicle-models/{modelId}/vins', [VehicleVinController::class, 'storeByModel'])->middleware('role:admin,manager');
Route::delete('vehicle-models/{modelId}/vins/{vinId}', [VehicleVinController::class, 'destroyByModel'])->middleware('role:admin,manager');

// Vehicle VINs legacy contract (nested under engines) - temporary compatibility
Route::get('vehicle-engines/{engineId}/vins', [VehicleVinController::class, 'index']);
Route::post('vehicle-engines/{engineId}/vins', [VehicleVinController::class, 'store'])->middleware('role:admin,manager');
Route::delete('vehicle-engines/{engineId}/vins/{vinId}', [VehicleVinController::class, 'destroy'])->middleware('role:admin,manager');

// Product Compatibility
Route::get('products/{productId}/compatibility', [ProductCompatibilityController::class, 'index']);
Route::post('product-compatibility', [ProductCompatibilityController::class, 'store'])->middleware('role:admin,manager');
Route::put('product-compatibility/{id}', [ProductCompatibilityController::class, 'update'])->middleware('role:admin,manager');
Route::delete('product-compatibility/{id}', [ProductCompatibilityController::class, 'destroy'])->middleware('role:admin,manager');

// Search products by vehicle
Route::post('vehicles/search-products', [ProductCompatibilityController::class, 'search']);
