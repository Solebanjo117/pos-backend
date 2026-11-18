<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Marcas de un proveedor (y proveedores de una marca), decisión de Luis 2026-11-18: conviven
 * "por sus piezas" (calculado de product_suppliers) y "marcada a mano" (brand_supplier).
 */
beforeEach(function () {
    $warehouse = Warehouse::create(['code' => 'W-SB', 'name' => 'Almacén']);
    $user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $warehouse->id, 'is_active' => true]);
    $user->warehouses()->attach($warehouse->id);
    $this->actingAs($user);

    $this->nikomoto = Supplier::create(['name' => 'NIKOMOTO 555 SUN']);
    $this->b555 = Brand::create(['name' => '555', 'status' => 'active']);
    $this->ctr = Brand::create(['name' => 'CTR', 'status' => 'active']);
    $this->wbs = Brand::create(['name' => 'WBS', 'status' => 'active']);
});

function sbVariant(Brand $brand, string $partNumber): ProductVariant
{
    $product = Product::create(['sku_base' => $partNumber, 'name' => 'Pieza '.$partNumber, 'status' => 'active']);

    return ProductVariant::create(['product_id' => $product->id, 'brand_id' => $brand->id, 'sku' => $partNumber, 'status' => 'active', 'is_default' => true]);
}

function sbPiece(Supplier $supplier, ProductVariant $variant, float $cost = 10): void
{
    ProductSupplier::create(['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'supplier_id' => $supplier->id, 'supplier_cost' => $cost, 'lead_time_days' => 0, 'minimum_order_quantity' => 1, 'is_preferred' => true]);
}

it('lists the brands of a supplier by its pieces and the ones marked by hand', function () {
    sbPiece($this->nikomoto, sbVariant($this->b555, '48820-52030'));
    sbPiece($this->nikomoto, sbVariant($this->b555, 'D8521-3RA1A'));
    sbPiece($this->nikomoto, sbVariant($this->ctr, '48521-JG00A'));
    $this->nikomoto->brands()->attach([$this->ctr->id, $this->wbs->id]);

    $list = collect($this->getJson("/api/v1/suppliers/{$this->nikomoto->id}/brands")->assertOk()->json('data'));
    $rows = $list->keyBy('name');

    expect($list->pluck('name')->map(fn ($name) => (string) $name)->all())->toBe(['555', 'CTR', 'WBS'])
        ->and($rows['555'])->toMatchArray(['origin' => 'pieces', 'pieces' => 2, 'manual' => false])
        ->and($rows['CTR'])->toMatchArray(['origin' => 'pieces', 'pieces' => 1, 'manual' => true])
        ->and($rows['WBS'])->toMatchArray(['origin' => 'manual', 'pieces' => 0, 'manual' => true]);
});

it('shows the suppliers of a brand the same way in the brand detail', function () {
    $japan = Supplier::create(['name' => 'JAPAN INTERNATIONAL PARTS']);
    sbPiece($this->nikomoto, sbVariant($this->b555, '48820-52030'));
    $this->b555->suppliers()->attach($japan->id);

    $links = collect($this->getJson("/api/v1/brands/{$this->b555->id}")->assertOk()->json('data.supplier_links'))->keyBy('name');

    expect($links['NIKOMOTO 555 SUN'])->toMatchArray(['origin' => 'pieces', 'pieces' => 1, 'manual' => false])
        ->and($links['JAPAN INTERNATIONAL PARTS'])->toMatchArray(['origin' => 'manual', 'pieces' => 0, 'manual' => true]);
});

it('does not mark the brand by hand when a piece is linked: it counts by its pieces', function () {
    $variant = sbVariant($this->b555, '48820-52030');

    $this->postJson("/api/v1/suppliers/{$this->nikomoto->id}/variants", ['variant_id' => $variant->id, 'supplier_cost' => 9.54])->assertCreated();

    expect($this->nikomoto->brands()->count())->toBe(0);
    $rows = $this->getJson("/api/v1/suppliers/{$this->nikomoto->id}/brands")->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['name' => '555', 'origin' => 'pieces']);
});

it('keeps a brand with pieces in the list when its hand mark is removed', function () {
    sbPiece($this->nikomoto, sbVariant($this->ctr, '48521-JG00A'));
    $this->nikomoto->brands()->attach($this->ctr->id);

    $rows = $this->deleteJson("/api/v1/suppliers/{$this->nikomoto->id}/brands/{$this->ctr->id}")->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['name' => 'CTR', 'origin' => 'pieces', 'manual' => false]);
});

it('does not warn when ordering another piece of a brand the supplier already supplies by its pieces', function () {
    sbPiece($this->nikomoto, sbVariant($this->b555, '48820-52030'));
    $other = sbVariant($this->b555, 'D8521-3RA1A');

    $warnings = app(PurchaseOrderService::class)->supplierMismatchWarnings($this->nikomoto->id, [['variant_id' => $other->id]]);

    expect($warnings)->toBe([]);
});
