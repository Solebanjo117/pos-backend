<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'po_number',
        'supplier_invoice_number',
        'supplier_id',
        'warehouse_id',
        'user_id',
        'status',
        'payment_status',
        'order_date',
        'expected_date',
        'received_date',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'total',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected $appends = ['formatted_total', 'status_label', 'amount_paid', 'amount_due', 'payment_status_label', 'display_name'];

    // ==================== RELATIONSHIPS ====================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    // ==================== SCOPES ====================

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeBySupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('order_date', [$from, $to]);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'open']);
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

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Borrador',
            'open' => 'Abierta',
            'sent' => 'Enviado',
            'partial' => 'Parcialmente Recibido',
            'received' => 'Recibido',
            'cancelled' => 'Cancelado',
            default => $this->status,
        };
    }

    public function getAmountPaidAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getAmountDueAttribute(): float
    {
        return max(0, (float) $this->total - $this->amount_paid);
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status ?? 'pending') {
            'paid' => 'Pagado',
            'partial' => 'Pago Parcial',
            default => 'Pendiente',
        };
    }

    // ==================== BUSINESS METHODS ====================

    /**
     * Generate unique PO number
     */
    public static function generatePoNumber(): string
    {
        $date = now()->format('Ymd');

        // Get the maximum sequence for today, INCLUDING soft-deleted records
        // to avoid UNIQUE constraint violations
        $lastPo = self::withTrashed()
            ->whereDate('created_at', now()->toDateString())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastPo ? (int) substr($lastPo->po_number, -4) + 1 : 1;

        return 'PO-'.$date.'-'.str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate and update totals from items
     */
    public function calculateTotals(): void
    {
        $items = $this->items()->get(['subtotal', 'tax_amount', 'misc_cost']);

        $this->subtotal = round((float) $items->sum('subtotal'), 2);
        $this->tax_amount = round((float) $items->sum('tax_amount'), 2);
        $miscTotal = round((float) $items->sum('misc_cost'), 2);
        $this->total = $this->subtotal + $this->tax_amount + $miscTotal + (float) ($this->shipping_cost ?? 0);
        $this->save();
    }

    /**
     * Check if PO can be edited
     */
    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if PO can be sent
     */
    public function canBeSent(): bool
    {
        return $this->status === 'draft' && $this->items()->count() > 0;
    }

    /**
     * Check if PO can receive items
     */
    public function canReceiveItems(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'partial', 'open'], true) && $this->items()->count() > 0;
    }

    /**
     * Check if PO can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return ! in_array($this->status, ['received', 'cancelled']);
    }

    /**
     * Check if this is a daily global PO (no single supplier in header).
     */
    public function isDailyGlobalPO(): bool
    {
        return $this->supplier_id === null;
    }

    /**
     * Get unique suppliers from items (for daily global POs).
     */
    public function getItemSuppliersAttribute(): \Illuminate\Support\Collection
    {
        return $this->items()
            ->whereNotNull('supplier_id')
            ->with('supplier')
            ->get()
            ->pluck('supplier')
            ->unique('id')
            ->values();
    }

    /**
     * Get display name for the PO (supplier name or "OC del [date]" for daily).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->isDailyGlobalPO()) {
            return 'OC del '.$this->order_date->format('d M Y');
        }

        return $this->supplier?->name ?? $this->po_number;
    }

    /**
     * Check if all items are fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(function ($item) {
            return $item->is_fully_received;
        });
    }

    /**
     * Add a payment to this purchase order
     */
    public function addPayment(float $amount, string $method = 'cash', ?string $referenceNumber = null, ?string $notes = null): PurchaseOrderPayment
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'paid_at' => now(),
            'user_id' => auth()->id(),
        ]);

        $this->recalculatePaymentStatus();

        return $payment;
    }

    /**
     * Recalculate and persist the payment_status based on current payments
     */
    public function recalculatePaymentStatus(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $total = (float) $this->total;

        if ($paid <= 0) {
            $this->payment_status = 'pending';
        } elseif ($paid >= $total) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }

        $this->save();
    }

    /**
     * Boot method to auto-generate PO number
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($po) {
            if (empty($po->po_number)) {
                // Retry up to 5 times if we hit a duplicate
                $attempts = 0;
                while ($attempts < 5) {
                    $po->po_number = self::generatePoNumber();

                    // Check if this number already exists (including soft-deleted)
                    if (! self::withTrashed()->where('po_number', $po->po_number)->exists()) {
                        break;
                    }

                    $attempts++;
                    // Small delay to let other transactions commit
                    usleep(100000); // 100ms
                }
            }
        });
    }
}
