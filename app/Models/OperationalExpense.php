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
        'other',
    ];

    protected $fillable = [
        'warehouse_id',
        'created_by',
        'cash_movement_id',
        'expense_date',
        'category',
        'description',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
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

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return match ($this->payment_method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'other' => 'Otro',
            default => $this->payment_method,
        };
    }
}
