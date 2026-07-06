<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessDatetimeAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_type',
        'record_id',
        'old_business_datetime',
        'new_business_datetime',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'old_business_datetime' => 'datetime',
        'new_business_datetime' => 'datetime',
        'changed_at' => 'datetime',
    ];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
