<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]));
});

it('creates and updates a customer with real catalog vehicles atomically', function (): void {
    $type = CustomerType::query()->where('normalized_name', 'flota')->firstOrFail();
    $make = VehicleMake::query()->create(['name' => 'Toyota']);
    $model = VehicleModel::query()->create([
        'make_id' => $make->id,
        'name' => 'Hilux',
        'year_from' => 2016,
        'year_to' => 2026,
    ]);
    $engine = VehicleEngine::query()->create([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_code' => '2GD',
    ]);
    $engine->models()->syncWithoutDetaching([$model->id]);

    $response = $this->postJson('/api/v1/customers', [
        'customer_type_id' => $type->id,
        'name' => 'Flota del Istmo',
        'tax_name' => 'Flota del Istmo, S.A.',
        'tax_id' => '155-100-200',
        'sync_vehicles' => true,
        'vehicles' => [[
            'vehicle_model_id' => $model->id,
            'vehicle_engine_id' => $engine->id,
            'year' => 2024,
            'vin' => 'VIN-FLOTA-001',
            'plate_number' => 'AB 1234',
        ]],
    ])->assertCreated()
        ->assertJsonPath('type', 'business')
        ->assertJsonPath('customer_type_id', $type->id)
        ->assertJsonPath('vehicles.0.plate_number', 'AB 1234');

    $customerId = $response->json('id');
    $this->assertDatabaseHas('vehicle_vins', ['vin' => 'VIN-FLOTA-001']);
    $this->getJson('/api/v1/customers?search=VIN-FLOTA-001')
        ->assertOk()->assertJsonPath('data.0.id', $customerId);
    $this->getJson('/api/v1/customers?search=155-100-200')
        ->assertOk()->assertJsonPath('data.0.id', $customerId);

    $this->putJson("/api/v1/customers/{$customerId}", [
        'name' => 'Flota del Istmo Actualizada',
        'sync_vehicles' => true,
        'vehicles' => [],
    ])->assertOk();

    $this->assertDatabaseHas('customer_vehicles', [
        'customer_id' => $customerId,
        'status' => 'inactive',
    ]);
    $this->assertDatabaseHas('vehicle_vins', ['vin' => 'VIN-FLOTA-001']);
});

it('manages customer types and blocks deleting a type in use', function (): void {
    $type = $this->postJson('/api/v1/customer-types', [
        'name' => '  Talleres   aliados ',
        'base_kind' => 'business',
    ])->assertCreated()->json('data');

    expect($type['normalized_name'])->toBe('talleres aliados');

    $this->postJson('/api/v1/customers', [
        'customer_type_id' => $type['id'],
        'name' => 'Taller Central',
    ])->assertCreated();

    $this->deleteJson("/api/v1/customer-types/{$type['id']}")
        ->assertUnprocessable();
});

it('keeps an inactive type when editing and reactivates the same customer vehicle', function (): void {
    $type = CustomerType::query()->where('normalized_name', 'flota')->firstOrFail();
    $make = VehicleMake::query()->create(['name' => 'Nissan']);
    $model = VehicleModel::query()->create(['make_id' => $make->id, 'name' => 'Frontier', 'year_from' => 2015, 'year_to' => 2026]);
    $engine = VehicleEngine::query()->create(['make_id' => $make->id, 'model_id' => $model->id, 'engine_code' => 'YD25']);
    $engine->models()->syncWithoutDetaching([$model->id]);
    $customer = Customer::query()->create(['code' => 'CLI-9001', 'name' => 'Cliente Flota', 'customer_type_id' => $type->id, 'type' => 'business']);
    $vehicle = $customer->vehicles()->create(['vehicle_model_id' => $model->id, 'vehicle_engine_id' => $engine->id, 'vin' => 'VIN-REACTIVA', 'status' => 'inactive']);
    $type->update(['is_active' => false]);

    $this->putJson("/api/v1/customers/{$customer->id}", [
        'customer_type_id' => $type->id,
        'name' => 'Cliente Flota Editado',
        'sync_vehicles' => true,
        'vehicles' => [['vehicle_model_id' => $model->id, 'vehicle_engine_id' => $engine->id, 'vin' => 'VIN-REACTIVA']],
    ])->assertOk();

    expect($customer->vehicles()->count())->toBe(1);
    expect($vehicle->fresh()->status)->toBe('active');
});

it('validates every bulk record before modifying any supplier', function (): void {
    $brand = Brand::query()->create(['name' => 'Marca protegida']);
    $blocked = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor protegido', 'brand_ids' => [$brand->id]])->assertCreated()->json('data');
    $free = $this->postJson('/api/v1/suppliers', ['name' => 'Proveedor libre'])->assertCreated()->json('data');

    $this->postJson('/api/v1/supplier-catalog/bulk-action', [
        'entity' => 'supplier', 'action' => 'delete', 'ids' => [$blocked['id'], $free['id']],
    ])->assertConflict()->assertJsonPath('message', 'Ningún registro fue modificado.');

    $this->assertDatabaseHas('suppliers', ['id' => $free['id'], 'deleted_at' => null]);
    $this->getJson('/api/v1/supplier-catalog/summary')->assertOk()->assertJsonPath('data.suppliers', 2);
});

it('stores supplier brands, rejects normalized duplicates and exposes safe deletion', function (): void {
    $brand = Brand::query()->create(['name' => 'Bosch']);

    $supplier = $this->postJson('/api/v1/suppliers', [
        'name' => '  Repuestos   Panamá ',
        'brand_ids' => [$brand->id],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Repuestos Panamá')
        ->assertJsonPath('data.brands.0.id', $brand->id)
        ->json('data');

    $this->postJson('/api/v1/suppliers', [
        'name' => 'repuestos panamá',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->getJson("/api/v1/suppliers/{$supplier['id']}")
        ->assertOk()
        ->assertJsonPath('relationships.brands.0.source', 'configured');

    $this->deleteJson("/api/v1/suppliers/{$supplier['id']}")
        ->assertUnprocessable()->assertJsonValidationErrors(['supplier']);
});

it('resolves brand products through variants without querying products brand id', function (): void {
    $brand = Brand::query()->create(['name' => 'Marca por variante']);
    $product = Product::query()->create([
        'name' => 'Producto con marca en variante',
        'sku_base' => 'BRAND-VARIANT-BASE',
        'system_id' => 'BRANDVARIANT01',
        'status' => 'active',
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'BRAND-VARIANT-SKU',
        'status' => 'active',
    ]);

    $this->getJson('/api/v1/brands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $brand->id)
        ->assertJsonPath('data.0.has_products', true)
        ->assertJsonPath('data.0.can_delete', false);

    expect($brand->products()->whereKey($product->id)->exists())->toBeTrue();
    $this->deleteJson("/api/v1/brands/{$brand->id}")->assertUnprocessable();
});

it('returns catalog and customer capabilities that match effective roles', function (): void {
    $category = Category::query()->create(['name' => 'Categoría visible']);
    $brand = Brand::query()->create(['name' => 'Marca visible']);
    $customer = Customer::query()->create(['code' => 'CLI-ROLE', 'name' => 'Cliente visible']);
    $seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);

    $this->actingAs($seller)
        ->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonPath('data.0.id', $customer->id)
        ->assertJsonPath('data.0.can_edit', false)
        ->assertJsonPath('data.0.can_delete', false);
    $this->getJson('/api/v1/categories?per_page=15')
        ->assertOk()
        ->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.can_edit', false)
        ->assertJsonPath('data.0.can_delete', false);
    $this->getJson('/api/v1/brands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $brand->id)
        ->assertJsonPath('data.0.can_edit', false)
        ->assertJsonPath('data.0.can_delete', false);
    $this->postJson('/api/v1/customers/bulk-action', [
        'action' => 'delete',
        'ids' => [$customer->id],
    ])->assertForbidden();
});
