<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductPricing;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'W-TAX',
        'name' => 'Warehouse Tax',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($this->warehouse->id);
    $this->actingAs($user);

    $this->category = Category::create([
        'name' => 'Filtros Tax Split',
        'slug' => 'filtros-tax-split-'.uniqid(),
        'priority' => 1,
    ]);

    $this->brand = Brand::create([
        'name' => 'Brand Tax '.uniqid(),
        'status' => 'active',
    ]);
});

function baseProductPayload(Brand $brand, Category $category, array $pricingOverrides = []): array
{
    return [
        'name' => 'Producto Tax Split',
        'sku_base' => 'TAX-'.strtoupper(substr(uniqid(), -6)),
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'sku' => 'TAX-VAR-'.strtoupper(substr(uniqid(), -6)),
        'pricing' => array_merge([
            'base_price' => 100,
            'msrp' => 120,
            'cost' => 60,
            'tax_rate' => 7,
        ], $pricingOverrides),
    ];
}

it('stores split sale and cost tax rates for product pricing', function () {
    $payload = baseProductPayload($this->brand, $this->category, [
        'sale_tax_rate' => 9,
        'cost_tax_rate' => 5,
    ]);

    $response = $this->postJson('/api/v1/products', $payload);

    $response->assertStatus(201);

    $productId = $response->json('data.id');
    expect($productId)->not->toBeNull();

    $pricing = ProductPricing::where('product_id', $productId)
        ->whereNotNull('variant_id')
        ->firstOrFail();

    expect((int) $pricing->sale_tax_rate)->toBe(9)
        ->and((int) $pricing->cost_tax_rate)->toBe(5)
        ->and((int) $pricing->tax_rate)->toBe(9);
});

it('maps legacy tax_rate into split fields when creating products', function () {
    $payload = baseProductPayload($this->brand, $this->category, [
        'tax_rate' => 8,
    ]);

    $response = $this->postJson('/api/v1/products', $payload);

    $response->assertStatus(201);

    $productId = $response->json('data.id');
    expect($productId)->not->toBeNull();

    $pricing = ProductPricing::where('product_id', $productId)
        ->whereNotNull('variant_id')
        ->firstOrFail();

    expect((int) $pricing->sale_tax_rate)->toBe(8)
        ->and((int) $pricing->cost_tax_rate)->toBe(8)
        ->and((int) $pricing->tax_rate)->toBe(8);
});

it('keeps tax_rate alias synced to sale_tax_rate when updating variant pricing', function () {
    $createResponse = $this->postJson('/api/v1/products', baseProductPayload($this->brand, $this->category));
    $createResponse->assertStatus(201);

    $productId = (int) $createResponse->json('data.id');
    $variantId = (int) $createResponse->json('data.default_variant.id');

    expect($productId)->toBeGreaterThan(0)
        ->and($variantId)->toBeGreaterThan(0);

    $updateResponse = $this->putJson("/api/v1/products/{$productId}/variants/{$variantId}", [
        'pricing' => [
            'base_price' => 125,
            'msrp' => 145,
            'cost' => 72,
            'sale_tax_rate' => 13,
            'cost_tax_rate' => 4,
        ],
    ]);

    $updateResponse->assertStatus(200);

    $pricing = ProductPricing::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->firstOrFail();

    expect((int) $pricing->sale_tax_rate)->toBe(13)
        ->and((int) $pricing->cost_tax_rate)->toBe(4)
        ->and((int) $pricing->tax_rate)->toBe(13);
});
