<?php

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Las marcas no tienen código propio (2026-11-17): su SKU es el número principal del producto.
 * Caso real: #177 terminal X-Trail. Lightspeed dice que la pieza es 48521-JG00A (CTR, WBS y 555);
 * D8521-JG00A es el código con que Nikomoto la llama. Luis eligió 48521-JG00A como principal.
 */
function brandProduct(string $principal, array $brands): Product
{
    $product = Product::create(['sku_base' => $principal, 'name' => 'Terminal '.$principal, 'status' => 'active']);
    ProductCode::create(['product_id' => $product->id, 'variant_id' => null, 'code' => $principal, 'normalized_code' => preg_replace('/[^A-Z0-9]/', '', strtoupper($principal)), 'type' => 'sku', 'status' => 'active', 'is_primary' => true]);
    foreach ($brands as $index => [$brandName, $sku]) {
        ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => (Brand::where('name', $brandName)->first() ?? Brand::create(['name' => $brandName, 'status' => 'active']))->id,
            'sku' => $sku,
            'status' => 'active',
            'is_default' => $index === 0,
        ]);
    }

    return $product->fresh(['variants.brand']);
}

function productNumbers(Product $product): array
{
    return ProductCode::query()->active()->where('product_id', $product->id)->whereNull('variant_id')
        ->orderByDesc('is_primary')->orderBy('id')->pluck('code')->all();
}

it('only reports in simulation mode', function () {
    $product = brandProduct('D8521-3RA1A', [['555', 'D8521-3RA1A'], ['ENDO', '48521-3RA1A']]);

    $this->artisan('catalog:fix-brand-codes')
        ->expectsOutputToContain('Se agrega como número de parte adicional')
        ->expectsOutputToContain('Modo simulación')
        ->assertSuccessful();

    expect($product->variants->firstWhere('brand.name', 'ENDO')->fresh()->sku)->toBe('48521-3RA1A');
});

it('moves a different brand code to the product numbers and gives the brand the principal', function () {
    $product = brandProduct('D8521-3RA1A', [['555', 'D8521-3RA1A'], ['ENDO', '48521-3RA1A']]);

    $this->artisan('catalog:fix-brand-codes --apply')->assertSuccessful();

    expect(productNumbers($product))->toBe(['D8521-3RA1A', '48521-3RA1A'])
        ->and($product->fresh()->sku_base)->toBe('D8521-3RA1A')
        ->and(ProductVariant::where('product_id', $product->id)->pluck('sku')->unique()->all())->toBe(['D8521-3RA1A']);
});

it('switches the principal number when asked (X-Trail: 48521-JG00A) and keeps the old one as additional', function () {
    $product = brandProduct('D8521-JG00A', [['555', 'D8521-JG00A'], ['CTR', '48521-JG00A'], ['WBS', '48521-JG00A']]);

    $this->artisan('catalog:fix-brand-codes --principal=D8521-JG00A:48521-JG00A --apply')->assertSuccessful();

    expect($product->fresh()->sku_base)->toBe('48521-JG00A')
        ->and(productNumbers($product))->toBe(['48521-JG00A', 'D8521-JG00A'])
        ->and(ProductVariant::where('product_id', $product->id)->pluck('sku')->unique()->all())->toBe(['48521-JG00A']);
});

it('discards a brand code left by an import and does not add it as a number', function () {
    $product = brandProduct('48830A-78B00', [['FORTIS', '48830A-78B00'], ['ENDO', '48830A-78B00-END-0D252D']]);

    $this->artisan('catalog:fix-brand-codes --apply')->assertSuccessful();

    expect(productNumbers($product))->toBe(['48830A-78B00'])
        ->and(ProductVariant::where('product_id', $product->id)->pluck('sku')->unique()->all())->toBe(['48830A-78B00']);
});

it('refuses a new principal that is not one of the product numbers', function () {
    brandProduct('D8521-JG00A', [['555', 'D8521-JG00A']]);

    $this->artisan('catalog:fix-brand-codes --principal=D8521-JG00A:99999-XXXX --apply')->assertExitCode(2);
});

it('fills each supplier cost with the last purchase already received', function () {
    $warehouse = Warehouse::create(['code' => 'W-SYNC', 'name' => 'Almacén']);
    $product = brandProduct('D8521-3RA1A', [['555', 'D8521-3RA1A']]);
    $variant = $product->variants->first();
    $nikomoto = Supplier::create(['name' => 'NIKOMOTO 555 SUN']);
    $japan = Supplier::create(['name' => 'JAPAN INTERNATIONAL PARTS']);
    $lot = fn (Supplier $supplier, float $cost, string $date) => InventoryLot::create([
        'product_id' => $product->id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id,
        'source_type' => 'purchase_order', 'received_quantity' => 1, 'quantity_available' => 1,
        'unit_cost_base' => $cost, 'tax_per_unit' => 0, 'misc_per_unit' => 0, 'landed_unit_cost' => $cost, 'sale_price' => 17, 'received_at' => $date,
    ]);
    $lot($nikomoto, 11.00, '2025-10-01');
    $lot($japan, 10.88, '2025-12-13');
    $lot($nikomoto, 9.54, '2026-04-04');

    $this->artisan('catalog:sync-supplier-costs')->expectsOutputToContain('Modo simulación')->assertSuccessful();
    expect(ProductSupplier::count())->toBe(0);

    $this->artisan('catalog:sync-supplier-costs --apply')->assertSuccessful();
    $this->artisan('catalog:sync-supplier-costs --apply')->assertSuccessful();

    $links = ProductSupplier::where('variant_id', $variant->id)->get()->keyBy('supplier_id');
    expect($links)->toHaveCount(2)
        ->and((float) $links[$nikomoto->id]->supplier_cost)->toBe(9.54)
        ->and((float) $links[$japan->id]->supplier_cost)->toBe(10.88)
        ->and($links[$nikomoto->id]->is_preferred)->toBeTrue();
});

it('prints the product number on tickets, proformas and quotes, not a brand code', function () {
    $views = resource_path('views');

    expect(file_get_contents("$views/tickets/sale.blade.php"))->toContain('$item->product?->sku_base ?: $item->variant->sku')
        ->and(file_get_contents("$views/tickets/proforma.blade.php"))->toContain('($product->sku_base ?? null) ?: ($variant?->sku')
        ->and(file_get_contents("$views/quotes/a4.blade.php"))->toContain('$item->product?->sku_base ?: null');
});

it('keeps the brand SKU equal to the product number even if a brand is saved with another code', function () {
    $warehouse = Warehouse::create(['code' => 'W-MIR', 'name' => 'Almacén']);
    $user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
    $product = brandProduct('48521-JG00A', [['CTR', '48521-JG00A']]);
    $variant = $product->variants->first();

    $this->putJson("/api/v1/products/{$product->id}/variants/{$variant->id}", [
        'brand_id' => $variant->brand_id,
        'status' => 'active',
        'sku' => 'CTR-PROPIO',
    ])->assertOk();

    expect($variant->fresh()->sku)->toBe('48521-JG00A');
});

it('has no separate OEM references: an equivalent number is a product number and is found in any format', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('oem_references'))->toBeFalse();

    $warehouse = Warehouse::create(['code' => 'W-OEM', 'name' => 'Almacén']);
    $user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);
    $product = brandProduct('48521-JG00A', [['CTR', '48521-JG00A']]);
    ProductCode::create(['product_id' => $product->id, 'variant_id' => null, 'code' => 'SR-N380', 'normalized_code' => 'SRN380', 'type' => 'alternate', 'status' => 'active', 'is_primary' => false]);

    $ids = collect($this->getJson('/api/v1/products?search=srn 380')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$product->id]);
});

it('creates the missing zero stock record of a brand, only once', function () {
    $warehouse = Warehouse::create(['code' => 'W-INV', 'name' => 'Almacén Principal']);
    $product = brandProduct('48521-JG00A', [['CTR', '48521-JG00A'], ['WBS', '48521-JG00A']]);
    [$ctr, $wbs] = [$product->variants[0], $product->variants[1]];
    \App\Models\ProductInventory::create(['product_id' => $product->id, 'variant_id' => $ctr->id, 'warehouse_id' => $warehouse->id, 'quantity_available' => 3, 'quantity_reserved' => 0, 'reorder_point' => 2, 'reorder_quantity' => 5]);

    $this->artisan('catalog:ensure-brand-inventory')->expectsOutputToContain('Se crearían 1 registros')->assertSuccessful();
    $this->artisan('catalog:ensure-brand-inventory --apply')->assertSuccessful();
    $this->artisan('catalog:ensure-brand-inventory --apply')->expectsOutputToContain('Todas las marcas ya tienen')->assertSuccessful();

    $rows = \App\Models\ProductInventory::where('product_id', $product->id)->get()->keyBy('variant_id');
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[$wbs->id]->quantity_available)->toBe(0)
        ->and((int) $rows[$wbs->id]->reorder_point)->toBe(0)
        ->and((int) $rows[$ctr->id]->quantity_available)->toBe(3);
});

it('no longer has the legacy production batches', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('product_batches'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('warehouse_batches'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('products', 'batch_tracking'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('inventory_movements', 'product_batch_id'))->toBeFalse();
});

it('moves OEM references to product numbers, or to an alternative product when the number belongs to another product', function () {
    $migration = require base_path('database/migrations/2026_11_18_154531_move_oem_references_to_product_codes.php');
    $migration->down();

    $terminal = brandProduct('TER-0001', [['555', 'TER-0001']]);
    $other = brandProduct('45503-19155', [['555', '45503-19155']]);
    \Illuminate\Support\Facades\DB::table('oem_references')->insert([
        ['product_id' => $terminal->id, 'oem_code' => 'SR-7950', 'manufacturer' => 'OEM', 'created_at' => now(), 'updated_at' => now()],
        ['product_id' => $terminal->id, 'oem_code' => '45503-19155', 'manufacturer' => '555', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    expect(\Illuminate\Support\Facades\Schema::hasTable('oem_references'))->toBeFalse()
        ->and(productNumbers($terminal))->toBe(['TER-0001', 'SR-7950'])
        ->and(productNumbers($other))->toBe(['45503-19155'])
        ->and(\Illuminate\Support\Facades\DB::table('alternative_products')->where('primary_product_id', $terminal->id)->where('alternative_product_id', $other->id)->value('relationship_type'))->toBe('compatible');
});
