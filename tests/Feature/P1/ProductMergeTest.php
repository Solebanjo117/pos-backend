<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCode;
use App\Models\ProductInventory;
use App\Models\ProductMedia;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\ProductMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * Productos repetidos: la misma pieza (número de parte) registrada en fichas distintas, cada una con
 * otra marca. Al unirlas, las marcas pasan a un solo producto con su inventario e historial.
 */
beforeEach(function () {
    $this->warehouse = Warehouse::create(['code' => 'W-MRG', 'name' => 'Almacén']);
    $make = VehicleMake::create(['name' => 'Make Mrg '.uniqid()]);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Model Mrg', 'year_from' => 2020, 'year_to' => 2025]);
    $this->engineA = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => 'ENG-A', 'displacement' => '1.5L']);
    $this->engineB = VehicleEngine::create(['model_id' => $model->id, 'engine_code' => 'ENG-B', 'displacement' => '2.0L']);
    $this->brands = collect(['555', 'ENDO', 'CTR', 'JPN'])->mapWithKeys(fn ($name) => [$name => Brand::create(['name' => $name, 'status' => 'active'])]);
});

function mergePart(string $name, string $partNumber, array $brands): Product
{
    $product = Product::create(['sku_base' => $partNumber, 'name' => $name, 'status' => 'active']);
    foreach (array_values($brands) as $index => $brand) {
        ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => test()->brands[$brand]->id,
            'sku' => $partNumber,
            'status' => 'active',
            'is_default' => $index === 0,
        ]);
    }

    return $product;
}

it('moves the brands with their stock, prices and history into the kept product', function () {
    $kept = mergePart('Terminal L200', '4410A173T', ['555', 'ENDO']);
    $duplicate = mergePart('Terminal L200 CRM-36', '4410-A173T', ['CTR']);
    $ctr = $duplicate->variants()->first();

    ProductInventory::create(['product_id' => $duplicate->id, 'variant_id' => $ctr->id, 'warehouse_id' => $this->warehouse->id, 'quantity_available' => 4]);
    ProductPricing::create(['product_id' => $duplicate->id, 'variant_id' => $ctr->id, 'base_price' => 25, 'cost' => 10]);
    ProductCode::create(['product_id' => $duplicate->id, 'variant_id' => $ctr->id, 'code' => '7701', 'normalized_code' => '7701', 'type' => 'upc', 'status' => 'active', 'is_primary' => true]);
    ProductCode::create(['product_id' => $kept->id, 'variant_id' => null, 'code' => '4410A173T', 'normalized_code' => '4410A173T', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);
    ProductCode::create(['product_id' => $duplicate->id, 'variant_id' => null, 'code' => 'CRM-36', 'normalized_code' => 'CRM36', 'type' => 'alternate', 'status' => 'active', 'is_primary' => true]);
    ProductMedia::create(['product_id' => $duplicate->id, 'media_type' => 'image', 'file_path' => 'p/ctr.jpg', 'original_filename' => 'ctr.jpg', 'file_size' => 10, 'display_order' => 1]);
    ProductAttribute::create(['product_id' => $kept->id, 'name' => 'Rosca', 'value' => 'M16x1.50']);
    ProductAttribute::create(['product_id' => $duplicate->id, 'name' => 'rosca', 'value' => 'M16x1.5']);
    ProductAttribute::create(['product_id' => $duplicate->id, 'name' => 'Lado', 'value' => 'LH RH']);
    ProductVehicleCompatibility::create(['product_id' => $kept->id, 'engine_id' => $this->engineA->id, 'all_engines' => false, 'all_chassis' => false, 'is_default' => true]);
    ProductVehicleCompatibility::create(['product_id' => $duplicate->id, 'engine_id' => $this->engineA->id, 'all_engines' => false, 'all_chassis' => false, 'is_default' => true]);
    ProductVehicleCompatibility::create(['product_id' => $duplicate->id, 'engine_id' => $this->engineB->id, 'all_engines' => false, 'all_chassis' => false]);

    $result = app(ProductMergeService::class)->merge($kept->id, [$duplicate->id]);

    expect($result['variants_moved'])->toBe(1)
        ->and($kept->variants()->pluck('brand_id')->sort()->values()->all())
        ->toBe($this->brands->only(['555', 'ENDO', 'CTR'])->pluck('id')->sort()->values()->all());

    $ctr->refresh();
    expect($ctr->product_id)->toBe($kept->id)
        ->and($ctr->is_default)->toBeFalse()
        ->and($ctr->sku)->toBe('4410-A173T')
        ->and(ProductInventory::where('variant_id', $ctr->id)->value('product_id'))->toBe($kept->id)
        ->and((float) ProductInventory::where('variant_id', $ctr->id)->value('quantity_available'))->toBe(4.0)
        ->and(ProductPricing::where('variant_id', $ctr->id)->value('product_id'))->toBe($kept->id)
        ->and(ProductCode::where('variant_id', $ctr->id)->value('product_id'))->toBe($kept->id)
        ->and(ProductMedia::where('file_path', 'p/ctr.jpg')->value('product_id'))->toBe($kept->id);

    // El código del producto repetido pasa como secundario; el principal sigue siendo el del conservado.
    expect(ProductCode::where('product_id', $kept->id)->whereNull('variant_id')->where('is_primary', true)->pluck('code')->all())->toBe(['4410A173T'])
        ->and(ProductCode::where('normalized_code', 'CRM36')->value('product_id'))->toBe($kept->id);

    // Atributos y vehículos: se agregan los que faltan, sin repetir, y el carro por defecto no cambia.
    expect(ProductAttribute::where('product_id', $kept->id)->pluck('value', 'name')->all())->toBe(['Rosca' => 'M16x1.50', 'Lado' => 'LH RH'])
        ->and(ProductVehicleCompatibility::where('product_id', $kept->id)->count())->toBe(2);
    if (Schema::hasColumn('product_vehicle_compatibility', 'is_default')) {
        expect(ProductVehicleCompatibility::where('product_id', $kept->id)->where('is_default', true)->pluck('engine_id')->all())->toBe([$this->engineA->id]);
    }

    expect(Product::find($duplicate->id))->toBeNull()
        ->and(Product::withTrashed()->find($duplicate->id)->trashed())->toBeTrue();
});

it('refuses to merge products that have the same brand', function () {
    $a = mergePart('Terminal Accent', '56540-22000', ['CTR', 'ENDO']);
    $b = mergePart('Terminal Accent', '56540-22000', ['CTR']);

    expect(fn () => app(ProductMergeService::class)->merge($a->id, [$b->id]))
        ->toThrow(RuntimeException::class, 'CTR');

    expect($b->fresh()->variants()->count())->toBe(1)
        ->and($b->fresh()->trashed())->toBeFalse();
});

it('groups products linked through any shared part number and keeps the one with most brands', function () {
    $a = mergePart('Terminal Civic', '53521-TBA-A01', ['555']);
    $b = mergePart('Terminal Civic', '53521-TBA-A01', ['ENDO', 'JPN']);
    $c = mergePart('Terminal Civic', '53521TBAA01', ['CTR']);
    mergePart('Otra pieza', '99999-00000', ['555']);

    $groups = app(ProductMergeService::class)->findGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['product_ids'])->toBe([$a->id, $b->id, $c->id])
        ->and($groups[0]['survivor_id'])->toBe($b->id)
        ->and($groups[0]['conflict'])->toBeNull();
});

it('only reports in dry run and merges with --apply, leaving conflicting groups alone', function () {
    $kept = mergePart('Terminal Yaris', '45503-0D460', ['555', 'ENDO']);
    $duplicate = mergePart('Terminal Yaris', '45503-0D460', ['JPN']);
    $clashA = mergePart('Terminal Accent', '56540-25000', ['CTR', 'ENDO']);
    $clashB = mergePart('Terminal Accent', '56540-25000', ['CTR']);

    $this->artisan('catalog:merge-duplicate-products')->assertSuccessful();
    expect(Product::count())->toBe(4);

    $this->artisan('catalog:merge-duplicate-products', ['--apply' => true])
        ->expectsOutputToContain('Grupos unidos: 1. Marcas movidas: 1.')
        ->assertSuccessful();

    expect(Product::pluck('id')->sort()->values()->all())->toBe([$kept->id, $clashA->id, $clashB->id])
        ->and($kept->variants()->count())->toBe(3);
});

it('moves brands loaded in the wrong product to the right one, with their stock', function () {
    $wrong = mergePart('Terminal Accent', '56540-22000', ['CTR', 'ENDO', 'JPN']);
    $right = mergePart('Terminal Accent', '56540-25000', ['555']);
    $endo = $wrong->variants()->where('brand_id', $this->brands['ENDO']->id)->first();
    $jpn = $wrong->variants()->where('brand_id', $this->brands['JPN']->id)->first();
    ProductInventory::create(['product_id' => $wrong->id, 'variant_id' => $endo->id, 'warehouse_id' => $this->warehouse->id, 'quantity_available' => 3]);

    $moved = app(ProductMergeService::class)->moveVariantsTo($right->id, [$endo->id, $jpn->id]);

    expect($moved)->toBe(2)
        ->and($endo->fresh()->product_id)->toBe($right->id)
        ->and($jpn->fresh()->product_id)->toBe($right->id)
        ->and(ProductInventory::where('variant_id', $endo->id)->value('product_id'))->toBe($right->id)
        ->and($wrong->variants()->count())->toBe(1)
        ->and($right->variants()->count())->toBe(3);
});

it('refuses to move a brand into a product that already has it', function () {
    $wrong = mergePart('Terminal Accent', '56540-22000', ['CTR', 'ENDO']);
    $right = mergePart('Terminal Accent', '56540-25000', ['CTR']);
    $ctr = $wrong->variants()->where('brand_id', $this->brands['CTR']->id)->first();

    expect(fn () => app(ProductMergeService::class)->moveVariantsTo($right->id, [$ctr->id]))
        ->toThrow(RuntimeException::class, 'CTR');
    expect($ctr->fresh()->product_id)->toBe($wrong->id);
});
