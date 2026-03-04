<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchInventoryController extends Controller
{
    /**
     * Get paginated list of batches grouped by batch_number with products.
     */
    public function index(Request $request): JsonResponse
    {
        // Start with ProductBatch and join warehouse_batches to get quantities
        $query = ProductBatch::with(['product'])
            ->select('product_batches.*')
            ->join('warehouse_batches', 'product_batches.id', '=', 'warehouse_batches.product_batch_id')
            ->where('warehouse_batches.quantity', '>', 0)
            ->groupBy('product_batches.id', 'product_batches.product_id', 'product_batches.batch_number', 
                      'product_batches.expiry_date', 'product_batches.manufacturing_date', 
                      'product_batches.cost', 'product_batches.created_at', 'product_batches.updated_at');

        // Filter by Warehouse
        if ($request->has('warehouse_id')) {
            $query->where('warehouse_batches.warehouse_id', $request->warehouse_id);
        }

        // Filter by Search (batch number or product)
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_batches.batch_number', 'ilike', "%{$search}%")
                  ->orWhereHas('product', function ($q2) use ($search) {
                      $q2->where('name', 'ilike', "%{$search}%")
                         ->orWhere('sku', 'ilike', "%{$search}%");
                  });
            });
        }

        // Filter by Expiry Date
        if ($request->has('expiry_status')) {
            $now = now();
            if ($request->expiry_status === 'expired') {
                $query->whereDate('product_batches.expiry_date', '<', $now);
            } elseif ($request->expiry_status === 'expiring_soon') {
                $query->whereDate('product_batches.expiry_date', '>=', $now)
                      ->whereDate('product_batches.expiry_date', '<=', $now->copy()->addDays(30));
            }
        }

        // Get all matching batches first
        $allBatches = $query->get();

        // Group by batch_number
        $groupedBatches = $allBatches->groupBy('batch_number')->map(function ($batchGroup, $batchNumber) use ($request) {
            // Get all products in this batch
            $products = $batchGroup->map(function ($batch) use ($request) {
                // Get warehouse quantities for this product batch
                $warehouseQuery = $batch->warehouseBatches()
                    ->with('warehouse')
                    ->where('quantity', '>', 0);
                
                if ($request->has('warehouse_id')) {
                    $warehouseQuery->where('warehouse_id', $request->warehouse_id);
                }
                
                $warehouseBatches = $warehouseQuery->get();
                
                return [
                    'product_id' => $batch->product_id,
                    'product_name' => $batch->product->name,
                    'product_sku' => $batch->product->sku,
                    'total_quantity' => $warehouseBatches->sum('quantity'),
                    'warehouses' => $warehouseBatches->map(fn($wb) => [
                        'warehouse_id' => $wb->warehouse_id,
                        'warehouse_name' => $wb->warehouse->name,
                        'quantity' => $wb->quantity,
                    ])->toArray(),
                ];
            })->values();

            $firstBatch = $batchGroup->first();
            $totalQuantity = $products->sum('total_quantity');

            return [
                'batch_number' => $batchNumber,
                'expiry_date' => $firstBatch->expiry_date,
                'manufacturing_date' => $firstBatch->manufacturing_date,
                'cost' => $firstBatch->cost,
                'total_quantity' => $totalQuantity,
                'product_count' => $products->count(),
                'products' => $products->toArray(),
            ];
        })->values();

        // Manual pagination
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $total = $groupedBatches->count();
        $paginatedBatches = $groupedBatches->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $paginatedBatches,
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => (int) $perPage,
                'total' => $total,
            ],
        ]);
    }
}
