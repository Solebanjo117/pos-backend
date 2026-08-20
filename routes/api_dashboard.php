<?php

use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

// Dashboard Routes
Route::prefix('dashboard')->middleware(['role:admin,manager,buyer,seller,cashier,assistant', 'warehouse_context'])->group(function () {
    // Get all dashboard data in one request
    Route::get('/', [DashboardController::class, 'index']);

    // Individual metric endpoints
    Route::get('/stats', [DashboardController::class, 'stats']);
    Route::get('/top-products', [DashboardController::class, 'topProducts']);
    Route::get('/low-stock', [DashboardController::class, 'lowStockProducts']);
    Route::get('/inventory-summary', [DashboardController::class, 'inventorySummary']);
    Route::get('/reorder-alerts', [DashboardController::class, 'reorderAlerts']);
    Route::get('/sales-chart', [DashboardController::class, 'salesChart']);
    Route::get('/profit-by-brand', [DashboardController::class, 'profitByBrand']);
});
