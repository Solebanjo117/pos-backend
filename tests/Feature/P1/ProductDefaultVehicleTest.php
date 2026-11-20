<?php

use App\Models\Product;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $warehouse = Warehouse::create(['code' => 'W-DEF', 'name' => 'Almacén principal']);
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
    $this->engine = VehicleEngine::create(['make_id' => $this->toyota->id, 'engine_code' => '1ZZFE']);
    $this->engine->models()->sync([$this->corolla->id, $this->rav4->id]);
    // Otro motor para el RAV4: en SQLite (pruebas) sigue el índice antiguo (product_id, engine_id).
    $this->rav4Engine = VehicleEngine::create(['make_id' => $this->toyota->id, 'engine_code' => '1AZFE']);
    $this->rav4Engine->models()->sync([$this->rav4->id]);
});

function defaultVehicleRow(int $productId): ?ProductVehicleCompatibility
{
    return ProductVehicleCompatibility::where('product_id', $productId)->where('is_default', true)->sole();
}

it('marks the first vehicle as default automatically', function () {
    $product = Product::create(['sku_base' => 'DEF-1', 'name' => 'Terminal', 'status' => 'active']);
    $first = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->corolla->id]);
    $second = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->rav4->id]);

    expect(defaultVehicleRow($product->id)->id)->toBe($first->id)
        ->and($second->fresh()->is_default)->toBeFalse();
});

it('promotes the next oldest vehicle when the default one is deleted', function () {
    $product = Product::create(['sku_base' => 'DEF-2', 'name' => 'Terminal', 'status' => 'active']);
    $first = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->corolla->id]);
    $second = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->rav4->id]);

    $first->delete();

    expect(defaultVehicleRow($product->id)->id)->toBe($second->id);

    $second->delete();
    expect(ProductVehicleCompatibility::where('product_id', $product->id)->where('is_default', true)->exists())->toBeFalse();
});

it('never makes a universal record the default', function () {
    $product = Product::create(['sku_base' => 'DEF-3', 'name' => 'Aceite', 'status' => 'active']);
    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'universal', 'all_engines' => true]);

    expect(ProductVehicleCompatibility::where('product_id', $product->id)->where('is_default', true)->exists())->toBeFalse();

    $car = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->corolla->id]);
    expect(defaultVehicleRow($product->id)->id)->toBe($car->id);
});

it('saves the default vehicle chosen on screen and keeps it first', function () {
    $id = $this->postJson('/api/v1/products', [
        'name' => 'Terminal con principal',
        'vehicle_compatibility' => [
            'vehicles' => [
                ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id],
                ['model_id' => $this->rav4->id, 'engine_id' => $this->rav4Engine->id],
            ],
            'default' => ['model_id' => $this->rav4->id, 'engine_id' => $this->rav4Engine->id],
        ],
    ])->assertCreated()->json('data.id');

    expect(defaultVehicleRow($id)->model_id)->toBe($this->rav4->id);

    $vehicles = collect($this->getJson('/api/v1/products?per_page=50')->assertOk()->json('data'))
        ->firstWhere('id', $id)['vehicles'];
    expect($vehicles[0]['label'])->toBe('TOYOTA RAV4 A20 2000-2005');

    $this->getJson("/api/v1/products/{$id}/compatibility")
        ->assertOk()
        ->assertJsonPath('data.0.model_id', $this->rav4->id)
        ->assertJsonPath('data.0.is_default', true);
});

it('changes the default on update, also to a kept legacy record', function () {
    $product = Product::create(['sku_base' => 'DEF-4', 'name' => 'Terminal', 'status' => 'active']);
    $raw = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'raw', 'raw_vehicle_key' => 'corolla ae100', 'raw_vehicle_text' => 'Corolla AE100']);
    expect(defaultVehicleRow($product->id)->id)->toBe($raw->id);

    // Vehículo exacto como principal.
    $this->putJson("/api/v1/products/{$product->id}", [
        'vehicle_compatibility' => [
            'keep_ids' => [$raw->id],
            'vehicles' => [['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id]],
            'default' => ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id],
        ],
    ])->assertOk();
    expect(defaultVehicleRow($product->id)->model_id)->toBe($this->corolla->id);

    // De vuelta al registro anterior que se conserva.
    $this->putJson("/api/v1/products/{$product->id}", [
        'vehicle_compatibility' => [
            'keep_ids' => [$raw->id],
            'vehicles' => [['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id]],
            'default' => ['id' => $raw->id],
        ],
    ])->assertOk();
    expect(defaultVehicleRow($product->id)->id)->toBe($raw->id);
});

it('falls back to the oldest vehicle when the chosen default is invalid or missing', function () {
    $id = $this->postJson('/api/v1/products', [
        'name' => 'Terminal sin principal válido',
        'vehicle_compatibility' => [
            'vehicles' => [
                ['model_id' => $this->corolla->id, 'engine_id' => $this->engine->id],
                ['model_id' => $this->rav4->id, 'engine_id' => $this->rav4Engine->id],
            ],
            'default' => ['id' => 999999],
        ],
    ])->assertCreated()->json('data.id');

    expect(defaultVehicleRow($id)->model_id)->toBe($this->corolla->id);
});

it('does not allow two default vehicles for the same product', function () {
    $product = Product::create(['sku_base' => 'DEF-5', 'name' => 'Terminal', 'status' => 'active']);
    ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->corolla->id]);
    $second = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'model', 'model_id' => $this->rav4->id]);

    expect(fn () => ProductVehicleCompatibility::query()->whereKey($second->id)->update(['is_default' => true]))
        ->toThrow(QueryException::class);
});
