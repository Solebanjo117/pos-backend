<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\ProductBatch;
use App\Models\ProductInventory;
use App\Models\WarehouseBatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryMovementService
{
    /**
     * Get paginated list of movements with filters
     */
    public function getMovements(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = InventoryMovement::with(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'productBatch', 'supplier', 'lotAllocations.lot']);

        // Apply filters
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['variant_id'])) {
            $query->where('variant_id', $filters['variant_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('warehouse_id', $filters['warehouse_id'])
                    ->orWhere('destination_warehouse_id', $filters['warehouse_id']);
            });
        }

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($searchQuery) use ($filters): void {
                $searchQuery->whereHas('product', function ($q) use ($filters) {
                    $q->where('name', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('sku_base', 'ilike', '%'.$filters['search'].'%');
                })->orWhereHas('variant', function ($q) use ($filters) {
                    $q->where('sku', 'ilike', '%'.$filters['search'].'%');
                });
            });
        }

        // Order by most recent first
        $orderBy = $filters['order_by'] ?? 'created_at';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get movement by ID
     */
    public function getMovementById(int $id): InventoryMovement
    {
        return InventoryMovement::with(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'productBatch', 'supplier', 'lotAllocations.lot', 'sourceLots'])
            ->findOrFail($id);
    }

    /**
     * Record inbound movement (arrival of stock)
     */
    public function recordInbound(array $data): InventoryMovement
    {
        if (empty($data['skip_fifo'])) {
            return app(InventoryFifoService::class)->receiveStock([
                ...$data,
                'unit_cost_base' => (float) ($data['cost'] ?? $data['unit_cost_base'] ?? 0),
                'source_type' => $data['reference_type'] ?? 'manual_inbound',
            ]);
        }

        return DB::transaction(function () use ($data) {
            $batchId = null;
            $inventoryBefore = $this->inventoryQuantity($data['product_id'], $data['warehouse_id'], $data['variant_id'] ?? null);

            // Handle Batch Logic
            if (! empty($data['batch_number'])) {
                $batch = ProductBatch::firstOrCreate(
                    [
                        'product_id' => $data['product_id'],
                        'batch_number' => $data['batch_number'],
                    ],
                    [
                        'expiry_date' => $data['expiry_date'] ?? null,
                        'manufacturing_date' => $data['manufacturing_date'] ?? null,
                        'cost' => $data['cost'] ?? 0,
                    ]
                );
                $batchId = $batch->id;

                // Update Warehouse Batch Inventory
                $this->updateBatchInventory($data['warehouse_id'], $batchId, $data['quantity'], 'add');
            }

            // Create movement record
            $movement = InventoryMovement::create([
                'movement_type' => 'in',
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'product_batch_id' => $batchId,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $data['quantity'],
                'quantity_before' => $inventoryBefore,
                'quantity_after' => $inventoryBefore + (int) $data['quantity'],
                'movement_group_uuid' => $data['movement_group_uuid'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
            ]);

            // Update product inventory (Aggregate)
            $this->updateInventoryQuantity(
                $data['product_id'],
                $data['warehouse_id'],
                $data['quantity'],
                'add',
                $data['variant_id'] ?? null
            );

            return $movement->load(['product', 'variant', 'warehouse', 'productBatch', 'supplier']);
        });
    }

    /**
     * Record outbound movement (removal of stock)
     */
    public function recordOutbound(array $data): InventoryMovement
    {
        if (empty($data['skip_fifo'])) {
            $result = app(InventoryFifoService::class)->consumeStock([
                ...$data,
                'strict' => ! \App\Models\Setting::get('allow_negative_inventory', false),
            ]);

            if ($result['movement']) {
                return $result['movement'];
            }

            throw new \Exception('Stock insuficiente en el almacÃ©n seleccionado.');
        }

        return DB::transaction(function () use ($data) {
            // Check if sufficient stock is available (Aggregate or Variant)
            $query = ProductInventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['warehouse_id']);

            if (! empty($data['variant_id'])) {
                $query->where('variant_id', $data['variant_id']);
            } else {
                $query->whereNull('variant_id');
            }

            $inventory = $query->first();
            $inventoryBefore = $inventory
                ? (int) $inventory->quantity_available + (int) $inventory->quantity_reserved
                : 0;

            $allowNegative = \App\Models\Setting::get('allow_negative_inventory', false);
            if (! $allowNegative && (! $inventory || $inventory->quantity_available < $data['quantity'])) {
                throw new \Exception('Stock insuficiente en el almacén seleccionado.');
            }

            // Handle Batch Logic (if batch is specified)
            if (! empty($data['product_batch_id'])) {
                $batchInventory = WarehouseBatch::where('warehouse_id', $data['warehouse_id'])
                    ->where('product_batch_id', $data['product_batch_id'])
                    ->first();

                if (! $batchInventory || $batchInventory->quantity < $data['quantity']) {
                    throw new \Exception('Stock insuficiente en el lote seleccionado.');
                }

                // Deduct from batch
                $this->updateBatchInventory($data['warehouse_id'], $data['product_batch_id'], $data['quantity'], 'subtract');
            }

            // Create movement record
            $movement = InventoryMovement::create([
                'movement_type' => 'out',
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'product_batch_id' => $data['product_batch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $data['quantity'],
                'quantity_before' => $inventoryBefore,
                'quantity_after' => $inventoryBefore - (int) $data['quantity'],
                'movement_group_uuid' => $data['movement_group_uuid'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
            ]);

            // Update product inventory (Aggregate)
            $this->updateInventoryQuantity(
                $data['product_id'],
                $data['warehouse_id'],
                $data['quantity'],
                'subtract',
                $data['variant_id'] ?? null
            );

            return $movement->load(['product', 'variant', 'warehouse', 'productBatch']);
        });
    }

    /**
     * Record transfer between warehouses
     */
    public function recordTransfer(array $data): InventoryMovement
    {
        $data['movement_group_uuid'] ??= (string) Str::uuid();

        if (empty($data['skip_fifo'])) {
            if ($data['warehouse_id'] == $data['destination_warehouse_id']) {
                throw new \Exception('El almacÃ©n de origen y destino deben ser diferentes.');
            }

            $result = app(InventoryFifoService::class)->consumeStock([
                ...$data,
                'reference_type' => 'transfer',
                'strict' => ! \App\Models\Setting::get('allow_negative_inventory', false),
            ]);

            if (! $result['movement']) {
                throw new \Exception('Stock insuficiente en el almacÃ©n de origen.');
            }

            app(InventoryFifoService::class)->createTransferInboundLots(
                $result['movement'],
                (int) $data['destination_warehouse_id'],
                (int) ($data['user_id'] ?? auth()->id() ?? 0)
            );

            return $result['movement']->load(['product', 'variant', 'warehouse', 'destinationWarehouse', 'productBatch']);
        }

        return DB::transaction(function () use ($data) {
            // Validate different warehouses
            if ($data['warehouse_id'] == $data['destination_warehouse_id']) {
                throw new \Exception('El almacén de origen y destino deben ser diferentes.');
            }

            // Check if sufficient stock is available in origin warehouse (Aggregate or Variant)
            $query = ProductInventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['warehouse_id']);

            if (! empty($data['variant_id'])) {
                $query->where('variant_id', $data['variant_id']);
            } else {
                $query->whereNull('variant_id');
            }

            $inventory = $query->first();
            $inventoryBefore = $inventory
                ? (int) $inventory->quantity_available + (int) $inventory->quantity_reserved
                : 0;

            $allowNegative = \App\Models\Setting::get('allow_negative_inventory', false);
            if (! $allowNegative && (! $inventory || $inventory->quantity_available < $data['quantity'])) {
                throw new \Exception('Stock insuficiente en el almacén de origen.');
            }

            // Handle Batch Logic (if batch is specified)
            if (! empty($data['product_batch_id'])) {
                $batchInventory = WarehouseBatch::where('warehouse_id', $data['warehouse_id'])
                    ->where('product_batch_id', $data['product_batch_id'])
                    ->first();

                if (! $batchInventory || $batchInventory->quantity < $data['quantity']) {
                    throw new \Exception('Stock insuficiente en el lote seleccionado.');
                }

                // Deduct from origin batch
                $this->updateBatchInventory($data['warehouse_id'], $data['product_batch_id'], $data['quantity'], 'subtract');

                // Add to destination batch
                $this->updateBatchInventory($data['destination_warehouse_id'], $data['product_batch_id'], $data['quantity'], 'add');
            }

            // Create transfer movement record
            $movement = InventoryMovement::create([
                'movement_type' => 'transfer',
                'product_id' => $data['product_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'product_batch_id' => $data['product_batch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'quantity' => $data['quantity'],
                'quantity_before' => $inventoryBefore,
                'quantity_after' => $inventoryBefore - (int) $data['quantity'],
                'movement_group_uuid' => $data['movement_group_uuid'],
                'reference_type' => 'transfer',
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
            ]);

            // Update origin warehouse (subtract)
            $this->updateInventoryQuantity(
                $data['product_id'],
                $data['warehouse_id'],
                $data['quantity'],
                'subtract',
                $data['variant_id'] ?? null
            );

            // Update destination warehouse (add)
            $this->updateInventoryQuantity(
                $data['product_id'],
                $data['destination_warehouse_id'],
                $data['quantity'],
                'add',
                $data['variant_id'] ?? null
            );

            return $movement->load(['product', 'variant', 'warehouse', 'destinationWarehouse', 'productBatch']);
        });
    }

    /**
     * Get movements by product
     */
    public function getMovementsByProduct(int $productId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $filters['product_id'] = $productId;

        return $this->getMovements($filters, $perPage);
    }

    /**
     * Get movements by warehouse
     */
    public function getMovementsByWarehouse(int $warehouseId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $filters['warehouse_id'] = $warehouseId;

        return $this->getMovements($filters, $perPage);
    }

    /**
     * Update inventory quantity (helper method)
     */
    protected function updateInventoryQuantity(int $productId, int $warehouseId, int $quantity, string $operation, ?int $variantId = null): void
    {
        $criteria = [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ];

        if ($variantId) {
            $criteria['variant_id'] = $variantId;
        } else {
            // Explicitly look for null variant_id to avoid mixing up with variant records
            // This assumes database has unique constraint on (product_id, warehouse_id, variant_id)
            // If variant_id is nullable (which it is), we need to ensure we don't accidentally match a variant record if we pass null
            // Eloquent firstOrCreate treats null as "WHERE variant_id IS NULL" which is correct
            $criteria['variant_id'] = null;
        }

        $inventory = ProductInventory::firstOrCreate(
            $criteria,
            [
                'quantity_available' => 0,
                'quantity_reserved' => 0,
                'reorder_point' => 10,
                'reorder_quantity' => 50,
            ]
        );

        if ($operation === 'add') {
            $inventory->increment('quantity_available', $quantity);
            $inventory->update(['last_restocked_at' => now()]);
        } elseif ($operation === 'subtract') {
            $inventory->decrement('quantity_available', $quantity);
        }
    }

    protected function inventoryQuantity(int $productId, int $warehouseId, ?int $variantId = null): int
    {
        $inventory = ProductInventory::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, fn ($query) => $query->where('variant_id', $variantId), fn ($query) => $query->whereNull('variant_id'))
            ->lockForUpdate()
            ->first();

        return $inventory
            ? (int) $inventory->quantity_available + (int) $inventory->quantity_reserved
            : 0;
    }

    /**
     * Update warehouse batch inventory (helper method)
     */
    protected function updateBatchInventory(int $warehouseId, int $batchId, float $quantity, string $operation): void
    {
        $batchInventory = WarehouseBatch::firstOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_batch_id' => $batchId,
            ],
            [
                'quantity' => 0,
                'quantity_reserved' => 0,
            ]
        );

        if ($operation === 'add') {
            $batchInventory->increment('quantity', $quantity);
        } elseif ($operation === 'subtract') {
            $batchInventory->decrement('quantity', $quantity);
        }
    }

    /**
     * Record bulk inbound movement
     */
    public function recordBulkInbound(array $items): array
    {
        $consolidatedItems = $this->consolidateItems($items);
        $groupUuid = (string) Str::uuid();

        return DB::transaction(function () use ($consolidatedItems, $groupUuid) {
            $movements = [];
            foreach ($consolidatedItems as $item) {
                $movements[] = $this->recordInbound([...$item, 'movement_group_uuid' => $groupUuid]);
            }

            return $movements;
        });
    }

    /**
     * Record bulk outbound movement
     */
    public function recordBulkOutbound(array $items): array
    {
        $consolidatedItems = $this->consolidateItems($items);
        $groupUuid = (string) Str::uuid();

        return DB::transaction(function () use ($consolidatedItems, $groupUuid) {
            $movements = [];
            foreach ($consolidatedItems as $item) {
                $movements[] = $this->recordOutbound([...$item, 'movement_group_uuid' => $groupUuid]);
            }

            return $movements;
        });
    }

    /**
     * Consolidate items to prevent duplicates
     */
    protected function consolidateItems(array $items): array
    {
        $consolidated = [];
        foreach ($items as $item) {
            $key = $item['product_id'].'-'.
                   ($item['variant_id'] ?? 'null').'-'.
                   ($item['warehouse_id'] ?? 'null').'-'.
                   ($item['product_batch_id'] ?? ($item['batch_number'] ?? 'null'));

            if (isset($consolidated[$key])) {
                $consolidated[$key]['quantity'] += $item['quantity'];
            } else {
                $consolidated[$key] = $item;
            }
        }

        return array_values($consolidated);
    }

    /**
     * Get related movements (batch/group)
     */
    public function getRelatedMovements(int $movementId): array
    {
        $movement = InventoryMovement::findOrFail($movementId);

        $query = InventoryMovement::with(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'productBatch', 'lotAllocations.lot', 'sourceLots'])
            ->where('id', '!=', $movementId);

        if ($movement->movement_group_uuid) {
            $grouped = (clone $query)->where('movement_group_uuid', $movement->movement_group_uuid)
                ->orderBy('created_at')
                ->get()
                ->all();
            if ($grouped !== []) {
                return $grouped;
            }
        }

        if ($movement->reference_type && $movement->reference_id) {
            return $query->where('reference_id', $movement->reference_id)
                ->where('reference_type', $movement->reference_type)
                ->orderBy('created_at')
                ->get()
                ->all();
        }

        return [];
    }
}
