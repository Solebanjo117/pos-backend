<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    protected $fillable = [
        'pos_session_id',
        'user_id',
        'type',
        'amount',
        'reason',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
