<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'payment_terms',
        'lead_time_days',
        'notes',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
        'lead_time_days' => 'integer',
    ];

    protected $attributes = [
        'status' => 'active',
        'country' => 'Panamá',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the products supplied by this supplier.
     */
    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    /**
     * Get the purchase orders for this supplier.
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // ==================== SCOPES ====================

    /**
     * Scope for active suppliers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to search suppliers.
     */
    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'ilike', "%{$term}%")
              ->orWhere('contact_name', 'ilike', "%{$term}%")
              ->orWhere('email', 'ilike', "%{$term}%")
              ->orWhere('phone', 'ilike', "%{$term}%");
        });
    }

    // ==================== ACCESSORS ====================

    /**
     * Get count of products supplied.
     */
    public function getProductCountAttribute(): int
    {
        return $this->productSuppliers()->count();
    }
}
