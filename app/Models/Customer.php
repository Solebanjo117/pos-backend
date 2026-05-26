<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'name',
        'email',
        'phone',
        'tax_id',
        'tax_name',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'credit_limit',
        'credit_days',
        'current_balance',
        'contact_person',
        'contact_phone',
        'notes',
        'status',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'credit_days' => 'integer',
    ];

    protected $attributes = [
        'type' => 'individual',
        'status' => 'active',
        'country' => 'Panamá',
        'credit_limit' => 0,
        'credit_days' => 0,
        'current_balance' => 0,
    ];

    protected $appends = ['store_credit', 'has_store_credit'];

    /**
     * Get customer transactions
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerTransaction::class)->orderBy('transaction_date', 'desc');
    }

    /**
     * Get sales orders
     */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class);
    }

    /**
     * Check if customer has available credit
     */
    public function hasAvailableCredit(float $amount): bool
    {
        if ($this->credit_limit <= 0) {
            return false;
        }

        return ($this->current_balance + $amount) <= $this->credit_limit;
    }

    /**
     * Get available credit
     */
    public function getAvailableCredit(): float
    {
        return max(0, $this->credit_limit - $this->current_balance);
    }

    /**
     * Get store credit (saldo a favor - when current_balance is negative)
     */
    public function getStoreCreditAttribute(): float
    {
        return $this->current_balance < 0 ? abs((float) $this->current_balance) : 0.0;
    }

    /**
     * Check if customer has store credit (saldo a favor)
     */
    public function getHasStoreCreditAttribute(): bool
    {
        return $this->current_balance < 0;
    }

    /**
     * Check if customer is blocked
     */
    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    /**
     * Check if customer is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Generate unique customer code
     */
    public static function generateCode(): string
    {
        $lastCustomer = static::withTrashed()->latest('id')->first();
        $nextId = $lastCustomer ? $lastCustomer->id + 1 : 1;

        return 'CL'.str_pad($nextId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Scope for active customers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for customers with credit
     */
    public function scopeWithCredit($query)
    {
        return $query->where('credit_limit', '>', 0);
    }

    /**
     * Scope to search customers
     */
    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('phone', 'ilike', "%{$term}%")
                ->orWhere('tax_id', 'ilike', "%{$term}%");
        });
    }
}
