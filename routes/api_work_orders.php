<?php

use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Work Orders API Routes
|--------------------------------------------------------------------------
| All authenticated users can create and manage work orders.
| Feature flag: modules_work_orders_enabled (checked in frontend).
*/

Route::middleware(['web'])->prefix('work-orders')->group(function () {

    Route::get('/',          [WorkOrderController::class, 'index']);
    Route::post('/',         [WorkOrderController::class, 'store']);
    Route::get('/{id}',      [WorkOrderController::class, 'show']);
    Route::put('/{id}',      [WorkOrderController::class, 'update']);
    Route::delete('/{id}',   [WorkOrderController::class, 'destroy']);
    Route::post('/{id}/transition', [WorkOrderController::class, 'transition']);

});
