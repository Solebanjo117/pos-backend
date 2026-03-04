<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'make_id',
        'name',
        'year_from',
        'year_to',
    ];

    protected $casts = [
        'make_id' => 'integer',
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    /**
     * Get the make that owns the model.
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    /**
     * Get the engines for this model.
     */
    public function engines(): HasMany
    {
        return $this->hasMany(VehicleEngine::class, 'model_id');
    }

    /**
     * Get the year range as a formatted string.
     */
    public function getYearRangeAttribute(): string
    {
        return "{$this->year_from}-{$this->year_to}";
    }
}
