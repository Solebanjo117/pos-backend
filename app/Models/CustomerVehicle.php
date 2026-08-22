<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'vehicle_model_id',
        'vehicle_chassis_id',
        'vehicle_engine_id',
        'vehicle_vin_id',
        'vin',
        'year',
        'plate_number',
        'label',
        'notes',
        'status',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'vehicle_model_id' => 'integer',
        'vehicle_chassis_id' => 'integer',
        'vehicle_engine_id' => 'integer',
        'vehicle_vin_id' => 'integer',
        'year' => 'integer',
    ];

    protected $appends = ['display_name', 'make'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function chassis(): BelongsTo
    {
        return $this->belongsTo(VehicleChassis::class, 'vehicle_chassis_id');
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'vehicle_engine_id');
    }

    public function catalogVin(): BelongsTo
    {
        return $this->belongsTo(VehicleVin::class, 'vehicle_vin_id');
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->model?->make?->name,
            $this->model?->name,
            $this->model?->generation,
            $this->chassis?->code,
            $this->engine?->engine_code,
            $this->vin,
        ]);

        return implode(' ', $parts);
    }

    public function getMakeAttribute(): ?VehicleMake
    {
        return $this->model?->make;
    }
}
