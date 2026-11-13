<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backfillProduct(string $skuBase, string $name = 'Producto'): Product
{
    return Product::create(['sku_base' => $skuBase, 'name' => $name, 'status' => 'active']);
}

it('only reports in simulation mode', function () {
    backfillProduct('56540-25000');

    $this->artisan('catalog:backfill-product-codes')
        ->expectsOutputToContain('Modo simulación')
        ->assertSuccessful();

    expect(ProductCode::count())->toBe(0);
});

it('creates the primary product-level code from the part number', function () {
    $product = backfillProduct(' 56540-25000 ');
    $brand = Brand::create(['name' => 'CTR', 'status' => 'active']);
    ProductVariant::create(['product_id' => $product->id, 'brand_id' => $brand->id, 'sku' => '56540-25000', 'status' => 'active', 'is_default' => true]);

    $this->artisan('catalog:backfill-product-codes --apply')->assertSuccessful();

    $code = ProductCode::sole();
    expect($code->product_id)->toBe($product->id)
        ->and($code->variant_id)->toBeNull()
        ->and($code->code)->toBe('56540-25000')
        ->and($code->normalized_code)->toBe('5654025000')
        ->and($code->type)->toBe('sku')
        ->and($code->is_primary)->toBeTrue()
        ->and($code->status)->toBe('active')
        ->and($product->fresh()->codes->pluck('code')->all())->toBe(['56540-25000']);
});

it('is safe to run twice', function () {
    backfillProduct('ABC-1');

    $this->artisan('catalog:backfill-product-codes --apply')->assertSuccessful();
    $this->artisan('catalog:backfill-product-codes --apply')->assertSuccessful();

    expect(ProductCode::count())->toBe(1);
});

it('skips products without a part number, archived products and products whose code already exists', function () {
    Product::create(['sku_base' => null, 'name' => 'Sin número', 'status' => 'active']);
    backfillProduct('ARCH-1')->delete();
    $withCode = backfillProduct('HAS-1');
    $brand = Brand::create(['name' => 'ENDO', 'status' => 'active']);
    $variant = ProductVariant::create(['product_id' => $withCode->id, 'brand_id' => $brand->id, 'sku' => 'HAS-1', 'status' => 'active', 'is_default' => true]);
    ProductCode::create(['product_id' => $withCode->id, 'variant_id' => $variant->id, 'code' => 'HAS-1', 'normalized_code' => 'HAS1', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    $this->artisan('catalog:backfill-product-codes --apply')->assertSuccessful();

    expect(ProductCode::count())->toBe(1);
});

it('does not create a code that another product already uses', function () {
    $first = backfillProduct('DUP-1');
    $second = backfillProduct('dup 1');

    $this->artisan('catalog:backfill-product-codes --apply')
        ->expectsOutputToContain('También en #'.$second->id)
        ->assertSuccessful();

    expect(ProductCode::count())->toBe(0);
});

it('no longer has products.alternate_code: secondary codes live in product_codes', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('products', 'alternate_code'))->toBeFalse();
});
