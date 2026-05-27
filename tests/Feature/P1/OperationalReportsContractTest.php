<?php

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reportUserWithWarehouse(): array
{
    $warehouse = Warehouse::create([
        'code' => 'OPR-001',
        'name' => 'Sucursal Reportes',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    return [$user, $warehouse];
}

function reportProductWithVariant(string $sku, string $name): array
{
    $brand = Brand::firstOrCreate(
        ['name' => 'Marca Operativa'],
        ['status' => 'active']
    );

    $product = Product::create([
        'sku_base' => $sku,
        'name' => $name,
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => $sku.'-VAR',
        'status' => 'active',
        'is_default' => true,
    ]);

    return [$product, $variant];
}

it('exposes stock value risk metrics for stock level reporting', function (): void {
    [$user, $warehouse] = reportUserWithWarehouse();
    [$criticalProduct, $criticalVariant] = reportProductWithVariant('RISK-CRIT', 'Producto Critico');
    [$lowProduct, $lowVariant] = reportProductWithVariant('RISK-LOW', 'Producto Bajo');

    ProductInventory::create([
        'product_id' => $criticalProduct->id,
        'variant_id' => $criticalVariant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
        'reorder_point' => 2,
        'reorder_quantity' => 10,
    ]);

    ProductInventory::create([
        'product_id' => $lowProduct->id,
        'variant_id' => $lowVariant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 3,
        'quantity_reserved' => 0,
        'reorder_point' => 2,
        'reorder_quantity' => 10,
    ]);

    InventoryLot::create([
        'product_id' => $criticalProduct->id,
        'variant_id' => $criticalVariant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'test',
        'source_id' => 1,
        'batch_number' => 'CRIT-LOT',
        'received_quantity' => 2,
        'quantity_available' => 2,
        'unit_cost_base' => 10,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 10,
        'sale_price' => 15,
        'received_at' => now(),
    ]);

    InventoryLot::create([
        'product_id' => $lowProduct->id,
        'variant_id' => $lowVariant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'test',
        'source_id' => 2,
        'batch_number' => 'LOW-LOT',
        'received_quantity' => 3,
        'quantity_available' => 3,
        'unit_cost_base' => 8,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 8,
        'sale_price' => 12,
        'received_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/stock-levels?warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_value', 44)
        ->assertJsonPath('summary.critical_value', 20)
        ->assertJsonPath('summary.low_value', 24)
        ->assertJsonPath('summary.at_risk_value', 44)
        ->assertJsonPath('by_warehouse.0.total_value', 44)
        ->assertJsonPath('by_warehouse.0.at_risk_value', 44);
});

it('sorts stock level report products before pagination', function (): void {
    [$user, $warehouse] = reportUserWithWarehouse();
    [$lowValueProduct, $lowValueVariant] = reportProductWithVariant('SORT-LOW', 'Producto Valor Bajo');
    [$highValueProduct, $highValueVariant] = reportProductWithVariant('SORT-HIGH', 'Producto Valor Alto');

    ProductInventory::create([
        'product_id' => $lowValueProduct->id,
        'variant_id' => $lowValueVariant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
        'reorder_point' => 0,
        'reorder_quantity' => 5,
    ]);

    ProductInventory::create([
        'product_id' => $highValueProduct->id,
        'variant_id' => $highValueVariant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 4,
        'quantity_reserved' => 0,
        'reorder_point' => 0,
        'reorder_quantity' => 5,
    ]);

    InventoryLot::create([
        'product_id' => $lowValueProduct->id,
        'variant_id' => $lowValueVariant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'test',
        'source_id' => 1,
        'batch_number' => 'SORT-LOW-LOT',
        'received_quantity' => 1,
        'quantity_available' => 1,
        'unit_cost_base' => 5,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 5,
        'sale_price' => 8,
        'received_at' => now(),
    ]);

    InventoryLot::create([
        'product_id' => $highValueProduct->id,
        'variant_id' => $highValueVariant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'test',
        'source_id' => 2,
        'batch_number' => 'SORT-HIGH-LOT',
        'received_quantity' => 4,
        'quantity_available' => 4,
        'unit_cost_base' => 25,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 25,
        'sale_price' => 40,
        'received_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/stock-levels?warehouse_id='.$warehouse->id.'&sort_by=stock_value&sort_direction=desc')
        ->assertOk()
        ->assertJsonPath('products.0.sku', 'SORT-HIGH')
        ->assertJsonPath('products.0.stock_value', 100)
        ->assertJsonPath('products.1.sku', 'SORT-LOW')
        ->assertJsonPath('products.1.stock_value', 5);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/stock-levels?warehouse_id='.$warehouse->id.'&sort_by=name&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('products.0.name', 'Producto Valor Alto')
        ->assertJsonPath('products.1.name', 'Producto Valor Bajo');
});

it('exposes neutral lot allocation costs in movement history summaries', function (): void {
    [$user, $warehouse] = reportUserWithWarehouse();
    [$product, $variant] = reportProductWithVariant('MOV-COST', 'Producto Movimiento');

    $movement = InventoryMovement::create([
        'movement_type' => 'out',
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 2,
        'reference_type' => 'sales_order',
        'reference_id' => 77,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lot = InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'purchase_order',
        'source_id' => 22,
        'batch_number' => 'MOV-LOT',
        'received_quantity' => 5,
        'quantity_available' => 3,
        'unit_cost_base' => 15,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 15,
        'sale_price' => 25,
        'received_at' => now(),
    ]);

    InventoryLotAllocation::create([
        'inventory_lot_id' => $lot->id,
        'inventory_movement_id' => $movement->id,
        'quantity' => 2,
        'unit_cost' => 15,
        'total_cost' => 30,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/movement-history?date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString())
        ->assertOk()
        ->assertJsonPath('summary.total_assigned_cost', 30)
        ->assertJsonPath('movements.0.lot_total_cost', 30)
        ->assertJsonPath('movements.0.lot_allocations.0.lot_id', $lot->id)
        ->assertJsonPath('movements.0.lot_allocations.0.total_cost', 30);
});

it('exposes z-report cash closing fields by warehouse and user including export', function (): void {
    [$user, $warehouse] = reportUserWithWarehouse();
    $otherUser = User::factory()->create([
        'role' => 'cashier',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $otherUser->warehouses()->attach($warehouse->id);
    $date = now()->toDateString();

    PosSession::create([
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->setTime(8, 0),
        'closed_at' => now()->setTime(17, 0),
        'opening_amount' => 100,
        'closing_amount' => 140,
        'expected_amount' => 135,
        'status' => 'closed',
        'closing_details' => [
            'summary' => [
                'declared_cash' => 140,
                'expected_cash' => 135,
                'difference' => 5,
                'total_difference' => 5,
            ],
        ],
    ]);

    PosSession::create([
        'user_id' => $otherUser->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->setTime(8, 30),
        'closed_at' => now()->setTime(17, 30),
        'opening_amount' => 50,
        'closing_amount' => 55,
        'expected_amount' => 55,
        'status' => 'closed',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/z-report?date='.$date.'&warehouse_id='.$warehouse->id.'&user_id='.$user->id)
        ->assertOk()
        ->assertJsonPath('cash_drawer.sessions_count', 1)
        ->assertJsonPath('cash_drawer.declared_cash', 140)
        ->assertJsonPath('cash_drawer.closing_amount', 140)
        ->assertJsonPath('cash_drawer.difference', 5)
        ->assertJsonPath('by_user.0.user_id', $user->id)
        ->assertJsonPath('by_user.0.sessions_count', 1)
        ->assertJsonPath('by_user.0.declared_cash', 140)
        ->assertJsonPath('by_user.0.difference', 5);

    $this->actingAs($user)
        ->get('/api/v1/reports/z-report/export?date='.$date.'&warehouse_id='.$warehouse->id.'&user_id='.$user->id)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
