<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDepositAllocation extends Model
{
    protected $fillable = ['financial_transfer_id', 'warehouse_id', 'source_scope', 'source_id', 'amount', 'released_at', 'released_by_transfer_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'released_at' => 'datetime'];
    }

    public function financialTransfer(): BelongsTo
    {
        return $this->belongsTo(FinancialTransfer::class);
    }
}
