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
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function productIndexFifoWarehouse(): array
{
    $warehouse = Warehouse::create(['code' => 'PIF-001', 'name' => 'Sucursal Listado']);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    return [$warehouse, $user];
}

/**
 * Crea un producto con una variante y su inventario; con $lots crea lotes FIFO (costo, precio, cantidad).
 *
 * @param  array<int, array{0: float, 1: float, 2: int}>  $lots
 */
function productIndexFifoProduct(Warehouse $warehouse, Brand $brand, string $sku, array $lots, float $fallbackCost = 0, float $fallbackPrice = 0): ProductVariant
{
    $product = Product::create(['sku_base' => $sku, 'name' => 'Producto '.$sku, 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => $sku.'-V',
        'status' => 'active',
        'is_default' => true,
    ]);
    ProductPricing::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'base_price' => $fallbackPrice,
        'cost' => $fallbackCost,
    ]);
    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => array_sum(array_column($lots, 2)),
        'quantity_reserved' => 0,
        'reorder_point' => 1,
        'reorder_quantity' => 5,
    ]);

    foreach ($lots as $index => [$cost, $price, $quantity]) {
        InventoryLot::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'source_type' => 'test',
            'source_id' => $index + 1,
            'batch_number' => $sku.'-L'.$index,
            'received_quantity' => $quantity,
            'quantity_available' => $quantity,
            'unit_cost_base' => $cost,
            'tax_per_unit' => 0,
            'misc_per_unit' => 0,
            'landed_unit_cost' => $cost,
            'sale_price' => $price,
            'received_at' => now()->subDays(10 - $index),
        ]);
    }

    return $variant;
}

it('lista productos con el mismo costo y precio FIFO que el cálculo individual', function () {
    [$warehouse, $user] = productIndexFifoWarehouse();
    $brand = Brand::create(['name' => 'Marca Listado', 'status' => 'active']);

    $withLots = productIndexFifoProduct($warehouse, $brand, 'PIF-A', [[10, 25, 2], [12, 30, 3]]);
    $withoutLots = productIndexFifoProduct($warehouse, $brand, 'PIF-B', [], 7.5, 19.99);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/products?per_page=20&warehouse_id='.$warehouse->id)
        ->assertSuccessful();

    $service = app(InventoryFifoService::class);
    foreach ([$withLots, $withoutLots] as $variant) {
        $row = collect($response->json('data'))->firstWhere('id', $variant->product_id);
        $inventory = collect($row['variants'])->firstWhere('id', $variant->id)['inventory'][0];
        $expected = $service->currentFifoPricing($variant->product_id, $variant->id, $warehouse->id);

        expect($inventory['active_lot_id'])->toBe($expected['active_lot_id'])
            ->and((float) $inventory['current_cost'])->toBe(round($expected['current_cost'], 2))
            ->and((float) $inventory['current_sale_price'])->toBe(round($expected['current_sale_price'], 2))
            ->and($inventory['warehouse']['name'])->toBe('Sucursal Listado');
    }

    expect($service->currentFifoPricing($withLots->product_id, $withLots->id, $warehouse->id))
        ->toMatchArray(['current_cost' => 10.0, 'current_sale_price' => 25.0, 'inventory_value' => 56.0])
        ->and($service->currentFifoPricing($withoutLots->product_id, $withoutLots->id, $warehouse->id))
        ->toMatchArray(['active_lot_id' => null, 'current_cost' => 7.5, 'current_sale_price' => 19.99, 'inventory_value' => 0.0]);
});

it('calcula en lote lo mismo que uno por uno', function () {
    [$warehouse] = productIndexFifoWarehouse();
    $brand = Brand::create(['name' => 'Marca Lote', 'status' => 'active']);
    $variants = [
        productIndexFifoProduct($warehouse, $brand, 'PIF-C', [[5, 9, 1], [6, 11, 4]]),
        productIndexFifoProduct($warehouse, $brand, 'PIF-D', [], 3.25, 8),
        productIndexFifoProduct($warehouse, $brand, 'PIF-E', [[1.333, 2.5, 3]]),
    ];

    $service = app(InventoryFifoService::class);
    $batch = $service->currentFifoPricingMany(array_map(fn (ProductVariant $variant) => [
        'product_id' => $variant->product_id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
    ], $variants));

    foreach ($variants as $variant) {
        expect($batch[InventoryFifoService::pricingKey($variant->product_id, $variant->id, $warehouse->id)])
            ->toEqual($service->currentFifoPricing($variant->product_id, $variant->id, $warehouse->id));
    }
});

it('no repite consultas por cada producto del listado', function () {
    [$warehouse, $user] = productIndexFifoWarehouse();
    $brand = Brand::create(['name' => 'Marca Conteo', 'status' => 'active']);

    $countQueries = function () use ($user, $warehouse): int {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($user)
            ->getJson('/api/v1/products?per_page=50&warehouse_id='.$warehouse->id)
            ->assertSuccessful();
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    };

    productIndexFifoProduct($warehouse, $brand, 'PIF-F0', [[4, 8, 2]]);
    productIndexFifoProduct($warehouse, $brand, 'PIF-G0', [], 2, 5);
    $fewProducts = $countQueries();

    foreach (range(1, 8) as $index) {
        productIndexFifoProduct($warehouse, $brand, 'PIF-F'.$index, [[4, 8, 2]]);
        productIndexFifoProduct($warehouse, $brand, 'PIF-G'.$index, [], 2, 5);
    }
    $manyProducts = $countQueries();

    expect($manyProducts)->toBe($fewProducts);
});
