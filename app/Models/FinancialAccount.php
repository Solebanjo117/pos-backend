<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    use HasFactory;

    public const TYPES = ['bank', 'petty_cash', 'other'];

    protected $fillable = [
        'name',
        'type',
        'institution_name',
        'masked_number',
        'currency',
        'opening_balance',
        'current_balance',
        'allow_negative_balance',
        'is_active',
        'is_legacy',
        'ledger_account_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'allow_negative_balance' => 'boolean',
            'is_active' => 'boolean',
            'is_legacy' => 'boolean',
        ];
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinancialAccountMovement::class);
    }
}
