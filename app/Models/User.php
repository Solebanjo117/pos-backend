<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Valid roles in the system.
     */
    public const VALID_ROLES = ['admin', 'manager', 'buyer', 'seller', 'cashier', 'assistant'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'current_warehouse_id',
        'role',
        'is_active',
        'can_manage_financial_adjustments',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_manage_financial_adjustments' => 'boolean',
        ];
    }

    /**
     * Check if the user has one of the given roles.
     */
    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($this->role, $roles);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'buyer';
    }

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function canManageFinancialAdjustments(): bool
    {
        return $this->is_active
            && $this->hasRole(['admin', 'manager'])
            && (bool) $this->can_manage_financial_adjustments;
    }

    /**
     * Get the current warehouse for this user.
     */
    public function currentWarehouse()
    {
        return $this->belongsTo(\App\Models\Warehouse::class, 'current_warehouse_id');
    }

    /**
     * Get all warehouses this user has access to.
     */
    public function warehouses()
    {
        return $this->belongsToMany(\App\Models\Warehouse::class, 'user_warehouse')
            ->withTimestamps();
    }

    /**
     * Check if the user has access to a specific warehouse.
     */
    public function hasWarehouseAccess(int $warehouseId): bool
    {
        // Admins always have access to all warehouses
        if ($this->isAdmin()) {
            return true;
        }

        return $this->warehouses()->where('warehouse_id', $warehouseId)->exists();
    }

    /**
     * Get the effective warehouse ID for the current user session.
     * Falls back to first allowed warehouse if current is not set or not allowed.
     */
    public function getEffectiveWarehouseId(): ?int
    {
        if ($this->current_warehouse_id && $this->hasWarehouseAccess($this->current_warehouse_id)) {
            return $this->current_warehouse_id;
        }
        // Fallback to first allowed warehouse
        $first = $this->warehouses()->first();

        return $first?->id;
    }
}
