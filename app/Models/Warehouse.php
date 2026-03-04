<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'location',
        'capacity',
        'status',
        'notes',
    ];

    protected $casts = [
        'capacity' => 'integer',
    ];

    /**
     * Get the inventory records for the warehouse.
     */
    /**
     * Get the inventory records for the warehouse.
     */
    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Get the batch inventory records for the warehouse.
     */
    public function warehouseBatches(): HasMany
    {
        return $this->hasMany(WarehouseBatch::class);
    }
}
