<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVehicleCompatibility;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Decisión de Luis (2026-11-24): la tabla de Productos muestra todas las categorías, números de
 * parte y carros, con el principal primero. El listado los entrega en ese orden y marca el carro
 * principal.
 */
it('lists every code, category and car of a product with the principal first', function () {
    $warehouse = Warehouse::create(['code' => 'W-CEL', 'name' => 'Almacén']);
    $user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $primaryCategory = Category::create(['name' => 'Terminales']);
    $extraCategory = Category::create(['name' => 'Suspensión']);
    $product = Product::create(['sku_base' => 'CEL-1', 'name' => 'Terminal celdas', 'status' => 'active', 'category_id' => $primaryCategory->id]);
    $product->extraCategories()->attach($extraCategory->id);

    ProductCode::create(['product_id' => $product->id, 'code' => 'ALT-9', 'normalized_code' => 'ALT9', 'type' => 'alternate', 'status' => 'active', 'is_primary' => false]);
    ProductCode::create(['product_id' => $product->id, 'code' => 'CEL-1', 'normalized_code' => 'CEL1', 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);

    // El primero queda como principal automáticamente; luego el principal pasa al segundo.
    $tiida = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'raw', 'raw_vehicle_text' => 'Nissan TIIDA', 'raw_vehicle_key' => 'nissan tiida']);
    $sentra = ProductVehicleCompatibility::create(['product_id' => $product->id, 'scope_type' => 'raw', 'raw_vehicle_text' => 'Nissan SENTRA B17', 'raw_vehicle_key' => 'nissan sentra b17']);
    $tiida->update(['is_default' => false]);
    $sentra->update(['is_default' => true]);

    $row = collect($this->getJson('/api/v1/products?search=CEL-1')->assertOk()->json('data'))->firstWhere('id', $product->id);

    expect(collect($row['vehicles'])->map(fn ($v) => [$v['label'], $v['primary']])->all())
        ->toBe([['Nissan SENTRA B17', true], ['Nissan TIIDA', false]])
        ->and(collect($row['codes'])->sortByDesc('is_primary')->pluck('code')->values()->all())->toBe(['CEL-1', 'ALT-9'])
        ->and($row['category']['name'])->toBe('Terminales')
        ->and(collect($row['extra_categories'])->pluck('name')->all())->toBe(['Suspensión']);
});
