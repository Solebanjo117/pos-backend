<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InventoryTransferController;

Route::prefix('inventory-transfers')->group(function () {
    Route::get('/', [InventoryTransferController::class, 'index']);
    Route::post('/', [InventoryTransferController::class, 'store']);
    Route::get('/{inventoryTransfer}', [InventoryTransferController::class, 'show']);
    Route::post('/{inventoryTransfer}/dispatch', [InventoryTransferController::class, 'dispatch']);
    Route::post('/{inventoryTransfer}/receive', [InventoryTransferController::class, 'receive']);
    Route::post('/{inventoryTransfer}/cancel', [InventoryTransferController::class, 'cancel']);
});
