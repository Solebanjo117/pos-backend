<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    protected $fillable = [
        'pos_session_id',
        'user_id',
        'type',
        'subtype',
        'amount',
        'balance_before',
        'balance_after',
        'reason',
        'reference_number',
        'payment_method',
        'notes',
        'source_type',
        'source_id',
        'business_datetime',
        'idempotency_key',
        'request_hash',
    ];

    protected $hidden = [
        'request_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'business_datetime' => 'datetime',
    ];

    public function posSession()
    {
        return $this->belongsTo(PosSession::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
