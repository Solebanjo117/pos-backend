<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethod extends Model
{
    use HasFactory;

    public const CASH_CODE = 'cash';

    public const DEFERRED_CODES = [
        'credit',
        'store_credit',
        'supplier_credit',
    ];

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

        if (! isset($cache[$code])) {
            $method = static::where('code', $code)->first();
            $cache[$code] = $method?->name ?? ucfirst($code);
        }

        return $cache[$code];
    }

    /**
     * @return array<int, string>
     */
    public static function realMoneyCodes(): array
    {
        $defaultCodes = ['cash', 'card', 'transfer', 'other', 'yappy', 'ach', 'cheque'];
        $activeCodes = static::active()
            ->pluck('code')
            ->filter(fn (string $code): bool => ! static::isDeferredCode($code))
            ->values()
            ->all();

        return array_values(array_unique(array_merge($defaultCodes, $activeCodes)));
    }

    public static function isDeferredCode(?string $code): bool
    {
        return $code === null || in_array($code, self::DEFERRED_CODES, true);
    }

    public static function isRealMoneyCode(?string $code): bool
    {
        return $code !== null && in_array($code, static::realMoneyCodes(), true);
    }

    public static function liquidityBucket(?string $code): ?string
    {
        if (static::isDeferredCode($code)) {
            return null;
        }

        return $code === self::CASH_CODE ? 'cash' : 'bank';
    }

    /**
     * Generate code from name
     */
    public static function generateCode(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
