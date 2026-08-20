<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeWorkOrderUser(Warehouse $warehouse): User
{
    $user = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    return $user;
}

function makeProductWithInventory(Warehouse $warehouse, int $qty = 10): array
{
    $brand = Brand::create(['name' => 'Brand WO', 'status' => 'active']);
    $product = Product::create(['sku_base' => 'SKU-WO-'.uniqid(), 'name' => 'Producto WO', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'SKU-WO-V-'.uniqid(),
        'status' => 'active',
        'is_default' => true,
    ]);
    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => $qty,
        'quantity_reserved' => 0,
    ]);

    return compact('product', 'variant');
}

// ─────────────────────────────────────────────────────────────────────────────
// Tests
// ─────────────────────────────────────────────────────────────────────────────

it('creates a minimal work order (no customer, no items) and returns 201', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W1', 'name' => 'WO Warehouse 1']);
    $user = makeWorkOrderUser($warehouse);

    $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
            'vehicle_description' => 'Toyota Corolla 2020',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.warehouse_id', $warehouse->id);
});

it('auto-generates a folio when none is provided', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W2', 'name' => 'WO Warehouse 2']);
    $user = makeWorkOrderUser($warehouse);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
        ])
        ->assertStatus(201);

    expect($response->json('data.folio'))->toStartWith('WO-');
});

it('creates a work order with items and decrements inventory', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W3', 'name' => 'WO Warehouse 3']);
    $user = makeWorkOrderUser($warehouse);
    ['product' => $product, 'variant' => $variant] = makeProductWithInventory($warehouse, 10);

    $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 3,
                'unit_price' => 150.00,
            ]],
        ])
        ->assertStatus(201);

    $remaining = ProductInventory::where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available');

    expect($remaining)->toBe(7);
});

it('rejects work order creation when warehouse_id is missing', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W4', 'name' => 'WO Warehouse 4']);
    $user = makeWorkOrderUser($warehouse);

    $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'vehicle_description' => 'Honda Civic',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['warehouse_id']);
});

it('rejects work order when item references non-existent product', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W5', 'name' => 'WO Warehouse 5']);
    $user = makeWorkOrderUser($warehouse);

    $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => 999999,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
                'unit_price' => 100.00,
            ]],
        ])
        ->assertStatus(422);
});

it('rejects work order when requested quantity exceeds available stock', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W6', 'name' => 'WO Warehouse 6']);
    $user = makeWorkOrderUser($warehouse);
    ['product' => $product, 'variant' => $variant] = makeProductWithInventory($warehouse, 2);

    $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 5,
                'unit_price' => 100.00,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Stock insuficiente para uno o más productos.']);
});

it('lists work orders and returns paginated response', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W7', 'name' => 'WO Warehouse 7']);
    $user = makeWorkOrderUser($warehouse);

    WorkOrder::create([
        'warehouse_id' => $warehouse->id,
        'created_by' => $user->id,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/work-orders')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'current_page', 'total']);
});

it('allows transitioning from pending to in_progress', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W8', 'name' => 'WO Warehouse 8']);
    $user = makeWorkOrderUser($warehouse);

    $wo = WorkOrder::create([
        'warehouse_id' => $warehouse->id,
        'created_by' => $user->id,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/work-orders/{$wo->id}/transition", ['status' => 'in_progress'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'in_progress');
});

it('rejects invalid status transition', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W9', 'name' => 'WO Warehouse 9']);
    $user = makeWorkOrderUser($warehouse);

    $wo = WorkOrder::create([
        'warehouse_id' => $warehouse->id,
        'created_by' => $user->id,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/work-orders/{$wo->id}/transition", ['status' => 'delivered'])
        ->assertStatus(422);
});

it('restores inventory when work order is cancelled', function () {
    $warehouse = Warehouse::create(['code' => 'WO-W10', 'name' => 'WO Warehouse 10']);
    $user = makeWorkOrderUser($warehouse);
    ['product' => $product, 'variant' => $variant] = makeProductWithInventory($warehouse, 10);

    $createResponse = $this->actingAs($user)
        ->postJson('/api/v1/work-orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 4,
                'unit_price' => 80.00,
            ]],
        ])
        ->assertStatus(201);

    $woId = $createResponse->json('data.id');

    // After creation, stock should be 6 (10 - 4)
    expect(ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available')
    )->toBe(6);

    // Cancel the work order
    $this->actingAs($user)
        ->postJson("/api/v1/work-orders/{$woId}/transition", ['status' => 'cancelled'])
        ->assertStatus(200);

    // Stock should be restored to 10
    expect(ProductInventory::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available')
    )->toBe(10);
});
