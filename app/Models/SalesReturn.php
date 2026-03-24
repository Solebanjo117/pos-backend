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
        'refund_method',
        'refund_notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
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
        return '$' . number_format($this->total_amount, 2);
    }

    // ==================== BUSINESS METHODS ====================

    public static function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $lastReturn = self::whereDate('created_at', now()->toDateString())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastReturn ? (int) substr($lastReturn->return_number, -4) + 1 : 1;

        return 'DEV-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Approve the return: restore inventory and process refund based on refund_method.
     */
    public function approve(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        // 1. Restore inventory for each returned item
        foreach ($this->items as $item) {
            // Create inventory movement (in)
            InventoryMovement::create([
                'product_id'     => $item->product_id,
                'variant_id'     => $item->variant_id,
                'warehouse_id'   => $this->warehouse_id,
                'movement_type'  => 'in',
                'quantity'       => $item->quantity,
                'reference_type' => 'sales_return',
                'reference_id'   => $this->id,
                'notes'          => "Devolución {$this->return_number} - Orden {$this->salesOrder->order_number}",
                'user_id'        => auth()->id(),
                'date'           => now(),
            ]);

            // Restore quantity_available
            $query = ProductInventory::where('warehouse_id', $this->warehouse_id);
            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->where('product_id', $item->product_id)->whereNull('variant_id');
            }
            $inventory = $query->first();
            if ($inventory) {
                $inventory->quantity_available += $item->quantity;
                $inventory->save();
            }
        }

        // 2. Process refund based on refund_method
        $method = $this->refund_method ?? 'balance';

        if ($method === 'balance' && $this->customer_id) {
            // Refund to customer balance (store credit)
            $customer = Customer::find($this->customer_id);
            if ($customer) {
                $balanceBefore = (float) $customer->current_balance;
                $customer->current_balance = $balanceBefore - (float) $this->total_amount;
                $customer->save();

                CustomerTransaction::create([
                    'customer_id'      => $customer->id,
                    'type'             => 'credit_note',
                    'reference_type'   => 'sales_return',
                    'reference_id'     => $this->id,
                    'amount'           => (float) $this->total_amount,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $customer->current_balance,
                    'description'      => "Devolución {$this->return_number}",
                    'transaction_date' => now(),
                    'created_by'       => auth()->id(),
                ]);
            }
        } elseif (in_array($method, ['cash', 'card', 'transfer'])) {
            // Refund via cash/card/transfer: record a negative payment on the original order
            if (class_exists(\App\Models\SalesOrderPayment::class)) {
                \App\Models\SalesOrderPayment::create([
                    'sales_order_id' => $this->sales_order_id,
                    'amount'         => -1 * abs((float) $this->total_amount),
                    'method'         => $method,
                    'notes'          => "Reembolso por devolución {$this->return_number}" . ($this->refund_notes ? " - {$this->refund_notes}" : ''),
                    'paid_at'        => now(),
                    'created_by'     => auth()->id(),
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
                        'customer_id'      => $customer->id,
                        'type'             => 'credit_note',
                        'reference_type'   => 'sales_return',
                        'reference_id'     => $this->id,
                        'amount'           => (float) $this->total_amount,
                        'balance_before'   => $balanceBefore,
                        'balance_after'    => $customer->current_balance,
                        'description'      => "Devolución {$this->return_number} (reembolso {$method})",
                        'transaction_date' => now(),
                        'created_by'       => auth()->id(),
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
                ->whereNotIn('method', ['credit'])
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
    public function reject(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->status = 'rejected';
        $this->save();

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
