<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['web'])->prefix('v1')->group(function () {

    // ---------------------------------------------------------------
    // Public to all authenticated users
    // ---------------------------------------------------------------
    require __DIR__ . '/api_dashboard.php';
    require __DIR__ . '/api_inventory.php';
    require __DIR__ . '/api_products.php';
    require __DIR__ . '/api_warehouses.php';
    require __DIR__ . '/api_inventory_movements.php';
    require __DIR__ . '/api_inventory_transfers.php';
    require __DIR__ . '/api_customers.php';
    require __DIR__ . '/api_vehicles.php';
    require __DIR__ . '/api_categories.php';
    require __DIR__ . '/api_brands.php';

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
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'role'                 => $user->role,
            'is_active'            => $user->is_active,
            'current_warehouse_id' => $user->current_warehouse_id,
        ]);
    });

    // ---------------------------------------------------------------
    // POS & Sales: seller, cashier, manager, admin
    // ---------------------------------------------------------------
    require __DIR__ . '/api_sales_orders.php';
    require __DIR__ . '/api_pos_sessions.php';
    require __DIR__ . '/api_tickets.php';
    require __DIR__ . '/api_sales_quotes.php';

    // ---------------------------------------------------------------
    // Purchasing: buyer, manager, admin
    // ---------------------------------------------------------------
    require __DIR__ . '/api_purchase_orders.php';
    require __DIR__ . '/api_suppliers.php';
    require __DIR__ . '/api_smart_reorder.php';
    require __DIR__ . '/api_accounts_payable.php';

    // ---------------------------------------------------------------
    // Reports: manager, admin (cashier/seller restricted in each file)
    // ---------------------------------------------------------------
    require __DIR__ . '/api_reports.php';

    // ---------------------------------------------------------------
    // User management: admin only
    // ---------------------------------------------------------------
    require __DIR__ . '/api_users.php';

    // ---------------------------------------------------------------
    // Backups: admin only
    // ---------------------------------------------------------------
    require __DIR__ . '/api_backup.php';

    // ---------------------------------------------------------------
    // Activity Logs: admin only
    // ---------------------------------------------------------------
    require __DIR__ . '/api_activity_logs.php';
});
