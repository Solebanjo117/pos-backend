<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAdjustment extends Model
{
    public const TYPE_CASH = 'cash';

    public const TYPE_BANK = 'bank';

    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_SALES = 'sales';

    public const TYPE_PURCHASES = 'purchases';

    public const TYPE_EXPENSES = 'expenses';

    public const TYPE_INVESTMENT = 'investment';

    public const TYPE_ACCOUNTS_RECEIVABLE = 'accounts_receivable';

    public const TYPE_ACCOUNTS_PAYABLE = 'accounts_payable';

    public const TYPE_MANUAL_CORRECTION = 'manual_correction';

    public const TYPES = [
        self::TYPE_CASH => 'Caja',
        self::TYPE_BANK => 'Banco',
        self::TYPE_INVENTORY => 'Inventario',
        self::TYPE_SALES => 'Ventas',
        self::TYPE_PURCHASES => 'Compras',
        self::TYPE_EXPENSES => 'Gastos',
        self::TYPE_INVESTMENT => 'Inversion',
        self::TYPE_ACCOUNTS_RECEIVABLE => 'Cuentas por cobrar',
        self::TYPE_ACCOUNTS_PAYABLE => 'Cuentas por pagar',
        self::TYPE_MANUAL_CORRECTION => 'Correccion manual',
    ];

    public const FLOW_KEYS = [
        self::TYPE_CASH => 'cash_adjustments',
        self::TYPE_BANK => 'bank_adjustments',
        self::TYPE_INVENTORY => 'inventory_adjustments',
        self::TYPE_SALES => 'sales_adjustments',
        self::TYPE_PURCHASES => 'purchase_adjustments',
        self::TYPE_EXPENSES => 'expense_adjustments',
        self::TYPE_INVESTMENT => 'investment_adjustments',
        self::TYPE_ACCOUNTS_RECEIVABLE => 'accounts_receivable_adjustments',
        self::TYPE_ACCOUNTS_PAYABLE => 'accounts_payable_adjustments',
        self::TYPE_MANUAL_CORRECTION => 'manual_adjustments',
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

    public static function flowKeyForType(string $type): ?string
    {
        return self::FLOW_KEYS[$type] ?? null;
    }
}
