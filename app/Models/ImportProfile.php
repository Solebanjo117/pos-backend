<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'entity',
        'mapping',
        'sheet_index',
        'sheet_name',
        'mode',
        'grouping_rules',
        'defaults',
    ];

    protected $casts = [
        'mapping' => 'array',
        'grouping_rules' => 'array',
        'defaults' => 'array',
        'sheet_index' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }
}
