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

    public const DIRECTION_INCREASE = 'increase';

    public const DIRECTION_DECREASE = 'decrease';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSED = 'reversed';

    public const TYPES = [
        self::TYPE_CASH => 'Efectivo',
        self::TYPE_BANK => 'Banco',
        self::TYPE_INVENTORY => 'Inventario',
        self::TYPE_SALES => 'Ventas',
        self::TYPE_PURCHASES => 'Compras',
        self::TYPE_EXPENSES => 'Gastos',
        self::TYPE_INVESTMENT => 'Inversión o aporte de capital',
        self::TYPE_ACCOUNTS_RECEIVABLE => 'Cuentas por cobrar',
        self::TYPE_ACCOUNTS_PAYABLE => 'Cuentas por pagar',
        self::TYPE_MANUAL_CORRECTION => 'Ajuste contable manual',
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
        'folio', 'idempotency_key', 'request_hash', 'warehouse_id', 'user_id',
        'type', 'direction', 'status', 'is_legacy', 'amount', 'reason', 'reference',
        'adjustment_date', 'notes', 'business_datetime', 'timezone', 'created_ip',
        'before_values', 'after_values', 'metadata', 'affected_ledger_account_id',
        'counterparty_ledger_account_id', 'pos_session_id', 'financial_account_id',
        'customer_id', 'sales_order_id', 'supplier_id', 'purchase_order_id',
        'product_id', 'variant_id', 'quantity', 'unit_cost', 'cash_movement_id',
        'financial_account_movement_id', 'inventory_movement_id',
        'account_ledger_entry_id', 'journal_entry_id', 'reversal_of_id',
        'reversed_by_adjustment_id',
    ];

    protected $appends = ['type_label'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'adjustment_date' => 'date',
            'business_datetime' => 'datetime',
            'is_legacy' => 'boolean',
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function affectedLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'affected_ledger_account_id');
    }

    public function counterpartyLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'counterparty_ledger_account_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    public function financialAccountMovement(): BelongsTo
    {
        return $this->belongsTo(FinancialAccountMovement::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function accountLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(AccountLedgerEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_adjustment_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function signedAmount(): float
    {
        return round((float) $this->amount * ($this->direction === self::DIRECTION_DECREASE ? -1 : 1), 2);
    }

    public static function flowKeyForType(string $type): ?string
    {
        return self::FLOW_KEYS[$type] ?? null;
    }
}
