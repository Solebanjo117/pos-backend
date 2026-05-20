<?php

use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryFifoDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a complete fifo history demo scenario for inventory operations', function () {
    $this->seed(InventoryFifoDemoSeeder::class);

    $product = Product::query()->where('sku_base', 'ALT-12V-90A')->firstOrFail();
    $variant = ProductVariant::query()->where('sku', 'ALT-12V-90A-VAL')->firstOrFail();
    $warehouse = Warehouse::query()->where('code', 'WH-CDMX-01')->firstOrFail();

    expect(ProductInventory::query()
        ->where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('quantity_available'))->toBe(17);

    expect(InventoryLot::query()
        ->where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('quantity_available', '>', 0)
        ->count())->toBe(2);

    expect(InventoryMovement::query()
        ->where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('movement_type', 'in')
        ->count())->toBe(2);

    $saleMovement = InventoryMovement::query()
        ->where('product_id', $product->id)
        ->where('variant_id', $variant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('movement_type', 'out')
        ->firstOrFail();

    expect(InventoryLotAllocation::query()
        ->where('inventory_movement_id', $saleMovement->id)
        ->sum('quantity'))->toBe(5);

    $user = User::query()->where('email', 'admin@hermes.com')->firstOrFail();

    $this->actingAs($user)
        ->getJson("/api/v1/products/{$product->id}/variants/{$variant->id}/inventory-history?warehouse_id={$warehouse->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.stock_available', 17)
        ->assertJsonPath('data.summary.current_cost', fn ($value) => is_numeric($value) && (float) $value > 0)
        ->assertJsonPath('data.summary.open_lots_count', 2)
        ->assertJsonCount(2, 'data.lots')
        ->assertJsonCount(2, 'data.purchase_movements')
        ->assertJsonCount(1, 'data.sale_movements')
        ->assertJsonPath('data.sale_movements.0.lot_allocations.0.quantity', 5)
        ->assertJsonPath('data.sale_movements.0.fifo_allocations.0.quantity', 5);
});
