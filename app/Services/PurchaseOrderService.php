<?php

namespace App\Services;

use App\Helpers\TaxRateHelper;
use App\Models\InventoryLot;
use App\Models\ProductBatch;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplyListItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', "%{$search}%")
                    ->orWhere('supplier_invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $supplierId = $filters['supplier_id'];
            // Search by supplier in header OR in items (for daily global POs)
            $query->where(function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId)
                    ->orWhereHas('items', function ($iq) use ($supplierId) {
                        $iq->where('supplier_id', $supplierId);
                    });
            });
        }

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        } elseif (! empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        } elseif (! empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        // Filter by payment status
        if (! empty($filters['payment_status'])) {
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
            'payments.user:id,name',
            'items.supplier:id,name', // Item-level supplier for daily POs
            'items.product:id,sku_base,name',
            'items.variant:id,product_id,brand_id,sku',
            'items.variant.brand:id,name',
            'items.product.pricing:id,product_id,cost,base_price',
        ])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperationalDetail(int $id): array
    {
        $purchaseOrder = $this->getById($id);
        $items = $purchaseOrder->items;
        $itemIds = $items->pluck('id')->all();

        $lots = InventoryLot::query()
            ->with(['warehouse:id,name', 'sourceMovement:id,reference_type,reference_id'])
            ->where('source_type', 'purchase_order')
            ->whereIn('source_id', $itemIds)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $lotsByItem = $lots->groupBy('source_id');
        $payload = $purchaseOrder->toArray();
        $payload['items'] = $items
            ->map(fn (PurchaseOrderItem $item): array => $this->serializeOperationalItem($item, $lotsByItem->get($item->id, collect())))
            ->values()
            ->all();
        $payload['payments'] = $this->serializePayments($purchaseOrder);
        $payload['cost_summary'] = $this->costSummary($purchaseOrder);
        $payload['receipt_summary'] = $this->receiptSummary($purchaseOrder);
        $payload['payment_summary'] = $this->paymentSummary($purchaseOrder);
        $payload['supplier_summary'] = $this->supplierSummary($purchaseOrder);
        $payload['ap_impact'] = $this->accountsPayableImpact($purchaseOrder);
        $payload['inventory_impact'] = $this->inventoryImpact($lots);

        return $payload;
    }

    /**
     * Create new purchase order
     */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $po = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
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
            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    // Support both variant_id-based and legacy product_id-based items
                    $variantId = $itemData['variant_id'] ?? null;
                    $productId = $itemData['product_id'] ?? null;

                    // If variant_id is provided, get product_id from variant
                    if ($variantId) {
                        $variant = ProductVariant::find($variantId);
                        if ($variant) {
                            $productId = $variant->product_id;
                        }
                    }

                    $po->items()->create([
                        'supplier_id' => $po->supplier_id,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'quantity_ordered' => $itemData['quantity_ordered'],
                        'units_per_package' => $itemData['units_per_package'] ?? 1,
                        'unit_cost' => $itemData['unit_cost'],
                        'tax_rate' => TaxRateHelper::resolve($itemData['tax_rate'] ?? null),
                        'tax_amount' => isset($itemData['tax_amount']) ? (float) $itemData['tax_amount'] : null,
                        'misc_cost' => (float) ($itemData['misc_cost'] ?? 0),
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }
            }

            $this->consumeSourceSupplyListItems($data);

            $po->calculateTotals();

            return $po->fresh(['supplier', 'warehouse', 'items.product']);
        });
    }

    public function getSupplierCandidates(array $data, SmartReorderService $smartReorderService): array
    {
        if ($data['source'] === 'low_stock') {
            return $this->getLowStockCandidates($data, $smartReorderService);
        }

        return $this->getDailySupplyListCandidates($data);
    }

    public function supplierMismatchWarnings(int $supplierId, array $items): array
    {
        $variantIds = collect($items)
            ->pluck('variant_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            return [];
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->get(['id', 'product_id', 'brand_id']);

        $supplierBrandIds = Supplier::query()
            ->find($supplierId)
            ?->brands()
            ->pluck('brands.id')
            ->map(fn ($id) => (int) $id)
            ?? collect();

        $directVariantIds = ProductSupplier::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('variant_id', $variantIds)
            ->pluck('variant_id')
            ->map(fn ($id) => (int) $id);

        $productLevelProductIds = ProductSupplier::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('product_id', $variants->pluck('product_id')->unique())
            ->whereNull('variant_id')
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id);

        $linkedVariantIds = $variants
            ->filter(fn (ProductVariant $variant): bool => $directVariantIds->contains((int) $variant->id)
                || $productLevelProductIds->contains((int) $variant->product_id)
                || ($variant->brand_id && $supplierBrandIds->contains((int) $variant->brand_id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $missingVariantIds = $variantIds->diff($linkedVariantIds);

        if ($missingVariantIds->isEmpty()) {
            return [];
        }

        return ProductVariant::with(['product:id,name,sku_base', 'brand:id,name'])
            ->whereIn('id', $missingVariantIds)
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'type' => 'supplier_variant_missing',
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'sku' => $variant->sku ?? $variant->product?->sku_base,
                'product_name' => $variant->product?->name,
                'brand_name' => $variant->brand?->name,
                'message' => 'La variante '.$variant->display_name.' no esta vinculada a este proveedor.',
            ])
            ->values()
            ->all();
    }

    private function getLowStockCandidates(array $data, SmartReorderService $smartReorderService): array
    {
        $supplierGroups = $smartReorderService->getLowStockProducts((int) $data['warehouse_id']);
        $supplierId = (int) $data['supplier_id'];

        foreach ($supplierGroups as $group) {
            if ((int) ($group['supplier']['id'] ?? 0) !== $supplierId) {
                continue;
            }

            return collect($group['items'])
                ->map(fn (array $item): array => [
                    'id' => 'low_stock_'.$item['inventory_id'],
                    'source' => 'low_stock',
                    'source_supply_list_item_id' => null,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'product_name' => $item['product']['name'] ?? 'Producto',
                    'sku' => $item['product']['sku'] ?? null,
                    'current_stock' => $item['current_stock'] ?? null,
                    'suggested_quantity' => max(1, (int) ($item['suggested_quantity'] ?? 1)),
                    'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                    'tax_rate' => TaxRateHelper::defaultRate(),
                    'tax_amount' => null,
                    'misc_cost' => 0,
                    'notes' => 'Stock bajo: actual '.($item['current_stock'] ?? 0).' / minimo '.($item['reorder_point'] ?? 0),
                ])
                ->values()
                ->all();
        }

        return [];
    }

    private function getDailySupplyListCandidates(array $data): array
    {
        $supplyDate = $data['supply_date'] ?? now()->toDateString();

        return SupplyListItem::with([
            'product:id,name,sku_base',
            'variant:id,sku,brand_id',
            'variant.brand:id,name',
        ])
            ->where('supplier_id', $data['supplier_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->whereDate('supply_date', $supplyDate)
            ->orderBy('created_at')
            ->get()
            ->map(fn (SupplyListItem $item): array => [
                'id' => 'daily_'.$item->id,
                'source' => 'daily',
                'source_supply_list_item_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product?->name ?? 'Producto',
                'sku' => $item->variant?->sku ?? $item->product?->sku_base,
                'current_stock' => $item->stock_added_qty,
                'suggested_quantity' => max(1, (int) ceil((float) $item->quantity_needed)),
                'unit_cost' => (float) ($item->unit_cost ?? 0),
                'tax_rate' => TaxRateHelper::resolve($item->tax_rate ?? null),
                'tax_amount' => null,
                'misc_cost' => 0,
                'notes' => $item->notes ?: 'Desde OC diaria',
            ])
            ->values()
            ->all();
    }

    private function consumeSourceSupplyListItems(array $data): void
    {
        $sourceItemIds = collect($data['source_supply_list_item_ids'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($sourceItemIds->isEmpty()) {
            return;
        }

        $sourceItems = SupplyListItem::query()
            ->whereIn('id', $sourceItemIds)
            ->lockForUpdate()
            ->get();

        if ($sourceItems->count() !== $sourceItemIds->count()) {
            throw new \Exception('Algunos items de OC diaria ya no estan disponibles.');
        }

        $hasInvalidScope = $sourceItems->contains(function (SupplyListItem $item) use ($data): bool {
            return (int) $item->supplier_id !== (int) $data['supplier_id']
                || (int) $item->warehouse_id !== (int) $data['warehouse_id'];
        });

        if ($hasInvalidScope) {
            throw new \Exception('Solo se pueden consumir items de OC diaria del mismo proveedor y almacen.');
        }

        $orderItemKeys = collect($data['items'] ?? [])
            ->map(function (array $item): string {
                return ($item['variant_id'] ?? 'nv').'|'.$item['product_id'];
            })
            ->unique();

        $hasMissingOrderItem = $sourceItems->contains(function (SupplyListItem $item) use ($orderItemKeys): bool {
            $key = ($item->variant_id ?? 'nv').'|'.$item->product_id;

            return ! $orderItemKeys->contains($key);
        });

        if ($hasMissingOrderItem) {
            throw new \Exception('Los items de OC diaria deben estar incluidos en la orden antes de consumirlos.');
        }

        SupplyListItem::query()
            ->whereIn('id', $sourceItemIds)
            ->delete();
    }

    /**
     * Update existing PO (only if draft)
     */
    public function update(int $id, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $po = PurchaseOrder::findOrFail($id);

            if (! $po->canBeEdited()) {
                throw new \Exception('Solo se pueden editar órdenes en borrador');
            }

            $po->update(array_filter([
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], fn ($v) => $v !== null));

            // Replace items if provided
            if (isset($data['items'])) {
                $existingItems = $po->items()
                    ->get(['product_id', 'variant_id', 'pre_stock_applied_qty'])
                    ->keyBy(function ($item) {
                        return ($item->variant_id ?? 'nv').'|'.$item->product_id;
                    });

                $po->items()->delete();
                foreach ($data['items'] as $itemData) {
                    $variantId = $itemData['variant_id'] ?? null;
                    $productId = $itemData['product_id'];
                    $itemKey = ($variantId ?? 'nv').'|'.$productId;
                    $existingPreApplied = (int) ($existingItems[$itemKey]->pre_stock_applied_qty ?? 0);
                    $orderedQty = (int) ($itemData['quantity_ordered'] ?? 0);

                    $po->items()->create([
                        'supplier_id' => $po->supplier_id,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'quantity_ordered' => $orderedQty,
                        'pre_stock_applied_qty' => min($existingPreApplied, max(0, $orderedQty)),
                        'units_per_package' => $itemData['units_per_package'] ?? 1,
                        'unit_cost' => $itemData['unit_cost'],
                        'tax_rate' => TaxRateHelper::resolve($itemData['tax_rate'] ?? null),
                        'tax_amount' => isset($itemData['tax_amount']) ? (float) $itemData['tax_amount'] : null,
                        'misc_cost' => (float) ($itemData['misc_cost'] ?? 0),
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

        if (! $po->canBeEdited()) {
            throw new \Exception('Solo se pueden eliminar órdenes en borrador');
        }

        $po->items()->delete();
        $po->delete();
    }

    /**
     * Duplicate a purchase order into a new editable draft.
     */
    public function duplicate(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $source = PurchaseOrder::with('items')->findOrFail($id);

            if ($source->supplier_id === null) {
                throw new \Exception('La OC diaria no se duplica desde este flujo.');
            }

            $po = PurchaseOrder::create([
                'supplier_id' => $source->supplier_id,
                'supplier_invoice_number' => null,
                'warehouse_id' => $source->warehouse_id,
                'user_id' => Auth::id(),
                'status' => 'draft',
                'order_date' => now()->toDateString(),
                'expected_date' => null,
                'tax_amount' => 0,
                'shipping_cost' => 0,
                'notes' => $source->notes ? 'Duplicada de '.$source->po_number.': '.$source->notes : 'Duplicada de '.$source->po_number,
            ]);

            foreach ($source->items as $item) {
                $po->items()->create([
                    'supplier_id' => $item->supplier_id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'quantity_ordered' => $item->quantity_ordered,
                    'units_per_package' => $item->units_per_package ?? 1,
                    'unit_cost' => $item->unit_cost,
                    'tax_rate' => TaxRateHelper::resolve($item->tax_rate ?? null),
                    'tax_amount' => (float) ($item->tax_amount ?? 0),
                    'misc_cost' => (float) ($item->misc_cost ?? 0),
                    'notes' => $item->notes,
                ]);
            }

            $po->calculateTotals();

            return $po->fresh(['supplier', 'warehouse', 'items.product', 'items.variant']);
        });
    }

    /**
     * Send PO to supplier
     */
    public function send(int $id): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($id);

        if (! $po->canBeSent()) {
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

            if (! $po->canReceiveItems()) {
                throw new \Exception('Esta orden no puede recibir mercancía');
            }

            foreach ($receivedItems as $received) {
                $item = PurchaseOrderItem::findOrFail($received['item_id']);

                if ((int) $item->purchase_order_id !== (int) $po->id) {
                    throw new \Exception('El artículo recibido no pertenece a esta orden de compra');
                }

                // Validate not receiving more than ordered
                $preAppliedQty = max(0, (int) ($item->pre_stock_applied_qty ?? 0));
                $maxReceivable = max(0, ((int) $item->quantity_ordered - $preAppliedQty) - (int) $item->quantity_received);
                $qtyToReceive = min($received['quantity_received'], $maxReceivable);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                // Update received quantity
                $item->quantity_received += $qtyToReceive;
                $item->save();

                // Determine if using variant or product (backwards compatibility)
                $useVariant = ! empty($item->variant_id);
                $productId = $item->product_id;
                $variantId = $item->variant_id;

                // Calculate actual units received (packages × units per package)
                $actualUnits = $qtyToReceive * ($item->units_per_package ?? 1);

                $lineUnits = max(1, (int) $item->quantity_ordered * (int) ($item->units_per_package ?? 1));
                $receivedRatio = $actualUnits / $lineUnits;
                $newSalePrice = $this->receivedSalePriceForItem($item, $received);

                app(InventoryFifoService::class)->receiveStock([
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'warehouse_id' => $po->warehouse_id,
                    'quantity' => $actualUnits,
                    'unit_cost_base' => (float) $item->unit_cost,
                    'tax_amount' => round((float) $item->tax_amount * $receivedRatio, 4),
                    'misc_cost' => round((float) ($item->misc_cost ?? 0) * $receivedRatio, 4),
                    'reference_type' => 'purchase_order',
                    'reference_id' => $po->id,
                    'source_type' => 'purchase_order',
                    'source_id' => $item->id,
                    'notes' => "RecepciÃ³n PO {$po->po_number}",
                    'user_id' => Auth::id(),
                    'supplier_id' => $item->supplier_id ?? $po->supplier_id,
                    'batch_number' => $received['batch_number'] ?? null,
                    'sale_price' => $newSalePrice,
                ]);

                // Handle batch tracking if enabled
                if ($item->product && $item->product->batch_tracking && ! empty($received['batch_number'])) {
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
            $po->refresh()->load('items');
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

            if (! $po->canBeCancelled()) {
                throw new \Exception('Esta orden no puede ser cancelada');
            }

            // If items were received (partial), revert inventory
            if ($po->status === 'partial') {
                foreach ($po->items as $item) {
                    if ($item->quantity_received > 0) {
                        app(InventoryFifoService::class)->reversePurchaseReceipt($po, $item, [
                            'notes' => "Cancelación PO {$po->po_number}",
                            'user_id' => Auth::id(),
                        ]);
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

        if (! empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        $allOrders = (clone $query)->withSum('payments', 'amount')->get();

        return [
            'total_orders' => $allOrders->count(),
            'total_spent' => $allOrders->where('status', '!=', 'cancelled')->sum('total'),
            'draft_orders' => $allOrders->where('status', 'draft')->count(),
            'sent_orders' => $allOrders->where('status', 'sent')->count(),
            'partial_orders' => $allOrders->where('status', 'partial')->count(),
            'received_orders' => $allOrders->where('status', 'received')->count(),
            'cancelled_orders' => $allOrders->where('status', 'cancelled')->count(),
            'average_order_value' => $allOrders->where('status', '!=', 'cancelled')->avg('total') ?? 0,
            'total_unpaid' => $allOrders->where('payment_status', '!=', 'paid')
                ->where('status', '!=', 'cancelled')
                ->sum(fn ($o) => max(0, (float) $o->total - (float) ($o->payments_sum_amount ?? 0))),
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
     * @param  array  $data  [
     *                       'warehouse_id' => int,
     *                       'supplier_id' => int,
     *                       'variant_id' => int (optional),
     *                       'product_id' => int (optional, required if no variant_id),
     *                       'quantity' => int,
     *                       'unit_cost' => float,
     *                       'sale_price' => float (optional),
     *                       'batch_number' => string (optional),
     *                       'expiry_date' => string (optional),
     *                       ]
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
            if ($variantId && ! $productId) {
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
                'tax_rate' => TaxRateHelper::resolve($data['tax_rate'] ?? null),
                'status' => 'received',
                'notes' => $data['notes'] ?? null,
            ]);

            $useVariant = ! empty($variantId);
            app(InventoryFifoService::class)->receiveStock([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'unit_cost_base' => (float) $unitCost,
                'tax_amount' => (float) ($item->tax_amount ?? 0),
                'misc_cost' => (float) ($item->misc_cost ?? 0),
                'reference_type' => 'purchase_order',
                'reference_id' => $dailyPO->id,
                'source_type' => 'purchase_order',
                'source_id' => $item->id,
                'reason' => 'Quick Purchase - RecepciÃ³n inmediata',
                'notes' => "RecepciÃ³n rÃ¡pida - Item #{$item->id}",
                'user_id' => Auth::id(),
                'supplier_id' => $supplierId,
                'batch_number' => $data['batch_number'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
            ]);

            // Handle batch tracking if applicable
            $product = \App\Models\Product::find($productId);
            if ($product?->batch_tracking && ! empty($data['batch_number'])) {
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
            if (! empty($data['sale_price'])) {
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
     * @param  array  $items  Array of item data (same format as addItemToDailyPO)
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
                    'tax_rate' => TaxRateHelper::resolve($itemData['tax_rate'] ?? null),
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
        $useVariant = ! empty($item->variant_id);

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

    private function receivedSalePriceForItem(PurchaseOrderItem $item, array $received): ?float
    {
        if (isset($received['sale_price']) && (float) $received['sale_price'] > 0) {
            return (float) $received['sale_price'];
        }

        $category = $item->product?->category;
        if ($category?->pricing_formula && $item->unit_cost > 0) {
            $calculated = app(PricingFormulaService::class)->calculate(
                $category->pricing_formula,
                (float) $item->unit_cost,
                (bool) $category->applies_itbm
            );

            return $calculated !== null ? (float) $calculated : null;
        }

        $pricing = ! empty($item->variant_id)
            ? $item->variant?->pricing
            : $item->product?->pricing;

        return $pricing ? (float) $pricing->base_price : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryLot>  $lots
     * @return array<string, mixed>
     */
    private function serializeOperationalItem(PurchaseOrderItem $item, \Illuminate\Support\Collection $lots): array
    {
        $line = $item->toArray();
        $orderedUnits = (int) $item->quantity_ordered * (int) ($item->units_per_package ?? 1);
        $receivedUnits = (int) $item->effective_received_quantity * (int) ($item->units_per_package ?? 1);
        $subtotal = (float) $item->subtotal;
        $taxTotal = (float) $item->tax_amount;
        $miscTotal = (float) ($item->misc_cost ?? 0);
        $lineTotal = round($subtotal + $taxTotal + $miscTotal, 2);

        $line['line_cost_summary'] = [
            'ordered_units' => $orderedUnits,
            'received_units' => $receivedUnits,
            'unit_cost' => round((float) $item->unit_cost, 2),
            'subtotal' => round($subtotal, 2),
            'tax_rate' => (int) $item->tax_rate,
            'tax_total' => round($taxTotal, 2),
            'misc_total' => round($miscTotal, 2),
            'line_total' => $lineTotal,
            'landed_unit_cost' => $orderedUnits > 0 ? round($lineTotal / $orderedUnits, 4) : 0.0,
        ];

        $line['inventory_lots'] = $lots
            ->map(fn (InventoryLot $lot): array => $this->serializeInventoryLot($lot))
            ->values()
            ->all();

        return $line;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializePayments(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->payments
            ->map(fn ($payment): array => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'method_label' => $payment->method_label,
                'reference_number' => $payment->reference_number,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toISOString(),
                'pos_session_id' => $payment->pos_session_id,
                'cash_movement_id' => $payment->cash_movement_id,
                'user' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function costSummary(PurchaseOrder $purchaseOrder): array
    {
        $miscTotal = round((float) $purchaseOrder->items->sum(fn (PurchaseOrderItem $item): float => (float) ($item->misc_cost ?? 0)), 2);

        return [
            'subtotal' => round((float) $purchaseOrder->subtotal, 2),
            'tax_total' => round((float) $purchaseOrder->tax_amount, 2),
            'misc_total' => $miscTotal,
            'shipping_total' => round((float) $purchaseOrder->shipping_cost, 2),
            'grand_total' => round((float) $purchaseOrder->total, 2),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function receiptSummary(PurchaseOrder $purchaseOrder): array
    {
        $orderedPackages = (int) $purchaseOrder->items->sum('quantity_ordered');
        $receivedPackages = (int) $purchaseOrder->items->sum(fn (PurchaseOrderItem $item): int => (int) $item->effective_received_quantity);
        $orderedUnits = (int) $purchaseOrder->items->sum(fn (PurchaseOrderItem $item): int => (int) $item->quantity_ordered * (int) ($item->units_per_package ?? 1));
        $receivedUnits = (int) $purchaseOrder->items->sum(fn (PurchaseOrderItem $item): int => (int) $item->effective_received_quantity * (int) ($item->units_per_package ?? 1));

        return [
            'ordered_packages' => $orderedPackages,
            'received_packages' => $receivedPackages,
            'pending_packages' => max(0, $orderedPackages - $receivedPackages),
            'ordered_units' => $orderedUnits,
            'received_units' => $receivedUnits,
            'pending_units' => max(0, $orderedUnits - $receivedUnits),
            'received_percent' => $orderedPackages > 0 ? round(($receivedPackages / $orderedPackages) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSummary(PurchaseOrder $purchaseOrder): array
    {
        return [
            'payment_status' => $purchaseOrder->payment_status,
            'payment_status_label' => $purchaseOrder->payment_status_label,
            'amount_paid' => round((float) $purchaseOrder->amount_paid, 2),
            'amount_due' => round((float) $purchaseOrder->amount_due, 2),
            'payments_count' => $purchaseOrder->payments->count(),
            'real_payment_total' => round((float) $purchaseOrder->payments->whereIn('method', ['cash', 'card', 'transfer', 'other'])->sum('amount'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierSummary(PurchaseOrder $purchaseOrder): array
    {
        if (! $purchaseOrder->supplier) {
            return [
                'id' => null,
                'name' => 'OC diaria general',
                'is_daily_order' => true,
                'item_suppliers_count' => $purchaseOrder->items->pluck('supplier_id')->filter()->unique()->count(),
            ];
        }

        return [
            'id' => $purchaseOrder->supplier->id,
            'name' => $purchaseOrder->supplier->name,
            'email' => $purchaseOrder->supplier->email,
            'phone' => $purchaseOrder->supplier->phone,
            'is_daily_order' => false,
            'invoice_number' => $purchaseOrder->supplier_invoice_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountsPayableImpact(PurchaseOrder $purchaseOrder): array
    {
        $amountDue = round((float) $purchaseOrder->amount_due, 2);

        return [
            'status' => match (true) {
                $purchaseOrder->status === 'cancelled' => 'cancelled',
                $amountDue <= 0.0 => 'settled',
                default => 'open',
            },
            'supplier_id' => $purchaseOrder->supplier_id,
            'document_type' => 'purchase_order',
            'document_id' => $purchaseOrder->id,
            'document_number' => $purchaseOrder->po_number,
            'amount_total' => round((float) $purchaseOrder->total, 2),
            'amount_paid' => round((float) $purchaseOrder->amount_paid, 2),
            'amount_due' => $amountDue,
            'stays_in_ap' => $amountDue > 0.0,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, InventoryLot>  $lots
     * @return array<string, mixed>
     */
    private function inventoryImpact(\Illuminate\Database\Eloquent\Collection $lots): array
    {
        return [
            'lots_count' => $lots->count(),
            'received_units' => (int) $lots->sum('received_quantity'),
            'available_units' => (int) $lots->sum('quantity_available'),
            'landed_value' => round((float) $lots->sum(fn (InventoryLot $lot): float => (int) $lot->received_quantity * (float) $lot->landed_unit_cost), 2),
            'available_value' => round((float) $lots->sum(fn (InventoryLot $lot): float => (int) $lot->quantity_available * (float) $lot->landed_unit_cost), 2),
            'lots' => $lots
                ->map(fn (InventoryLot $lot): array => $this->serializeInventoryLot($lot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInventoryLot(InventoryLot $lot): array
    {
        return [
            'id' => $lot->id,
            'source_item_id' => $lot->source_id,
            'warehouse_id' => $lot->warehouse_id,
            'warehouse_name' => $lot->warehouse?->name,
            'batch_number' => $lot->batch_number,
            'received_quantity' => (int) $lot->received_quantity,
            'quantity_available' => (int) $lot->quantity_available,
            'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
            'tax_per_unit' => round((float) $lot->tax_per_unit, 4),
            'tax_total' => round((int) $lot->received_quantity * (float) $lot->tax_per_unit, 2),
            'misc_per_unit' => round((float) $lot->misc_per_unit, 4),
            'misc_total' => round((int) $lot->received_quantity * (float) $lot->misc_per_unit, 2),
            'landed_unit_cost' => round((float) $lot->landed_unit_cost, 4),
            'landed_total' => round((int) $lot->received_quantity * (float) $lot->landed_unit_cost, 2),
            'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
            'received_at' => $lot->received_at?->toISOString(),
        ];
    }
}
