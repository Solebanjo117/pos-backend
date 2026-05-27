<?php

use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('paginates movement history report movements in batches of twenty five', function (): void {
    $warehouse = Warehouse::create([
        'code' => 'RPT-001',
        'name' => 'Almacen Reportes',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $brand = Brand::create([
        'name' => 'Marca Reportes',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'RPT-MOV',
        'name' => 'Producto Reporte Movimientos',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'RPT-MOV-001',
        'status' => 'active',
        'is_default' => true,
    ]);

    foreach (range(1, 30) as $index) {
        InventoryMovement::create([
            'movement_type' => 'in',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'reference_type' => 'test',
            'reference_id' => $index,
            'user_id' => $user->id,
            'created_at' => now()->subMinutes($index),
            'updated_at' => now()->subMinutes($index),
        ]);
    }

    $this->actingAs($user)
        ->getJson('/api/v1/reports/movement-history?date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString().'&page=1')
        ->assertOk()
        ->assertJsonCount(25, 'movements')
        ->assertJsonPath('summary.total_movements', 30)
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.total', 30)
        ->assertJsonPath('pagination.last_page', 2);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/movement-history?date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString().'&page=2')
        ->assertOk()
        ->assertJsonCount(5, 'movements')
        ->assertJsonPath('summary.total_movements', 30)
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.total', 30)
        ->assertJsonPath('pagination.last_page', 2);
});
