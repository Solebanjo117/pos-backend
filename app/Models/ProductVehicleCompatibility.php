<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVehicleCompatibility extends Model
{
    use HasFactory;

    protected $table = 'product_vehicle_compatibility';

    protected $fillable = [
        'product_id',
        'scope_type',
        'make_id',
        'model_id',
        'engine_id',
        'chassis_id',
        'notes',
        'all_engines',
        'all_chassis',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'make_id' => 'integer',
        'model_id' => 'integer',
        'engine_id' => 'integer',
        'chassis_id' => 'integer',
        'all_engines' => 'boolean',
        'all_chassis' => 'boolean',
    ];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the vehicle engine (nullable — record may be chassis-only).
     */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Get the vehicle chassis (nullable — record may be engine-only).
     */
    public function chassis(): BelongsTo
    {
        return $this->belongsTo(VehicleChassis::class, 'chassis_id');
    }
}
