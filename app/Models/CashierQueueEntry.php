<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CashierQueueEntry extends Model
{
    public const TYPE_SALE = 'sale_collection';

    public const TYPE_EXPENSE = 'expense_payment';

    public const TYPE_RETURN = 'sales_return_refund';

    public const TYPE_CASH_IN = 'cash_in_request';

    public const TYPE_CASH_OUT = 'cash_out_request';

    public const TYPE_FUNDING = 'funding_request';

    public const STATUS_PENDING = 'pending';

    public const STATUS_OPENED = 'opened';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'entry_type', 'source_type', 'source_id', 'warehouse_id', 'status',
        'estimated_amount', 'final_amount', 'scheduled_for', 'opened_by',
        'opened_at', 'completed_by', 'completed_at', 'cancelled_by',
        'cancelled_at', 'metadata',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'scheduled_for' => 'datetime',
        'opened_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
