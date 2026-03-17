<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'product_id',
        'variant_id',
        'warehouse_id',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'work_order_id' => 'integer',
        'product_id'    => 'integer',
        'variant_id'    => 'integer',
        'warehouse_id'  => 'integer',
        'quantity'      => 'integer',
        'unit_price'    => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
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
}
