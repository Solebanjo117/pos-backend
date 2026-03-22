<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'customer_id',
        'user_id',
        'pos_session_id',
        'date',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_cost',
        'total',
        'payment_status',
        'payment_method',
        'notes',
        'internal_notes',
        'audit_logs',
    ];

    protected $casts = [
        'date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'audit_logs' => 'array',
    ];

    protected $appends = ['formatted_total', 'status_label', 'amount_paid', 'amount_due'];

    // ==================== RELATIONSHIPS ====================
    
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesOrderPayment::class)->orderBy('paid_at', 'asc');
    }

    // ==================== SCOPES ====================
    
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'pending']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['cancelled']);
    }

    // ==================== ACCESSORS ====================
    
    public function getFormattedTotalAttribute(): string
    {
        return '$' . number_format($this->total, 2);
    }

    public function getAmountPaidAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum('amount');
        }
        return (float) $this->payments()->sum('amount');
    }

    public function getAmountDueAttribute(): float
    {
        return max(0, (float) $this->total - $this->amount_paid);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Borrador',
            'pending' => 'Pendiente',
            'confirmed' => 'Confirmada',
            'processing' => 'En Proceso',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }

    // ==================== BUSINESS METHODS ====================
    
    /**
     * Generate unique order number
     */
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $lastOrder = self::whereDate('created_at', now()->toDateString())
            ->orderBy('id', 'desc')
            ->first();
        
        $sequence = $lastOrder ? (int) substr($lastOrder->order_number, -4) + 1 : 1;
        
        return 'ORD-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate and update totals
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('subtotal');
        $this->tax_amount = $this->items->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_amount + $this->shipping_cost - $this->discount_amount;
        $this->save();
    }

    /**
     * Check if order can be edited
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'pending']);
    }

    /**
     * Check if order can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    /**
     * Check if order can be confirmed
     */
    public function canBeConfirmed(): bool
    {
        return in_array($this->status, ['draft', 'pending']);
    }

    /**
     * Confirm order and deduct inventory
     */
    public function confirm(): bool
    {
        if (!$this->canBeConfirmed()) {
            return false;
        }

        $allowNegative = Setting::get('allow_negative_inventory', false);

        // Check stock availability only if negative inventory is not allowed
        if (!$allowNegative) {
            foreach ($this->items as $item) {
                // Use variant_id if available, fall back to product_id
                $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                
                if ($item->variant_id) {
                    $query->where('variant_id', $item->variant_id);
                } else {
                    $query->where('product_id', $item->product_id);
                }
                
                $inventory = $query->first();

                if (!$inventory || $inventory->quantity_available < $item->quantity) {
                    throw new \Exception("Stock insuficiente para {$item->product->name}");
                }
            }
        }

        // Create inventory movements and deduct stock
        foreach ($this->items as $item) {
            InventoryMovement::create([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $item->warehouse_id,
                'movement_type' => 'out',
                'quantity' => $item->quantity,
                'reference_type' => 'sale',
                'reference_id' => $this->id,
                'notes' => "Venta - Orden {$this->order_number}",
                'user_id' => auth()->id(),
                'date' => now(),
            ]);

            // Update stock — check if stock was pre-reserved
            $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->where('product_id', $item->product_id);
            }
            $inventory = $query->first();

            if ($inventory) {
                if ($inventory->quantity_reserved >= $item->quantity) {
                    // Stock was pre-reserved (draft/pending → confirmed)
                    $inventory->quantity_reserved -= $item->quantity;
                } else {
                    // Direct confirm (POS) — deduct from available
                    $inventory->quantity_available -= $item->quantity;
                }
                $inventory->save();
            }
        }

        $this->status = 'confirmed';

        // Charge debt to customer if applicable
        if ($this->customer_id) {
            $customer = \App\Models\Customer::find($this->customer_id);
            if ($customer) {
                $balanceBefore = (float) $customer->current_balance;
                $customer->current_balance = $balanceBefore + (float) $this->total;
                $customer->save();

                \App\Models\CustomerTransaction::create([
                    'customer_id'      => $customer->id,
                    'type'             => 'sale',
                    'reference_type'   => 'sales_order',
                    'reference_id'     => $this->id,
                    'amount'           => (float) $this->total,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $customer->current_balance,
                    'description'      => "Cargo por venta {$this->order_number}",
                    'transaction_date' => now(),
                    'created_by'       => auth()->id(),
                ]);
            }
        }

        $this->save();
        
        // Auto-complete if payment is already received (POS sales)
        if ($this->payment_status === 'paid') {
            $this->complete();
        }

        return true;
    }

    /**
     * Complete order
     */
    public function complete(): bool
    {
        if ($this->status !== 'confirmed' && $this->status !== 'processing') {
            return false;
        }

        $this->status = 'completed';
        $this->save();

        return true;
    }

    /**
     * Register a payment (cash, card, transfer, or credit).
     * Updates payment_status and, for credit payments, adjusts customer balance.
     */
    public function addPayment(float $amount, string $method, ?string $notes = null, $paidAt = null): SalesOrderPayment
    {
        $payment = $this->payments()->create([
            'amount'     => $amount,
            'method'     => $method,
            'notes'      => $notes,
            'paid_at'    => $paidAt ?? now(),
            'created_by' => auth()->id(),
        ]);

        // Recalculate payment_status
        $paid = (float) $this->payments()->sum('amount');
        if ($paid <= 0) {
            $this->payment_status = 'pending';
        } elseif ($paid >= (float) $this->total) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }
        $this->save();

        // Reduce customer debt (or apply store_credit)
        if ($this->customer_id) {
            $customer = \App\Models\Customer::find($this->customer_id);
            if ($customer) {
                $balanceBefore = (float) $customer->current_balance;

                // For store_credit, we INCREASE the balance (use up the credit)
                // For other methods, we DECREASE the balance (reduce debt)
                if ($method === 'store_credit') {
                    // Store credit: balance is negative, add amount to bring it closer to 0
                    $customer->current_balance = $balanceBefore + $amount;
                    $transactionType = 'store_credit_used';
                    $description = "Saldo a favor aplicado a venta {$this->order_number}";
                } else {
                    $customer->current_balance = $balanceBefore - $amount;
                    $transactionType = 'payment';
                    $description = "Abono a venta {$this->order_number}";
                }
                $customer->save();

                \App\Models\CustomerTransaction::create([
                    'customer_id'      => $customer->id,
                    'type'             => $transactionType,
                    'reference_type'   => 'sales_order_payment',
                    'reference_id'     => $payment->id,
                    'amount'           => $amount,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $customer->current_balance,
                    'description'      => $description,
                    'transaction_date' => $paidAt ?? now(),
                    'created_by'       => auth()->id(),
                ]);
            }
        }

        // Auto-complete if fully paid and already confirmed
        if ($this->payment_status === 'paid' && in_array($this->status, ['confirmed', 'processing'])) {
            $this->complete();
        }

        return $payment;
    }

    /**
     * Cancel order and revert inventory if needed
     */
    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        // If order was confirmed, revert inventory (restore to available)
        if ($this->status === 'confirmed' || $this->status === 'processing') {
            foreach ($this->items as $item) {
                InventoryMovement::create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'warehouse_id' => $item->warehouse_id,
                    'movement_type' => 'in',
                    'quantity' => $item->quantity,
                    'reference_type' => 'sale_cancelled',
                    'reference_id' => $this->id,
                    'notes' => "Cancelación de orden {$this->order_number}",
                    'user_id' => auth()->id(),
                    'date' => now(),
                ]);

                $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                if ($item->variant_id) {
                    $query->where('variant_id', $item->variant_id);
                } else {
                    $query->where('product_id', $item->product_id);
                }
                $inventory = $query->first();
                if ($inventory) {
                    $inventory->quantity_available += $item->quantity;
                    $inventory->save();
                }
            }
        }

        // If order was draft/pending, release reserved stock back to available
        if (in_array($this->status, ['draft', 'pending'])) {
            foreach ($this->items as $item) {
                $query = ProductInventory::where('warehouse_id', $item->warehouse_id);
                if ($item->variant_id) {
                    $query->where('variant_id', $item->variant_id);
                } else {
                    $query->where('product_id', $item->product_id);
                }
                $inventory = $query->first();
                if ($inventory && $inventory->quantity_reserved >= $item->quantity) {
                    $inventory->quantity_reserved  -= $item->quantity;
                    $inventory->quantity_available += $item->quantity;
                    $inventory->save();
                }
            }
        }

        // Revert payments: refund customer balance for each recorded payment
        $totalRefunded = 0;
        foreach ($this->payments as $payment) {
            $totalRefunded += (float) $payment->amount;

            if ($this->customer_id) {
                $customer = \App\Models\Customer::find($this->customer_id);
                if ($customer) {
                    $balanceBefore = (float) $customer->current_balance;
                    $customer->current_balance = $balanceBefore + (float) $payment->amount;
                    $customer->save();

                    \App\Models\CustomerTransaction::create([
                        'customer_id'      => $customer->id,
                        'type'             => 'credit_note',
                        'reference_type'   => 'sales_order_cancelled',
                        'reference_id'     => $this->id,
                        'amount'           => $payment->amount,
                        'balance_before'   => $balanceBefore,
                        'balance_after'    => $customer->current_balance,
                        'description'      => "Reembolso por cancelación de orden {$this->order_number} ({$payment->formatted_method})",
                        'transaction_date' => now(),
                        'created_by'       => auth()->id(),
                    ]);
                }
            }
        }

        $this->status = 'cancelled';
        if ($totalRefunded > 0) {
            $this->payment_status = 'refunded';
        }
        $this->save();

        return true;
    }

    /**
     * Boot method to auto-generate order number
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = self::generateOrderNumber();
            }
        });
    }
}
