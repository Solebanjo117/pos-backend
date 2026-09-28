<?php

use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PurchaseOrderPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchase Orders API Routes
|--------------------------------------------------------------------------
*/

Route::get('purchase-orders/pending-receipt', [PurchaseOrderController::class, 'pendingReceipt'])
    ->middleware(['warehouse_context']);

Route::prefix('purchase-orders')->middleware(['role:admin,manager,buyer', 'warehouse_context'])->group(function () {
    // Statistics
    Route::get('/statistics', [PurchaseOrderController::class, 'statistics']);

    // Daily PO & Quick Purchase (v2)
    Route::get('/daily', [PurchaseOrderController::class, 'getDailyPO']);
    Route::post('/quick-purchase', [PurchaseOrderController::class, 'quickPurchase']);
    Route::post('/reception-batch', [PurchaseOrderController::class, 'receptionBatch']);

    // CRUD
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::get('/supplier-candidates', [PurchaseOrderController::class, 'supplierCandidates']);
    Route::post('/', [PurchaseOrderController::class, 'store']);
    Route::post('/quick-receive', [PurchaseOrderController::class, 'quickReceive']); // Legacy
    Route::get('/{id}', [PurchaseOrderController::class, 'show']);
    Route::put('/{id}', [PurchaseOrderController::class, 'update']);
    Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
    Route::post('/{id}/duplicate', [PurchaseOrderController::class, 'duplicate']);

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

Route::middleware(['role:admin,manager,buyer', 'warehouse_context'])->group(function () {
    Route::post('purchase-orders/{purchaseOrder}/payment-requests/{paymentRequest}/cancel', [\App\Http\Controllers\Api\PurchasePaymentRequestController::class, 'cancel']);
    Route::get('purchase-orders/{purchaseOrder}/payment-requests', [\App\Http\Controllers\Api\PurchasePaymentRequestController::class, 'index']);
    Route::post('purchase-orders/{purchaseOrder}/payment-requests', [\App\Http\Controllers\Api\PurchasePaymentRequestController::class, 'store']);
});
