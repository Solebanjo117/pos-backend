<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_type',
        'account_id',
        'movement_type',
        'document_type',
        'document_id',
        'document_number',
        'payment_batch_id',
        'debit',
        'credit',
        'balance_after',
        'occurred_at',
        'reference_number',
        'notes',
        'created_by',
        'reversed_entry_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(AccountPaymentBatch::class, 'payment_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }
}
