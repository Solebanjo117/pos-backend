<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'source_type',
        'source_id',
        'source_movement_id',
        'batch_number',
        'received_quantity',
        'quantity_available',
        'unit_cost_base',
        'tax_per_unit',
        'misc_per_unit',
        'landed_unit_cost',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'warehouse_id' => 'integer',
            'source_id' => 'integer',
            'source_movement_id' => 'integer',
            'received_quantity' => 'integer',
            'quantity_available' => 'integer',
            'unit_cost_base' => 'decimal:4',
            'tax_per_unit' => 'decimal:4',
            'misc_per_unit' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_movement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryLotAllocation::class);
    }
}
