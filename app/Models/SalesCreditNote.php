<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCreditNote extends Model
{
    protected $fillable = [
        'credit_note_number', 'sales_return_id', 'sales_order_id', 'customer_id',
        'total_amount', 'receivable_amount', 'refund_amount', 'store_credit_amount',
        'status', 'issued_by', 'issued_at', 'settled_by', 'settled_at',
        'settlement_method', 'reference_number',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'receivable_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'store_credit_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
