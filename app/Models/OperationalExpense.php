<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'draft' => 'Borrador',
        'paid' => 'Pagado',
        'pending' => 'Pendiente',
        'scheduled' => 'Programado',
        'queued' => 'En caja',
        'cancelled' => 'Cancelado',
        'reversed' => 'Reversado',
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
        'customer_id',
        'beneficiary_id',
        'ledger_account_id',
        'description',
        'amount',
        'estimated_amount',
        'final_amount',
        'variance_amount',
        'variance_reason',
        'payment_method',
        'planned_payment_method',
        'recurrence',
        'status',
        'paid_at',
        'due_at',
        'queued_at',
        'cancelled_at',
        'reversed_at',
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
        'estimated_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'business_datetime' => 'datetime',
        'paid_at' => 'datetime',
        'due_at' => 'datetime',
        'queued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reversed_at' => 'datetime',
        'affects_cash_flow' => 'boolean',
        'affects_income_statement' => 'boolean',
    ];

    protected $appends = ['category_label', 'payment_method_label', 'party', 'capabilities'];

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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(OperationalExpenseBeneficiary::class, 'beneficiary_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationalExpenseEvent::class)->latest();
    }

    public function queueEntries(): MorphMany
    {
        return $this->morphMany(CashierQueueEntry::class, 'source');
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->categoryRecord?->name ?? self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return $this->payment_method === null ? null : PaymentMethod::getNameByCode($this->payment_method);
    }

    /** @return array{type: string, id: int, name: string}|null */
    public function getPartyAttribute(): ?array
    {
        $party = match (true) {
            $this->customer_id !== null => ['type' => 'customer', 'model' => $this->customer],
            $this->supplier_id !== null => ['type' => 'supplier', 'model' => $this->supplier],
            $this->beneficiary_id !== null && $this->beneficiary?->user_id !== null => ['type' => 'staff', 'model' => $this->beneficiary],
            $this->beneficiary_id !== null => ['type' => 'beneficiary', 'model' => $this->beneficiary],
            default => null,
        };

        if (! $party || ! $party['model']) {
            return null;
        }

        return [
            'type' => $party['type'],
            'id' => (int) ($party['type'] === 'staff' ? $party['model']->user_id : $party['model']->id),
            'related_id' => (int) $party['model']->id,
            'name' => (string) $party['model']->name,
        ];
    }

    /** @return array{can_edit: bool, can_send_to_cashier: bool, can_cancel: bool, can_reverse: bool} */
    public function getCapabilitiesAttribute(): array
    {
        return [
            'can_edit' => in_array($this->status, ['draft', 'pending', 'scheduled'], true),
            'can_send_to_cashier' => in_array($this->status, ['pending', 'scheduled'], true),
            'can_cancel' => in_array($this->status, ['draft', 'pending', 'scheduled', 'queued'], true),
            'can_reverse' => $this->status === 'paid',
        ];
    }
}
