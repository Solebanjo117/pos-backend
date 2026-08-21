<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'normalized_name',
        'vendor_external_id',
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

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier): void {
            $supplier->normalized_name = self::normalizeName($supplier->name);
        });
    }

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

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function operationalExpenses(): HasMany
    {
        return $this->hasMany(OperationalExpense::class);
    }

    /**
     * Get brands associated with this supplier.
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withTimestamps();
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

    public static function normalizeName(string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
