<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class PaymentMethod extends Model
{
    use HasFactory;

    public const CASH_CODE = 'cash';

    public const LIQUIDITY_CASH = 'cash';

    public const LIQUIDITY_BANK = 'bank';

    public const LIQUIDITY_CREDIT = 'credit';

    public const LIQUIDITY_STORE_CREDIT = 'store_credit';

    public const CONTEXT_SALES = 'sales';

    public const CONTEXT_CUSTOMER_RECEIPTS = 'customer_receipts';

    public const CONTEXT_SUPPLIER_PAYMENTS = 'supplier_payments';

    public const CONTEXT_PURCHASE_ORDERS = 'purchase_orders';

    public const CONTEXT_RETURNS = 'returns';

    public const DEFERRED_CODES = [
        'credit',
        'store_credit',
        'supplier_credit',
    ];

    private const CONTEXT_COLUMNS = [
        self::CONTEXT_SALES => 'available_in_sales',
        self::CONTEXT_CUSTOMER_RECEIPTS => 'available_in_customer_receipts',
        self::CONTEXT_SUPPLIER_PAYMENTS => 'available_in_supplier_payments',
        self::CONTEXT_PURCHASE_ORDERS => 'available_in_purchase_orders',
        self::CONTEXT_RETURNS => 'available_in_returns',
    ];

    protected $fillable = [
        'name',
        'code',
        'is_active',
        'is_system',
        'requires_reference',
        'requires_cash_count',
        'liquidity_bucket',
        'available_in_sales',
        'available_in_customer_receipts',
        'available_in_supplier_payments',
        'available_in_purchase_orders',
        'available_in_returns',
        'sort_order',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'is_system'           => 'boolean',
        'requires_reference'  => 'boolean',
        'requires_cash_count' => 'boolean',
        'available_in_sales' => 'boolean',
        'available_in_customer_receipts' => 'boolean',
        'available_in_supplier_payments' => 'boolean',
        'available_in_purchase_orders' => 'boolean',
        'available_in_returns' => 'boolean',
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

    public function scopeForContext(Builder $query, ?string $context): Builder
    {
        $column = static::contextColumn($context);

        if ($column === null || ! static::hasColumn($column)) {
            return $query;
        }

        return $query->where($column, true);
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
    public static function realMoneyCodes(?string $context = null): array
    {
        $defaultCodes = ['cash', 'card', 'transfer', 'other', 'yappy', 'ach', 'cheque'];
        $activeCodes = static::active()
            ->forContext($context)
            ->pluck('code')
            ->filter(fn (string $code): bool => ! static::isDeferredCode($code))
            ->values()
            ->all();

        if ($activeCodes === []) {
            return $defaultCodes;
        }

        return array_values(array_unique($activeCodes));
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

        if ($code === self::CASH_CODE) {
            return self::LIQUIDITY_CASH;
        }

        if (static::hasColumn('liquidity_bucket')) {
            $bucket = static::query()
                ->where('code', $code)
                ->value('liquidity_bucket');

            if (is_string($bucket) && $bucket !== '') {
                return $bucket;
            }
        }

        return self::LIQUIDITY_BANK;
    }

    public static function contextColumn(?string $context): ?string
    {
        return $context ? (self::CONTEXT_COLUMNS[$context] ?? null) : null;
    }

    public static function contextOptions(): array
    {
        return array_keys(self::CONTEXT_COLUMNS);
    }

    public function isAvailableForContext(?string $context): bool
    {
        $column = static::contextColumn($context);

        if ($column === null || ! static::hasColumn($column)) {
            return true;
        }

        return (bool) $this->{$column};
    }

    private static function hasColumn(string $column): bool
    {
        static $columns = [];

        if (! array_key_exists($column, $columns)) {
            $columns[$column] = Schema::hasColumn('payment_methods', $column);
        }

        return $columns[$column];
    }

    /**
     * Generate code from name
     */
    public static function generateCode(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
