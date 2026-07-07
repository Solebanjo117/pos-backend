<?php

use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->middleware(['role:admin,manager', 'warehouse_context'])->group(function () {
    Route::get('/stock-levels', [ReportController::class, 'stockLevels']);
    Route::get('/movement-history', [ReportController::class, 'movementHistory']);

    Route::get('/financial', [ReportController::class, 'financial']);
    Route::get('/income-statement', [ReportController::class, 'incomeStatement']);
    Route::get('/financial-position', [ReportController::class, 'financialPosition']);
    Route::get('/ledger-trial-balance', [ReportController::class, 'ledgerTrialBalance']);
    Route::get('/customer-references', [ReportController::class, 'customerReferences']);
    Route::get('/sales-by-client', [ReportController::class, 'salesByClient']);
    Route::get('/sales-by-client/export/{format}', [ReportController::class, 'exportSalesByClient']);
    Route::get('/brand-profitability', [ReportController::class, 'brandProfitability']);
    Route::get('/category-profitability', [ReportController::class, 'categoryProfitability']);
    Route::get('/z-report', [ReportController::class, 'zReport']);
    Route::get('/z-report/export', [ReportController::class, 'exportZReport']);

    Route::get('/daily-summary', [ReportController::class, 'dailySummary']);
    Route::get('/financial-snapshots', [ReportController::class, 'financialSnapshots']);
    Route::get('/financial-weekly', [ReportController::class, 'financialWeekly']);
    Route::get('/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('/financial-drilldown', [ReportController::class, 'financialDrilldown']);
    Route::get('/financial-advanced-status', [ReportController::class, 'financialAdvancedStatus']);
    Route::get('/accounting/journal', [ReportController::class, 'accountingJournal']);
    Route::get('/accounting/entries/{entry}', [ReportController::class, 'accountingEntry']);
    Route::get('/accounting/accounts', [ReportController::class, 'accountingAccounts']);
    Route::get('/accounting/accounts/{account}/ledger', [ReportController::class, 'accountingAccountLedger']);
    Route::get('/accounting/accounts/{account}/t-account', [ReportController::class, 'accountingTAccount']);
    Route::get('/accounting/trial-balance', [ReportController::class, 'accountingTrialBalance']);
    Route::post('/capital-movements', [ReportController::class, 'storeCapitalMovement'])->middleware('throttle:60,1');
    Route::get('/capital-movements', [ReportController::class, 'capitalMovements']);
    Route::post('/adjustments', [ReportController::class, 'storeAdjustment'])->middleware('throttle:60,1');
    Route::get('/adjustments', [ReportController::class, 'adjustments']);

    Route::get('/stock-levels/export/{format}', [ReportController::class, 'exportStockLevels']);
    Route::get('/inventory-items/export/{format}', [ReportController::class, 'exportInventoryItems']);
    Route::get('/movement-history/export/{format}', [ReportController::class, 'exportMovementHistory']);
});
