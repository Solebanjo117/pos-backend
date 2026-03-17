<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'folio',
        'customer_id',
        'warehouse_id',
        'created_by',
        'assigned_to',
        'status',
        'vehicle_description',
        'notes',
        'labour_cost',
        'estimated_delivery_at',
    ];

    protected $casts = [
        'customer_id'           => 'integer',
        'warehouse_id'          => 'integer',
        'created_by'            => 'integer',
        'assigned_to'           => 'integer',
        'labour_cost'           => 'decimal:2',
        'estimated_delivery_at' => 'datetime',
    ];

    public const VALID_STATUSES = ['pending', 'in_progress', 'done', 'delivered', 'cancelled'];

    public const STATUS_TRANSITIONS = [
        'pending'     => ['in_progress', 'cancelled'],
        'in_progress' => ['done', 'cancelled'],
        'done'        => ['delivered', 'cancelled'],
        'delivered'   => [],
        'cancelled'   => [],
    ];

    protected static function booted(): void
    {
        static::creating(function (WorkOrder $wo) {
            if (empty($wo->folio)) {
                $wo->folio = 'WO-' . strtoupper(uniqid());
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::STATUS_TRANSITIONS[$this->status] ?? []);
    }
}
