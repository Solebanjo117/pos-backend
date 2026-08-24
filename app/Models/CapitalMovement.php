<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapitalMovement extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = [
        'warehouse_id',
        'user_id',
        'movement_type',
        'amount',
        'movement_date',
        'reason',
        'notes',
        'source_type',
        'source_id',
        'business_datetime',
        'financial_account_id',
        'idempotency_key',
        'request_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'movement_date' => 'date',
        'business_datetime' => 'datetime',
    ];

    protected $appends = ['movement_type_label'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMovementTypeLabelAttribute(): string
    {
        return $this->movement_type === self::TYPE_IN ? 'Aporte' : 'Retiro';
    }
}
