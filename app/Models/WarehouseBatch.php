<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_batch_id',
        'quantity',
        'quantity_reserved',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quantity_reserved' => 'decimal:2',
    ];

    /**
     * Get the warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the product batch.
     */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
