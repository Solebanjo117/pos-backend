<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductCodeNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create([
        'code' => 'W-CODE',
        'name' => 'Almacén códigos',
    ]);

    $user = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($this->warehouse->id);
    $this->actingAs($user);
});

it('normalizes commercial codes without creating partial identities', function () {
    $normalizer = app(ProductCodeNormalizer::class);

    expect($normalizer->normalize('45503-19155'))->toBe('4550319155')
        ->and($normalizer->normalize('45503 19155'))->toBe('4550319155')
        ->and($normalizer->normalize('45503.19155'))->toBe('4550319155')
        ->and($normalizer->normalize('1234'))->not->toBe($normalizer->normalize('123456'));
});

it('creates a product without inventing a commercial code', function () {
    $response = $this->postJson('/api/v1/products', [
        'name' => 'Producto sin código',
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.primary_code', null)
        ->assertJsonCount(0, 'data.codes');

    $product = Product::findOrFail($response->json('data.id'));
    expect($product->sku_base)->toBeNull()
        ->and($product->system_id)->not->toBeNull()
        ->and($product->codes()->count())->toBe(0);
});

it('stores multiple codes on the sellable variant with one primary', function () {
    $brand = Brand::factory()->create();

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Terminal dirección',
        'brand_id' => $brand->id,
        'codes' => [
            ['code' => '45503-19155', 'type' => 'sku', 'is_primary' => true],
            ['code' => '7501234567890', 'type' => 'upc', 'is_primary' => false],
            ['code' => 'ALT.45503', 'type' => 'alternate', 'is_primary' => false],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.primary_code', '45503-19155')
        ->assertJsonCount(3, 'data.codes');

    $variantId = $response->json('data.default_variant.id');
    expect(ProductCode::query()->where('variant_id', $variantId)->count())->toBe(3)
        ->and(ProductCode::query()->where('variant_id', $variantId)->where('is_primary', true)->count())->toBe(1);
});

it('blocks an equivalent code on another product', function () {
    $first = $this->postJson('/api/v1/products', [
        'name' => 'Producto original',
        'codes' => [
            ['code' => '45503-19155', 'type' => 'sku', 'is_primary' => true],
        ],
    ])->assertCreated();

    $this->postJson('/api/v1/products', [
        'name' => 'Producto duplicado',
        'codes' => [
            ['code' => '45503 19155', 'type' => 'manual', 'is_primary' => true],
        ],
    ])->assertStatus(409)
        ->assertJsonPath('code', 'PRODUCT_CODE_CONFLICT')
        ->assertJsonPath('data.product.id', $first->json('data.id'));

    expect(Product::query()->where('name', 'Producto duplicado')->exists())->toBeFalse();
});

it('searches every equivalent format but does not partially match longer codes', function () {
    $short = $this->postJson('/api/v1/products', [
        'name' => 'Código corto',
        'codes' => [['code' => '1234', 'type' => 'sku', 'is_primary' => true]],
    ])->assertCreated()->json('data.id');

    $long = $this->postJson('/api/v1/products', [
        'name' => 'Código largo',
        'codes' => [['code' => '123456', 'type' => 'sku', 'is_primary' => true]],
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/products?search=1234')
        ->assertOk()
        ->assertJsonPath('data.0.id', $short)
        ->assertJsonMissing(['id' => $long]);
});

it('switches the primary code without changing the product identity', function () {
    $created = $this->postJson('/api/v1/products', [
        'name' => 'Producto editable',
        'codes' => [
            ['code' => 'PRI-001', 'type' => 'sku', 'is_primary' => true],
            ['code' => 'ALT-001', 'type' => 'alternate', 'is_primary' => false],
        ],
    ])->assertCreated();

    $productId = $created->json('data.id');

    $this->putJson('/api/v1/products/'.$productId, [
        'codes' => [
            ['code' => 'PRI-001', 'type' => 'sku', 'is_primary' => false],
            ['code' => 'ALT-001', 'type' => 'alternate', 'is_primary' => true],
        ],
    ])->assertOk()->assertJsonPath('data.primary_code', 'ALT-001');

    expect(Product::findOrFail($productId)->sku_base)->toBe('ALT-001')
        ->and(Product::query()->count())->toBe(1);
});

it('audits legacy codes without writes and applies the backfill idempotently', function () {
    Product::factory()->create(['sku_base' => 'LEG-0001', 'name' => 'Producto heredado']);

    $this->artisan('products:reconcile-codes', ['--json' => true])
        ->assertSuccessful();
    expect(ProductCode::query()->count())->toBe(0);

    $this->artisan('products:reconcile-codes', ['--apply' => true, '--json' => true])
        ->assertSuccessful();
    $this->artisan('products:reconcile-codes', ['--apply' => true, '--json' => true])
        ->assertSuccessful();

    expect(ProductCode::query()->count())->toBe(1)
        ->and(ProductCode::query()->firstOrFail()->type)->toBe('legacy_generated');
});
