<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\ProductInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class SalesOrderService
{
    /**
     * Get all sales orders with filters
     */
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = SalesOrder::with(['customer', 'user', 'items.product', 'items.warehouse', 'payments', 'returns']);

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
        return SalesOrder::with(['customer', 'user', 'items.product', 'items.warehouse', 'payments', 'returns'])
            ->findOrFail($id);
    }

    /**
     * Create new sales order
     */
    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $userId = $data['user_id'] ?? \Illuminate\Support\Facades\Auth::id();
            
            if (!$userId) {
                throw new \Exception('No se pudo determinar el usuario. Por favor, inicie sesión nuevamente.');
            }
            
            // Determine warehouse context for the order
            $warehouseId = $data['warehouse_id'] ?? null;
            if (!$warehouseId && isset($data['items']) && count($data['items']) > 0) {
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

            // ── Reserve stock for draft/pending orders ──
            if (in_array($requestedStatus, ['draft', 'pending'])) {
                // R28: Validate stock availability before reserving
                foreach ($order->items as $item) {
                    $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                    if ($item->variant_id) {
                        $query->where('variant_id', $item->variant_id);
                    } else {
                        $query->where('product_id', $item->product_id)->whereNull('variant_id');
                    }
                    $inventory = $query->first();

                    // R28: Check stock availability
                    $allowNegative = \App\Models\Setting::get('allow_negative_inventory', false);
                    if (!$allowNegative && (!$inventory || $inventory->quantity_available < $item->quantity)) {
                        $productName = $item->product->name ?? 'producto';
                        $available = $inventory ? $inventory->quantity_available : 0;
                        throw new \Exception("Stock insuficiente para {$productName}. Disponible: {$available}, Solicitado: {$item->quantity}");
                    }
                }

                // If validation passed, reserve stock
                foreach ($order->items as $item) {
                    $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                    if ($item->variant_id) {
                        $query->where('variant_id', $item->variant_id);
                    } else {
                        $query->where('product_id', $item->product_id)->whereNull('variant_id');
                    }
                    $inventory = $query->first();
                    if ($inventory) {
                        $inventory->quantity_available -= $item->quantity;
                        $inventory->quantity_reserved  += $item->quantity;
                        $inventory->save();
                    }
                }
            }

            // Handle payments from POS (multiple or single)
            if (isset($data['payments']) && is_array($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    if (isset($payment['amount']) && (float)$payment['amount'] > 0) {
                        $order->addPayment(
                            amount: (float)$payment['amount'],
                            method: $payment['method'] ?? 'cash',
                            notes: $payment['notes'] ?? 'Pago POS',
                            isCheckout: true
                        );
                    }
                }
            } elseif (isset($data['initial_payment']) && isset($data['payment_method'])) {
                // Legacy support for single payment
                if ((float)$data['initial_payment'] > 0) {
                    $order->addPayment(
                        amount: (float)$data['initial_payment'],
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
            if (!empty($data['source_quote_id'])) {
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

        if (!$order->canBeEdited()) {
            throw new \Exception('No se puede editar una orden en estado ' . $order->status);
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'customer_id' => $data['customer_id'] ?? $order->customer_id,
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

        if (!in_array($order->status, ['draft', 'cancelled'])) {
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

            if (!$order->canBeEdited() && !$isConfirmed) {
                throw new \Exception('No se pueden agregar items a una orden en estado ' . $order->status);
            }

        // Resolve unit cost from preferred supplier
        $unitCost = 0;
        $variantId = $itemData['variant_id'] ?? null;
        if ($variantId) {
            $supplierPivot = \Illuminate\Support\Facades\DB::table('product_suppliers')
                ->where('variant_id', $variantId)
                ->orderByDesc('is_preferred')
                ->first();
            $unitCost = $supplierPivot->supplier_cost ?? 0;
        }

        $item = SalesOrderItem::create([
            'sales_order_id' => $orderId,
            'product_id' => $itemData['product_id'],
            'variant_id' => $variantId,
            'warehouse_id' => $itemData['warehouse_id'],
            'seller_id' => $itemData['seller_id'] ?? null,
            'quantity' => $itemData['quantity'],
            'unit_price' => $itemData['unit_price'],
            'unit_cost' => $unitCost,
            'discount_percent' => $itemData['discount_percent'] ?? 0,
            'discount_amount' => $itemData['discount_amount'] ?? 0,
            'tax_percent' => $itemData['tax_percent'] ?? 0,
        ]);

        if ($isConfirmed) {
            $allowNegative = \App\Models\Setting::get('allow_negative_inventory', false);
            $query = \App\Models\ProductInventory::where('warehouse_id', $item->warehouse_id);
            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->where('product_id', $item->product_id);
            }
            $inventory = $query->first();

            if (!$allowNegative && (!$inventory || $inventory->quantity_available < $item->quantity)) {
                throw new \Exception("Stock insuficiente para agregar " . ($item->product->name ?? 'producto') . " a esta orden.");
            }

            // Create inventory movement
            \App\Models\InventoryMovement::create([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $item->warehouse_id,
                'movement_type' => 'out',
                'quantity' => $item->quantity,
                'reference_type' => 'sale',
                'reference_id' => $order->id,
                'notes' => "Agregado post-venta a Orden {$order->order_number}",
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'date' => now(),
            ]);

            if ($inventory) {
                $inventory->quantity_available -= $item->quantity;
                $inventory->save();
            }
            
            // Re-evaluate payment status immediately
            // Subtotals are updated via SalesOrderItem's saved event. We need to wait for that or just re-evaluate manually.
            // When we refresh the order, the total will be updated.
            $order->refresh();
            $paid = $order->amount_paid;
            if ($paid < $order->total && $paid > 0) {
                $order->payment_status = 'partial';
            } elseif ($paid <= 0) {
                $order->payment_status = 'pending';
            }
            $order->save();
            
            // Adjust customer balance if this is a credit-like system where the balance was already locked
            // Actually, adding items to a confirmed order means the order is worth more, 
            // so if there's a customer, we should charge them the difference!
            $diffAmount = $item->total; // total for this item
            if ($order->customer_id) {
                $customer = \App\Models\Customer::find($order->customer_id);
                if ($customer) {
                    $balanceBefore = (float) $customer->current_balance;
                    $customer->current_balance = $balanceBefore + $diffAmount;
                    $customer->save();

                    \App\Models\CustomerTransaction::create([
                        'customer_id'      => $customer->id,
                        'type'             => 'sale',
                        'reference_type'   => 'sales_order',
                        'reference_id'     => $order->id,
                        'amount'           => $diffAmount,
                        'balance_before'   => $balanceBefore,
                        'balance_after'    => $customer->current_balance,
                        'description'      => "Cargo por producto agregado a venta {$order->order_number}",
                        'transaction_date' => now(),
                        'created_by'       => \Illuminate\Support\Facades\Auth::id(),
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
        $item = SalesOrderItem::findOrFail($itemId);
        $order = $item->salesOrder;

        if (!$order->canBeEdited()) {
            throw new \Exception('No se pueden editar items de una orden en estado ' . $order->status);
        }

        $item->update([
            'quantity' => $data['quantity'] ?? $item->quantity,
            'unit_price' => $data['unit_price'] ?? $item->unit_price,
            'discount_percent' => $data['discount_percent'] ?? $item->discount_percent,
            'discount_amount' => $data['discount_amount'] ?? $item->discount_amount,
            'tax_percent' => $data['tax_percent'] ?? $item->tax_percent,
            'seller_id' => array_key_exists('seller_id', $data) ? $data['seller_id'] : $item->seller_id,
        ]);

        return $item->fresh();
    }

    /**
     * Remove item
     */
    public function removeItem(int $itemId): bool
    {
        $item = SalesOrderItem::findOrFail($itemId);
        $order = $item->salesOrder;

        if (!$order->canBeEdited()) {
            throw new \Exception('No se pueden quitar items de una orden en estado ' . $order->status);
        }

        return $item->delete();
    }

    /**
     * Confirm order and deduct inventory
     */
    public function confirmOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);

        // Check stock availability first
        $this->checkStockAvailability($id);

        // Confirm (this will create inventory movements)
        $order->confirm();

        return $order->fresh();
    }

    /**
     * Complete order
     */
    public function completeOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);
        $order->complete();

        return $order->fresh();
    }

    /**
     * Cancel order
     */
    public function cancelOrder(int $id): SalesOrder
    {
        $order = $this->getById($id);
        $order->cancel();

        return $order->fresh();
    }

    /**
     * Check stock availability for all items
     */
    public function checkStockAvailability(int $id): array
    {
        $order = $this->getById($id);
        $issues = [];

        foreach ($order->items as $item) {
            $query = ProductInventory::where('warehouse_id', $item->warehouse_id);

            // Filter by variant_id if present, otherwise by product_id
            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->where('product_id', $item->product_id);
            }

            $inventory = $query->first();
            $available = $inventory ? $inventory->quantity_available : 0;

            if ($available < $item->quantity) {
                $issues[] = [
                    'product' => $item->product->name,
                    'warehouse' => $item->warehouse->name,
                    'required' => $item->quantity,
                    'available' => $available,
                    'missing' => $item->quantity - $available,
                ];
            }
        }

        if (!empty($issues)) {
            throw new \Exception('Stock insuficiente: ' . json_encode($issues));
        }

        return [];
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
