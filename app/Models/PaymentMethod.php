<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'is_active',
        'is_system',
        'requires_reference',
        'requires_cash_count',
        'sort_order',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'is_system'           => 'boolean',
        'requires_reference'  => 'boolean',
        'requires_cash_count' => 'boolean',
        'sort_order'          => 'integer',
    ];

    /**
     * Scope: Only active payment methods
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Only system payment methods
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope: Order by sort_order
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope: Methods that require cash counting (opening/closing)
     */
    public function scopeRequiresCashCount(Builder $query): Builder
    {
        return $query->where('requires_cash_count', true);
    }

    /**
     * Get name by code (static helper for formatting payments)
     */
    public static function getNameByCode(string $code): string
    {
        static $cache = [];

        if (!isset($cache[$code])) {
            $method = static::where('code', $code)->first();
            $cache[$code] = $method?->name ?? ucfirst($code);
        }

        return $cache[$code];
    }

    /**
     * Generate code from name
     */
    public static function generateCode(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
