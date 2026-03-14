<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use App\Models\ProductBatch;
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
            $query->bySupplier($filters['supplier_id']);
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

                // Create inventory movement (entry)
                $movementData = [
                    'warehouse_id' => $po->warehouse_id,
                    'movement_type' => 'in',
                    'quantity' => $qtyToReceive,
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

                $inventory->quantity_available += $qtyToReceive;
                $inventory->save();

                // Handle batch tracking if enabled
                if ($item->product && $item->product->batch_tracking && !empty($received['batch_number'])) {
                    $batchData = [
                        'product_id' => $productId,
                        'batch_number' => $received['batch_number'],
                        'quantity' => $qtyToReceive,
                        'expiry_date' => $received['expiry_date'] ?? null,
                        'cost' => $item->unit_cost,
                    ];

                    if ($useVariant) {
                        $batchData['variant_id'] = $variantId;
                    }

                    ProductBatch::create($batchData);
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
}
