<?php

namespace App\Helpers;

class PriceHelper
{
    /**
     * Round a price UP to the nearest increment.
     *
     * Examples with increment=1.00: 10.21 → 11, 10.00 → 10
     * Examples with increment=0.25: 10.21 → 10.25, 10.26 → 10.50
     * Examples with increment=0.50: 10.21 → 10.50, 10.51 → 11.00
     * Examples with increment=5.00: 10.21 → 15.00
     *
     * @param  float  $price  The raw price to round
     * @param  float  $increment  Rounding step (0.25, 0.50, 1.00, 5.00, 10.00)
     * @return float The rounded price
     */
    public static function roundUp(float $price, float $increment = 1.00): float
    {
        if ($increment <= 0) {
            return $price;
        }

        return ceil($price / $increment) * $increment;
    }
}
