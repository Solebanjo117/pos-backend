<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class PosController extends Controller
{
    /**
     * Get POS settings
     */
    public function getSettings(): JsonResponse
    {
        $singleWarehouseMode = config('pos.single_warehouse_mode', false);
        $defaultWarehouseId = config('pos.default_warehouse_id', 1);

        $defaultWarehouse = null;
        if ($singleWarehouseMode && $defaultWarehouseId) {
            $defaultWarehouse = Warehouse::find($defaultWarehouseId);
        }

        return response()->json([
            'single_warehouse_mode' => $singleWarehouseMode,
            'default_warehouse_id' => $defaultWarehouseId,
            'client_mode' => config('pos.client_mode', false),
            'default_warehouse' => $defaultWarehouse ? [
                'id' => $defaultWarehouse->id,
                'name' => $defaultWarehouse->name,
                'code' => $defaultWarehouse->code,
            ] : null,
        ]);
    }
}
