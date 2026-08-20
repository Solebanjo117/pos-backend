<?php

use App\Http\Controllers\Api\SupplyListController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Supply List API Routes  (R29)
|--------------------------------------------------------------------------
| Accessible to: admin, manager, buyer, assistant
*/

Route::prefix('supply-list')->middleware(['role:admin,manager,buyer,assistant', 'warehouse_context'])->group(function () {
    Route::get('/', [SupplyListController::class, 'index']);
    Route::post('/', [SupplyListController::class, 'store']);
    Route::put('/{id}', [SupplyListController::class, 'update']);
    Route::post('/convert', [SupplyListController::class, 'convert']);
    Route::post('/import-low-stock', [SupplyListController::class, 'importLowStock']);
    Route::delete('/{id}', [SupplyListController::class, 'destroy']);
});
