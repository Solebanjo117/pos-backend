<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['web', App\Http\Middleware\TrustFrontendProxyAuth::class, 'throttle:120,1'])->prefix('v1')->group(function () {
    require __DIR__.'/api_store_settings.php';
});

Route::middleware(['web', App\Http\Middleware\TrustFrontendProxyAuth::class.':required', 'throttle:120,1'])->prefix('v1')->group(function () {

    // ---------------------------------------------------------------
    // Public to all authenticated users
    // ---------------------------------------------------------------
    require __DIR__.'/api_dashboard.php';
    require __DIR__.'/api_inventory.php';
    require __DIR__.'/api_products.php';
    require __DIR__.'/api_product_import.php';
    require __DIR__.'/api_imports.php';
    require __DIR__.'/api_warehouses.php';
    require __DIR__.'/api_inventory_movements.php';
    require __DIR__.'/api_inventory_transfers.php';
    require __DIR__.'/api_customers.php';
    require __DIR__.'/api_accounts_receivable.php';
    require __DIR__.'/api_vehicles.php';
    require __DIR__.'/api_categories.php';
    require __DIR__.'/api_brands.php';

    // User warehouse context (any authenticated user)
    Route::post('/user/warehouse-context', [App\Http\Controllers\Api\UserController::class, 'setWarehouseContext']);
    Route::get('/user/warehouse-context', [App\Http\Controllers\Api\UserController::class, 'getWarehouseContext']);
    // Current authenticated user info (role, etc.)
    Route::get('/user', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        if (! $user) {
            return response()->json(['user' => null]);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'can_manage_financial_adjustments' => $user->canManageFinancialAdjustments(),
            'current_warehouse_id' => $user->current_warehouse_id,
        ]);
    });

    // ---------------------------------------------------------------
    // POS & Sales: seller, cashier, manager, admin
    // ---------------------------------------------------------------
    require __DIR__.'/api_sales_orders.php';
    require __DIR__.'/api_pos_sessions.php';
    require __DIR__.'/api_cashier_queue.php';
    require __DIR__.'/api_tickets.php';
    require __DIR__.'/api_sales_quotes.php';
    require __DIR__.'/api_sales_credit_notes.php';
    require __DIR__.'/api_transaction_traces.php';

    // ---------------------------------------------------------------
    // Work Orders: all authenticated roles
    // ---------------------------------------------------------------
    require __DIR__.'/api_work_orders.php';

    // ---------------------------------------------------------------
    // Purchasing: buyer, manager, admin
    // ---------------------------------------------------------------
    require __DIR__.'/api_purchase_orders.php';
    require __DIR__.'/api_suppliers.php';
    require __DIR__.'/api_smart_reorder.php';
    require __DIR__.'/api_accounts_payable.php';
    require __DIR__.'/api_supply_list.php';
    require __DIR__.'/api_operational_expenses.php';
    require __DIR__.'/api_financial_transfers.php';

    // ---------------------------------------------------------------
    // Reports: manager, admin (cashier/seller restricted in each file)
    // ---------------------------------------------------------------
    require __DIR__.'/api_reports.php';

    // ---------------------------------------------------------------
    // User management: admin only
    // ---------------------------------------------------------------
    require __DIR__.'/api_users.php';

    // ---------------------------------------------------------------
    // Backups: admin only
    // ---------------------------------------------------------------
    require __DIR__.'/api_backup.php';
    require __DIR__.'/api_data_reset.php';

    // ---------------------------------------------------------------
    // Store Settings: admin and managers
    // ---------------------------------------------------------------
    require __DIR__.'/api_pricing_formulas.php';

    // ---------------------------------------------------------------
    // Payment Methods: configurable payment methods
    // ---------------------------------------------------------------
    require __DIR__.'/api_payment_methods.php';

    // ---------------------------------------------------------------
    // Business datetime corrections: admin only, audit-tracked
    // ---------------------------------------------------------------
    require __DIR__.'/api_business_datetime.php';

    // ---------------------------------------------------------------
    // Activity Logs: admin only
    // ---------------------------------------------------------------
    require __DIR__.'/api_activity_logs.php';

    // ---------------------------------------------------------------
    // Changelog: authenticated users
    // ---------------------------------------------------------------
    require __DIR__.'/api_changelog.php';
});
