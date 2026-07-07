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

    public const STATUSES = [
        'paid' => 'Pagado',
        'pending' => 'Pendiente',
        'scheduled' => 'Programado',
    ];

    public const RECURRENCES = [
        'one_time' => 'Unico',
        'weekly' => 'Semanal',
        'monthly' => 'Mensual',
        'yearly' => 'Anual',
    ];

    public const EXPENSE_KINDS = [
        'operational' => 'Operativo',
        'administrative' => 'Administrativo',
        'financial' => 'Financiero',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'warehouse_id',
        'created_by',
        'cash_movement_id',
        'pos_session_id',
        'expense_date',
        'category',
        'category_id',
        'subcategory',
        'supplier_id',
        'ledger_account_id',
        'description',
        'amount',
        'payment_method',
        'recurrence',
        'status',
        'paid_at',
        'affects_cash_flow',
        'affects_income_statement',
        'expense_kind',
        'reference_number',
        'notes',
        'business_datetime',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'business_datetime' => 'datetime',
        'paid_at' => 'datetime',
        'affects_cash_flow' => 'boolean',
        'affects_income_statement' => 'boolean',
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

    public function categoryRecord(): BelongsTo
    {
        return $this->belongsTo(OperationalExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->categoryRecord?->name ?? self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return $this->payment_method === null ? null : PaymentMethod::getNameByCode($this->payment_method);
    }
}
