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
