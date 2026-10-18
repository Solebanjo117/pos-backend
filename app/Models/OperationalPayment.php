<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OperationalPayment extends Model
{
    protected $fillable = ['warehouse_id', 'source_type', 'source_id', 'line_number', 'direction', 'method', 'liquidity_bucket', 'amount', 'financial_account_id', 'cash_movement_id', 'pos_session_id', 'created_by', 'reference_number', 'notes', 'reversed_at', 'business_datetime'];

    protected $casts = ['amount' => 'decimal:2', 'reversed_at' => 'datetime', 'business_datetime' => 'datetime'];

    protected $appends = ['pending_account'];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function getPendingAccountAttribute(): bool
    {
        return $this->liquidity_bucket === 'bank' && ! $this->financial_account_id && ! $this->reversed_at;
    }
}
