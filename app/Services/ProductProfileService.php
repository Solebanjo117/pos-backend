<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItem;
use App\Models\SalesQuoteItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class ProductProfileService
{
    public function __construct(
        private readonly InventoryFifoService $fifoService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function profile(Product $product, array $filters): array
    {
        $warehouseId = (int) $filters['warehouse_id'];
        $variantId = isset($filters['variant_id']) ? (int) $filters['variant_id'] : null;
        $perPage = min((int) ($filters['per_page'] ?? 10), 50);

        $product->loadMissing([
            'pricing',
            'category',
            'productLine',
            'vehicleCompatibility.make',
            'vehicleCompatibility.model.make',
            'vehicleCompatibility.engine.model.make',
            'vehicleCompatibility.chassis.model.make',
        ]);

        $variants = ProductVariant::query()
            ->with([
                'brand',
                'pricing',
                'suppliers',
                'inventory' => function ($query) use ($warehouseId): void {
                    $query->with('warehouse')->where('warehouse_id', $warehouseId);
                },
            ])
            ->where('product_id', $product->id)
            ->when($variantId, fn (Builder $query): Builder => $query->where('id', $variantId))
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        if ($variantId && $variants->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ProductVariant::class, [$variantId]);
        }

        return [
            'product' => $this->serializedProduct($product),
            'warehouse_id' => $warehouseId,
            'variants' => $variants
                ->map(fn (ProductVariant $variant): array => $this->serializedVariantProfile($product, $variant, $warehouseId, $perPage, $filters))
                ->values(),
            'customers' => $this->customersForProduct($product->id, $variantId, $warehouseId, $filters),
            'suppliers' => $this->suppliersForProduct($product->id, $variantId),
            'compatibility' => $this->serializedCompatibility($product),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'system_id' => $product->system_id,
            'sku_base' => $product->sku_base,
            'name' => $product->name,
            'status' => $product->status,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'product_line' => $product->productLine ? [
                'id' => $product->productLine->id,
                'name' => $product->productLine->name,
            ] : null,
            'pricing' => [
                'configuration' => $this->configurationPricing($product->pricing),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedVariantProfile(Product $product, ProductVariant $variant, int $warehouseId, int $perPage, array $filters): array
    {
        $currentPricing = $this->currentPricing($product->id, $variant->id, $warehouseId);

        return [
            'id' => $variant->id,
            'system_id' => $variant->system_id,
            'sku' => $variant->sku,
            'upc' => $variant->upc,
            'status' => $variant->status,
            'is_default' => (bool) $variant->is_default,
            'brand' => $variant->brand ? [
                'id' => $variant->brand->id,
                'name' => $variant->brand->name,
            ] : null,
            'pricing' => [
                'configuration' => $this->configurationPricing($variant->pricing),
                'current' => $currentPricing,
                'price_family' => [
                    'visible_price_source' => $currentPricing['active_lot_id'] ? 'active_lot' : 'configuration_fallback',
                    'surfaces' => ['product', 'pos', 'inventory', 'sales_orders', 'sales_quotes'],
                ],
            ],
            'inventory' => $this->inventoryForVariant($product->id, $variant->id, $warehouseId, $currentPricing),
            'lots' => $this->lotsForVariant($product->id, $variant->id, $warehouseId, $perPage),
            'movements' => $this->movementsForVariant($product->id, $variant->id, $warehouseId, $perPage, $filters),
            'sales' => $this->salesForVariant($product->id, $variant->id, $warehouseId, $perPage, $filters),
            'quotes' => $this->quotesForVariant($product->id, $variant->id, $warehouseId, $perPage, $filters),
            'purchases' => $this->purchasesForVariant($product->id, $variant->id, $warehouseId, $perPage, $filters),
            'suppliers' => $this->suppliersForProduct($product->id, $variant->id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configurationPricing(?ProductPricing $pricing): ?array
    {
        if (! $pricing) {
            return null;
        }

        $saleTaxRate = (float) ($pricing->sale_tax_rate ?? $pricing->tax_rate ?? 0);
        $costTaxRate = (float) ($pricing->cost_tax_rate ?? $pricing->tax_rate ?? $saleTaxRate);

        return [
            'base_price' => round((float) $pricing->base_price, 2),
            'calculated_price' => round((float) $pricing->calculated_price, 2),
            'cost' => round((float) $pricing->cost_total, 2),
            'cost_base' => round((float) $pricing->cost_base, 2),
            'cost_miscellaneous' => round((float) $pricing->cost_miscellaneous, 2),
            'cost_total' => round((float) $pricing->cost_total, 2),
            'sale_tax_rate' => $saleTaxRate,
            'cost_tax_rate' => $costTaxRate,
            'tax_type' => $pricing->tax_type ?? 'standard',
            'pricing_formula' => $pricing->pricing_formula,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPricing(int $productId, int $variantId, int $warehouseId): array
    {
        $pricing = $this->fifoService->currentFifoPricing($productId, $variantId, $warehouseId);
        $currentCost = round((float) $pricing['current_cost'], 2);
        $currentSalePrice = round((float) $pricing['current_sale_price'], 2);

        return [
            'warehouse_id' => $warehouseId,
            'active_lot_id' => $pricing['active_lot_id'],
            'current_cost' => $currentCost,
            'current_sale_price' => $currentSalePrice,
            'inventory_value' => round((float) $pricing['inventory_value'], 2),
            'fifo_cost_current' => $currentCost,
            'fifo_sale_price_current' => $currentSalePrice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryForVariant(int $productId, int $variantId, int $warehouseId, array $currentPricing): array
    {
        $records = ProductInventory::query()
            ->with('warehouse')
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->get();

        return [
            'summary' => [
                'warehouse_id' => $warehouseId,
                'stock_available' => (int) $records->sum('quantity_available'),
                'stock_reserved' => (int) $records->sum('quantity_reserved'),
                'active_lot_id' => $currentPricing['active_lot_id'],
                'current_cost' => $currentPricing['current_cost'],
                'current_sale_price' => $currentPricing['current_sale_price'],
                'fifo_cost_current' => $currentPricing['fifo_cost_current'],
                'fifo_sale_price_current' => $currentPricing['fifo_sale_price_current'],
                'inventory_value' => $currentPricing['inventory_value'],
            ],
            'warehouses' => $records->map(fn (ProductInventory $inventory): array => [
                'warehouse' => $inventory->warehouse ? [
                    'id' => $inventory->warehouse->id,
                    'name' => $inventory->warehouse->name,
                    'code' => $inventory->warehouse->code,
                ] : null,
                'quantity_available' => (int) $inventory->quantity_available,
                'quantity_reserved' => (int) $inventory->quantity_reserved,
                'reorder_point' => (int) $inventory->reorder_point,
                'reorder_quantity' => (int) $inventory->reorder_quantity,
                'location_in_warehouse' => $inventory->location_in_warehouse,
                'last_restocked_at' => $inventory->last_restocked_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lotsForVariant(int $productId, int $variantId, int $warehouseId, int $perPage): Collection
    {
        return InventoryLot::query()
            ->with('warehouse')
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->limit($perPage)
            ->get()
            ->map(fn (InventoryLot $lot): array => $this->serializedLot($lot))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function movementsForVariant(int $productId, int $variantId, int $warehouseId, int $perPage, array $filters): array
    {
        $baseQuery = InventoryMovement::query()
            ->with(['warehouse', 'destinationWarehouse', 'supplier', 'sourceLots', 'lotAllocations.lot'])
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where(function (Builder $query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhere('destination_warehouse_id', $warehouseId);
            });

        $this->applyCreatedAtDateFilters($baseQuery, $filters);

        $recent = (clone $baseQuery)
            ->latest('created_at')
            ->limit($perPage)
            ->get();

        return [
            'summary' => [
                'total_movements' => (clone $baseQuery)->count(),
                'inbound_movements' => (clone $baseQuery)->where('movement_type', 'in')->count(),
                'outbound_movements' => (clone $baseQuery)->where('movement_type', 'out')->count(),
                'transfer_movements' => (clone $baseQuery)->where('movement_type', 'transfer')->count(),
            ],
            'recent' => $recent->map(fn (InventoryMovement $movement): array => $this->serializedMovement($movement))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesForVariant(int $productId, int $variantId, int $warehouseId, int $perPage, array $filters): array
    {
        $baseQuery = SalesOrderItem::query()
            ->with(['salesOrder.customer', 'salesOrder.user', 'warehouse'])
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId);

        $this->applySalesOrderDateFilters($baseQuery, $filters);

        $items = (clone $baseQuery)->get();
        $recent = (clone $baseQuery)->latest('created_at')->limit($perPage)->get();

        return [
            'summary' => [
                'orders_count' => $items->pluck('sales_order_id')->unique()->count(),
                'units_sold' => (int) $items->sum('quantity'),
                'total_revenue' => round((float) $items->sum('total'), 2),
                'customers_count' => $items
                    ->map(fn (SalesOrderItem $item) => $item->salesOrder?->customer_id)
                    ->filter()
                    ->unique()
                    ->count(),
            ],
            'recent' => $recent->map(fn (SalesOrderItem $item): array => $this->serializedSaleItem($item))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotesForVariant(int $productId, int $variantId, int $warehouseId, int $perPage, array $filters): array
    {
        $baseQuery = SalesQuoteItem::query()
            ->with(['salesQuote.customer', 'salesQuote.user', 'warehouse'])
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId);

        $this->applySalesQuoteDateFilters($baseQuery, $filters);

        $items = (clone $baseQuery)->get();
        $recent = (clone $baseQuery)->latest('created_at')->limit($perPage)->get();

        return [
            'summary' => [
                'quotes_count' => $items->pluck('sales_quote_id')->unique()->count(),
                'units_quoted' => (int) $items->sum('quantity'),
                'total_quoted' => round((float) $items->sum('total'), 2),
                'customers_count' => $items
                    ->map(fn (SalesQuoteItem $item) => $item->salesQuote?->customer_id)
                    ->filter()
                    ->unique()
                    ->count(),
            ],
            'recent' => $recent->map(fn (SalesQuoteItem $item): array => $this->serializedQuoteItem($item))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchasesForVariant(int $productId, int $variantId, int $warehouseId, int $perPage, array $filters): array
    {
        $baseQuery = PurchaseOrderItem::query()
            ->with(['purchaseOrder.supplier', 'purchaseOrder.warehouse', 'supplier'])
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId));

        $this->applyPurchaseOrderDateFilters($baseQuery, $filters);

        $items = (clone $baseQuery)->get();
        $recent = (clone $baseQuery)->latest('created_at')->limit($perPage)->get();

        return [
            'summary' => [
                'orders_count' => $items->pluck('purchase_order_id')->unique()->count(),
                'units_ordered' => (int) $items->sum(fn (PurchaseOrderItem $item): int => (int) $item->total_units_ordered),
                'units_received' => (int) $items->sum(fn (PurchaseOrderItem $item): int => (int) $item->total_units_received),
                'total_ordered' => round((float) $items->sum(fn (PurchaseOrderItem $item): float => (float) $item->total), 2),
            ],
            'recent' => $recent->map(fn (PurchaseOrderItem $item): array => $this->serializedPurchaseItem($item))->values(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function customersForProduct(int $productId, ?int $variantId, int $warehouseId, array $filters): Collection
    {
        $query = SalesOrderItem::query()
            ->with('salesOrder.customer')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, fn (Builder $query): Builder => $query->where('variant_id', $variantId));

        $this->applySalesOrderDateFilters($query, $filters);

        return $query->get()
            ->filter(fn (SalesOrderItem $item): bool => (bool) $item->salesOrder?->customer)
            ->groupBy(fn (SalesOrderItem $item): int => (int) $item->salesOrder->customer_id)
            ->map(function (Collection $items): array {
                $customer = $items->first()->salesOrder->customer;

                return [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'orders_count' => $items->pluck('sales_order_id')->unique()->count(),
                    'units_purchased' => (int) $items->sum('quantity'),
                    'total_spent' => round((float) $items->sum('total'), 2),
                    'last_purchased_at' => $items
                        ->map(fn (SalesOrderItem $item): ?string => $item->salesOrder?->date?->toDateString())
                        ->filter()
                        ->max(),
                ];
            })
            ->sortByDesc(fn (array $row): string => $row['last_purchased_at'] ?? '')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function suppliersForProduct(int $productId, ?int $variantId): Collection
    {
        $configuredSuppliers = ProductSupplier::query()
            ->with(['supplier', 'variant.brand'])
            ->where('product_id', $productId)
            ->when($variantId, fn (Builder $query): Builder => $query->where('variant_id', $variantId))
            ->get()
            ->filter(fn (ProductSupplier $productSupplier): bool => (bool) $productSupplier->supplier)
            ->map(fn (ProductSupplier $productSupplier): array => [
                'id' => $productSupplier->supplier->id,
                'name' => $productSupplier->supplier->name,
                'variant_id' => $productSupplier->variant_id,
                'variant_sku' => $productSupplier->variant?->sku,
                'brand_name' => $productSupplier->variant?->brand?->name,
                'supplier_cost' => round((float) $productSupplier->supplier_cost, 2),
                'lead_time_days' => $productSupplier->lead_time_days,
                'minimum_order_quantity' => $productSupplier->minimum_order_quantity,
                'is_preferred' => (bool) $productSupplier->is_preferred,
                'source' => 'configured',
            ]);

        $purchaseSuppliers = PurchaseOrderItem::query()
            ->with(['purchaseOrder.supplier', 'variant.brand', 'supplier'])
            ->where('product_id', $productId)
            ->when($variantId, fn (Builder $query): Builder => $query->where('variant_id', $variantId))
            ->get()
            ->map(function (PurchaseOrderItem $item): ?array {
                $supplier = $item->supplier ?? $item->purchaseOrder?->supplier;

                if (! $supplier) {
                    return null;
                }

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'variant_id' => $item->variant_id,
                    'variant_sku' => $item->variant?->sku,
                    'brand_name' => $item->variant?->brand?->name,
                    'supplier_cost' => round((float) $item->unit_cost, 2),
                    'lead_time_days' => null,
                    'minimum_order_quantity' => null,
                    'is_preferred' => false,
                    'source' => 'purchase_order',
                ];
            })
            ->filter();

        return $configuredSuppliers
            ->concat($purchaseSuppliers)
            ->unique(fn (array $row): string => $row['id'].'-'.$row['variant_id'])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function serializedCompatibility(Product $product): Collection
    {
        return $product->vehicleCompatibility
            ->map(fn ($compatibility): array => [
                'id' => $compatibility->id,
                'scope_type' => $compatibility->scope_type,
                'make_id' => $compatibility->make_id,
                'model_id' => $compatibility->model_id,
                'engine_id' => $compatibility->engine_id,
                'chassis_id' => $compatibility->chassis_id,
                'raw_vehicle_text' => $compatibility->raw_vehicle_text,
                'make' => $compatibility->make?->name ?? $compatibility->model?->make?->name ?? $compatibility->engine?->model?->make?->name ?? $compatibility->chassis?->model?->make?->name,
                'model' => $compatibility->model?->name ?? $compatibility->engine?->model?->name ?? $compatibility->chassis?->model?->name,
                'year_range' => $compatibility->model?->year_range ?? $compatibility->engine?->model?->year_range ?? $compatibility->chassis?->model?->year_range,
                'engine' => $compatibility->engine?->full_description,
                'chassis' => $compatibility->chassis ? [
                    'id' => $compatibility->chassis->id,
                    'code' => $compatibility->chassis->code,
                    'name' => $compatibility->chassis->name,
                    'full_description' => $compatibility->chassis->full_description,
                ] : null,
                'all_engines' => (bool) $compatibility->all_engines,
                'all_chassis' => (bool) $compatibility->all_chassis,
                'notes' => $compatibility->notes,
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedLot(InventoryLot $lot): array
    {
        return [
            'id' => $lot->id,
            'warehouse_id' => $lot->warehouse_id,
            'warehouse' => $lot->warehouse ? [
                'id' => $lot->warehouse->id,
                'name' => $lot->warehouse->name,
                'code' => $lot->warehouse->code,
            ] : null,
            'source_type' => $lot->source_type,
            'source_id' => $lot->source_id,
            'source_movement_id' => $lot->source_movement_id,
            'batch_number' => $lot->batch_number,
            'received_quantity' => (int) $lot->received_quantity,
            'quantity_available' => (int) $lot->quantity_available,
            'unit_cost_base' => round((float) $lot->unit_cost_base, 2),
            'tax_per_unit' => round((float) $lot->tax_per_unit, 2),
            'misc_per_unit' => round((float) $lot->misc_per_unit, 2),
            'landed_unit_cost' => round((float) $lot->landed_unit_cost, 2),
            'sale_price' => round((float) $lot->sale_price, 2),
            'remaining_value' => round((int) $lot->quantity_available * (float) $lot->landed_unit_cost, 2),
            'received_at' => $lot->received_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedMovement(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movement_type' => $movement->movement_type,
            'reason' => $movement->reason,
            'quantity' => (int) $movement->quantity,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'notes' => $movement->notes,
            'warehouse' => $movement->warehouse ? [
                'id' => $movement->warehouse->id,
                'name' => $movement->warehouse->name,
                'code' => $movement->warehouse->code,
            ] : null,
            'destination_warehouse' => $movement->destinationWarehouse ? [
                'id' => $movement->destinationWarehouse->id,
                'name' => $movement->destinationWarehouse->name,
                'code' => $movement->destinationWarehouse->code,
            ] : null,
            'supplier' => $movement->supplier ? [
                'id' => $movement->supplier->id,
                'name' => $movement->supplier->name,
            ] : null,
            'source_lots' => $movement->sourceLots->map(fn (InventoryLot $lot): array => $this->serializedLot($lot))->values(),
            'lot_allocations' => $movement->lotAllocations->map(fn ($allocation): array => [
                'lot_id' => $allocation->inventory_lot_id,
                'quantity' => (int) $allocation->quantity,
                'unit_cost' => round((float) $allocation->unit_cost, 2),
                'total_cost' => round((float) $allocation->total_cost, 2),
                'sale_price' => round((float) ($allocation->lot?->sale_price ?? 0), 2),
            ])->values(),
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedSaleItem(SalesOrderItem $item): array
    {
        $order = $item->salesOrder;

        return [
            'id' => $order?->id,
            'order_number' => $order?->order_number,
            'date' => $order?->date?->toDateString(),
            'status' => $order?->status,
            'pos_session_id' => $order?->pos_session_id,
            'customer' => $order?->customer ? [
                'id' => $order->customer->id,
                'code' => $order->customer->code,
                'name' => $order->customer->name,
            ] : null,
            'warehouse' => $item->warehouse ? [
                'id' => $item->warehouse->id,
                'name' => $item->warehouse->name,
                'code' => $item->warehouse->code,
            ] : null,
            'quantity' => (int) $item->quantity,
            'unit_price' => round((float) $item->unit_price, 2),
            'unit_cost' => round((float) $item->unit_cost, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'tax_amount' => round((float) $item->tax_amount, 2),
            'total' => round((float) $item->total, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedQuoteItem(SalesQuoteItem $item): array
    {
        $quote = $item->salesQuote;

        return [
            'id' => $quote?->id,
            'quote_number' => $quote?->quote_number,
            'date' => $quote?->date?->toDateString(),
            'status' => $quote?->status,
            'pos_session_id' => $quote?->pos_session_id,
            'customer' => $quote?->customer ? [
                'id' => $quote->customer->id,
                'code' => $quote->customer->code,
                'name' => $quote->customer->name,
            ] : null,
            'warehouse' => $item->warehouse ? [
                'id' => $item->warehouse->id,
                'name' => $item->warehouse->name,
                'code' => $item->warehouse->code,
            ] : null,
            'quantity' => (int) $item->quantity,
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'tax_amount' => round((float) $item->tax_amount, 2),
            'total' => round((float) $item->total, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializedPurchaseItem(PurchaseOrderItem $item): array
    {
        $order = $item->purchaseOrder;
        $supplier = $item->supplier ?? $order?->supplier;

        return [
            'id' => $order?->id,
            'po_number' => $order?->po_number,
            'supplier_invoice_number' => $order?->supplier_invoice_number,
            'order_date' => $order?->order_date?->toDateString(),
            'received_date' => $order?->received_date?->toDateString(),
            'status' => $order?->status,
            'payment_status' => $order?->payment_status,
            'supplier' => $supplier ? [
                'id' => $supplier->id,
                'name' => $supplier->name,
            ] : null,
            'warehouse' => $order?->warehouse ? [
                'id' => $order->warehouse->id,
                'name' => $order->warehouse->name,
                'code' => $order->warehouse->code,
            ] : null,
            'quantity_ordered' => (int) $item->quantity_ordered,
            'units_per_package' => (int) ($item->units_per_package ?? 1),
            'total_units_ordered' => (int) $item->total_units_ordered,
            'total_units_received' => (int) $item->total_units_received,
            'unit_cost' => round((float) $item->unit_cost, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'tax_amount' => round((float) $item->tax_amount, 2),
            'misc_cost' => round((float) $item->misc_cost, 2),
            'total' => round((float) $item->total, 2),
        ];
    }

    private function applyCreatedAtDateFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
    }

    private function applySalesOrderDateFilters(Builder $query, array $filters): void
    {
        $query->whereHas('salesOrder', function (Builder $query) use ($filters): void {
            $query
                ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '>=', $date))
                ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '<=', $date));
        });
    }

    private function applySalesQuoteDateFilters(Builder $query, array $filters): void
    {
        $query->whereHas('salesQuote', function (Builder $query) use ($filters): void {
            $query
                ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '>=', $date))
                ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '<=', $date));
        });
    }

    private function applyPurchaseOrderDateFilters(Builder $query, array $filters): void
    {
        $query->whereHas('purchaseOrder', function (Builder $query) use ($filters): void {
            $query
                ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date))
                ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date));
        });
    }
}
