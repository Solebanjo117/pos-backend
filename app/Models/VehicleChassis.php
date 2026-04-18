<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleChassis extends Model
{
    use HasFactory;

    protected $table = 'vehicle_chassis';

    protected $fillable = [
        'code',
        'name',
        'model_id',
        'year_from',
        'year_to',
        'notes',
    ];

    protected $casts = [
        'model_id' => 'integer',
    ];

    /**
     * Get the vehicle model that this chassis belongs to (optional).
     * Chassis can exist independently (similar to R21 for engines).
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Get all associated models for this chassis.
     */
    public function models(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModel::class, 'vehicle_chassis_model', 'chassis_id', 'model_id')
            ->withTimestamps();
    }

    /**
     * Get the product compatibility records for this chassis.
     */
    public function compatibility(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class, 'chassis_id');
    }

    /**
     * Get the compatible products via pivots.
     */
    public function compatibleProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_vehicle_compatibility', 'chassis_id', 'product_id')
            ->withPivot('notes', 'all_engines', 'all_chassis')
            ->withTimestamps();
    }

    /**
     * Get a human-readable description of this chassis.
     */
    public function getFullDescriptionAttribute(): string
    {
        $parts = array_filter([
            $this->code,
            $this->name,
            $this->year_from ? "{$this->year_from}–{$this->year_to}" : null,
        ]);

        return implode(' ', $parts);
    }
}
