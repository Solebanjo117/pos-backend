<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesQuote extends Model
{
    use HasFactory, SoftDeletes;

    const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];

    protected $fillable = [
        'quote_number',
        'customer_id',
        'user_id',
        'warehouse_id',
        'date',
        'valid_until',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'converted_order_id',
        'notes',
    ];

    protected $casts = [
        'date'            => 'date',
        'valid_until'     => 'date',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total'           => 'decimal:2',
    ];

    protected $appends = ['formatted_total', 'status_label'];

    // ==================== RELATIONSHIPS ====================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return '$' . number_format($this->total, 2);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft'     => 'Borrador',
            'sent'      => 'Enviada',
            'accepted'  => 'Aceptada',
            'rejected'  => 'Rechazada',
            'expired'   => 'Expirada',
            'converted' => 'Convertida a OV',
            default     => ucfirst($this->status),
        };
    }

    // ==================== SCOPES ====================

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'accepted']);
    }

    public function scopeExpirable($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'accepted'])
                     ->whereNotNull('valid_until')
                     ->where('valid_until', '<', now()->toDateString());
    }

    // ==================== BUSINESS METHODS ====================

    public static function generateQuoteNumber(): string
    {
        $prefix = 'COT-';
        $date = now()->format('Ymd');

        $lastQuote = static::withTrashed()
            ->where('quote_number', 'like', $prefix . $date . '%')
            ->orderByDesc('quote_number')
            ->first();

        if ($lastQuote) {
            $lastNumber = (int) substr($lastQuote->quote_number, strlen($prefix . $date . '-'));
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . $date . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
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
        return in_array($this->status, ['draft', 'sent', 'accepted'])
            && $this->converted_order_id === null
            && $this->items()->count() > 0;
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
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
