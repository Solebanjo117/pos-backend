<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\ProductPricing;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'W-COST',
        'name' => 'Warehouse Cost',
    ]);

    $user = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);

    $user->warehouses()->attach($this->warehouse->id);
    $this->actingAs($user);

    $this->category = Category::create([
        'name' => 'Cost Breakdown Category',
        'slug' => 'cost-breakdown-category-' . uniqid(),
        'priority' => 1,
    ]);

    $this->brandDefault = Brand::create([
        'name' => 'Brand Cost Default ' . uniqid(),
        'status' => 'active',
    ]);

    $this->brandVariant = Brand::create([
        'name' => 'Brand Cost Variant ' . uniqid(),
        'status' => 'active',
    ]);
});

function variantCostBaseProductPayload(Brand $brand, Category $category): array
{
    return [
        'name' => 'Producto Costo Base',
        'sku_base' => 'CST-' . strtoupper(substr(uniqid(), -6)),
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'sku' => 'CST-VAR-' . strtoupper(substr(uniqid(), -6)),
        'pricing' => [
            'base_price' => 100,
            'msrp' => 120,
            'cost' => 60,
            'tax_rate' => 7,
        ],
    ];
}

it('computes variant cost total from cost breakdown and syncs legacy cost alias', function () {
    $productResponse = $this->postJson('/api/v1/products', variantCostBaseProductPayload($this->brandDefault, $this->category));
    $productResponse->assertStatus(201);

    $productId = (int) $productResponse->json('data.id');

    $createVariantResponse = $this->postJson("/api/v1/products/{$productId}/variants", [
        'brand_id' => $this->brandVariant->id,
        'sku' => 'CST-BRK-' . strtoupper(substr(uniqid(), -6)),
        'pricing' => [
            'base_price' => 155,
            'msrp' => 165,
            'cost_base' => 100,
            'cost_tax_rate' => 7,
            'cost_miscellaneous' => 5,
            'sale_tax_rate' => 7,
        ],
    ]);

    $createVariantResponse->assertStatus(201);

    expect((float) $createVariantResponse->json('data.pricing.cost'))->toBe(112.0)
        ->and((float) $createVariantResponse->json('data.pricing.cost_total'))->toBe(112.0);

    $variantId = (int) $createVariantResponse->json('data.id');

    $pricing = ProductPricing::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->firstOrFail();

    expect((float) $pricing->cost)->toBe(112.0)
        ->and((float) $pricing->cost_total)->toBe(112.0)
        ->and((float) $pricing->cost_base)->toBe(100.0)
        ->and((float) $pricing->cost_miscellaneous)->toBe(5.0)
        ->and((int) $pricing->cost_tax_rate)->toBe(7)
        ->and((int) $pricing->sale_tax_rate)->toBe(7)
        ->and((int) $pricing->tax_rate)->toBe(7);

    $updateVariantResponse = $this->putJson("/api/v1/products/{$productId}/variants/{$variantId}", [
        'pricing' => [
            'base_price' => 240,
            'msrp' => 260,
            'cost_base' => 200,
            'cost_tax_rate' => 10,
            'cost_miscellaneous' => 20,
            'sale_tax_rate' => 7,
        ],
    ]);

    $updateVariantResponse->assertStatus(200);

    expect((float) $updateVariantResponse->json('data.pricing.cost'))->toBe(240.0)
        ->and((float) $updateVariantResponse->json('data.pricing.cost_total'))->toBe(240.0);

    $pricing->refresh();

    expect((float) $pricing->cost)->toBe(240.0)
        ->and((float) $pricing->cost_total)->toBe(240.0)
        ->and((float) $pricing->cost_base)->toBe(200.0)
        ->and((float) $pricing->cost_miscellaneous)->toBe(20.0)
        ->and((int) $pricing->cost_tax_rate)->toBe(10);
});

it('keeps legacy cost unchanged when cost_base is not explicitly provided', function () {
    $productResponse = $this->postJson('/api/v1/products', variantCostBaseProductPayload($this->brandDefault, $this->category));
    $productResponse->assertStatus(201);

    $productId = (int) $productResponse->json('data.id');

    $createVariantResponse = $this->postJson("/api/v1/products/{$productId}/variants", [
        'brand_id' => $this->brandVariant->id,
        'sku' => 'CST-LEG-' . strtoupper(substr(uniqid(), -6)),
        'pricing' => [
            'base_price' => 130,
            'msrp' => 140,
            'cost' => 100,
            'cost_tax_rate' => 7,
            'sale_tax_rate' => 7,
        ],
    ]);

    $createVariantResponse->assertStatus(201);

    expect((float) $createVariantResponse->json('data.pricing.cost'))->toBe(100.0)
        ->and((float) $createVariantResponse->json('data.pricing.cost_total'))->toBe(100.0);

    $variantId = (int) $createVariantResponse->json('data.id');

    $pricing = ProductPricing::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->firstOrFail();

    expect($pricing->getRawOriginal('cost_base'))->toBeNull()
        ->and((float) $pricing->cost)->toBe(100.0)
        ->and((float) $pricing->cost_total)->toBe(100.0);
});

it('creates an opening lot when creating a variant with initial stock', function () {
    $productResponse = $this->postJson('/api/v1/products', variantCostBaseProductPayload($this->brandDefault, $this->category));
    $productResponse->assertStatus(201);

    $productId = (int) $productResponse->json('data.id');

    $createVariantResponse = $this->postJson("/api/v1/products/{$productId}/variants", [
        'brand_id' => $this->brandVariant->id,
        'pricing' => [
            'base_price' => 175,
            'msrp' => 190,
            'cost_base' => 80,
            'cost_tax_rate' => 10,
            'cost_miscellaneous' => 4,
            'sale_tax_rate' => 7,
        ],
        'initial_inventory' => [[
            'warehouse_id' => $this->warehouse->id,
            'stock_available' => 7,
            'reorder_point' => 1,
            'reorder_quantity' => 2,
        ]],
    ]);

    $createVariantResponse->assertStatus(201);

    $variantId = (int) $createVariantResponse->json('data.id');

    $inventory = ProductInventory::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();

    $lot = InventoryLot::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();

    expect((int) $inventory->quantity_available)->toBe(7)
        ->and(InventoryLot::where('variant_id', $variantId)->count())->toBe(1)
        ->and((int) $lot->quantity_available)->toBe(7)
        ->and((int) $lot->received_quantity)->toBe(7)
        ->and($lot->source_type)->toBe('opening_balance')
        ->and((float) $lot->landed_unit_cost)->toBe(92.0);
});

it('does not create an opening lot when creating a variant with zero initial stock', function () {
    $productResponse = $this->postJson('/api/v1/products', variantCostBaseProductPayload($this->brandDefault, $this->category));
    $productResponse->assertStatus(201);

    $productId = (int) $productResponse->json('data.id');

    $createVariantResponse = $this->postJson("/api/v1/products/{$productId}/variants", [
        'brand_id' => $this->brandVariant->id,
        'pricing' => [
            'base_price' => 175,
            'msrp' => 190,
            'cost_base' => 80,
            'cost_tax_rate' => 10,
            'cost_miscellaneous' => 4,
            'sale_tax_rate' => 7,
        ],
        'initial_inventory' => [[
            'warehouse_id' => $this->warehouse->id,
            'stock_available' => 0,
            'reorder_point' => 1,
            'reorder_quantity' => 2,
        ]],
    ]);

    $createVariantResponse->assertStatus(201);

    $variantId = (int) $createVariantResponse->json('data.id');

    $inventory = ProductInventory::where('product_id', $productId)
        ->where('variant_id', $variantId)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();

    expect((int) $inventory->quantity_available)->toBe(0)
        ->and(InventoryLot::where('variant_id', $variantId)->count())->toBe(0);
});
