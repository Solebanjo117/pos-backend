<?php

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationalInventoryContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'INV-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Inventario',
    ]);

    $otherWarehouse = Warehouse::create([
        'code' => 'INV-ALT-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Alterno',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach([$warehouse->id, $otherWarehouse->id]);

    $brand = Brand::create([
        'name' => 'Shell',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'ACE-10W30',
        'name' => 'Aceite 10W30 cuarto mineral',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'SH-10W30-QT',
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductPricing::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'base_price' => 12.50,
        'msrp' => 12.50,
        'cost' => 0,
        'margin_percentage' => 0,
        'tax_rate' => 0,
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 0,
        'quantity_reserved' => 2,
        'reorder_point' => 5,
        'reorder_quantity' => 12,
    ]);

    /** @var InventoryFifoService $fifo */
    $fifo = app(InventoryFifoService::class);

    $fifo->receiveStock([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
        'unit_cost' => 5.35,
        'sale_price' => 11.25,
        'source_type' => 'purchase_order',
        'reference_type' => 'purchase_order',
        'reference_id' => 501,
        'received_at' => now()->subDays(3),
        'reason' => 'Recepcion OC',
        'user_id' => $user->id,
    ]);

    $fifo->receiveStock([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 10,
        'unit_cost' => 6.42,
        'sale_price' => 13.75,
        'source_type' => 'purchase_order',
        'reference_type' => 'purchase_order',
        'reference_id' => 502,
        'received_at' => now()->subDay(),
        'reason' => 'Recepcion OC',
        'user_id' => $user->id,
    ]);

    $fifo->consumeStock([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 12,
        'reference_type' => 'sale',
        'reference_id' => 9001,
        'reason' => 'Venta',
        'user_id' => $user->id,
        'strict' => true,
    ]);

    return compact('warehouse', 'otherWarehouse', 'user', 'brand', 'product', 'variant');
}

it('lists operational inventory by variant with neutral valuation fields and legacy aliases', function () {
    $context = operationalInventoryContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory?warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonPath('data.0.product_id', $context['product']->id)
        ->assertJsonPath('data.0.variant_id', $context['variant']->id)
        ->assertJsonPath('data.0.variant_sku', 'SH-10W30-QT')
        ->assertJsonPath('data.0.brand', 'Shell')
        ->assertJsonPath('data.0.stock_available', 8)
        ->assertJsonPath('data.0.quantity_available', 8)
        ->assertJsonPath('data.0.quantity_reserved', 2)
        ->assertJsonPath('data.0.current_cost', 6.42)
        ->assertJsonPath('data.0.current_sale_price', 13.75)
        ->assertJsonPath('data.0.inventory_value', 51.36)
        ->assertJsonPath('data.0.fifo_cost_current', 6.42)
        ->assertJsonPath('data.0.fifo_sale_price_current', 13.75)
        ->assertJsonPath('data.0.fifo_stock_value', 51.36)
        ->assertJsonPath('data.0.open_lots_count', 1)
        ->assertJsonPath('data.0.last_received_at', fn ($value) => is_string($value) && $value !== '')
        ->assertJsonPath('data.0.last_sold_at', fn ($value) => is_string($value) && $value !== '');
});

it('filters operational inventory by warehouse and product variant search', function () {
    $context = operationalInventoryContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory?warehouse_id='.$context['otherWarehouse']->id)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory?search=SH-10W30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.variant_id', $context['variant']->id);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory?search=Shell')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.brand', 'Shell');
});

it('filters operational inventory by variant id and warehouse', function () {
    $context = operationalInventoryContext();

    $otherBrand = Brand::create([
        'name' => 'Pennzoil',
        'status' => 'active',
    ]);

    $otherVariant = ProductVariant::create([
        'product_id' => $context['product']->id,
        'brand_id' => $otherBrand->id,
        'sku' => 'PZ-10W30-QT',
        'status' => 'active',
    ]);

    ProductInventory::create([
        'product_id' => $context['product']->id,
        'variant_id' => $otherVariant->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity_available' => 0,
        'quantity_reserved' => 0,
        'reorder_point' => 5,
        'reorder_quantity' => 12,
    ]);

    app(InventoryFifoService::class)->receiveStock([
        'product_id' => $context['product']->id,
        'variant_id' => $otherVariant->id,
        'warehouse_id' => $context['warehouse']->id,
        'quantity' => 6,
        'unit_cost' => 7.15,
        'source_type' => 'purchase_order',
        'reference_type' => 'purchase_order',
        'reference_id' => 503,
        'received_at' => now(),
        'reason' => 'Recepcion OC',
        'user_id' => $context['user']->id,
    ]);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory?warehouse_id='.$context['warehouse']->id.'&variant_id='.$context['variant']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.variant_id', $context['variant']->id)
        ->assertJsonPath('data.0.variant_sku', 'SH-10W30-QT');
});

it('returns operational inventory summary with neutral inventory value and legacy alias', function () {
    $context = operationalInventoryContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonPath('data.total_stock', 8)
        ->assertJsonPath('data.total_inventory_value', 51.36)
        ->assertJsonPath('data.total_fifo_value', 51.36)
        ->assertJsonPath('data.low_stock_count', 0)
        ->assertJsonPath('data.open_lots_count', 1)
        ->assertJsonPath('data.movements_today', 3);
});

it('lists open inventory lots for the lots tab with neutral and legacy query aliases', function () {
    $context = operationalInventoryContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory/batches?lots=1&warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_id', $context['product']->id)
        ->assertJsonPath('data.0.variant_id', $context['variant']->id)
        ->assertJsonPath('data.0.product_name', 'Aceite 10W30 cuarto mineral')
        ->assertJsonPath('data.0.variant_sku', 'SH-10W30-QT')
        ->assertJsonPath('data.0.brand', 'Shell')
        ->assertJsonPath('data.0.quantity_available', 8)
        ->assertJsonPath('data.0.landed_unit_cost', 6.42)
        ->assertJsonPath('data.0.remaining_value', 51.36)
        ->assertJsonPath('data.0.source_type', 'purchase_order')
        ->assertJsonPath('data.0.source_id', 502);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory/batches?open_lots=1&warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', fn ($value) => is_int($value));

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory/batches?fifo=1&warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', fn ($value) => is_int($value));
});

it('updates an available inventory lot stock costs date and sale price without rewriting historical allocations', function () {
    $context = operationalInventoryContext();
    $lot = InventoryLot::query()
        ->where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('quantity_available', '>', 0)
        ->firstOrFail();

    $response = $this->actingAs($context['user'])
        ->patchJson("/api/v1/inventory/lots/{$lot->id}", [
            'received_at' => now()->subDays(10)->toDateTimeString(),
            'quantity_available' => 6,
            'unit_cost_base' => 7.00,
            'tax_per_unit' => 0.07,
            'misc_per_unit' => 0.05,
            'sale_price' => 14.75,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $lot->id)
        ->assertJsonPath('data.quantity_available', 6)
        ->assertJsonPath('data.unit_cost_base', fn ($value) => (float) $value === 7.0)
        ->assertJsonPath('data.tax_per_unit', 0.07)
        ->assertJsonPath('data.misc_per_unit', 0.05)
        ->assertJsonPath('data.landed_unit_cost', 7.12)
        ->assertJsonPath('data.remaining_value', 42.72)
        ->assertJsonPath('data.sale_price', 14.75);

    expect(
        ProductInventory::query()
            ->where('variant_id', $context['variant']->id)
            ->where('warehouse_id', $context['warehouse']->id)
            ->value('quantity_available')
    )->toBe(6);

    expect($context['variant']->pricing()->value('base_price'))->toBe('12.50');

    $this->actingAs($context['user'])
        ->getJson('/api/v1/inventory/summary?warehouse_id='.$context['warehouse']->id)
        ->assertOk()
        ->assertJsonPath('data.total_stock', 6)
        ->assertJsonPath('data.total_inventory_value', 42.72)
        ->assertJsonPath('data.total_fifo_value', 42.72);

    $historyResponse = $this->actingAs($context['user'])
        ->getJson("/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/inventory-history?warehouse_id={$context['warehouse']->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.current_cost', 7.12)
        ->assertJsonPath('data.summary.current_sale_price', 14.75)
        ->assertJsonPath('data.summary.inventory_value', 42.72)
        ->assertJsonPath('data.summary.fifo_cost_current', 7.12)
        ->assertJsonPath('data.summary.fifo_sale_price_current', 14.75)
        ->assertJsonPath('data.lots.0.id', $lot->id)
        ->assertJsonPath('data.lots.0.quantity_available', 6)
        ->assertJsonPath('data.lots.0.unit_cost_base', 7)
        ->assertJsonPath('data.lots.0.tax_per_unit', 0.07)
        ->assertJsonPath('data.lots.0.misc_per_unit', 0.05)
        ->assertJsonPath('data.lots.0.landed_unit_cost', 7.12)
        ->assertJsonPath('data.lots.0.sale_price', 14.75);

    $sourceLot = collect($historyResponse->json('data.purchase_movements'))
        ->flatMap(fn (array $movement) => $movement['source_lots'] ?? [])
        ->firstWhere('lot_id', $lot->id);

    expect($sourceLot)->not->toBeNull()
        ->and((float) $sourceLot['unit_cost_base'])->toBe(7.0)
        ->and((float) $sourceLot['tax_per_unit'])->toBe(0.07)
        ->and((float) $sourceLot['landed_unit_cost'])->toBe(7.12)
        ->and((float) $sourceLot['sale_price'])->toBe(14.75);
});

it('exposes purchase lot tax and misc as line totals while preserving unit valuation', function () {
    $context = operationalInventoryContext();

    /** @var InventoryFifoService $fifo */
    $fifo = app(InventoryFifoService::class);
    $fifo->receiveStock([
        'product_id' => $context['product']->id,
        'variant_id' => $context['variant']->id,
        'warehouse_id' => $context['otherWarehouse']->id,
        'quantity' => 20,
        'unit_cost' => 30.00,
        'tax_amount' => 42.00,
        'misc_cost' => 6.00,
        'sale_price' => 5000.00,
        'source_type' => 'purchase_order',
        'reference_type' => 'purchase_order',
        'reference_id' => 37,
        'source_id' => 94,
    ]);

    $this->actingAs($context['user'])
        ->getJson("/api/v1/products/{$context['product']->id}/variants/{$context['variant']->id}/inventory-history?warehouse_id={$context['otherWarehouse']->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.stock_available', 20)
        ->assertJsonPath('data.summary.current_cost', 32.40)
        ->assertJsonPath('data.summary.inventory_value', fn ($value) => (float) $value === 648.0)
        ->assertJsonPath('data.lots.0.received_quantity', 20)
        ->assertJsonPath('data.lots.0.quantity_available', 20)
        ->assertJsonPath('data.lots.0.unit_cost_base', fn ($value) => (float) $value === 30.0)
        ->assertJsonPath('data.lots.0.tax_per_unit', 2.10)
        ->assertJsonPath('data.lots.0.tax_total', fn ($value) => (float) $value === 42.0)
        ->assertJsonPath('data.lots.0.misc_per_unit', 0.30)
        ->assertJsonPath('data.lots.0.misc_total', fn ($value) => (float) $value === 6.0)
        ->assertJsonPath('data.lots.0.landed_unit_cost', 32.40)
        ->assertJsonPath('data.lots.0.landed_total', fn ($value) => (float) $value === 648.0)
        ->assertJsonPath('data.lots.0.remaining_value', fn ($value) => (float) $value === 648.0)
        ->assertJsonPath('data.purchase_movements.0.source_lots.0.tax_total', fn ($value) => (float) $value === 42.0)
        ->assertJsonPath('data.purchase_movements.0.source_lots.0.misc_total', fn ($value) => (float) $value === 6.0)
        ->assertJsonPath('data.purchase_movements.0.source_lots.0.landed_total', fn ($value) => (float) $value === 648.0);
});

it('validates editable inventory lot stock costs and sale price', function () {
    $context = operationalInventoryContext();
    $lot = InventoryLot::query()
        ->where('variant_id', $context['variant']->id)
        ->where('warehouse_id', $context['warehouse']->id)
        ->where('quantity_available', '>', 0)
        ->firstOrFail();

    $this->actingAs($context['user'])
        ->patchJson("/api/v1/inventory/lots/{$lot->id}", [
            'received_at' => null,
            'quantity_available' => -1,
            'unit_cost_base' => -1,
            'tax_per_unit' => -0.01,
            'misc_per_unit' => -0.01,
            'sale_price' => -0.01,
        ])
        ->assertJsonValidationErrors([
            'received_at',
            'quantity_available',
            'unit_cost_base',
            'tax_per_unit',
            'misc_per_unit',
            'sale_price',
        ]);
});
