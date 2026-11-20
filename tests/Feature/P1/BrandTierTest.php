<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]));
});

it('creates a premium brand and returns its tier', function (): void {
    $this->postJson('/api/v1/brands', [
        'name' => 'Marca Premium',
        'tier' => 'premium',
    ])->assertCreated();

    $brand = Brand::query()->where('name', 'Marca Premium')->firstOrFail();
    expect($brand->tier)->toBe('premium');

    $this->getJson("/api/v1/brands/{$brand->id}")
        ->assertOk()
        ->assertJsonPath('data.tier', 'premium');
    $this->getJson('/api/v1/brands/all')
        ->assertOk()
        ->assertJsonPath('data.0.tier', 'premium');
});

it('defaults to standard when the tier is not sent', function (): void {
    $this->postJson('/api/v1/brands', ['name' => 'Marca Sin Gama'])->assertCreated();
    $quickBrand = Brand::query()->create(['name' => 'Marca Rapida']);

    expect(Brand::query()->where('name', 'Marca Sin Gama')->value('tier'))->toBe('standard')
        ->and($quickBrand->fresh()->tier)->toBe('standard');
});

it('rejects a tier other than standard or premium', function (): void {
    $this->postJson('/api/v1/brands', ['name' => 'Marca Rara', 'tier' => 'gold'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tier' => 'La gama de la marca debe ser Standard o Premium.']);

    $this->postJson('/api/v1/brands', ['name' => 'Marca Vacia', 'tier' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tier');
});

it('changes the tier when the brand is updated without touching other fields', function (): void {
    $brand = Brand::query()->create([
        'name' => 'Marca Editable',
        'website' => 'https://ejemplo.com',
        'logo_url' => 'https://ejemplo.com/logo.png',
    ]);

    $this->putJson("/api/v1/brands/{$brand->id}", [
        'name' => 'Marca Editable',
        'tier' => 'premium',
    ])->assertOk();

    $brand->refresh();
    expect($brand->tier)->toBe('premium')
        ->and($brand->website)->toBe('https://ejemplo.com')
        ->and($brand->logo_url)->toBe('https://ejemplo.com/logo.png');
});

it('includes the brand tier in the product variants list', function (): void {
    $brand = Brand::query()->create(['name' => 'Marca Variante', 'tier' => 'premium']);
    $product = Product::query()->create(['name' => 'Producto Gama', 'sku_base' => 'GAMA-1', 'status' => 'active']);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'GAMA-1-V',
        'status' => 'active',
    ]);

    $this->getJson("/api/v1/products/{$product->id}/variants")
        ->assertOk()
        ->assertJsonPath('data.0.id', $variant->id)
        ->assertJsonPath('data.0.brand.name', 'Marca Variante')
        ->assertJsonPath('data.0.brand.tier', 'premium');
});
