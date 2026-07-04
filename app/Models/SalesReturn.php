<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'return_number',
        'sales_order_id',
        'warehouse_id',
        'customer_id',
        'user_id',
        'status',
        'reason',
        'total_amount',
        'receivable_applied_amount',
        'money_refund_amount',
        'store_credit_amount',
        'refund_method',
        'refund_notes',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'receivable_applied_amount' => 'decimal:2',
        'money_refund_amount' => 'decimal:2',
        'store_credit_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'formatted_total'];

    // ==================== RELATIONSHIPS ====================

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            default => $this->status,
        };
    }

    public function getFormattedTotalAttribute(): string
    {
        return '$'.number_format($this->total_amount, 2);
    }

    // ==================== BUSINESS METHODS ====================

    public static function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $lastReturn = self::whereDate('created_at', now()->toDateString())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastReturn ? (int) substr($lastReturn->return_number, -4) + 1 : 1;

        return 'DEV-'.$date.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Approve the return: restore inventory and process refund based on refund_method.
     */
    public function approve(?User $user = null, array $amounts = []): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        if ($user !== null || $amounts !== []) {
            $this->fill([
                'status' => 'approved',
                'receivable_applied_amount' => $amounts['receivable_applied_amount'] ?? $this->receivable_applied_amount ?? 0,
                'money_refund_amount' => $amounts['money_refund_amount'] ?? $this->money_refund_amount ?? 0,
                'store_credit_amount' => $amounts['store_credit_amount'] ?? $this->store_credit_amount ?? 0,
                'approved_by' => $user?->id,
                'approved_at' => now(),
            ])->save();

            return true;
        }

        // 1. Restore inventory for each returned item
        foreach ($this->items as $item) {
            app(\App\Services\InventoryFifoService::class)->receiveStock([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $this->warehouse_id,
                'quantity' => $item->quantity,
                'unit_cost_base' => (float) ($item->salesOrderItem?->unit_cost ?? 0),
                'landed_unit_cost' => (float) ($item->salesOrderItem?->unit_cost ?? 0),
                'reference_type' => 'sales_return',
                'reference_id' => $this->id,
                'source_type' => 'sales_return',
                'source_id' => $this->id,
                'notes' => "Devolución {$this->return_number} - Orden {$this->salesOrder->order_number}",
                'user_id' => auth()->id(),
                'received_at' => now(),
            ]);

        }

        // 2. Process refund based on refund_method
        $method = $this->refund_method ?? 'balance';

        if (in_array($method, ['balance', 'store_credit'], true) && $this->customer_id) {
            // Refund to customer balance (store credit)
            $customer = Customer::find($this->customer_id);
            if ($customer) {
                $balanceBefore = (float) $customer->current_balance;
                $customer->current_balance = $balanceBefore - (float) $this->total_amount;
                $customer->save();

                CustomerTransaction::create([
                    'customer_id' => $customer->id,
                    'type' => 'credit_note',
                    'reference_type' => 'sales_return',
                    'reference_id' => $this->id,
                    'amount' => (float) $this->total_amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $customer->current_balance,
                    'description' => "Devolución {$this->return_number}",
                    'transaction_date' => now(),
                    'created_by' => auth()->id(),
                ]);
            }
        } elseif (in_array($method, PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_RETURNS), true)) {
            // Refund via cash/card/transfer: record a negative payment on the original order
            if (class_exists(\App\Models\SalesOrderPayment::class)) {
                \App\Models\SalesOrderPayment::create([
                    'sales_order_id' => $this->sales_order_id,
                    'amount' => -1 * abs((float) $this->total_amount),
                    'method' => $method,
                    'notes' => "Reembolso por devolución {$this->return_number}".($this->refund_notes ? " - {$this->refund_notes}" : ''),
                    'paid_at' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            // Also adjust customer balance if there is a customer (reduce any pending debt)
            if ($this->customer_id) {
                $customer = Customer::find($this->customer_id);
                if ($customer) {
                    $balanceBefore = (float) $customer->current_balance;
                    $customer->current_balance = $balanceBefore - (float) $this->total_amount;
                    $customer->save();

                    CustomerTransaction::create([
                        'customer_id' => $customer->id,
                        'type' => 'credit_note',
                        'reference_type' => 'sales_return',
                        'reference_id' => $this->id,
                        'amount' => (float) $this->total_amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $customer->current_balance,
                        'description' => "Devolución {$this->return_number} (reembolso {$method})",
                        'transaction_date' => now(),
                        'created_by' => auth()->id(),
                    ]);
                }
            }
        }

        $this->status = 'approved';
        $this->save();

        // 3. Update the original sales order's payment_status
        $order = $this->salesOrder;
        if ($order) {
            // Recalculate payment_status based on current payments
            $amountPaid = (float) $order->payments()
                ->whereNotIn('method', PaymentMethod::DEFERRED_CODES)
                ->sum('amount');

            // Check if entire order was returned
            $totalReturned = (float) \App\Models\SalesReturn::where('sales_order_id', $order->id)
                ->where('status', 'approved')
                ->sum('total_amount');

            if ($totalReturned >= (float) $order->total) {
                // All items returned - mark as refunded
                $order->payment_status = 'refunded';
            } else {
                // Partial return - recalculate based on payments
                $effectiveTotal = (float) $order->total - $totalReturned;

                if ($amountPaid <= 0) {
                    $order->payment_status = 'pending';
                } elseif ($amountPaid >= $effectiveTotal) {
                    $order->payment_status = 'paid';
                } else {
                    $order->payment_status = 'partial';
                }
            }

            $order->save();
        }

        return true;
    }

    /**
     * Reject the return.
     */
    public function reject(?User $user = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->fill([
            'status' => 'rejected',
            'rejected_by' => $user?->id,
            'rejected_at' => now(),
        ])->save();

        return true;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->return_number)) {
                $return->return_number = self::generateReturnNumber();
            }
        });
    }
}
