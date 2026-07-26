<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountPaymentBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_type',
        'account_id',
        'pos_session_id',
        'cash_movement_id',
        'direction',
        'batch_number',
        'amount',
        'method',
        'reference_number',
        'notes',
        'paid_at',
        'created_by',
        'business_datetime',
        'financial_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'business_datetime' => 'datetime',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(AccountPaymentApplication::class, 'payment_batch_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AccountLedgerEntry::class, 'payment_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }
}
