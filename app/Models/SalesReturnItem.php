<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_return_id',
        'sales_order_item_id',
        'product_id',
        'variant_id',
        'quantity',
        'unit_price',
        'subtotal',
        'user_id',
        'seller_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected $appends = ['product_name', 'product_sku', 'user_name'];

    // ==================== RELATIONSHIPS ====================

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'seller_id');
    }

    // ==================== ACCESSORS ====================

    public function getProductNameAttribute(): string
    {
        return $this->product->name ?? 'Producto eliminado';
    }

    public function getProductSkuAttribute(): string
    {
        if ($this->variant) {
            return $this->variant->sku ?? '';
        }

        return $this->product->sku_base ?? '';
    }

    public function getUserNameAttribute(): ?string
    {
        return $this->user?->name;
    }
}
