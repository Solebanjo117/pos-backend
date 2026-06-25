<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAdjustment extends Model
{
    public const TYPE_CASH = 'cash';

    public const TYPE_INVENTORY = 'inventory';

    public const TYPES = [
        self::TYPE_CASH => 'Caja',
        self::TYPE_INVENTORY => 'Inventario',
    ];

    protected $fillable = [
        'warehouse_id',
        'user_id',
        'type',
        'amount',
        'reason',
        'adjustment_date',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'adjustment_date' => 'date',
    ];

    protected $appends = ['type_label'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
