<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SmartReorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SmartReorderController extends Controller
{
    protected SmartReorderService $service;

    public function __construct(SmartReorderService $service)
    {
        $this->service = $service;
    }

    /**
     * Get low stock products grouped by supplier
     */
    public function getLowStock(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');
        
        $data = $this->service->getLowStockProducts($warehouseId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get low stock statistics
     */
    public function getStats(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');
        
        $stats = $this->service->getLowStockStats($warehouseId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Batch create purchase orders from smart reorder
     */
    public function batchCreate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array|min:1',
            'orders.*.supplier_id' => 'required|exists:suppliers,id',
            'orders.*.warehouse_id' => 'required|exists:warehouses,id',
            'orders.*.expected_date' => 'nullable|date',
            'orders.*.notes' => 'nullable|string',
            'orders.*.items' => 'required|array|min:1',
            'orders.*.items.*.product_id' => 'required|exists:products,id',
            'orders.*.items.*.quantity' => 'required|integer|min:1',
            'orders.*.items.*.unit_cost' => 'required|numeric|min:0',
            'orders.*.items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get first available user from database
            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado. No se pueden crear órdenes de compra.',
                ], 401);
            }
            
            $orders = $this->service->batchCreatePurchaseOrders(
                $request->input('orders'),
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => count($orders) . ' purchase orders created successfully',
                'data' => $orders,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating purchase orders: ' . $e->getMessage(),
            ], 500);
        }
    }
}
