<?php

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function productForPosPreparation(string $name, float $price, Warehouse $warehouse, int $stock): Product
{
    $product = Product::factory()->create(['name' => $name]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_default' => true]);
    ProductPricing::create(['product_id' => $product->id, 'variant_id' => $variant->id, 'base_price' => $price]);
    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => $stock,
        'quantity_reserved' => 0,
    ]);

    return $product;
}

it('applies the POS in-stock filter to the effective warehouse and real variant inventory', function (): void {
    $warehouseA = Warehouse::create(['code' => 'W-POS-PREP-A', 'name' => 'POS Preparation A']);
    $warehouseB = Warehouse::create(['code' => 'W-POS-PREP-B', 'name' => 'POS Preparation B']);
    $admin = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouseA->id, 'is_active' => true]);
    $admin->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

    $visible = productForPosPreparation('Disponible A', 10, $warehouseA, 3);
    productForPosPreparation('Disponible B', 20, $warehouseB, 4);

    $this->actingAs($admin)->getJson('/api/v1/products?warehouse_id='.$warehouseA->id.'&in_stock=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.total_stock', 3);
});

it('applies price filters and sorting sent by the POS product picker', function (): void {
    $warehouse = Warehouse::create(['code' => 'W-POS-PRICE', 'name' => 'POS Price']);
    $admin = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $admin->warehouses()->attach($warehouse->id);

    $cheap = productForPosPreparation('Producto Economico', 10, $warehouse, 2);
    $expensive = productForPosPreparation('Producto Costoso', 25, $warehouse, 2);

    $this->actingAs($admin)->getJson('/api/v1/products?min_price=20&max_price=30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $expensive->id);

    $this->actingAs($admin)->getJson('/api/v1/products?sort=price_desc')
        ->assertOk()
        ->assertJsonPath('data.0.id', $expensive->id)
        ->assertJsonPath('data.1.id', $cheap->id);
});

it('rejects invalid POS product filter contracts', function (): void {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->actingAs($admin)->getJson('/api/v1/products?min_price=20&max_price=10&sort=unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['max_price', 'sort']);
});
