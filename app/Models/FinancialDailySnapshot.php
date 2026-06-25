<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialDailySnapshot extends Model
{
    protected $fillable = [
        'warehouse_id',
        'date',
        'opening_cash',
        'opening_inventory',
        'opening_accounts_receivable',
        'opening_accounts_payable',
        'cash_sales',
        'credit_sales',
        'cash_purchases',
        'credit_purchases',
        'customer_payments',
        'supplier_payments',
        'expenses',
        'cogs_cash_sales',
        'cogs_credit_sales',
        'investment_in',
        'investment_out',
        'cash_adjustments',
        'inventory_adjustments',
        'closing_cash',
        'closing_inventory',
        'accounts_receivable',
        'accounts_payable',
        'gross_profit',
        'net_profit',
        'net_investment',
        'is_locked',
        'computed_at',
        'version',
        'validation_warnings',
    ];

    protected $casts = [
        'date' => 'date',
        'opening_cash' => 'decimal:2',
        'opening_inventory' => 'decimal:2',
        'opening_accounts_receivable' => 'decimal:2',
        'opening_accounts_payable' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'credit_sales' => 'decimal:2',
        'cash_purchases' => 'decimal:2',
        'credit_purchases' => 'decimal:2',
        'customer_payments' => 'decimal:2',
        'supplier_payments' => 'decimal:2',
        'expenses' => 'decimal:2',
        'cogs_cash_sales' => 'decimal:2',
        'cogs_credit_sales' => 'decimal:2',
        'investment_in' => 'decimal:2',
        'investment_out' => 'decimal:2',
        'cash_adjustments' => 'decimal:2',
        'inventory_adjustments' => 'decimal:2',
        'closing_cash' => 'decimal:2',
        'closing_inventory' => 'decimal:2',
        'accounts_receivable' => 'decimal:2',
        'accounts_payable' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'net_investment' => 'decimal:2',
        'is_locked' => 'boolean',
        'computed_at' => 'datetime',
        'validation_warnings' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
