<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRunRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_run_id',
        'model_type',
        'model_id',
        'action',
        'summary',
    ];

    protected $casts = [
        'summary' => 'array',
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
