<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CashOperationRequest extends Model
{
    public const TYPE_CASH_IN = 'cash_in';

    public const TYPE_CASH_OUT = 'cash_out';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_FUNDING = 'funding';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'warehouse_id',
        'requested_by',
        'operation_type',
        'amount',
        'notes',
        'operational_expense_id',
        'funding_category_id',
        'queue_entry_id',
        'cash_movement_id',
        'capital_movement_id',
        'status',
        'requested_at',
        'processed_by',
        'processed_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'idempotency_key',
        'request_hash',
    ];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function operationalExpense(): BelongsTo
    {
        return $this->belongsTo(OperationalExpense::class);
    }

    public function fundingCategory(): BelongsTo
    {
        return $this->belongsTo(FundingCategory::class);
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(CashierQueueEntry::class, 'queue_entry_id');
    }

    public function queueEntries(): MorphMany
    {
        return $this->morphMany(CashierQueueEntry::class, 'source');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function capitalMovement(): BelongsTo
    {
        return $this->belongsTo(CapitalMovement::class);
    }
}
