<?php

use App\Http\Controllers\Api\InventoryController;
use Illuminate\Support\Facades\Route;

// Inventory Routes
Route::prefix('inventory')->group(function () {
    // Get inventory list with filters
    Route::get('/', [InventoryController::class, 'index']);

    // Operational inventory metrics
    Route::get('/summary', [InventoryController::class, 'summary']);

    // Get batch inventory list (New)
    Route::get('/batches', [App\Http\Controllers\Api\BatchInventoryController::class, 'index']);

    // Update available inventory lot date and costs
    Route::patch('/lots/{lot}', [InventoryController::class, 'updateLot']);

    // Proveedor de un lote sin compra (existencia inicial, ajustes): se carga a mano
    Route::patch('/lots/{lot}/supplier', [App\Http\Controllers\Api\VariantSupplierController::class, 'assignLotSupplier'])->middleware('role:admin,manager,buyer');

    // Update inventory for specific product and warehouse
    Route::put('/products/{productId}/warehouses/{warehouseId}', [InventoryController::class, 'update']);

    // Waste / Mermas
    Route::post('/waste', [InventoryController::class, 'storeWaste']);
    Route::get('/waste', [InventoryController::class, 'wasteHistory']);
});

// Warehouse Routes
Route::get('/warehouses', [InventoryController::class, 'warehouses']);
