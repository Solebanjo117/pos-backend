<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryFifoService
{
    public function receiveStock(array $data): InventoryMovement
    {
        return DB::transaction(function () use ($data) {
            $quantity = max(0, (int) $data['quantity']);

            $movement = InventoryMovement::create([
                'movement_type' => 'in',
                'reason' => $data['reason'] ?? null,
                'product_id' => (int) $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'warehouse_id' => (int) $data['warehouse_id'],
                'quantity' => $quantity,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? Auth::id(),
                'supplier_id' => $data['supplier_id'] ?? null,
                'product_batch_id' => $data['product_batch_id'] ?? null,
            ]);

            $this->createLotForInboundMovement($movement, $data);
            $this->addAggregateStock(
                (int) $data['product_id'],
                $data['variant_id'] ?? null,
                (int) $data['warehouse_id'],
                $quantity
            );

            return $movement->load(['product', 'variant', 'warehouse']);
        });
    }

    public function createLotForInboundMovement(InventoryMovement $movement, array $data = []): InventoryLot
    {
        $quantity = max(0, (int) ($data['quantity'] ?? $movement->quantity));
        $unitCostBase = round((float) ($data['unit_cost_base'] ?? $data['unit_cost'] ?? 0), 4);
        $taxPerUnit = $quantity > 0 ? round((float) ($data['tax_amount'] ?? 0) / $quantity, 4) : 0;
        $miscPerUnit = $quantity > 0 ? round((float) ($data['misc_cost'] ?? 0) / $quantity, 4) : 0;
        $landedUnitCost = round((float) ($data['landed_unit_cost'] ?? ($unitCostBase + $taxPerUnit + $miscPerUnit)), 4);
        $salePrice = round((float) ($data['sale_price'] ?? $this->resolveFallbackSalePrice($movement->product_id, $movement->variant_id)), 2);

        return InventoryLot::create([
            'product_id' => $movement->product_id,
            'variant_id' => $movement->variant_id,
            'warehouse_id' => $movement->warehouse_id,
            'source_type' => $data['source_type'] ?? $movement->reference_type ?? 'manual_inbound',
            'source_id' => $data['source_id'] ?? $movement->reference_id,
            'source_movement_id' => $movement->id,
            'batch_number' => $data['batch_number'] ?? null,
            'received_quantity' => $quantity,
            'quantity_available' => $quantity,
            'unit_cost_base' => $unitCostBase,
            'tax_per_unit' => $taxPerUnit,
            'misc_per_unit' => $miscPerUnit,
            'landed_unit_cost' => $landedUnitCost,
            'sale_price' => $salePrice,
            'received_at' => $data['received_at'] ?? now(),
        ]);
    }

    public function consumeStock(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $productId = (int) $data['product_id'];
            $variantId = $data['variant_id'] ?? null;
            $warehouseId = (int) $data['warehouse_id'];
            $requestedQuantity = max(0, (int) $data['quantity']);

            $this->ensureOpeningLotForAggregate($productId, $variantId, $warehouseId);

            $availableQuantity = $this->availableLotQuantity($productId, $variantId, $warehouseId);
            $quantityToConsume = min($requestedQuantity, $availableQuantity);

            if (($data['strict'] ?? false) && $quantityToConsume < $requestedQuantity) {
                throw new \Exception('Stock insuficiente para consumir FIFO.');
            }

            if ($quantityToConsume <= 0) {
                return [
                    'movement' => null,
                    'consumed_quantity' => 0,
                    'allocations' => collect(),
                ];
            }

            $movement = InventoryMovement::create([
                'movement_type' => 'out',
                'reason' => $data['reason'] ?? null,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'destination_warehouse_id' => $data['destination_warehouse_id'] ?? null,
                'quantity' => $quantityToConsume,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? Auth::id(),
                'product_batch_id' => $data['product_batch_id'] ?? null,
            ]);

            $remaining = $quantityToConsume;
            $allocations = collect();

            $lots = $this->availableLotsQuery($productId, $variantId, $warehouseId)->lockForUpdate()->get();
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $quantityFromLot = min($remaining, (int) $lot->quantity_available);
                $lot->quantity_available -= $quantityFromLot;
                $lot->save();

                $allocations->push(InventoryLotAllocation::create([
                    'inventory_lot_id' => $lot->id,
                    'inventory_movement_id' => $movement->id,
                    'sales_order_item_id' => $data['sales_order_item_id'] ?? null,
                    'quantity' => $quantityFromLot,
                    'unit_cost' => $lot->landed_unit_cost,
                    'total_cost' => round($quantityFromLot * (float) $lot->landed_unit_cost, 4),
                ]));

                $remaining -= $quantityFromLot;
            }

            $this->subtractAggregateStock($productId, $variantId, $warehouseId, $quantityToConsume, (bool) ($data['consume_reserved'] ?? false));

            return [
                'movement' => $movement->load(['product', 'variant', 'warehouse']),
                'consumed_quantity' => $quantityToConsume,
                'allocations' => $allocations,
                'weighted_unit_cost' => $this->weightedUnitCost($allocations),
            ];
        });
    }

    public function adjustStockToQuantity(array $data): ?InventoryMovement
    {
        return DB::transaction(function () use ($data) {
            $productId = (int) $data['product_id'];
            $variantId = $data['variant_id'] ?? null;
            $warehouseId = (int) $data['warehouse_id'];
            $targetQuantity = max(0, (int) $data['quantity']);
            $inventory = $this->findAggregateInventory($productId, $variantId, $warehouseId);
            $currentQuantity = (int) ($inventory?->quantity_available ?? 0);
            $delta = $targetQuantity - $currentQuantity;

            if ($delta === 0) {
                return null;
            }

            if ($delta > 0) {
                return $this->receiveStock([
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $delta,
                    'unit_cost_base' => (float) ($data['unit_cost_base'] ?? $data['unit_cost'] ?? $this->resolveFallbackUnitCost($productId, $variantId)),
                    'landed_unit_cost' => (float) ($data['landed_unit_cost'] ?? $data['unit_cost_base'] ?? $data['unit_cost'] ?? $this->resolveFallbackUnitCost($productId, $variantId)),
                    'reference_type' => $data['reference_type'] ?? 'manual_adjustment',
                    'reference_id' => $data['reference_id'] ?? null,
                    'source_type' => $data['source_type'] ?? 'manual_adjustment',
                    'source_id' => $data['source_id'] ?? null,
                    'reason' => $data['reason'] ?? 'manual_stock_adjustment',
                    'notes' => $data['notes'] ?? null,
                    'user_id' => $data['user_id'] ?? Auth::id(),
                ]);
            }

            $result = $this->consumeStock([
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => abs($delta),
                'reference_type' => $data['reference_type'] ?? 'manual_adjustment',
                'reference_id' => $data['reference_id'] ?? null,
                'reason' => $data['reason'] ?? 'manual_stock_adjustment',
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? Auth::id(),
                'strict' => true,
            ]);

            return $result['movement'];
        });
    }

    public function reversePurchaseReceipt(PurchaseOrder $purchaseOrder, PurchaseOrderItem $item, array $data = []): ?InventoryMovement
    {
        return DB::transaction(function () use ($purchaseOrder, $item, $data) {
            $quantityToReverse = (int) $item->quantity_received * (int) ($item->units_per_package ?? 1);

            if ($quantityToReverse <= 0) {
                return null;
            }

            $lots = InventoryLot::query()
                ->where('source_type', 'purchase_order')
                ->where('source_id', $item->id)
                ->where('warehouse_id', $purchaseOrder->warehouse_id)
                ->lockForUpdate()
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->get();

            $availableFromReceipt = (int) $lots->sum('quantity_available');
            if ($availableFromReceipt < $quantityToReverse) {
                $productName = $item->product->name ?? "ID:{$item->product_id}";
                throw new \Exception("No se puede cancelar la OC: stock insuficiente para revertir la recepción de {$productName}. Disponible en sus lotes: {$availableFromReceipt}, recibido originalmente: {$quantityToReverse}. Revise el inventario.");
            }

            $movement = InventoryMovement::create([
                'movement_type' => 'out',
                'reason' => $data['reason'] ?? 'purchase_order_cancelled',
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $purchaseOrder->warehouse_id,
                'quantity' => $quantityToReverse,
                'reference_type' => 'purchase_order_cancelled',
                'reference_id' => $purchaseOrder->id,
                'notes' => $data['notes'] ?? "Cancelación PO {$purchaseOrder->po_number}",
                'user_id' => $data['user_id'] ?? Auth::id(),
            ]);

            $remaining = $quantityToReverse;
            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $quantityFromLot = min($remaining, (int) $lot->quantity_available);
                $lot->quantity_available -= $quantityFromLot;
                $lot->save();

                InventoryLotAllocation::create([
                    'inventory_lot_id' => $lot->id,
                    'inventory_movement_id' => $movement->id,
                    'quantity' => $quantityFromLot,
                    'unit_cost' => $lot->landed_unit_cost,
                    'total_cost' => round($quantityFromLot * (float) $lot->landed_unit_cost, 4),
                ]);

                $remaining -= $quantityFromLot;
            }

            $this->subtractAggregateStock(
                $item->product_id,
                $item->variant_id,
                $purchaseOrder->warehouse_id,
                $quantityToReverse,
                false
            );

            return $movement;
        });
    }

    public function restoreFromOutboundMovement(InventoryMovement $outboundMovement, array $data = []): ?InventoryMovement
    {
        return DB::transaction(function () use ($outboundMovement, $data) {
            $allocations = $outboundMovement->lotAllocations()->with('lot')->get();
            $quantityToRestore = (int) $allocations->sum('quantity');

            if ($quantityToRestore <= 0) {
                return null;
            }

            foreach ($allocations as $allocation) {
                $allocation->lot->increment('quantity_available', (int) $allocation->quantity);
            }

            $movement = InventoryMovement::create([
                'movement_type' => 'in',
                'reason' => $data['reason'] ?? 'fifo_restore',
                'product_id' => $outboundMovement->product_id,
                'variant_id' => $outboundMovement->variant_id,
                'warehouse_id' => $outboundMovement->warehouse_id,
                'quantity' => $quantityToRestore,
                'reference_type' => $data['reference_type'] ?? 'inventory_restore',
                'reference_id' => $data['reference_id'] ?? $outboundMovement->reference_id,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? Auth::id(),
            ]);

            $this->addAggregateStock(
                $outboundMovement->product_id,
                $outboundMovement->variant_id,
                $outboundMovement->warehouse_id,
                $quantityToRestore
            );

            return $movement;
        });
    }

    public function restoreGroupedOutboundMovements(EloquentCollection|Collection $outboundMovements, array $data = []): void
    {
        foreach ($outboundMovements as $movement) {
            $this->restoreFromOutboundMovement($movement, $data);
        }
    }

    public function createTransferInboundLots(InventoryMovement $outboundMovement, int $destinationWarehouseId, int $userId): InventoryMovement
    {
        return DB::transaction(function () use ($outboundMovement, $destinationWarehouseId, $userId) {
            $allocations = $outboundMovement->lotAllocations()->with('lot')->get();
            $quantity = (int) $allocations->sum('quantity');

            $movement = InventoryMovement::create([
                'product_id' => $outboundMovement->product_id,
                'variant_id' => $outboundMovement->variant_id,
                'warehouse_id' => $destinationWarehouseId,
                'destination_warehouse_id' => $outboundMovement->warehouse_id,
                'movement_type' => 'in',
                'quantity' => $quantity,
                'reference_type' => $outboundMovement->reference_type,
                'reference_id' => $outboundMovement->reference_id,
                'reason' => $outboundMovement->reason,
                'user_id' => $userId,
            ]);

            foreach ($allocations as $allocation) {
                $lot = $allocation->lot;
                InventoryLot::create([
                    'product_id' => $lot->product_id,
                    'variant_id' => $lot->variant_id,
                    'warehouse_id' => $destinationWarehouseId,
                    'source_type' => 'transfer',
                    'source_id' => $outboundMovement->reference_id,
                    'source_movement_id' => $movement->id,
                    'batch_number' => $lot->batch_number,
                    'received_quantity' => (int) $allocation->quantity,
                    'quantity_available' => (int) $allocation->quantity,
                    'unit_cost_base' => $lot->unit_cost_base,
                    'tax_per_unit' => $lot->tax_per_unit,
                    'misc_per_unit' => $lot->misc_per_unit,
                    'landed_unit_cost' => $allocation->unit_cost,
                    'sale_price' => $lot->sale_price,
                    'received_at' => now(),
                ]);
            }

            $this->addAggregateStock(
                $outboundMovement->product_id,
                $outboundMovement->variant_id,
                $destinationWarehouseId,
                $quantity
            );

            return $movement;
        });
    }

    public function ensureOpeningLotForInventory(int $productId, ?int $variantId, int $warehouseId, int $quantity, float $unitCost): ?InventoryLot
    {
        $existingOpeningLot = InventoryLot::query()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->where('source_type', 'opening_balance')
            ->whereNull('source_id')
            ->first();

        if ($existingOpeningLot instanceof InventoryLot) {
            return null;
        }

        $missingQuantity = max(0, $quantity - $this->availableLotQuantity($productId, $variantId, $warehouseId));

        if ($missingQuantity <= 0) {
            return null;
        }

        $movement = InventoryMovement::create([
            'movement_type' => 'in',
            'reason' => 'opening_balance',
            'product_id' => $productId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => $missingQuantity,
            'reference_type' => 'opening_balance',
            'notes' => 'Saldo inicial FIFO',
            'user_id' => Auth::id(),
        ]);

        return $this->createLotForInboundMovement($movement, [
            'quantity' => $missingQuantity,
            'unit_cost_base' => $unitCost,
            'landed_unit_cost' => $unitCost,
            'source_type' => 'opening_balance',
            'received_at' => now(),
        ]);
    }

    public function availableStockValue(?int $warehouseId = null): float
    {
        return (float) InventoryLot::query()
            ->where('quantity_available', '>', 0)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->get()
            ->sum(fn (InventoryLot $lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost);
    }

    public function weightedUnitCost(Collection $allocations): float
    {
        $quantity = (int) $allocations->sum('quantity');

        if ($quantity <= 0) {
            return 0.0;
        }

        return round((float) $allocations->sum('total_cost') / $quantity, 2);
    }

    public function currentFifoCost(int $productId, ?int $variantId, int $warehouseId): float
    {
        return (float) $this->currentFifoPricing($productId, $variantId, $warehouseId)['current_cost'];
    }

    /**
     * @return array{active_lot_id: int|null, current_cost: float, current_sale_price: float, inventory_value: float}
     */
    public function currentFifoPricing(int $productId, ?int $variantId, int $warehouseId): array
    {
        $lot = $this->availableLotsQuery($productId, $variantId, $warehouseId)->first();

        return [
            'active_lot_id' => $lot?->id,
            'current_cost' => $lot ? (float) $lot->landed_unit_cost : $this->resolveFallbackUnitCost($productId, $variantId),
            'current_sale_price' => $lot ? (float) $lot->sale_price : $this->resolveFallbackSalePrice($productId, $variantId),
            'inventory_value' => round($this->availableStockValueForItem($productId, $variantId, $warehouseId), 2),
        ];
    }

    private function availableStockValueForItem(int $productId, ?int $variantId, int $warehouseId): float
    {
        return (float) $this->availableLotsQuery($productId, $variantId, $warehouseId)
            ->get(['quantity_available', 'landed_unit_cost'])
            ->sum(fn (InventoryLot $lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost);
    }

    private function ensureOpeningLotForAggregate(int $productId, ?int $variantId, int $warehouseId): void
    {
        $inventory = $this->findAggregateInventory($productId, $variantId, $warehouseId);
        $aggregateAvailable = (int) ($inventory?->quantity_available ?? 0) + (int) ($inventory?->quantity_reserved ?? 0);
        $lotAvailable = $this->availableLotQuantity($productId, $variantId, $warehouseId);
        $missingLotQuantity = max(0, $aggregateAvailable - $lotAvailable);

        if ($missingLotQuantity > 0) {
            $this->ensureOpeningLotForInventory(
                $productId,
                $variantId,
                $warehouseId,
                $missingLotQuantity,
                $this->resolveFallbackUnitCost($productId, $variantId)
            );
        }
    }

    private function availableLotQuantity(int $productId, ?int $variantId, int $warehouseId): int
    {
        return (int) $this->availableLotsQuery($productId, $variantId, $warehouseId)->sum('quantity_available');
    }

    private function availableLotsQuery(int $productId, ?int $variantId, int $warehouseId)
    {
        return InventoryLot::query()
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_available', '>', 0)
            ->when(
                $variantId,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->where('product_id', $productId)->whereNull('variant_id')
            )
            ->orderBy('received_at')
            ->orderBy('id');
    }

    private function addAggregateStock(int $productId, ?int $variantId, int $warehouseId, int $quantity): void
    {
        $inventory = ProductInventory::firstOrCreate(
            [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
            ],
            [
                'quantity_available' => 0,
                'quantity_reserved' => 0,
                'reorder_point' => 10,
                'reorder_quantity' => 50,
            ]
        );

        $inventory->quantity_available += $quantity;
        $inventory->last_restocked_at = now();
        $inventory->save();
    }

    private function subtractAggregateStock(int $productId, ?int $variantId, int $warehouseId, int $quantity, bool $consumeReserved): void
    {
        $inventory = $this->findAggregateInventory($productId, $variantId, $warehouseId);

        if (! $inventory) {
            return;
        }

        $remaining = $quantity;
        if ($consumeReserved && $inventory->quantity_reserved > 0) {
            $fromReserved = min($remaining, (int) $inventory->quantity_reserved);
            $inventory->quantity_reserved -= $fromReserved;
            $remaining -= $fromReserved;
        }

        $inventory->quantity_available = max(0, (int) $inventory->quantity_available - $remaining);
        $inventory->save();
    }

    private function findAggregateInventory(int $productId, ?int $variantId, int $warehouseId): ?ProductInventory
    {
        return ProductInventory::query()
            ->where('warehouse_id', $warehouseId)
            ->when(
                $variantId,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->where('product_id', $productId)->whereNull('variant_id')
            )
            ->first();
    }

    private function resolveFallbackUnitCost(int $productId, ?int $variantId): float
    {
        if ($variantId) {
            $variant = ProductVariant::with('pricing')->find($variantId);

            return (float) ($variant?->pricing?->cost_total ?? 0);
        }

        $pricing = ProductPricing::query()
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->first();

        return (float) ($pricing?->cost_total ?? 0);
    }

    private function resolveFallbackSalePrice(int $productId, ?int $variantId): float
    {
        if ($variantId) {
            $variant = ProductVariant::with('pricing')->find($variantId);

            return (float) ($variant?->pricing?->base_price ?? 0);
        }

        $pricing = ProductPricing::query()
            ->where('product_id', $productId)
            ->whereNull('variant_id')
            ->first();

        return (float) ($pricing?->base_price ?? 0);
    }
}
