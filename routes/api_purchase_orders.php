<?php

use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseOrderPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchase Orders API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('purchase-orders')->middleware(['role:admin,manager,buyer', 'warehouse_context'])->group(function () {
    // Statistics
    Route::get('/statistics', [PurchaseOrderController::class, 'statistics']);

    // CRUD
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::post('/', [PurchaseOrderController::class, 'store']);
    Route::post('/quick-receive', [PurchaseOrderController::class, 'quickReceive']);
    Route::get('/{id}', [PurchaseOrderController::class, 'show']);
    Route::put('/{id}', [PurchaseOrderController::class, 'update']);
    Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);

    // Export
    Route::get('/{id}/pdf', [PurchaseOrderController::class, 'exportPdf']);

    // Lifecycle
    Route::post('/{id}/send', [PurchaseOrderController::class, 'send']);
    Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('/{id}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Payments (Accounts Payable)
    Route::get('/{poId}/payments', [PurchaseOrderPaymentController::class, 'index']);
    Route::post('/{poId}/payments', [PurchaseOrderPaymentController::class, 'store']);
    Route::delete('/{poId}/payments/{paymentId}', [PurchaseOrderPaymentController::class, 'destroy']);
});
