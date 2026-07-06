<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalExpense extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'electricity' => 'Luz',
        'accounting' => 'Contador',
        'rent' => 'Alquiler',
        'maintenance' => 'Mantenimiento',
        'software' => 'Programa mensual',
        'payroll' => 'Planilla',
        'utilities' => 'Servicios',
        'other' => 'Otro',
    ];

    public const PAYMENT_METHODS = [
        'cash',
        'card',
        'transfer',
        'yappy',
        'ach',
        'cheque',
        'other',
    ];

    protected $fillable = [
        'warehouse_id',
        'created_by',
        'cash_movement_id',
        'pos_session_id',
        'expense_date',
        'category',
        'description',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
        'business_datetime',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'business_datetime' => 'datetime',
    ];

    protected $appends = ['category_label', 'payment_method_label'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return $this->payment_method === null ? null : PaymentMethod::getNameByCode($this->payment_method);
    }
}
