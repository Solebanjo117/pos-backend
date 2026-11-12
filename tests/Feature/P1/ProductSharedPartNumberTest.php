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
 * Un producto (la pieza, con su número de parte) puede venderse en varias marcas (variantes) que
 * comparten ese número: 11220-50Y00 de I&R, TOMITA y NAKAMOTO. El número es del producto.
 */
beforeEach(function () {
    $warehouse = Warehouse::create(['code' => 'W-PART', 'name' => 'Almacén']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $this->product = Product::create(['sku_base' => '11220-50Y00', 'name' => 'Soporte de motor', 'status' => 'active']);
    $this->variants = collect(['I&R', 'TOMITA', 'NAKAMOTO'])->map(function (string $brandName, int $index) {
        return ProductVariant::create([
            'product_id' => $this->product->id,
            'brand_id' => Brand::create(['name' => $brandName, 'status' => 'active'])->id,
            'sku' => '11220-50Y00',
            'status' => 'active',
            'is_default' => $index === 0,
        ]);
    });
});

function savePartVariant(ProductVariant $variant, array $codes)
{
    return test()->putJson("/api/v1/products/{$variant->product_id}/variants/{$variant->id}", [
        'brand_id' => $variant->brand_id,
        'status' => 'active',
        'sku' => $codes[0]['code'] ?? null,
        'codes' => $codes,
    ]);
}

it('lets every brand of the part be saved with the shared part number', function () {
    foreach ($this->variants as $variant) {
        savePartVariant($variant, [['code' => '11220-50Y00', 'type' => 'sku', 'is_primary' => true]])
            ->assertOk();
    }

    // Cada marca conserva su SKU (el número de parte) y no se lo apropia como código propio.
    foreach ($this->variants as $variant) {
        expect($variant->fresh()->sku)->toBe('11220-50Y00')
            ->and(ProductCode::query()->where('variant_id', $variant->id)->count())->toBe(0);
    }

    // El número de parte queda una sola vez, como código del producto.
    $productCodes = ProductCode::query()->where('product_id', $this->product->id)->whereNull('variant_id')->get();
    expect($productCodes)->toHaveCount(1)
        ->and($productCodes->first()->code)->toBe('11220-50Y00')
        ->and($productCodes->first()->is_primary)->toBeTrue()
        ->and($this->product->fresh()->sku_base)->toBe('11220-50Y00');
});

it('still stores a code of its own on the brand (e.g. its barcode)', function () {
    $tomita = $this->variants[1];

    savePartVariant($tomita, [
        ['code' => '11220-50Y00', 'type' => 'sku', 'is_primary' => true],
        ['code' => '4901234567894', 'type' => 'upc', 'is_primary' => false],
    ])->assertOk();

    $own = ProductCode::query()->where('variant_id', $tomita->id)->get();
    expect($own->pluck('code')->all())->toBe(['4901234567894'])
        ->and($tomita->fresh()->sku)->toBe('11220-50Y00')
        ->and($tomita->fresh()->upc)->toBe('4901234567894');
});

it('keeps the part number as the product code when the product itself is edited', function () {
    $this->putJson("/api/v1/products/{$this->product->id}", [
        'codes' => [['code' => '11220-50Y00', 'type' => 'sku', 'is_primary' => true]],
    ])->assertOk();

    expect($this->product->fresh()->sku_base)->toBe('11220-50Y00')
        ->and($this->variants[0]->fresh()->sku)->toBe('11220-50Y00')
        ->and(ProductCode::query()->whereNotNull('variant_id')->count())->toBe(0);
});

it('still finds the product by its part number', function () {
    $ids = collect($this->getJson('/api/v1/products?search=1122050Y00')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->product->id);
});
