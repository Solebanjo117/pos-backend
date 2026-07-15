<?php

use App\Http\Controllers\Api\InventoryMovementController;
use Illuminate\Support\Facades\Route;

Route::prefix('inventory-movements')->middleware(['role:admin,manager,buyer,assistant', 'warehouse_context'])->group(function () {
    Route::get('/', [InventoryMovementController::class, 'index']);
    Route::get('/product/{productId}', [InventoryMovementController::class, 'byProduct'])->whereNumber('productId');
    Route::get('/warehouse/{warehouseId}', [InventoryMovementController::class, 'byWarehouse'])->whereNumber('warehouseId');
    Route::post('/inbound', [InventoryMovementController::class, 'storeInbound']);
    Route::post('/inbound/bulk', [InventoryMovementController::class, 'storeBulkInbound']);
    Route::post('/outbound', [InventoryMovementController::class, 'storeOutbound']);
    Route::post('/outbound/bulk', [InventoryMovementController::class, 'storeBulkOutbound']);
    Route::post('/transfer', [InventoryMovementController::class, 'storeTransfer']);
    Route::get('/{id}/related', [InventoryMovementController::class, 'related'])->whereNumber('id');
    Route::get('/{id}', [InventoryMovementController::class, 'show'])->whereNumber('id');
});
