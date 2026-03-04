<?php

namespace App\Services;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\ProductInventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

class InventoryTransferService
{
    /**
     * Create a new transfer in draft status.
     */
    public function create(array $data, int $userId): InventoryTransfer
    {
        return DB::transaction(function () use ($data, $userId) {
            $transfer = InventoryTransfer::create([
                'reference' => InventoryTransfer::generateReference(),
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
                'status' => 'draft',
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            return $transfer->load(['items.product', 'items.variant', 'fromWarehouse', 'toWarehouse', 'creator']);
        });
    }

    /**
     * Dispatch a transfer: deduct stock from origin warehouse, set status to in_transit.
     */
    public function dispatch(InventoryTransfer $transfer): InventoryTransfer
    {
        if ($transfer->status !== 'draft') {
            throw new \Exception('Solo se pueden despachar transferencias en estado borrador.');
        }

        return DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                // Validate stock availability
                $inventory = ProductInventory::where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->when($item->variant_id, fn($q) => $q->where('variant_id', $item->variant_id))
                    ->first();

                $available = $inventory ? $inventory->quantity : 0;
                if ($available < $item->quantity) {
                    $productName = $item->product->name ?? "ID:{$item->product_id}";
                    throw new \Exception("Stock insuficiente para {$productName}. Disponible: {$available}, Solicitado: {$item->quantity}");
                }

                // Deduct from origin
                $inventory->decrement('quantity', $item->quantity);

                // Record outbound movement
                InventoryMovement::create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type' => 'out',
                    'quantity' => $item->quantity,
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id,
                    'reason' => "Transferencia salida → {$transfer->toWarehouse->name} ({$transfer->reference})",
                ]);
            }

            $transfer->update([
                'status' => 'in_transit',
                'dispatched_at' => now(),
            ]);

            return $transfer->fresh()->load(['items.product', 'items.variant', 'fromWarehouse', 'toWarehouse']);
        });
    }

    /**
     * Receive a transfer: add stock to destination warehouse, set status to completed.
     */
    public function receive(InventoryTransfer $transfer, int $receivedByUserId): InventoryTransfer
    {
        if ($transfer->status !== 'in_transit') {
            throw new \Exception('Solo se pueden recibir transferencias en tránsito.');
        }

        return DB::transaction(function () use ($transfer, $receivedByUserId) {
            foreach ($transfer->items as $item) {
                // Add to destination
                $inventory = ProductInventory::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'variant_id' => $item->variant_id,
                    ],
                    ['quantity' => 0, 'min_stock' => 0, 'max_stock' => 0]
                );

                $inventory->increment('quantity', $item->quantity);

                // Record inbound movement
                InventoryMovement::create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'type' => 'in',
                    'quantity' => $item->quantity,
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id,
                    'reason' => "Transferencia entrada ← {$transfer->fromWarehouse->name} ({$transfer->reference})",
                ]);
            }

            $transfer->update([
                'status' => 'completed',
                'received_by' => $receivedByUserId,
                'received_at' => now(),
            ]);

            return $transfer->fresh()->load(['items.product', 'items.variant', 'fromWarehouse', 'toWarehouse']);
        });
    }

    /**
     * Cancel a draft transfer.
     */
    public function cancel(InventoryTransfer $transfer): InventoryTransfer
    {
        if (!in_array($transfer->status, ['draft'])) {
            throw new \Exception('Solo se pueden cancelar transferencias en estado borrador.');
        }

        $transfer->update(['status' => 'cancelled']);
        return $transfer->fresh();
    }
}
