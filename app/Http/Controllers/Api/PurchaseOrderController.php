<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Services\SmartReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    private PurchaseOrderService $purchaseOrderService;

    public function __construct(PurchaseOrderService $purchaseOrderService)
    {
        $this->purchaseOrderService = $purchaseOrderService;
    }

    /**
     * GET /api/v1/purchase-orders
     * List all purchase orders with filters
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'status', 'supplier_id',
            'date_from', 'date_to', 'payment_status',
            'sort_by', 'sort_direction', 'per_page',
        ]);

        $orders = $this->purchaseOrderService->getAll($filters);

        return response()->json($orders);
    }

    /**
     * GET /api/v1/purchase-orders/{id}
     * Get single purchase order
     */
    public function show(int $id): JsonResponse
    {
        $order = $this->purchaseOrderService->getById($id);

        return response()->json(['data' => $order]);
    }

    /**
     * GET /api/v1/purchase-orders/supplier-candidates
     * Candidate items for provider-based purchase order list mode.
     */
    public function supplierCandidates(Request $request, SmartReorderService $smartReorderService): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'source' => 'required|in:low_stock,daily',
            'supply_date' => 'nullable|date',
        ]);

        $items = $this->purchaseOrderService->getSupplierCandidates($validated, $smartReorderService);

        return response()->json([
            'data' => $items,
        ]);
    }

    /**
     * POST /api/v1/purchase-orders
     * Create new purchase order
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'nullable|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'tax_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.units_per_package' => 'nullable|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'source_supply_list_item_ids' => 'nullable|array',
            'source_supply_list_item_ids.*' => 'integer|exists:supply_list_items,id',
        ]);

        try {
            $order = $this->purchaseOrderService->create($validated);

            return response()->json([
                'message' => 'Orden de compra creada exitosamente',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /api/v1/purchase-orders/{id}
     * Update existing purchase order
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'order_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'tax_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity_ordered' => 'required_with:items|integer|min:1',
            'items.*.units_per_package' => 'nullable|integer|min:1',
            'items.*.unit_cost' => 'required_with:items|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $order = $this->purchaseOrderService->update($id, $validated);

            return response()->json([
                'message' => 'Orden de compra actualizada exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/v1/purchase-orders/{id}
     * Delete purchase order
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->purchaseOrderService->delete($id);

            return response()->json([
                'message' => 'Orden de compra eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/{id}/duplicate
     * Create a new draft purchase order from an existing order.
     */
    public function duplicate(int $id): JsonResponse
    {
        try {
            $order = $this->purchaseOrderService->duplicate($id);

            return response()->json([
                'message' => 'Orden de compra duplicada exitosamente',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/{id}/send
     * Mark as sent to supplier
     */
    public function send(int $id): JsonResponse
    {
        try {
            $order = $this->purchaseOrderService->send($id);

            return response()->json([
                'message' => 'Orden enviada al proveedor',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/{id}/receive
     * Receive items
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:1',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.sale_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $order = $this->purchaseOrderService->receiveItems($id, $validated['items']);

            return response()->json([
                'message' => 'Mercancía recibida exitosamente',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/{id}/cancel
     * Cancel purchase order
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $order = $this->purchaseOrderService->cancel($id);

            return response()->json([
                'message' => 'Orden de compra cancelada',
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/quick-receive
     * Fast-track for POS Backorders: Create, Send, and Receive instantly.
     * DEPRECATED: Use quickPurchase for daily PO system.
     */
    public function quickReceive(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $order = $this->purchaseOrderService->quickReceive($validated);

            return response()->json([
                'message' => 'Backorder (Orden rápida) abastecido exitosamente',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/quick-purchase
     * Quick Purchase v2: Adds items to the daily global PO with immediate stock reception.
     * Each item can have its own supplier.
     */
    public function quickPurchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'supplier_id' => 'nullable|exists:suppliers,id', // Default supplier for all items
            'items' => 'required|array|min:1',
            'items.*.supplier_id' => 'required_without:supplier_id|exists:suppliers,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string',
        ]);

        // Ensure each item has a supplier (from item or default)
        foreach ($validated['items'] as &$item) {
            if (empty($item['supplier_id']) && ! empty($validated['supplier_id'])) {
                $item['supplier_id'] = $validated['supplier_id'];
            }
        }

        try {
            $order = $this->purchaseOrderService->quickPurchase($validated);

            return response()->json([
                'message' => 'Compra rápida procesada - Stock actualizado',
                'data' => $order,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/purchase-orders/reception-batch
     * POS Reception Mode: Process a batch of items in reception mode.
     * All items go to the daily global PO with immediate stock reception.
     */
    public function receptionBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'items' => 'required|array|min:1',
            'items.*.supplier_id' => 'required|exists:suppliers,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
            'items.*.batch_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $order = $this->purchaseOrderService->batchAddItemsToDailyPO(
                $validated['warehouse_id'],
                $validated['items']
            );

            return response()->json([
                'message' => 'Recepción procesada - '.count($validated['items']).' item(s) agregados al stock',
                'data' => $order,
                'items_added' => count($validated['items']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/v1/purchase-orders/daily
     * Get or create today's daily global PO for a warehouse.
     */
    public function getDailyPO(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        try {
            $order = $this->purchaseOrderService->getOrCreateDailyPurchaseOrder($validated['warehouse_id']);

            return response()->json([
                'data' => $order->load(['items.product', 'items.variant', 'items.supplier']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/v1/purchase-orders/pending-receipt
     * Queue of purchase orders pending reception.
     */
    public function pendingReceipt(Request $request): JsonResponse
    {
        $warehouseId = $request->header('X-Warehouse-Id')
            ?? $request->user()?->current_warehouse_id;

        $orders = PurchaseOrder::with(['supplier', 'items.product'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereIn('status', ['sent', 'partial'])
            ->orderByRaw('expected_date is null, expected_date asc')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'data' => $orders,
            'count' => $orders->count(),
        ]);
    }

    /**
     * GET /api/v1/purchase-orders/statistics
     * Get purchase order statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $filters = $request->only(['date_from', 'date_to']);
        $stats = $this->purchaseOrderService->getStatistics($filters);

        return response()->json($stats);
    }

    /**
     * GET /api/v1/purchase-orders/{id}/pdf
     * Export purchase order to PDF
     */
    public function exportPdf(Request $request, int $id)
    {
        $mode = $request->query('mode', 'standard'); // 'standard' or 'worksheet'

        try {
            $order = $this->purchaseOrderService->getById($id);

            $pdfService = app(\App\Services\PdfExportService::class);
            $pdf = $pdfService->exportPurchaseOrder($order, $mode);

            $filename = $mode === 'worksheet'
                ? "hoja-pedido-{$order->po_number}.pdf"
                : "orden-compra-{$order->po_number}.pdf";

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error generando PDF: '.$e->getMessage(),
            ], 500);
        }
    }
}
