<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'sales_return_id',
        'amount',
        'method',
        'reference_number',
        'notes',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get formatted method name from PaymentMethod table
     */
    public function getFormattedMethodAttribute(): string
    {
        return PaymentMethod::getNameByCode($this->method);
    }
}
