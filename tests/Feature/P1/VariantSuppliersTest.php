<?php

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use App\Services\PdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Proveedores de cada marca (decisión del jefe, 2026-11-17), con su ejemplo:
 * D8521-3RA1A marca 555, comprada a Nikomoto (la llama 726HAIQ7271) y a Japan (D8521-3RA1A).
 * - El costo de cada proveedor es lo que se pagó en su última compra.
 * - El proveedor por defecto es el más barato (se puede escoger otro al pedir).
 * - Cada lote FIFO guarda su proveedor; los que no tienen se pueden cargar a mano.
 */
beforeEach(function () {
    $this->warehouse = Warehouse::create(['code' => 'W-SUP', 'name' => 'Almacén']);
    $this->user = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $this->warehouse->id, 'is_active' => true]);
    $this->user->warehouses()->attach($this->warehouse->id);
    $this->actingAs($this->user);

    $this->product = Product::create(['sku_base' => 'D8521-3RA1A', 'name' => 'Terminal Sentra', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => Brand::create(['name' => '555', 'status' => 'active'])->id,
        'sku' => 'D8521-3RA1A',
        'status' => 'active',
        'is_default' => true,
    ]);
    $this->nikomoto = Supplier::create(['name' => 'NIKOMOTO 555 SUN']);
    $this->japan = Supplier::create(['name' => 'JAPAN INTERNATIONAL PARTS']);
});

function brandSuppliersUrl($test, ?Supplier $supplier = null): string
{
    return "/api/v1/products/{$test->product->id}/variants/{$test->variant->id}/suppliers".($supplier ? "/{$supplier->id}" : '');
}

function defaultSupplierId(ProductVariant $variant): ?int
{
    return ProductSupplier::query()->where('variant_id', $variant->id)->where('is_preferred', true)->value('supplier_id');
}

it('lists the suppliers of a brand with their code and cost, the cheapest as default', function () {
    $this->putJson(brandSuppliersUrl($this, $this->japan), ['supplier_code' => 'D8521-3RA1A', 'supplier_cost' => 10.88])->assertOk();
    $this->putJson(brandSuppliersUrl($this, $this->nikomoto), ['supplier_code' => ' 726HAIQ7271 ', 'supplier_cost' => 9.54])->assertOk();

    $this->getJson(brandSuppliersUrl($this))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.supplier_name', 'NIKOMOTO 555 SUN')
        ->assertJsonPath('data.0.supplier_code', '726HAIQ7271')
        ->assertJsonPath('data.0.supplier_cost', 9.54)
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.1.supplier_name', 'JAPAN INTERNATIONAL PARTS')
        ->assertJsonPath('data.1.is_default', false);
});

it('moves the default to the other supplier when its cost becomes the cheapest', function () {
    $this->putJson(brandSuppliersUrl($this, $this->japan), ['supplier_cost' => 10.88]);
    $this->putJson(brandSuppliersUrl($this, $this->nikomoto), ['supplier_cost' => 9.54]);
    expect(defaultSupplierId($this->variant))->toBe($this->nikomoto->id);

    $this->putJson(brandSuppliersUrl($this, $this->japan), ['supplier_cost' => 8.00])->assertOk();
    expect(defaultSupplierId($this->variant))->toBe($this->japan->id);

    $this->deleteJson(brandSuppliersUrl($this, $this->japan))->assertOk()->assertJsonCount(1, 'data');
    expect(defaultSupplierId($this->variant))->toBe($this->nikomoto->id);
});

it('ignores a manual preferred mark from the supplier screen: the cheapest stays as default', function () {
    $this->postJson("/api/v1/suppliers/{$this->nikomoto->id}/variants", ['variant_id' => $this->variant->id, 'supplier_cost' => 9.54])->assertCreated();
    $this->postJson("/api/v1/suppliers/{$this->japan->id}/variants", ['variant_id' => $this->variant->id, 'supplier_cost' => 10.88, 'is_preferred' => true, 'supplier_code' => 'D8521-3RA1A'])->assertCreated();

    expect(defaultSupplierId($this->variant))->toBe($this->nikomoto->id)
        ->and(ProductSupplier::where('supplier_id', $this->japan->id)->value('supplier_code'))->toBe('D8521-3RA1A');
});

it('updates the supplier cost with what was paid when a purchase is received, and the lot keeps its supplier', function () {
    $this->putJson(brandSuppliersUrl($this, $this->japan), ['supplier_cost' => 10.88]);
    $this->putJson(brandSuppliersUrl($this, $this->nikomoto), ['supplier_cost' => 9.54]);

    $this->postJson('/api/v1/purchase-orders/quick-purchase', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'supplier_id' => $this->japan->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'quantity_ordered' => 2,
            'unit_cost' => 8.25,
        ]],
    ])->assertSuccessful();

    $japanLink = ProductSupplier::where('variant_id', $this->variant->id)->where('supplier_id', $this->japan->id)->first();
    expect((float) $japanLink->supplier_cost)->toBe(8.25)
        ->and($japanLink->last_purchased_at)->not->toBeNull()
        ->and(defaultSupplierId($this->variant))->toBe($this->japan->id)
        ->and(InventoryLot::where('variant_id', $this->variant->id)->where('source_type', 'purchase_order')->value('supplier_id'))->toBe($this->japan->id);
});

it('links a new supplier to the brand the first time it is bought from it', function () {
    $this->postJson('/api/v1/purchase-orders/quick-purchase', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'supplier_id' => $this->nikomoto->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'quantity_ordered' => 6,
            'unit_cost' => 9.54,
        ]],
    ])->assertSuccessful();

    $this->getJson(brandSuppliersUrl($this))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.supplier_cost', 9.54)
        ->assertJsonPath('data.0.is_default', true);
});

it('lets a lot without supplier get one by hand, but not a lot that came from a purchase', function () {
    $opening = app(InventoryFifoService::class)->receiveStock([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 3,
        'unit_cost_base' => 7.5,
        'source_type' => 'opening_balance',
    ]);
    $lot = InventoryLot::where('source_movement_id', $opening->id)->sole();
    expect($lot->supplier_id)->toBeNull();

    $this->patchJson("/api/v1/inventory/lots/{$lot->id}/supplier", ['supplier_id' => $this->japan->id])
        ->assertOk()
        ->assertJsonPath('data.supplier.name', 'JAPAN INTERNATIONAL PARTS');

    expect($lot->fresh()->supplier_id)->toBe($this->japan->id)
        ->and((float) ProductSupplier::where('variant_id', $this->variant->id)->where('supplier_id', $this->japan->id)->value('supplier_cost'))->toBe(7.5);

    $this->postJson('/api/v1/purchase-orders/quick-purchase', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [['supplier_id' => $this->nikomoto->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity_ordered' => 1, 'unit_cost' => 9]],
    ])->assertSuccessful();
    $purchaseLot = InventoryLot::where('variant_id', $this->variant->id)->where('source_type', 'purchase_order')->sole();

    $this->patchJson("/api/v1/inventory/lots/{$purchaseLot->id}/supplier", ['supplier_id' => $this->japan->id])->assertStatus(422);
    expect($purchaseLot->fresh()->supplier_id)->toBe($this->nikomoto->id);
});

it('shows each lot supplier in the brand inventory history', function () {
    $this->postJson('/api/v1/purchase-orders/quick-purchase', [
        'warehouse_id' => $this->warehouse->id,
        'items' => [['supplier_id' => $this->japan->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity_ordered' => 2, 'unit_cost' => 10.88]],
    ])->assertSuccessful();

    $lots = $this->getJson("/api/v1/products/{$this->product->id}/variants/{$this->variant->id}/fifo-history")->assertOk()->json('data.lots');

    expect(collect($lots)->firstWhere('source_type', 'purchase_order'))
        ->toMatchArray(['supplier' => ['id' => $this->japan->id, 'name' => 'JAPAN INTERNATIONAL PARTS'], 'supplier_editable' => false]);
});

it('finds the product by the code a supplier uses for it', function () {
    $this->putJson(brandSuppliersUrl($this, $this->nikomoto), ['supplier_code' => '726HAIQ7271', 'supplier_cost' => 9.54]);

    $ids = collect($this->getJson('/api/v1/products?search=726haiq-7271')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$this->product->id]);
});

it('prints the supplier code on the purchase order, with our part number below', function () {
    $this->putJson(brandSuppliersUrl($this, $this->nikomoto), ['supplier_code' => '726HAIQ7271', 'supplier_cost' => 9.54]);
    $order = PurchaseOrder::create([
        'po_number' => 'OC-TEST-1',
        'supplier_id' => $this->nikomoto->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
        'order_date' => now(),
    ]);
    $order->items()->create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'quantity_ordered' => 6,
        'unit_cost' => 9.54,
    ]);

    $storeData = (new ReflectionMethod(PdfExportService::class, 'storeData'))->invoke(app(PdfExportService::class));
    $html = view('pdf.purchase-order', ['purchaseOrder' => $order->fresh(['items.product', 'items.variant.brand', 'supplier', 'warehouse']), 'mode' => 'standard', ...$storeData])->render();

    expect($html)->toContain('726HAIQ7271')
        ->and($html)->toContain('Nuestro: D8521-3RA1A');
});
