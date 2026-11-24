<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Los números de parte ("Código y estado" en Editar producto) son del PRODUCTO, aunque tenga una
 * sola marca. Cada marca (variante) solo guarda sus códigos propios, si los tiene.
 */
beforeEach(function () {
    $warehouse = Warehouse::create(['code' => 'W-OWN', 'name' => 'Almacén']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $this->product = Product::create(['sku_base' => '11206-864Y', 'name' => 'Terminal', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => Brand::create(['name' => '555', 'status' => 'active'])->id,
        'sku' => '11206-864Y',
        'status' => 'active',
        'is_default' => true,
    ]);
});

function productLevelCodes(Product $product): array
{
    return ProductCode::query()->active()->where('product_id', $product->id)->whereNull('variant_id')
        ->orderByDesc('is_primary')->orderBy('id')->pluck('code')->all();
}

function brandCodes(ProductVariant $variant): array
{
    return ProductCode::query()->active()->where('variant_id', $variant->id)->pluck('code')->all();
}

it('saves the product codes on the product when it has a single brand', function () {
    $this->putJson("/api/v1/products/{$this->product->id}", [
        'codes' => [
            ['code' => '11206-864Y', 'type' => 'sku', 'is_primary' => true],
            ['code' => 'OEM-555', 'type' => 'alternate', 'is_primary' => false],
        ],
    ])->assertOk();

    expect(productLevelCodes($this->product))->toBe(['11206-864Y', 'OEM-555'])
        ->and(brandCodes($this->variant))->toBe([])
        ->and($this->variant->fresh()->sku)->toBe('11206-864Y')
        ->and($this->product->fresh()->sku_base)->toBe('11206-864Y');
});

it('keeps the part number on the product when its only brand is saved', function () {
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => null, 'code' => '11206-864Y', 'normalized_code' => '11206864Y', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    $this->putJson("/api/v1/products/{$this->product->id}/variants/{$this->variant->id}", [
        'brand_id' => $this->variant->brand_id,
        'status' => 'active',
        'codes' => [
            ['code' => '11206-864Y', 'type' => 'sku', 'is_primary' => true],
            ['code' => 'SR-1234', 'type' => 'manual', 'is_primary' => false],
        ],
    ])->assertOk();

    expect(productLevelCodes($this->product))->toBe(['11206-864Y'])
        ->and(brandCodes($this->variant))->toBe(['SR-1234'])
        ->and($this->variant->fresh()->sku)->toBe('11206-864Y')
        ->and($this->product->fresh()->sku_base)->toBe('11206-864Y');
});

it('never changes the product part number from a brand', function () {
    $this->putJson("/api/v1/products/{$this->product->id}/variants/{$this->variant->id}", [
        'brand_id' => $this->variant->brand_id,
        'status' => 'active',
        'codes' => [['code' => 'SR-9999', 'type' => 'manual', 'is_primary' => true]],
    ])->assertOk();

    expect($this->product->fresh()->sku_base)->toBe('11206-864Y')
        ->and(brandCodes($this->variant))->toBe(['SR-9999']);
});

it('moves a part number saved on the brand (older data) back to the product', function () {
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'code' => '11206-864Y', 'normalized_code' => '11206864Y', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    $this->putJson("/api/v1/products/{$this->product->id}", [
        'codes' => [['code' => '11206-864Y', 'type' => 'sku', 'is_primary' => true]],
    ])->assertOk();

    expect(productLevelCodes($this->product))->toBe(['11206-864Y'])
        ->and(brandCodes($this->variant))->toBe([]);
});

it('gives every brand the new part number as SKU when the product number changes', function () {
    $other = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => Brand::create(['name' => 'CTR', 'status' => 'active'])->id,
        'sku' => 'CTR-OWN',
        'status' => 'active',
        'is_default' => false,
    ]);

    $this->putJson("/api/v1/products/{$this->product->id}", [
        'codes' => [['code' => '11206-864Z', 'type' => 'sku', 'is_primary' => true]],
    ])->assertOk();

    expect($this->product->fresh()->sku_base)->toBe('11206-864Z')
        ->and($this->variant->fresh()->sku)->toBe('11206-864Z')
        ->and($other->fresh()->sku)->toBe('11206-864Z');
});

it('shows the product codes in the product detail, not the brand ones', function () {
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => null, 'code' => '11206-864Y', 'normalized_code' => '11206864Y', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'code' => 'SR-1234', 'normalized_code' => 'SR1234', 'type' => 'manual', 'status' => 'active', 'is_primary' => true]);

    $this->getJson("/api/v1/products/{$this->product->id}")
        ->assertOk()
        ->assertJsonPath('data.primary_code', '11206-864Y')
        ->assertJsonCount(1, 'data.codes')
        ->assertJsonPath('data.codes.0.code', '11206-864Y');
});

it('does not flag the product part number as a duplicate while editing one of its brands', function () {
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => null, 'code' => '11206-864Y', 'normalized_code' => '11206864Y', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    $this->getJson('/api/v1/products/check-duplicate?'.http_build_query(['code' => '11206-864Y', 'exclude_variant_id' => $this->variant->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still flags a code that belongs to another product while editing a brand', function () {
    $other = Product::create(['sku_base' => 'OTRO-1', 'name' => 'Otro', 'status' => 'active']);
    ProductCode::create(['product_id' => $other->id, 'variant_id' => null, 'code' => 'OTRO-1', 'normalized_code' => 'OTRO1', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    $this->getJson('/api/v1/products/check-duplicate?'.http_build_query(['code' => 'OTRO-1', 'exclude_variant_id' => $this->variant->id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $other->id);
});

it('gives a new brand the product part number as its SKU when no code is sent', function () {
    $brand = Brand::create(['name' => 'CTR', 'status' => 'active']);

    $this->postJson("/api/v1/products/{$this->product->id}/variants", [
        'brand_id' => $brand->id,
        'sku' => '',
        'status' => 'active',
        'pricing' => ['base_price' => 10, 'cost' => 5],
    ])->assertSuccessful();

    $variant = ProductVariant::query()->where('brand_id', $brand->id)->sole();
    expect($variant->sku)->toBe('11206-864Y')
        ->and(brandCodes($variant))->toBe([]);
});

it('keeps the brand SKU and codes when the brand is saved without codes (prices only)', function () {
    $this->variant->forceFill(['sku' => 'SR-1234'])->save();
    ProductCode::create(['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'code' => 'SR-1234', 'normalized_code' => 'SR1234', 'type' => 'manual', 'status' => 'active', 'is_primary' => true]);

    $this->putJson("/api/v1/products/{$this->product->id}/variants/{$this->variant->id}", [
        'brand_id' => $this->variant->brand_id,
        'status' => 'active',
        'pricing' => ['base_price' => 12, 'cost' => 6],
    ])->assertOk();

    expect($this->variant->fresh()->sku)->toBe('SR-1234')
        ->and(brandCodes($this->variant))->toBe(['SR-1234']);
});

/*
 * Error encontrado por Luis (2026-11-24): al guardar el producto sin cambiar el principal, el
 * principal se perdía (se apagaban todos y el que ya era principal no se volvía a guardar).
 */
it('keeps the principal number when the product is saved again without changing it', function () {
    $payload = ['codes' => [
        ['code' => '11206-864Y', 'type' => 'sku', 'is_primary' => true],
        ['code' => 'OEM-555', 'type' => 'alternate', 'is_primary' => false],
    ]];

    $this->putJson("/api/v1/products/{$this->product->id}", $payload)->assertOk();
    $this->putJson("/api/v1/products/{$this->product->id}", $payload)->assertOk();

    expect(ProductCode::query()->active()->where('product_id', $this->product->id)->whereNull('variant_id')
        ->where('is_primary', true)->pluck('code')->all())->toBe(['11206-864Y']);
});

it('repairs products left without a principal number, preferring the product number', function () {
    ProductCode::create(['product_id' => $this->product->id, 'code' => 'OEM-1', 'normalized_code' => 'OEM1', 'type' => 'alternate', 'status' => 'active', 'is_primary' => false]);
    ProductCode::create(['product_id' => $this->product->id, 'code' => '11206-864Y', 'normalized_code' => '11206864Y', 'type' => 'sku', 'status' => 'active', 'is_primary' => false]);

    $this->artisan('catalog:fix-primary-codes')->expectsOutputToContain('Modo simulación')->assertSuccessful();
    expect(ProductCode::where('is_primary', true)->count())->toBe(0);

    $this->artisan('catalog:fix-primary-codes --apply')->assertSuccessful();
    expect(ProductCode::where('is_primary', true)->pluck('code')->all())->toBe(['11206-864Y']);

    $this->artisan('catalog:fix-primary-codes')->expectsOutputToContain('Todos los productos tienen')->assertSuccessful();
});
