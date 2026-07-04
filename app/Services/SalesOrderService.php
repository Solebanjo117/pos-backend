<?php

namespace App\Services;

use App\Helpers\TaxRateHelper;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    /**
     * Get all sales orders with filters
     */
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = SalesOrder::with(['customer', 'customerVehicle.model.make', 'customerVehicle.chassis', 'customerVehicle.engine', 'referredByCustomer', 'user', 'items.product', 'items.warehouse', 'payments', 'returns']);

        // Apply filters
        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (isset($filters['customer_id'])) {
            $query->byCustomer($filters['customer_id']);
        }

        if (isset($filters['warehouse_id'])) {
            $warehouseId = $filters['warehouse_id'];
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        if (isset($filters['payment_status'])) {
            $statuses = explode(',', $filters['payment_status']);
            $query->whereIn('payment_status', $statuses);
        }

        if (isset($filters['date_from']) && isset($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%");
                    });
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * Get single order by ID
     */
    public function getById(int $id): ?SalesOrder
    {
        return SalesOrder::with(['customer', 'customerVehicle.model.make', 'customerVehicle.chassis', 'customerVehicle.engine', 'referredByCustomer', 'user', 'items.product', 'items.warehouse', 'payments', 'returns'])
            ->findOrFail($id);
    }

    /**
     * Create new sales order
     */
    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $userId = $data['user_id'] ?? \Illuminate\Support\Facades\Auth::id();

            if (! $userId) {
                throw new \Exception('No se pudo determinar el usuario. Por favor, inicie sesión nuevamente.');
            }

            // Determine warehouse context for the order
            $warehouseId = $data['warehouse_id'] ?? null;
            if (! $warehouseId && isset($data['items']) && count($data['items']) > 0) {
                $warehouseId = $data['items'][0]['warehouse_id'] ?? null;
            }

            // Check for active pos session for this user and warehouse
            $sessionQuery = \App\Models\PosSession::where('user_id', $userId)
                ->where('status', 'open');

            if ($warehouseId) {
                $sessionQuery->where('warehouse_id', $warehouseId);
            }

            $activeSession = $sessionQuery->first();

            // Determine requested status to apply after adding items
            $requestedStatus = $data['status'] ?? 'draft';

            // Create order
            $order = SalesOrder::create([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_vehicle_id' => $data['customer_vehicle_id'] ?? null,
                'referred_by_customer_id' => $data['referred_by_customer_id'] ?? null,
                'user_id' => $userId,
                'pos_session_id' => $activeSession ? $activeSession->id : null,
                'date' => $data['date'] ?? now(),
                'status' => 'draft', // Force draft initially so items can be added
                'payment_status' => $data['payment_status'] ?? 'pending',
                'payment_method' => $data['payment_method'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'audit_logs' => $data['audit_logs'] ?? null,
            ]);

            // Add items if provided
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($order->id, $itemData);
                }
            }

            $order->refresh();

            // ── Reserve stock for draft/pending orders (only what is available) ──
            if (in_array($requestedStatus, ['draft', 'pending'])) {
                foreach ($order->items as $item) {
                    if ($item->is_custom_line) {
                        continue;
                    }

                    $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                    if ($item->variant_id) {
                        $query->where('variant_id', $item->variant_id);
                    } else {
                        $query->where('product_id', $item->product_id)->whereNull('variant_id');
                    }
                    $inventory = $query->first();

                    $available = $inventory ? max(0, (int) $inventory->quantity_available) : 0;
                    $requested = (int) $item->quantity;
                    $reserveAmount = min($requested, $available);

                    if ($requested > $available) {
                        $productName = $item->product->name ?? 'producto';
                        $order->stockWarnings[] = [
                            'type' => 'stock_shortage',
                            'product_id' => $item->product_id,
                            'variant_id' => $item->variant_id,
                            'warehouse_id' => $item->warehouse_id,
                            'product_name' => $productName,
                            'requested_quantity' => $requested,
                            'available_quantity' => $available,
                            'missing_quantity' => $requested - $available,
                            'message' => "{$productName}: solicitado {$requested}, disponible {$available}, faltante ".($requested - $available),
                        ];
                    }

                    if ($inventory && $reserveAmount > 0) {
                        $inventory->quantity_available -= $reserveAmount;
                        $inventory->quantity_reserved += $reserveAmount;
                        $inventory->save();
                    }
                }
            }

            // Handle payments from POS (multiple or single)
            if (isset($data['payments']) && is_array($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    if (isset($payment['amount']) && (float) $payment['amount'] > 0) {
                        $order->addPayment(
                            amount: (float) $payment['amount'],
                            method: $payment['method'] ?? 'cash',
                            notes: $payment['notes'] ?? 'Pago POS',
                            isCheckout: true,
                            referenceNumber: $payment['reference_number'] ?? null,
                        );
                    }
                }
            } elseif (isset($data['initial_payment']) && isset($data['payment_method'])) {
                // Legacy support for single payment
                if ((float) $data['initial_payment'] > 0) {
                    $order->addPayment(
                        amount: (float) $data['initial_payment'],
                        method: $data['payment_method'],
                        notes: 'Pago inicial POS',
                        isCheckout: true
                    );
                }
            } else {
                // Legacy support if no initial payment was passed but payment_status is 'paid'
                if (($data['payment_status'] ?? null) === 'paid') {
                    $order->addPayment(
                        amount: $order->total,
                        method: $data['payment_method'] ?? 'cash',
                        notes: 'Pago completo POS',
                        isCheckout: true
                    );
                }
            }

            // Auto-confirm if status is not draft
            if (in_array($requestedStatus, ['confirmed', 'processing', 'completed'])) {
                $order->confirm();
            } else {
                $order->update(['status' => $requestedStatus]);
            }

            // ── Convert Sales Quote if applicable ──
            if (! empty($data['source_quote_id'])) {
                $quote = \App\Models\SalesQuote::find($data['source_quote_id']);
                if ($quote) {
                    $quote->markCollected();
                    $quote->update(['converted_order_id' => $order->id]);
                }
            }

            $order->refresh();

            return $order;
        });
    }

    /**
     * Update existing sales order
     */
    public function update(int $id, array $data): SalesOrder
    {
        $order = $this->getById($id);

        if (! $order->canBeEdited()) {
            throw new \Exception('No se puede editar una orden en estado '.$order->status);
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
                'customer_vehicle_id' => array_key_exists('customer_vehicle_id', $data) ? $data['customer_vehicle_id'] : $order->customer_vehicle_id,
                'referred_by_customer_id' => array_key_exists('referred_by_customer_id', $data) ? $data['referred_by_customer_id'] : $order->referred_by_customer_id,
                'date' => $data['date'] ?? $order->date,
                'discount_amount' => $data['discount_amount'] ?? $order->discount_amount,
                'shipping_cost' => $data['shipping_cost'] ?? $order->shipping_cost,
                'payment_method' => $data['payment_method'] ?? $order->payment_method,
                'notes' => $data['notes'] ?? $order->notes,
            ]);

            // Update items if provided
            if (isset($data['items'])) {
                // Remove all existing items
                $order->items()->delete();

                // Add new items
                foreach ($data['items'] as $itemData) {
                    $this->addItem($order->id, $itemData);
                }
            }

            $order->refresh();

            return $order;
        });
    }

    /**
     * Delete (soft delete) sales order
     */
    public function delete(int $id): bool
    {
        $order = $this->getById($id);

        if (! in_array($order->status, ['draft', 'cancelled'])) {
            throw new \Exception('Solo se pueden eliminar órdenes en estado borrador o canceladas');
        }

        return $order->delete();
    }

    /**
     * Add item to order
     */
    public function addItem(int $orderId, array $itemData): SalesOrderItem
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($orderId, $itemData) {
            $order = $this->getById($orderId);

            $isConfirmed = in_array($order->status, ['confirmed', 'processing', 'completed']);

            if (! $order->canBeEdited() && ! $isConfirmed) {
                throw new \Exception('No se pueden agregar items a una orden en estado '.$order->status);
            }

            $isCustomLine = (bool) ($itemData['is_custom_line'] ?? false);
            $customDescription = $isCustomLine ? trim((string) ($itemData['custom_description'] ?? '')) : null;

            if ($isCustomLine && $customDescription === '') {
                throw new \Exception('La descripcion es obligatoria para articulos miscelaneos.');
            }

            // Validate product is vendible (active status, not archived)
            if (! $isCustomLine && ! empty($itemData['product_id'])) {
                $product = Product::find($itemData['product_id']);
                if ($product && ! $product->isVendible()) {
                    throw new \Exception("El producto \"{$product->name}\" no está disponible para la venta (Estado: {$product->status}).");
                }
            }

            // Resolve unit cost from preferred supplier when this is a catalog line
            $unitCost = 0;
            $variantId = $isCustomLine ? null : ($itemData['variant_id'] ?? null);
            if (! $isCustomLine && $variantId) {
                $supplierPivot = \Illuminate\Support\Facades\DB::table('product_suppliers')
                    ->where('variant_id', $variantId)
                    ->orderByDesc('is_preferred')
                    ->first();
                $unitCost = $supplierPivot->supplier_cost ?? 0;
            }

            $item = SalesOrderItem::create([
                'sales_order_id' => $orderId,
                'product_id' => $isCustomLine ? null : ($itemData['product_id'] ?? null),
                'variant_id' => $variantId,
                'warehouse_id' => $itemData['warehouse_id'],
                'seller_id' => $itemData['seller_id'] ?? null,
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'unit_cost' => $unitCost,
                'discount_percent' => $itemData['discount_percent'] ?? 0,
                'discount_amount' => $itemData['discount_amount'] ?? 0,
                'tax_percent' => TaxRateHelper::resolve($itemData['tax_percent'] ?? null),
                'is_custom_line' => $isCustomLine,
                'custom_description' => $customDescription,
                'added_after_sale' => $isConfirmed,
            ]);

            if ($isConfirmed) {
                if (! $isCustomLine) {
                    $query = \App\Models\ProductInventory::where('warehouse_id', $item->warehouse_id);
                    if ($item->variant_id) {
                        $query->where('variant_id', $item->variant_id);
                    } else {
                        $query->where('product_id', $item->product_id);
                    }
                    $inventory = $query->first();

                    $available = $inventory ? max(0, (int) $inventory->quantity_available) : 0;
                    $requested = (int) $item->quantity;
                    $quantityToDeduct = min($requested, $available);

                    if ($quantityToDeduct > 0) {
                        $fifoResult = app(InventoryFifoService::class)->consumeStock([
                            'product_id' => $item->product_id,
                            'variant_id' => $item->variant_id,
                            'warehouse_id' => $item->warehouse_id,
                            'quantity' => $quantityToDeduct,
                            'reference_type' => 'post_sale_addition',
                            'reference_id' => $item->id,
                            'sales_order_item_id' => $item->id,
                            'reason' => 'post_sale_addition',
                            'notes' => "Agregado post-venta item #{$item->id} a Orden {$order->order_number}",
                            'user_id' => \Illuminate\Support\Facades\Auth::id(),
                        ]);

                        if (($fifoResult['weighted_unit_cost'] ?? 0) > 0) {
                            $item->forceFill(['unit_cost' => $fifoResult['weighted_unit_cost']])->save();
                        }
                    }
                }

                // Re-evaluate payment status immediately
                $order->refresh();
                $paid = $order->amount_paid;
                if ($paid < $order->total && $paid > 0) {
                    $order->payment_status = 'partial';
                } elseif ($paid <= 0) {
                    $order->payment_status = 'pending';
                }
                $order->save();

                // Adding lines to a confirmed order increases customer's debt.
                $diffAmount = $item->total;
                if ($order->customer_id) {
                    $customer = \App\Models\Customer::find($order->customer_id);
                    if ($customer) {
                        $balanceBefore = (float) $customer->current_balance;
                        $customer->current_balance = $balanceBefore + $diffAmount;
                        $customer->save();

                        \App\Models\CustomerTransaction::create([
                            'customer_id' => $customer->id,
                            'type' => 'sale',
                            'reference_type' => 'sales_order',
                            'reference_id' => $order->id,
                            'amount' => $diffAmount,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $customer->current_balance,
                            'description' => "Cargo por producto agregado a venta {$order->order_number}",
                            'transaction_date' => now(),
                            'created_by' => \Illuminate\Support\Facades\Auth::id(),
                        ]);
                    }
                }
            }

            return $item;
        });
    }

    /**
     * Update item
     */
    public function updateItem(int $itemId, array $data): SalesOrderItem
    {
        return DB::transaction(function () use ($itemId, $data) {
            $item = SalesOrderItem::findOrFail($itemId);
            $order = $item->salesOrder;

            $isPostSaleOrder = in_array($order->status, ['confirmed', 'processing', 'completed'], true);
            if (! $order->canBeEdited() && (! $isPostSaleOrder || ! $item->added_after_sale)) {
                throw new \Exception('No se pueden editar items de una orden en estado '.$order->status);
            }

            $oldTotal = (float) $item->total;

            $item->update([
                'quantity' => $data['quantity'] ?? $item->quantity,
                'unit_price' => $data['unit_price'] ?? $item->unit_price,
                'discount_percent' => $data['discount_percent'] ?? $item->discount_percent,
                'discount_amount' => $data['discount_amount'] ?? $item->discount_amount,
                'tax_percent' => $data['tax_percent'] ?? $item->tax_percent,
                'seller_id' => array_key_exists('seller_id', $data) ? $data['seller_id'] : $item->seller_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $item->notes,
            ]);

            $freshItem = $item->fresh();

            if ($isPostSaleOrder && $freshItem->added_after_sale) {
                $this->applyPostSaleItemAdjustment($order->fresh(), (float) $freshItem->total - $oldTotal);
            }

            return $freshItem;
        });
    }

    private function applyPostSaleItemAdjustment(SalesOrder $order, float $differenceAmount): void
    {
        $paid = $order->amount_paid;
        if ($paid < $order->total && $paid > 0) {
            $order->payment_status = 'partial';
        } elseif ($paid <= 0) {
            $order->payment_status = 'pending';
        } else {
            $order->payment_status = 'paid';
        }
        $order->save();

        if (abs($differenceAmount) < 0.01 || ! $order->customer_id) {
            return;
        }

        $customer = \App\Models\Customer::find($order->customer_id);
        if (! $customer) {
            return;
        }

        $balanceBefore = (float) $customer->current_balance;
        $customer->current_balance = $balanceBefore + $differenceAmount;
        $customer->save();

        \App\Models\CustomerTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'sale',
            'reference_type' => 'sales_order',
            'reference_id' => $order->id,
            'amount' => $differenceAmount,
            'balance_before' => $balanceBefore,
            'balance_after' => $customer->current_balance,
            'description' => "Ajuste por edicion de producto agregado a venta {$order->order_number}",
            'transaction_date' => now(),
            'created_by' => \Illuminate\Support\Facades\Auth::id(),
        ]);
    }

    /**
     * Remove item from order.
     *
     * Draft/pending: any item can be removed (existing behavior).
     * Confirmed/processing/completed: only lines with added_after_sale = true may be
     * removed. The removal reverses inventory and adjusts the customer balance via a
     * negative delta through applyPostSaleItemAdjustment().
     */
    public function removeItem(int $itemId): bool
    {
        return DB::transaction(function () use ($itemId) {
            $item = SalesOrderItem::findOrFail($itemId);
            $order = $item->salesOrder;

            if ($order->canBeEdited()) {
                return $item->delete();
            }

            if (! $this->isPostSaleMutableOrder($order)) {
                throw new \Exception('No se pueden quitar items de una orden en estado '.$order->status);
            }

            if (! $item->added_after_sale) {
                throw new \Exception('Solo se pueden eliminar artículos agregados después de confirmar la orden.');
            }

            $removedTotal = (float) $item->total;

            // Delete line — SalesOrderItem::deleted observer recalculates order totals
            $item->delete();

            // Restore inventory for catalog lines (pass order so the lookup is order-scoped)
            $this->restoreRemovedPostSaleItemInventory($item, $order);

            // Apply negative delta to payment_status and customer balance
            $order->refresh();
            $this->applyPostSaleItemAdjustment($order, -$removedTotal);

            return true;
        });
    }

    /**
     * Whether the order status allows post-sale item mutations.
     */
    private function isPostSaleMutableOrder(SalesOrder $order): bool
    {
        return in_array($order->status, ['confirmed', 'processing', 'completed'], true);
    }

    /**
     * Restore the inventory that was deducted when a post-sale item was added.
     *
     * The outbound movement uses reference_type = 'post_sale_addition' and
     * reference_id = $item->id, so the lookup is a unique 1:1 match even when
     * two post-sale lines of the same product exist on the same order.
     *
     * The inbound restore movement stores reference_id = $order->id so that
     * SalesOrder::cancel() can locate it without depending on hard-deleted item rows.
     *
     * Never restores for custom lines.
     */
    private function restoreRemovedPostSaleItemInventory(SalesOrderItem $item, SalesOrder $order): void
    {
        if ($item->is_custom_line) {
            return;
        }

        // Unique 1:1 lookup: each post-sale outbound is linked to its specific item
        $outboundMovement = \App\Models\InventoryMovement::query()
            ->where('reference_type', 'post_sale_addition')
            ->where('reference_id', $item->id)
            ->where('movement_type', 'out')
            ->first();

        if (! $outboundMovement) {
            return;
        }

        $quantityToRestore = (int) $outboundMovement->quantity;

        if ($quantityToRestore <= 0) {
            return;
        }

        app(InventoryFifoService::class)->restoreFromOutboundMovement($outboundMovement, [
            'reference_type' => 'sales_order_item_removed',
            'reference_id' => $order->id,
            'reason' => 'post_sale_item_removed',
            'notes' => "RestauraciÃ³n post-venta: artÃ­culo #{$item->id} eliminado de orden {$order->order_number}",
            'user_id' => \Illuminate\Support\Facades\Auth::id(),
        ]);

    }

    /**
     * Confirm order and deduct inventory
     */
    public function confirmOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);

        $order->confirm();

        $warnings = $order->stockWarnings;

        $fresh = $order->fresh();
        $fresh->stockWarnings = $warnings;

        return $fresh;
    }

    /**
     * Complete order
     */
    public function completeOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);
        $order->complete();

        $fresh = $order->fresh();
        $fresh->stockWarnings = [];

        return $fresh;
    }

    /**
     * Cancel order
     */
    public function cancelOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);
        $order->cancel();

        $fresh = $order->fresh();
        $fresh->stockWarnings = [];

        return $fresh;
    }

    /**
     * Check stock availability for all items
     */
    public function checkStockAvailability(int $id): array
    {
        $order = $this->getById($id);
        $warnings = [];

        foreach ($order->items as $item) {
            if ($item->is_custom_line) {
                continue;
            }

            $query = ProductInventory::where('warehouse_id', $item->warehouse_id);

            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->where('product_id', $item->product_id);
            }

            $inventory = $query->first();
            $available = $inventory ? max(0, (int) $inventory->quantity_available) : 0;

            if ($available < $item->quantity) {
                $warnings[] = [
                    'type' => 'stock_shortage',
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'warehouse_id' => $item->warehouse_id,
                    'product_name' => $item->product->name ?? $item->custom_description,
                    'requested_quantity' => $item->quantity,
                    'available_quantity' => $available,
                    'missing_quantity' => $item->quantity - $available,
                    'message' => ($item->product->name ?? $item->custom_description).": solicitado {$item->quantity}, disponible {$available}",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Get order statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $query = SalesOrder::query();

        if (isset($filters['date_from']) && isset($filters['date_to'])) {
            $query->byDateRange($filters['date_from'], $filters['date_to']);
        }

        if (isset($filters['warehouse_id'])) {
            $warehouseId = $filters['warehouse_id'];
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        return [
            'total_orders' => (clone $query)->count(),
            'total_sales' => (clone $query)->sum('total'),
            'pending_orders' => (clone $query)->byStatus('pending')->count(),
            'confirmed_orders' => (clone $query)->byStatus('confirmed')->count(),
            'completed_orders' => (clone $query)->byStatus('completed')->count(),
            'cancelled_orders' => (clone $query)->byStatus('cancelled')->count(),
            'average_order_value' => (clone $query)->avg('total') ?? 0,
        ];
    }
}
