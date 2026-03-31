<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleEngine extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_id',
        'engine_code',
        'displacement',
        'fuel_type',
        'aspiration',
    ];

    protected $casts = [
        'model_id' => 'integer',
    ];

    /**
     * Get the model that owns the engine.
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Get the product compatibility records.
     */
    public function compatibility(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class, 'engine_id');
    }

    /**
     * Get the compatible products.
     */
    public function compatibleProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_vehicle_compatibility', 'engine_id', 'product_id')
            ->withPivot('notes')
            ->withTimestamps();
    }

    /**
     * Get full engine description.
     */
    public function getFullDescriptionAttribute(): string
    {
        $parts = array_filter([
            $this->displacement,
            $this->engine_code,
            $this->fuel_type,
        ]);
        
        return implode(' ', $parts);
    }
}
