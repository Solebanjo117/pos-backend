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
        'engine_id',
        'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'engine_id' => 'integer',
    ];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the vehicle engine.
     */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
    }
}
