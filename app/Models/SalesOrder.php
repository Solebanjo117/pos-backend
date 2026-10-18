<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;

    public array $stockWarnings = [];

    protected $fillable = [
        'order_number',
        'idempotency_key',
        'request_hash',
        'customer_id',
        'customer_vehicle_id',
        'referred_by_customer_id',
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
        'sent_to_warehouse_at',
        'prepared_by',
        'prepared_at',
        'business_datetime',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_return_id',
    ];

    protected $casts = [
        'date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'audit_logs' => 'array',
        'sent_to_warehouse_at' => 'datetime',
        'prepared_at' => 'datetime',
        'business_datetime' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $hidden = ['request_hash'];

    protected $appends = ['formatted_total', 'status_label', 'amount_paid', 'amount_due', 'total_returned'];

    // ==================== RELATIONSHIPS ====================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerVehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class);
    }

    public function referredByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function sourceQuote(): HasOne
    {
        return $this->hasOne(SalesQuote::class, 'converted_order_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesOrderPayment::class)->orderBy('paid_at', 'asc');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(\App\Models\SalesReturn::class, 'sales_order_id');
    }

    public function financialAdjustments(): HasMany
    {
        return $this->hasMany(FinancialAdjustment::class)
            ->where('is_legacy', false);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cancellationReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'cancellation_return_id');
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
        return $query
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to);
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
        return '$'.number_format($this->total, 2);
    }

    public function getAmountPaidAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments
                ->reject(fn (SalesOrderPayment $payment): bool => PaymentMethod::isDeferredCode($payment->method))
                ->sum('amount');
        }

        return (float) $this->payments()
            ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
            ->sum('amount');
    }

    public function getAmountDueAttribute(): float
    {
        // Calculate total amount returned (approved returns only)
        $totalReturned = $this->total_returned;

        // Effective total after returns
        $effectiveTotal = (float) $this->total - $totalReturned;
        $storeCreditApplied = $this->relationLoaded('payments')
            ? (float) $this->payments->where('method', 'store_credit')->sum('amount')
            : (float) $this->payments()->where('method', 'store_credit')->sum('amount');

        // Amount still owed
        $adjustments = $this->relationLoaded('financialAdjustments')
            ? (float) $this->financialAdjustments
                ->sum(fn (FinancialAdjustment $adjustment): float => $adjustment->signedAmount())
            : (float) FinancialAdjustment::query()
                ->where('sales_order_id', $this->id)
                ->where('is_legacy', false)
                ->get(['amount', 'direction'])
                ->sum(fn (FinancialAdjustment $adjustment): float => $adjustment->signedAmount());

        return max(0, $effectiveTotal - $this->amount_paid - $storeCreditApplied + $adjustments);
    }

    public function getTotalReturnedAttribute(): float
    {
        if ($this->relationLoaded('returns')) {
            return (float) $this->returns->where('status', 'approved')->sum('total_amount');
        }

        return (float) $this->returns()->where('status', 'approved')->sum('total_amount');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
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
        return app(\App\Services\DocumentSequenceService::class)->nextSalesOrderFolio(
            now(\App\Support\RegionalDateRange::timezone()),
        );
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
        return $this->status !== 'cancelled';
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
    public function confirm(bool $consumeReserved = true): bool
    {
        if (! $this->canBeConfirmed()) {
            return false;
        }

        $this->stockWarnings = [];

        // Create inventory movements and deduct stock (only what is available)
        foreach ($this->items as $item) {
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
            $reserved = $consumeReserved && $inventory
                ? max(0, (int) $inventory->quantity_reserved)
                : 0;
            $requested = (int) $item->quantity;
            $quantityToDeduct = min($requested, max(0, $available + $reserved));

            if ($requested > $quantityToDeduct) {
                $label = $item->product?->name ?? $item->custom_description ?? 'producto';
                $this->stockWarnings[] = [
                    'type' => 'stock_shortage',
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'warehouse_id' => $item->warehouse_id,
                    'product_name' => $label,
                    'requested_quantity' => $requested,
                    'available_quantity' => $available,
                    'missing_quantity' => $requested - $quantityToDeduct,
                    'message' => "{$label}: solicitado {$requested}, disponible {$available}, faltante ".($requested - $quantityToDeduct),
                ];
            }

            if ($quantityToDeduct <= 0) {
                continue;
            }

            $fifoResult = app(\App\Services\InventoryFifoService::class)->consumeStock([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => $quantityToDeduct,
                'reference_type' => 'sale',
                'reference_id' => $this->id,
                'sales_order_item_id' => $item->id,
                'notes' => "Venta - Orden {$this->order_number}",
                'user_id' => Auth::id(),
                'consume_reserved' => $consumeReserved,
            ]);

            if (($fifoResult['weighted_unit_cost'] ?? 0) > 0) {
                $item->forceFill(['unit_cost' => $fifoResult['weighted_unit_cost']])->save();
            }
        }

        $this->status = 'confirmed';

        // Charge pending amount to customer, excluding all payments already made
        if ($this->customer_id) {
            $customer = \App\Models\Customer::find($this->customer_id);
            if ($customer) {
                // Charge only the amount that remains unpaid (total - real payments)
                // Note: 'credit' method is NOT real money received, only registers intent
                $amountPaid = (float) $this->payments()
                    ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
                    ->sum('amount');
                $storeCreditApplied = (float) $this->payments()
                    ->where('method', 'store_credit')
                    ->sum('amount');
                $amountToBill = max(
                    0,
                    (float) $this->total - $amountPaid - $storeCreditApplied,
                );

                // Only create transaction if there's an amount to bill
                if ($amountToBill > 0) {
                    $balanceBefore = (float) $customer->current_balance;
                    $customer->current_balance = $balanceBefore + $amountToBill;
                    $customer->save();

                    \App\Models\CustomerTransaction::create([
                        'customer_id' => $customer->id,
                        'type' => 'sale',
                        'reference_type' => 'sales_order',
                        'reference_id' => $this->id,
                        'amount' => $amountToBill,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->current_balance,
                        'description' => "Cargo por venta {$this->order_number}",
                        'transaction_date' => now(),
                        'created_by' => Auth::id(),
                    ]);
                }
            }
        }

        $this->save();

        // Recalculate payment_status after confirming
        $amountPaid = (float) $this->payments()
            ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
            ->sum('amount');

        if ($amountPaid <= 0) {
            $this->payment_status = 'pending';
        } elseif ($amountPaid >= (float) $this->total) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
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
    public function addPayment(float $amount, string $method, ?string $notes = null, $paidAt = null, bool $isCheckout = false, ?string $referenceNumber = null, ?int $financialAccountId = null, ?string $idempotencyKey = null, ?string $requestHash = null, ?int $accountPaymentBatchId = null): SalesOrderPayment
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'paid_at' => $paidAt ?? now(),
            'created_by' => Auth::id(),
            'financial_account_id' => $financialAccountId,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'account_payment_batch_id' => $accountPaymentBatchId,
            'pos_session_id' => $accountPaymentBatchId ? AccountPaymentBatch::query()->find($accountPaymentBatchId)?->pos_session_id : $this->pos_session_id,
        ]);

        // Recalculate payment_status
        $paid = (float) $this->payments()
            ->whereNotIn('method', ['credit', 'supplier_credit'])
            ->sum('amount');
        if ($paid <= 0) {
            $this->payment_status = 'pending';
        } elseif ($paid >= (float) $this->total) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }
        $this->save();

        if ($this->customer_id) {
            $customer = \App\Models\Customer::find($this->customer_id);
            if ($customer) {
                $balanceBefore = $customer->current_balance;

                // store_credit SIEMPRE afecta balance, incluso en checkout
                if ($method === 'store_credit') {
                    $customer->current_balance += $amount; // El saldo a favor se gasta asumiéndose negativo o a la par
                    $customer->save();

                    \App\Models\CustomerTransaction::create([
                        'customer_id' => $customer->id,
                        'type' => 'store_credit_used',
                        'amount' => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->current_balance,
                        'reference_type' => 'sales_order',
                        'reference_id' => $this->id,
                        'description' => "Saldo a favor aplicado a venta {$this->order_number}",
                        'transaction_date' => now(),
                    ]);
                } elseif (! PaymentMethod::isDeferredCode($method) && ! $isCheckout) {
                    // Si es cobro normal (efectivo/tarjeta/transferencia), solo baja el balance general
                    // si NO es un pago efectuado el día del Checkout original
                    $customer->current_balance -= $amount;
                    $customer->save();

                    \App\Models\CustomerTransaction::create([
                        'customer_id' => $customer->id,
                        'type' => 'payment',
                        'amount' => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->current_balance,
                        'reference_type' => 'sales_order',
                        'reference_id' => $this->id,
                        'description' => "Abono a venta {$this->order_number}",
                        'transaction_date' => now(),
                    ]);
                }
                // Si $isCheckout === true y el método != store_credit:
                // No tocar balance. confirm() se encargará del cargo neto al facturar la venta.
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
        throw new \LogicException('Las anulaciones deben procesarse mediante SalesOrderCancellationService.');
    }

    /**
     * Set sent_to_warehouse_at
     */
    public function sendToWarehouse(): bool
    {
        if ($this->sent_to_warehouse_at !== null) {
            return false;
        }

        $this->sent_to_warehouse_at = now();

        return $this->save();
    }

    /**
     * Mark order as prepared
     */
    public function markAsPrepared(int $userId): bool
    {
        if ($this->prepared_at !== null) {
            return false;
        }

        $this->prepared_by = $userId;
        $this->prepared_at = now();

        return $this->save();
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
