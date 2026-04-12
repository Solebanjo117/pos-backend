<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Models\ProductBatch;
use App\Services\PricingFormulaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseOrderService
{
    /**
     * Get all purchase orders with filters
     */
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = PurchaseOrder::with(['supplier:id,name', 'warehouse:id,name', 'user:id,name'])
            ->withCount('items')
            ->withSum('payments', 'amount'); // avoid N+1 for amount_paid

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', "%{$search}%")
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        if (!empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $supplierId = $filters['supplier_id'];
            // Search by supplier in header OR in items (for daily global POs)
            $query->where(function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId)
                  ->orWhereHas('items', function ($iq) use ($supplierId) {
                      $iq->where('supplier_id', $supplierId);
                  });
            });
        }

        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        } elseif (!empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        } elseif (!empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        // Filter by payment status
        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get single PO by ID
     */
    public function getById(int $id): PurchaseOrder
    {
        return PurchaseOrder::with([
            'supplier',
            'warehouse',
            'user:id,name',
            'items.supplier:id,name', // Item-level supplier for daily POs
            'items.product:id,sku_base,name',
            'items.product.defaultVariant.brand:id,name', // Fallback for products
            'items.variant.brand:id,name', // Primary source
            'items.product.pricing:id,product_id,cost,base_price',
        ])->findOrFail($id);
    }

    /**
     * Create new purchase order
     */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $po = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'user_id' => Auth::id(),
                'status' => 'draft',
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            // Add items
            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    // Support both variant_id-based and legacy product_id-based items
                    $variantId = $itemData['variant_id'] ?? null;
                    $productId = $itemData['product_id'] ?? null;
                    
                    // If variant_id is provided, get product_id from variant
                    if ($variantId) {
                        $variant = \App\Models\ProductVariant::find($variantId);
                        if ($variant) {
                            $productId = $variant->product_id;
                        }
                    }
                    
                    $po->items()->create([
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'quantity_ordered' => $itemData['quantity_ordered'],
                        'unit_cost' => $itemData['unit_cost'],
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }
            }

            $po->calculateTotals();

            return $po->fresh(['supplier', 'warehouse', 'items.product']);
        });
    }

    /**
     * Update existing PO (only if draft)
     */
    public function update(int $id, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $po = PurchaseOrder::findOrFail($id);

            if (!$po->canBeEdited()) {
                throw new \Exception('Solo se pueden editar órdenes en borrador');
            }

            $po->update(array_filter([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn($v) => $v !== null));

            // Replace items if provided
            if (isset($data['items'])) {
                $po->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $po->items()->create([
                        'product_id' => $itemData['product_id'],
                        'quantity_ordered' => $itemData['quantity_ordered'],
                        'unit_cost' => $itemData['unit_cost'],
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }
            }

            $po->calculateTotals();

            return $po->fresh(['supplier', 'warehouse', 'items.product']);
        });
    }

    /**
     * Delete PO (only if draft)
     */
    public function delete(int $id): void
    {
        $po = PurchaseOrder::findOrFail($id);

        if (!$po->canBeEdited()) {
            throw new \Exception('Solo se pueden eliminar órdenes en borrador');
        }

        $po->items()->delete();
        $po->delete();
    }

    /**
     * Send PO to supplier
     */
    public function send(int $id): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($id);

        if (!$po->canBeSent()) {
            throw new \Exception('Esta orden no puede ser enviada (debe estar en borrador y tener items)');
        }

        $po->status = 'sent';
        $po->save();

        return $po->fresh(['supplier', 'warehouse', 'items.product']);
    }

    /**
     * Receive items for a PO
     * $receivedItems format: [{ item_id: int, quantity_received: int, batch_number?: string, expiry_date?: string }]
     */
    public function receiveItems(int $id, array $receivedItems): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $receivedItems) {
            $po = PurchaseOrder::with('items.product', 'items.variant')->findOrFail($id);

            if (!$po->canReceiveItems()) {
                throw new \Exception('Esta orden no puede recibir mercancía');
            }

            foreach ($receivedItems as $received) {
                $item = PurchaseOrderItem::findOrFail($received['item_id']);

                // Validate not receiving more than ordered
                $maxReceivable = $item->quantity_ordered - $item->quantity_received;
                $qtyToReceive = min($received['quantity_received'], $maxReceivable);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                // Update received quantity
                $item->quantity_received += $qtyToReceive;
                $item->save();

                // Determine if using variant or product (backwards compatibility)
                $useVariant = !empty($item->variant_id);
                $productId = $item->product_id;
                $variantId = $item->variant_id;

                // Calculate actual units received (packages × units per package)
                $actualUnits = $qtyToReceive * ($item->units_per_package ?? 1);

                // Create inventory movement (entry)
                $movementData = [
                    'warehouse_id' => $po->warehouse_id,
                    'movement_type' => 'in',
                    'quantity' => $actualUnits,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $po->id,
                    'notes' => "Recepción PO {$po->po_number}",
                    'user_id' => Auth::id(),
                    'date' => now(),
                ];

                if ($useVariant) {
                    $movementData['variant_id'] = $variantId;
                    $movementData['product_id'] = $productId; // Keep for backwards compatibility
                } else {
                    $movementData['product_id'] = $productId;
                }

                InventoryMovement::create($movementData);

                // Update inventory (variant or product)
                if ($useVariant) {
                    // Update variant inventory
                    $inventory = ProductInventory::firstOrCreate(
                        [
                            'variant_id' => $variantId,
                            'warehouse_id' => $po->warehouse_id,
                        ],
                        [
                            'product_id' => $productId,
                            'quantity_available' => 0,
                            'quantity_reserved' => 0,
                            'reorder_point' => 10,
                            'reorder_quantity' => 50
                        ]
                    );
                } else {
                    // Fallback to product inventory (old behavior)
                    $inventory = ProductInventory::firstOrCreate(
                        [
                            'product_id' => $productId,
                            'warehouse_id' => $po->warehouse_id,
                        ],
                        ['quantity_available' => 0, 'quantity_reserved' => 0, 'reorder_point' => 10, 'reorder_quantity' => 50]
                    );
                }

                $inventory->quantity_available += $actualUnits;
                $inventory->save();

                // Handle batch tracking if enabled
                if ($item->product && $item->product->batch_tracking && !empty($received['batch_number'])) {
                    $batchData = [
                        'product_id' => $productId,
                        'batch_number' => $received['batch_number'],
                        'quantity' => $actualUnits,
                        'expiry_date' => $received['expiry_date'] ?? null,
                        'cost' => $item->unit_cost,
                    ];

                    if ($useVariant) {
                        $batchData['variant_id'] = $variantId;
                    }

                    ProductBatch::create($batchData);
                }

                // R30 — Update sale price if provided and different (LL-012)
                $newSalePrice = isset($received['sale_price']) ? (float) $received['sale_price'] : null;
                if ($newSalePrice !== null && $newSalePrice > 0) {
                    if ($useVariant && $item->variant) {
                        $pricing = $item->variant->pricing;
                        if ($pricing && (float) $pricing->base_price !== $newSalePrice) {
                            $pricing->update(['base_price' => $newSalePrice]);
                        }
                    } elseif ($item->product) {
                        $pricing = $item->product->pricing;
                        if ($pricing && (float) $pricing->base_price !== $newSalePrice) {
                            $pricing->update(['base_price' => $newSalePrice]);
                        }
                    }
                } else {
                    // R23 — No explicit sale_price: try category pricing formula as fallback
                    $category = $item->product?->category;
                    if ($category?->pricing_formula && $item->unit_cost > 0) {
                        $formulaService = app(PricingFormulaService::class);
                        $calculated = $formulaService->calculate(
                            $category->pricing_formula,
                            (float) $item->unit_cost,
                            (bool) $category->applies_itbm
                        );
                        if ($calculated !== null) {
                            $pricing = $useVariant
                                ? $item->variant?->pricing
                                : $item->product?->pricing;
                            if ($pricing && (float) $pricing->base_price !== $calculated) {
                                $pricing->update(['base_price' => $calculated]);
                            }
                        }
                    }
                }

            }

            // Update PO status based on reception
            $po->refresh();
            if ($po->isFullyReceived()) {
                $po->status = 'received';
                $po->received_date = now();
            } else {
                $po->status = 'partial';
            }
            $po->save();

            return $po->fresh(['supplier', 'warehouse', 'items.product', 'items.variant']);
        });
    }

    /**
     * Fast-track method for POS: Create, Send, and Receive a Purchase Order instantly.
     * $data requires: supplier_id, warehouse_id, items array (with quantities, costs).
     */
    public function quickReceive(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            // 1. Create the PO
            $po = $this->create([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => now()->toDateString(),
                'expected_date' => now()->toDateString(),
                'items' => $data['items'],
                'notes' => 'Generado por Backorder rápido desde POS',
            ]);

            // 2. Mark as sent
            $poSent = $this->send($po->id);

            // 3. Receive the items immediately
            $receivePayload = [];
            foreach ($poSent->items as $item) {
                // If the user specified an expiry date/batch, we would map it here.
                // For a quick backorder we only need to receive all that was ordered.
                $receivePayload[] = [
                    'item_id' => $item->id,
                    'quantity_received' => $item->quantity_ordered,
                ];
            }

            return $this->receiveItems($poSent->id, $receivePayload);
        });
    }

    /**
     * Cancel PO and revert inventory if needed
     */
    public function cancel(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $po = PurchaseOrder::with('items')->findOrFail($id);

            if (!$po->canBeCancelled()) {
                throw new \Exception('Esta orden no puede ser cancelada');
            }

            // If items were received (partial), revert inventory
            if ($po->status === 'partial') {
                foreach ($po->items as $item) {
                    if ($item->quantity_received > 0) {
                        $useVariant = !empty($item->variant_id);
                        $productId = $item->product_id;
                        $variantId = $item->variant_id;

                        // Create reverse movement
                        $movementData = [
                            'warehouse_id' => $po->warehouse_id,
                            'movement_type' => 'out',
                            'quantity' => $item->quantity_received,
                            'reference_type' => 'purchase_order_cancelled',
                            'reference_id' => $po->id,
                            'notes' => "Cancelación PO {$po->po_number}",
                            'user_id' => Auth::id(),
                            'date' => now(),
                        ];

                        if ($useVariant) {
                            $movementData['variant_id'] = $variantId;
                            $movementData['product_id'] = $productId;
                        } else {
                            $movementData['product_id'] = $productId;
                        }

                        InventoryMovement::create($movementData);

                        // Revert stock (variant or product)
                        if ($useVariant) {
                            $inventory = ProductInventory::where('variant_id', $variantId)
                                ->where('warehouse_id', $po->warehouse_id)
                                ->first();
                        } else {
                            $inventory = ProductInventory::where('product_id', $productId)
                                ->where('warehouse_id', $po->warehouse_id)
                                ->first();
                        }

                        if ($inventory) {
                            // R28: Validate stock before reverting purchase receipt
                            if ($inventory->quantity_available < $item->quantity_received) {
                                $productName = $item->product->name ?? "ID:{$productId}";
                                throw new \Exception("No se puede cancelar la OC: stock insuficiente para revertir la recepción de {$productName}. Disponible: {$inventory->quantity_available}, Recibido originalmente: {$item->quantity_received}. Revise el inventario.");
                            }
                            $inventory->quantity_available -= $item->quantity_received;
                            $inventory->save();
                        }
                    }
                }
            }

            $po->status = 'cancelled';
            $po->save();

            return $po->fresh(['supplier', 'warehouse', 'items.product', 'items.variant']);
        });
    }

    /**
     * Get purchase order statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $query = PurchaseOrder::query();

        if (!empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        $allOrders = (clone $query)->withSum('payments', 'amount')->get();

        return [
            'total_orders'           => $allOrders->count(),
            'total_spent'            => $allOrders->where('status', '!=', 'cancelled')->sum('total'),
            'draft_orders'           => $allOrders->where('status', 'draft')->count(),
            'sent_orders'            => $allOrders->where('status', 'sent')->count(),
            'partial_orders'         => $allOrders->where('status', 'partial')->count(),
            'received_orders'        => $allOrders->where('status', 'received')->count(),
            'cancelled_orders'       => $allOrders->where('status', 'cancelled')->count(),
            'average_order_value'    => $allOrders->where('status', '!=', 'cancelled')->avg('total') ?? 0,
            'total_unpaid'           => $allOrders->where('payment_status', '!=', 'paid')
                                           ->where('status', '!=', 'cancelled')
                                           ->sum(fn($o) => max(0, (float)$o->total - (float)($o->payments_sum_amount ?? 0))),
            'pending_payment_orders' => $allOrders->where('payment_status', '!=', 'paid')
                                           ->where('status', '!=', 'cancelled')
                                           ->count(),
        ];
    }

    // ==================== DAILY GLOBAL PO METHODS ====================

    /**
     * Get or create the daily global purchase order for a warehouse.
     * Daily POs have no single supplier - each item defines its own supplier.
     */
    public function getOrCreateDailyPurchaseOrder(int $warehouseId): PurchaseOrder
    {
        $today = now()->toDateString();

        // Look for existing daily PO (no supplier_id, status open or draft)
        $dailyPO = PurchaseOrder::whereNull('supplier_id')
            ->where('warehouse_id', $warehouseId)
            ->whereDate('order_date', $today)
            ->whereIn('status', ['open', 'draft'])
            ->first();

        if ($dailyPO) {
            return $dailyPO;
        }

        // Create new daily PO
        return DB::transaction(function () use ($warehouseId, $today) {
            return PurchaseOrder::create([
                'supplier_id' => null, // No single supplier - items define their own
                'warehouse_id' => $warehouseId,
                'user_id' => Auth::id(),
                'status' => 'open',
                'payment_status' => 'paid', // Quick purchases are paid on the spot
                'order_date' => $today,
                'expected_date' => $today,
                'subtotal' => 0,
                'tax_amount' => 0,
                'shipping_cost' => 0,
                'total' => 0,
                'notes' => 'OC Global del Día - Recepciones rápidas',
            ]);
        });
    }

    /**
     * Add an item to the daily PO with immediate stock reception.
     * This is the core method for Quick Purchase from POS.
     * 
     * @param array $data [
     *   'warehouse_id' => int,
     *   'supplier_id' => int,
     *   'variant_id' => int (optional),
     *   'product_id' => int (optional, required if no variant_id),
     *   'quantity' => int,
     *   'unit_cost' => float,
     *   'sale_price' => float (optional),
     *   'batch_number' => string (optional),
     *   'expiry_date' => string (optional),
     * ]
     */
    public function addItemToDailyPO(array $data): PurchaseOrderItem
    {
        return DB::transaction(function () use ($data) {
            $warehouseId = $data['warehouse_id'];
            $supplierId = $data['supplier_id'];
            $variantId = $data['variant_id'] ?? null;
            $productId = $data['product_id'] ?? null;
            $quantity = $data['quantity'];
            $unitCost = $data['unit_cost'];

            // Get product_id from variant if needed
            if ($variantId && !$productId) {
                $variant = \App\Models\ProductVariant::find($variantId);
                if ($variant) {
                    $productId = $variant->product_id;
                }
            }

            // Get or create today's daily PO
            $dailyPO = $this->getOrCreateDailyPurchaseOrder($warehouseId);

            // Create the item with supplier and received status
            $item = $dailyPO->items()->create([
                'supplier_id' => $supplierId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity_ordered' => $quantity,
                'quantity_received' => $quantity, // Immediately received
                'unit_cost' => $unitCost,
                'status' => 'received',
                'notes' => $data['notes'] ?? null,
            ]);

            // Create inventory movement (entry)
            $useVariant = !empty($variantId);
            $movementData = [
                'warehouse_id' => $warehouseId,
                'movement_type' => 'in',
                'quantity' => $quantity,
                'reference_type' => 'purchase_order',
                'reference_id' => $dailyPO->id,
                'reason' => 'Quick Purchase - Recepción inmediata',
                'notes' => "Recepción rápida - Item #{$item->id}",
                'user_id' => Auth::id(),
            ];

            if ($useVariant) {
                $movementData['variant_id'] = $variantId;
                $movementData['product_id'] = $productId;
            } else {
                $movementData['product_id'] = $productId;
            }

            InventoryMovement::create($movementData);

            // Update inventory
            if ($useVariant) {
                $inventory = ProductInventory::firstOrCreate(
                    ['variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                    ['product_id' => $productId, 'quantity_available' => 0, 'quantity_reserved' => 0, 'reorder_point' => 10, 'reorder_quantity' => 50]
                );
            } else {
                $inventory = ProductInventory::firstOrCreate(
                    ['product_id' => $productId, 'warehouse_id' => $warehouseId],
                    ['quantity_available' => 0, 'quantity_reserved' => 0, 'reorder_point' => 10, 'reorder_quantity' => 50]
                );
            }
            $inventory->quantity_available += $quantity;
            $inventory->save();

            // Handle batch tracking if applicable
            $product = \App\Models\Product::find($productId);
            if ($product?->batch_tracking && !empty($data['batch_number'])) {
                $batchData = [
                    'product_id' => $productId,
                    'batch_number' => $data['batch_number'],
                    'quantity' => $quantity,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'cost' => $unitCost,
                ];
                if ($useVariant) {
                    $batchData['variant_id'] = $variantId;
                }
                ProductBatch::create($batchData);
            }

            // Update sale price if provided
            if (!empty($data['sale_price'])) {
                $this->updateItemSalePrice($item, (float) $data['sale_price']);
            }

            // Recalculate PO totals
            $dailyPO->calculateTotals();

            return $item->fresh(['product', 'variant', 'supplier']);
        });
    }

    /**
     * Batch add items to daily PO (for POS Reception Mode).
     * 
     * @param int $warehouseId
     * @param array $items Array of item data (same format as addItemToDailyPO)
     * @return PurchaseOrder The daily PO with all added items
     */
    public function batchAddItemsToDailyPO(int $warehouseId, array $items): PurchaseOrder
    {
        return DB::transaction(function () use ($warehouseId, $items) {
            foreach ($items as $itemData) {
                $itemData['warehouse_id'] = $warehouseId;
                $this->addItemToDailyPO($itemData);
            }

            $dailyPO = $this->getOrCreateDailyPurchaseOrder($warehouseId);
            return $dailyPO->fresh(['items.product', 'items.variant', 'items.supplier']);
        });
    }

    /**
     * Quick Purchase v2: Uses the daily PO system instead of creating individual POs.
     * Backwards compatible with the original quickReceive signature.
     */
    public function quickPurchase(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $warehouseId = $data['warehouse_id'];

            foreach ($data['items'] as $itemData) {
                $this->addItemToDailyPO([
                    'warehouse_id' => $warehouseId,
                    'supplier_id' => $itemData['supplier_id'] ?? $data['supplier_id'],
                    'variant_id' => $itemData['variant_id'] ?? null,
                    'product_id' => $itemData['product_id'] ?? null,
                    'quantity' => $itemData['quantity_ordered'] ?? $itemData['quantity'],
                    'unit_cost' => $itemData['unit_cost'],
                    'sale_price' => $itemData['sale_price'] ?? null,
                    'batch_number' => $itemData['batch_number'] ?? null,
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'notes' => $itemData['notes'] ?? 'Quick Purchase desde POS',
                ]);
            }

            return $this->getOrCreateDailyPurchaseOrder($warehouseId)
                ->fresh(['items.product', 'items.variant', 'items.supplier']);
        });
    }

    /**
     * Helper to update sale price for an item's product/variant.
     */
    private function updateItemSalePrice(PurchaseOrderItem $item, float $salePrice): void
    {
        $useVariant = !empty($item->variant_id);
        
        if ($useVariant && $item->variant) {
            $pricing = $item->variant->pricing;
            if ($pricing && (float) $pricing->base_price !== $salePrice) {
                $pricing->update(['base_price' => $salePrice]);
            }
        } elseif ($item->product) {
            $pricing = $item->product->pricing;
            if ($pricing && (float) $pricing->base_price !== $salePrice) {
                $pricing->update(['base_price' => $salePrice]);
            }
        }
    }
}
