<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalExpenseCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'subcategory',
        'default_ledger_account_id',
        'expense_kind',
        'affects_cash_flow',
        'affects_income_statement',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'affects_cash_flow' => 'boolean',
        'affects_income_statement' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function defaultLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'default_ledger_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OperationalExpense::class, 'category_id');
    }
}
