<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'pos_session_id',
        'cash_movement_id',
        'user_id',
        'amount',
        'method',
        'reference_number',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = ['formatted_amount', 'method_label'];

    // ==================== RELATIONSHIPS ====================

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== ACCESSORS ====================

    public function getFormattedAmountAttribute(): string
    {
        return '$'.number_format($this->amount, 2);
    }

    public function getMethodLabelAttribute(): string
    {
        return PaymentMethod::getNameByCode($this->method);
    }
}
