<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OemReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'oem_code',
        'manufacturer',
    ];

    protected $casts = [
        'product_id' => 'integer',
    ];

    /**
     * Get the product that owns the OEM reference.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
