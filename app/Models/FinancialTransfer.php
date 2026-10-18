<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransfer extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSED = 'reversed';

    public const ENDPOINT_FINANCIAL_ACCOUNT = 'financial_account';

    public const ENDPOINT_POS_SESSION = 'pos_session';

    protected $fillable = [
        'folio',
        'idempotency_key',
        'request_hash',
        'from_endpoint_type',
        'from_endpoint_id',
        'to_endpoint_type',
        'to_endpoint_id',
        'amount',
        'reference',
        'notes',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime',
        'receipt_size',
        'status',
        'business_datetime',
        'created_by',
        'reversal_of_id',
        'reversed_by_transfer_id',
        'collection_date_from',
        'collection_date_to',
    ];

    protected $hidden = ['receipt_path', 'request_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'business_datetime' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_transfer_id');
    }

    public function depositAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CashDepositAllocation::class);
    }
}
