<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCode>
 */
class ProductCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'code' => fake()->unique()->bothify('SKU-####-????'),
            'normalized_code' => fn (array $attributes) => preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper($attributes['code'])),
            'type' => 'sku',
            'status' => 'active',
            'is_primary' => true,
            'metadata' => null,
        ];
    }
}
