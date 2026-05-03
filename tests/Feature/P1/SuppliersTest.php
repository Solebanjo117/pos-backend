<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects supplier creation without name', function () {
    $buyer = User::factory()->create([
        'role' => 'buyer',
        'is_active' => true,
    ]);

    $this->actingAs($buyer)
        ->postJson('/api/v1/suppliers', [
            'email' => 'supplier@example.com',
        ])
        ->assertStatus(422);
});

it('associates supplied variants with supplier purchase metadata', function () {
    $buyer = User::factory()->create([
        'role' => 'buyer',
        'is_active' => true,
    ]);
    $supplier = Supplier::create([
        'name' => 'Proveedor Especializado',
        'status' => 'active',
    ]);
    $brand = Brand::create([
        'name' => 'Marca A',
        'status' => 'active',
    ]);
    $product = Product::create([
        'sku_base' => 'BASE-001',
        'system_id' => 'P000000001',
        'name' => 'Filtro de aceite',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'FLT-MA-001',
        'status' => 'active',
    ]);

    $this->actingAs($buyer)
        ->postJson("/api/v1/suppliers/{$supplier->id}/variants", [
            'variant_id' => $variant->id,
            'supplier_cost' => 12.5,
            'lead_time_days' => 4,
            'minimum_order_quantity' => 3,
            'is_preferred' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.0.variant_id', $variant->id)
        ->assertJsonPath('data.0.supplier_cost', 12.5)
        ->assertJsonPath('data.0.minimum_order_quantity', 3)
        ->assertJsonPath('data.0.is_preferred', true);

    $this->assertDatabaseHas('product_suppliers', [
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'is_preferred' => true,
    ]);
});

it('returns purchase order warnings for variants not supplied by the selected supplier', function () {
    $buyer = User::factory()->create([
        'role' => 'buyer',
        'is_active' => true,
    ]);
    $linkedSupplier = Supplier::create([
        'name' => 'Proveedor Vinculado',
        'status' => 'active',
    ]);
    $otherSupplier = Supplier::create([
        'name' => 'Proveedor OC',
        'status' => 'active',
    ]);
    $brand = Brand::create([
        'name' => 'Marca B',
        'status' => 'active',
    ]);
    $product = Product::create([
        'sku_base' => 'BASE-002',
        'system_id' => 'P000000002',
        'name' => 'Pastilla de freno',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'BRK-MB-001',
        'status' => 'active',
    ]);
    ProductSupplier::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'supplier_id' => $linkedSupplier->id,
        'supplier_cost' => 10,
    ]);

    $warnings = app(\App\Services\PurchaseOrderService::class)
        ->supplierMismatchWarnings($otherSupplier->id, [[
            'product_id' => $product->id,
            'variant_id' => $variant->id,
        ]]);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['type'])->toBe('supplier_variant_missing')
        ->and($warnings[0]['variant_id'])->toBe($variant->id);
});
