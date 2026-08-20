<?php

namespace Tests\Feature\P0;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_switch_warehouse_via_query_param_for_products_listing(): void
    {
        $w1 = Warehouse::create(['code' => 'W1', 'name' => 'Warehouse 1']);
        $w2 = Warehouse::create(['code' => 'W2', 'name' => 'Warehouse 2']);

        $cashier = User::factory()->create([
            'role' => 'cashier',
            'is_active' => true,
            'current_warehouse_id' => $w1->id,
        ]);
        $cashier->warehouses()->attach($w1->id);

        $brand = Brand::create(['name' => 'Brand A', 'status' => 'active']);
        $product = Product::create(['sku_base' => 'SKU-BASE-1', 'name' => 'Producto 1', 'status' => 'active']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => $brand->id,
            'sku' => 'SKU-1',
            'status' => 'active',
            'is_default' => true,
        ]);

        ProductInventory::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $w1->id,
            'quantity_available' => 5,
            'quantity_reserved' => 0,
        ]);
        ProductInventory::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $w2->id,
            'quantity_available' => 100,
            'quantity_reserved' => 0,
        ]);

        $this->actingAs($cashier);

        $res = $this->getJson("/api/v1/products?warehouse_id={$w2->id}&per_page=10");
        $res->assertOk();

        $data = $res->json('data.0');
        $this->assertSame(5, $data['total_stock']);
    }
}
