<?php

namespace App\Helpers;

use App\Models\Setting;

class TaxRateHelper
{
    public const FALLBACK_RATE = 7;

    public static function defaultRate(): int
    {
        return self::normalize(Setting::get('store_tax_rate', self::FALLBACK_RATE), self::FALLBACK_RATE);
    }

    public static function resolve(mixed $value): int
    {
        return self::normalize($value, self::defaultRate());
    }

    public static function normalize(mixed $value, int $fallback = self::FALLBACK_RATE): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (! is_numeric($value)) {
            return $fallback;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    public static function amount(float $subtotal, mixed $rate = null): float
    {
        return round($subtotal * (self::resolve($rate) / 100), 2);
    }
}
