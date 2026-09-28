<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class PurchasePaymentRequest extends Model
{
    protected $fillable = ['purchase_order_id', 'warehouse_id', 'requested_by', 'amount', 'notes', 'idempotency_key', 'request_hash'];

    protected $casts = ['amount' => 'decimal:2'];

    protected $hidden = ['request_hash'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function queueEntry(): MorphOne
    {
        return $this->morphOne(CashierQueueEntry::class, 'source');
    }
}
