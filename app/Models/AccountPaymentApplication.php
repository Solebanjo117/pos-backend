<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPaymentApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_batch_id',
        'document_type',
        'document_id',
        'document_number',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(AccountPaymentBatch::class, 'payment_batch_id');
    }
}
