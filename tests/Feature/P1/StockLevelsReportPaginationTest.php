<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('paginates stock level report products in batches of twenty five', function (): void {
    $warehouse = Warehouse::create([
        'code' => 'STK-001',
        'name' => 'Almacen Stock',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $brand = Brand::create([
        'name' => 'Marca Stock',
        'status' => 'active',
    ]);

    foreach (range(1, 30) as $index) {
        $product = Product::create([
            'sku_base' => 'STK-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'name' => 'Producto Stock '.$index,
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => $brand->id,
            'sku' => 'STK-VAR-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'status' => 'active',
            'is_default' => true,
        ]);

        ProductInventory::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity_available' => 10,
            'quantity_reserved' => 0,
            'reorder_point' => 2,
            'reorder_quantity' => 5,
        ]);
    }

    $this->actingAs($user)
        ->getJson('/api/v1/reports/stock-levels?page=1')
        ->assertOk()
        ->assertJsonCount(25, 'products')
        ->assertJsonPath('summary.total_products', 30)
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.total', 30)
        ->assertJsonPath('pagination.last_page', 2);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/stock-levels?page=2')
        ->assertOk()
        ->assertJsonCount(5, 'products')
        ->assertJsonPath('summary.total_products', 30)
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.total', 30)
        ->assertJsonPath('pagination.last_page', 2);
});
