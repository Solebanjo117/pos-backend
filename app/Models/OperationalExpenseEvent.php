<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalExpenseEvent extends Model
{
    protected $fillable = [
        'operational_expense_id', 'user_id', 'event_type', 'from_status',
        'to_status', 'reason', 'before', 'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(OperationalExpense::class, 'operational_expense_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
