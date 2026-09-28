<?php

namespace App\Models;

use App\Support\RegionalDateRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuote extends Model
{
    use HasFactory, SoftDeletes;

    const STATUSES = ['draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted', 'collected', 'rejected', 'expired', 'converted'];

    protected $fillable = [
        'quote_number',
        'idempotency_key',
        'draft_revision',
        'draft_request_hash',
        'vehicle_context',
        'customer_id',
        'customer_vehicle_id',
        'user_id',
        'warehouse_id',
        'pos_session_id',
        'date',
        'valid_until',
        'status',
        'sent_to_cashier_at',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'converted_order_id',
        'notes',
        'internal_notes',
    ];

    protected $casts = [
        'draft_revision' => 'integer',
        'vehicle_context' => 'array',
        'date' => 'date',
        'valid_until' => 'date',
        'sent_to_cashier_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    protected $appends = ['formatted_total', 'status_label'];

    // ==================== RELATIONSHIPS ====================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerVehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuoteItem::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_order_id');
    }

    // ==================== ACCESSORS ====================

    public function getFormattedTotalAttribute(): string
    {
        return '$'.number_format((float) $this->total, 2);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'sent_to_deposit' => 'En Depósito',
            'sent_to_cashier' => 'En Caja',
            'accepted' => 'Aceptada',
            'collected' => 'Cobrada',
            'rejected' => 'Rechazada',
            'expired' => 'Expirada',
            'converted' => 'Convertida a OV',
            default => ucfirst($this->status),
        };
    }

    // ==================== SCOPES ====================

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted']);
    }

    public function scopeExpirable($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'accepted'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now()->toDateString());
    }

    // ==================== RELATIONS ====================

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    // ==================== STATE TRANSITIONS ====================

    public function sendToDeposit(): bool
    {
        if (! in_array($this->status, ['draft', 'sent'])) {
            return false;
        }

        return $this->update(['status' => 'sent_to_deposit']);
    }

    public function sendToCashier(): bool
    {
        if (! in_array($this->status, ['draft', 'sent', 'sent_to_deposit'])) {
            return false;
        }

        return $this->update([
            'status' => 'sent_to_cashier',
            'sent_to_cashier_at' => now(),
        ]);
    }

    public function markCollected(): bool
    {
        if (! in_array($this->status, ['sent_to_cashier', 'accepted'])) {
            return false;
        }

        return $this->update(['status' => 'collected']);
    }

    // ==================== BUSINESS METHODS ====================

    public static function generateQuoteNumber(): string
    {
        return app(\App\Services\DocumentSequenceService::class)->nextQuoteFolio(
            now(RegionalDateRange::timezone()),
        );
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('subtotal');
        $this->tax_amount = $this->items()->sum('tax_amount');
        $this->discount_amount = $this->items()->sum('discount_amount');
        $this->total = $this->subtotal + $this->tax_amount;
        $this->save();
    }

    public function canBeConverted(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted'])
            && $this->converted_order_id === null
            && $this->items()->count() > 0;
    }

    public function isExpired(): bool
    {
        return $this->valid_until && \Carbon\Carbon::parse($this->valid_until)->isPast();
    }

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($quote) {
            if (empty($quote->quote_number)) {
                $quote->quote_number = static::generateQuoteNumber();
            }
            if (empty($quote->date)) {
                $quote->date = now();
            }
        });
    }
}
