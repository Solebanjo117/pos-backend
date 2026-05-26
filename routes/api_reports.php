<?php

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->middleware(['role:admin,manager', 'warehouse_context'])->group(function () {
    Route::get('/stock-levels', [ReportController::class, 'stockLevels']);
    Route::get('/movement-history', [ReportController::class, 'movementHistory']);

    Route::get('/financial', [ReportController::class, 'financial']);
    Route::get('/customer-references', [ReportController::class, 'customerReferences']);
    Route::get('/brand-profitability', [ReportController::class, 'brandProfitability']);
    Route::get('/category-profitability', [ReportController::class, 'categoryProfitability']);
    Route::get('/z-report', [ReportController::class, 'zReport']);
    Route::get('/z-report/export', [ReportController::class, 'exportZReport']);

    Route::get('/stock-levels/export/{format}', [ReportController::class, 'exportStockLevels']);
    Route::get('/movement-history/export/{format}', [ReportController::class, 'exportMovementHistory']);
});
