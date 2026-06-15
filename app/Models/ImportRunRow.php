<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRunRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_run_id',
        'row_number',
        'sheet_name',
        'status',
        'action_type',
        'execution_status',
        'label',
        'search_text',
        'product_group_key',
        'variant_key',
        'conflict_key',
        'brand_key',
        'category_key',
        'is_edited',
        'validated_at',
        'executed_at',
        'execution_error',
        'data',
        'source_data',
        'errors',
        'warnings',
        'conflicts',
        'change_preview',
    ];

    protected $casts = [
        'data' => 'array',
        'source_data' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'conflicts' => 'array',
        'change_preview' => 'array',
        'is_edited' => 'boolean',
        'validated_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }
}
