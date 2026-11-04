<?php

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create(['code' => 'W-FIT', 'name' => 'Almacén compatibilidad']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $this->toyota = VehicleMake::create(['name' => 'TOYOTA']);
    $this->corolla = VehicleModel::create(['make_id' => $this->toyota->id, 'name' => 'COROLLA', 'generation' => 'E110', 'year_from' => 1998, 'year_to' => 1999]);
    $this->rav4 = VehicleModel::create(['make_id' => $this->toyota->id, 'name' => 'RAV4', 'generation' => 'A20', 'year_from' => 2000, 'year_to' => 2005]);
    $this->engine = VehicleEngine::create(['make_id' => $this->toyota->id, 'engine_code' => '1ZZFE', 'displacement' => '1.8L', 'valves' => '16V', 'cam_type' => 'DOHC']);
    $this->engine->models()->sync([$this->corolla->id, $this->rav4->id]);
});

function corollaPart(): Product
{
    $product = Product::create(['sku_base' => 'TERM-E110', 'name' => 'Terminal cremallera', 'status' => 'active']);
    ProductVehicleCompatibility::create([
        'product_id' => $product->id,
        'scope_type' => 'vehicle',
        'make_id' => test()->toyota->id,
        'model_id' => test()->corolla->id,
        'engine_id' => test()->engine->id,
    ]);

    return $product;
}

function productIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('creates a product with exact vehicles (model + engine)', function () {
    $id = $this->postJson('/api/v1/products', [
        'name' => 'Terminal nueva',
        'vehicle_compatibility' => ['vehicles' => [
            ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id],
            ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id],
        ]],
    ])->assertCreated()->json('data.id');

    $rows = ProductVehicleCompatibility::where('product_id', $id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->only(['scope_type', 'make_id', 'model_id', 'engine_id']))->toBe([
            'scope_type' => 'vehicle',
            'make_id' => $this->toyota->id,
            'model_id' => $this->corolla->id,
            'engine_id' => $this->engine->id,
        ]);
});

it('rejects a model and engine pair that is not a registered vehicle', function () {
    $hilux = VehicleModel::create(['make_id' => $this->toyota->id, 'name' => 'HILUX', 'generation' => 'N80', 'year_from' => 1990, 'year_to' => 2003]);

    $this->postJson('/api/v1/products', [
        'name' => 'Terminal inválida',
        'vehicle_compatibility' => ['vehicles' => [['model_id' => $hilux->id, 'engine_id' => $this->engine->id]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['vehicle_compatibility.vehicles.0']);
});

it('keeps the listed legacy records and replaces only the exact vehicles on update', function () {
    $product = corollaPart();
    $raw = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'raw', 'raw_vehicle_key' => 'corolla ae100', 'raw_vehicle_text' => 'Corolla AE100']);
    $make = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'make', 'make_id' => $this->toyota->id]);

    $this->putJson("/api/v1/products/{$product->id}", [
        'vehicle_compatibility' => [
            'keep_ids' => [$raw->id],
            'vehicles' => [['model_id' => $this->rav4->id, 'engine_id' => $this->engine->id]],
        ],
    ])->assertOk();

    $rows = ProductVehicleCompatibility::where('product_id', $product->id)->get();
    expect($rows->pluck('id'))->toContain($raw->id)->not->toContain($make->id)
        ->and($rows->where('scope_type', 'vehicle')->pluck('model_id')->all())->toBe([$this->rav4->id])
        ->and($rows)->toHaveCount(2);
});

it('keeps the previous replace-all behaviour when keep_ids is not sent', function () {
    $product = corollaPart();
    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'make', 'make_id' => $this->toyota->id]);

    $this->putJson("/api/v1/products/{$product->id}", [
        'vehicle_compatibility' => ['engines' => [['engine_id' => $this->engine->id]]],
    ])->assertOk();

    expect(ProductVehicleCompatibility::where('product_id', $product->id)->pluck('scope_type')->all())->toBe(['engine']);
});

it('filters POS products by the customer vehicle using model and engine together', function () {
    $corollaPart = corollaPart();
    $generic = Product::create(['sku_base' => 'GEN-1', 'name' => 'Sin compatibilidad', 'status' => 'active']);

    $forCorolla = productIds($this->getJson("/api/v1/products?per_page=50&vehicle_engine_id={$this->engine->id}&vehicle_model_id={$this->corolla->id}")->assertOk());
    $forRav4 = productIds($this->getJson("/api/v1/products?per_page=50&vehicle_engine_id={$this->engine->id}&vehicle_model_id={$this->rav4->id}")->assertOk());
    $engineOnly = productIds($this->getJson("/api/v1/products?per_page=50&vehicle_engine_id={$this->engine->id}")->assertOk());

    expect($forCorolla)->toContain($corollaPart->id)->toContain($generic->id)
        ->and($forRav4)->not->toContain($corollaPart->id)->toContain($generic->id)
        ->and($engineOnly)->toContain($corollaPart->id);
});

it('ranks exact vehicles in the vehicle search and excludes other models with the same engine', function () {
    $corollaPart = corollaPart();

    $exact = $this->postJson('/api/v1/vehicles/search-products', ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id])->assertOk();
    expect(collect($exact->json('data'))->firstWhere('id', $corollaPart->id)['fitment_match_type'] ?? null)->toBe('exact');

    $byModel = $this->postJson('/api/v1/vehicles/search-products', ['model_id' => $this->corolla->id])->assertOk();
    expect(collect($byModel->json('data'))->firstWhere('id', $corollaPart->id)['fitment_match_type'] ?? null)->toBe('model');

    $rav4 = $this->postJson('/api/v1/vehicles/search-products', ['model_id' => $this->rav4->id, 'engine_id' => $this->engine->id])->assertOk();
    expect(productIds($rav4))->not->toContain($corollaPart->id);

    $byYear = $this->postJson('/api/v1/vehicles/search-products', ['model_id' => $this->corolla->id, 'year' => 1999])->assertOk();
    expect(productIds($byYear))->toContain($corollaPart->id);
});

it('finds exact vehicle products by model generation or engine in the text search', function () {
    $corollaPart = corollaPart();

    foreach (['e110', 'corolla', '1zzfe'] as $term) {
        expect(productIds($this->getJson("/api/v1/products?per_page=50&search={$term}")->assertOk()))
            ->toContain($corollaPart->id);
    }
});

it('stores an exact vehicle through the compatibility endpoint and rejects unknown pairs', function () {
    $product = Product::create(['sku_base' => 'API-1', 'name' => 'Por API', 'status' => 'active']);
    $payload = ['product_id' => $product->id, 'scope_type' => 'vehicle', 'model_id' => $this->corolla->id, 'engine_id' => $this->engine->id];

    $this->postJson('/api/v1/product-compatibility', $payload)->assertCreated()->assertJsonPath('data.make_id', $this->toyota->id);
    $this->postJson('/api/v1/product-compatibility', $payload)->assertOk();

    $other = VehicleEngine::create(['make_id' => $this->toyota->id, 'engine_code' => '2E']);
    $this->postJson('/api/v1/product-compatibility', [...$payload, 'engine_id' => $other->id])->assertUnprocessable();

    expect(ProductVehicleCompatibility::where('product_id', $product->id)->count())->toBe(1);
});

it('labels exact vehicles with generation and engine in the product list and detail', function () {
    $corollaPart = corollaPart();

    $listed = collect($this->getJson('/api/v1/products?per_page=50')->json('data'))->firstWhere('id', $corollaPart->id);
    expect($listed['vehicles'])->toBe([['label' => 'TOYOTA COROLLA E110 1998-1999', 'detail' => '1.8L 16V DOHC 1ZZFE']]);

    $detail = $this->getJson("/api/v1/products/{$corollaPart->id}?include=compatibility")->assertOk()->json('data.compatibility.0');
    expect($detail)->toMatchArray(['scope_type' => 'vehicle', 'generation' => 'E110', 'engine_code' => '1ZZFE']);
});
