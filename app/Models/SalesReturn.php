<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'settlement_status',
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
        'settled_by',
        'settled_at',
        'settlement_method',
        'settlement_reference',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'receivable_applied_amount' => 'decimal:2',
        'money_refund_amount' => 'decimal:2',
        'store_credit_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'formatted_total'];

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

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(SalesCreditNote::class);
    }

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

    public static function generateReturnNumber(): string
    {
        return app(\App\Services\DocumentSequenceService::class)->nextCommercialFolio();
    }

    /** Mark an already validated return as approved. Financial settlement stays in Caja. */
    public function approve(?User $user = null, array $amounts = []): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        if ($user === null) {
            throw new \LogicException('Las devoluciones deben aprobarse mediante SalesReturnService.');
        }

        $this->fill([
            'status' => 'approved',
            'settlement_status' => 'pending',
            'receivable_applied_amount' => $amounts['receivable_applied_amount'] ?? 0,
            'money_refund_amount' => $amounts['money_refund_amount'] ?? 0,
            'store_credit_amount' => $amounts['store_credit_amount'] ?? 0,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        return true;
    }

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

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SalesReturn $salesReturn): void {
            if (empty($salesReturn->return_number)) {
                $salesReturn->return_number = self::generateReturnNumber();
            }
        });
    }
}
