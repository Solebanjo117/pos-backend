<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleVin extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_id',
        'chassis_id',
        'engine_id',
        'vin',
        'notes',
    ];

    protected $casts = [
        'model_id' => 'integer',
        'chassis_id' => 'integer',
        'engine_id' => 'integer',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
    }

    public function chassis(): BelongsTo
    {
        return $this->belongsTo(VehicleChassis::class, 'chassis_id');
    }
}
