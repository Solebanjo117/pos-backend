<?php

use App\Http\Controllers\Api\InventoryMovementController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory-movements')->group(function () {
    Route::get('/', [InventoryMovementController::class, 'index']);
    Route::get('/{id}', [InventoryMovementController::class, 'show']);
    Route::get('/{id}/related', [InventoryMovementController::class, 'related']);
    Route::post('/inbound', [InventoryMovementController::class, 'storeInbound']);
    Route::post('/inbound/bulk', [InventoryMovementController::class, 'storeBulkInbound']);
    Route::post('/outbound', [InventoryMovementController::class, 'storeOutbound']);
    Route::post('/outbound/bulk', [InventoryMovementController::class, 'storeBulkOutbound']);
    Route::post('/transfer', [InventoryMovementController::class, 'storeTransfer']);
    Route::get('/product/{productId}', [InventoryMovementController::class, 'byProduct']);
    Route::get('/warehouse/{warehouseId}', [InventoryMovementController::class, 'byWarehouse']);
});
