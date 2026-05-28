<?php

use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SalesOrderPaymentController;
use App\Http\Controllers\Api\SalesReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales Orders API Routes
|--------------------------------------------------------------------------
*/

// POS settings
Route::middleware(['web'])->get('/pos/settings', [PosController::class, 'getSettings']);

Route::middleware(['web'])->prefix('sales-orders')->group(function () {
    // Statistics (specific routes BEFORE dynamic {id} routes)
    Route::get('/statistics', [SalesOrderController::class, 'statistics']);

    // Warehouse Queue (specific routes BEFORE dynamic {id} routes)
    Route::get('/pending-at-warehouse', [SalesOrderController::class, 'pendingAtWarehouse']);
    Route::get('/pending-delivery', [SalesOrderController::class, 'pendingDelivery']);

    // CRUD endpoints (dynamic routes go AFTER specific routes)
    Route::get('/', [SalesOrderController::class, 'index']);
    Route::post('/', [SalesOrderController::class, 'store']);
    Route::get('/{salesOrder}/operational-detail', [SalesOrderController::class, 'operationalDetail']);
    Route::get('/{id}', [SalesOrderController::class, 'show']);
    Route::put('/{id}', [SalesOrderController::class, 'update']);
    Route::delete('/{id}', [SalesOrderController::class, 'destroy']);
    Route::patch('/{id}/add-items', [SalesOrderController::class, 'addItemsToExistingOrder']);

    // Warehouse Queue actions (with {id})
    Route::post('/{id}/send-to-warehouse', [SalesOrderController::class, 'sendToWarehouse']);
    Route::post('/{id}/mark-prepared', [SalesOrderController::class, 'markPrepared']);

    // Order lifecycle
    Route::post('/{id}/confirm', [SalesOrderController::class, 'confirm']);
    Route::post('/{id}/complete', [SalesOrderController::class, 'complete']);
    Route::post('/{id}/cancel', [SalesOrderController::class, 'cancel']);

    // Items management
    Route::post('/{id}/items', [SalesOrderController::class, 'addItem']);
    Route::put('/items/{itemId}', [SalesOrderController::class, 'updateItem']);
    Route::delete('/items/{itemId}', [SalesOrderController::class, 'removeItem']);

    // Payments / Abonos
    Route::get('/{id}/payments', [SalesOrderPaymentController::class, 'index']);
    Route::post('/{id}/payments', [SalesOrderPaymentController::class, 'store']);

    // Returns / Devoluciones
    Route::get('/{id}/returns', [SalesReturnController::class, 'index']);
    Route::post('/{id}/returns', [SalesReturnController::class, 'store']);
});

// Standalone return actions
Route::middleware(['web'])->prefix('sales-returns')->group(function () {
    Route::get('/', [SalesReturnController::class, 'listAll']);
    Route::get('/{salesReturn}/operational-detail', [SalesReturnController::class, 'operationalDetail']);
    Route::post('/{id}/approve', [SalesReturnController::class, 'approve']);
    Route::post('/{id}/reject', [SalesReturnController::class, 'reject']);
});
