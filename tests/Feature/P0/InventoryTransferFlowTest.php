<?php

namespace Tests\Feature\P0;

use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTransferFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_and_receive_transfer_moves_stock_and_creates_movements(): void
    {
        $from = Warehouse::create(['code' => 'W1', 'name' => 'Warehouse 1']);
        $to = Warehouse::create(['code' => 'W2', 'name' => 'Warehouse 2']);

        $brand = Brand::create(['name' => 'Brand A', 'status' => 'active']);
        $product = Product::create(['sku_base' => 'SKU-BASE-1', 'name' => 'Producto 1', 'status' => 'active']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => $brand->id,
            'sku' => 'SKU-1',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        ProductInventory::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $from->id,
            'quantity_available' => 10,
            'quantity_reserved' => 0,
        ]);

        $svc = app(InventoryTransferService::class);
        $transfer = $svc->create([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'notes' => null,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 3],
            ],
        ], $user->id);

        $this->assertSame('draft', $transfer->status);

        $transfer = $svc->dispatch($transfer);

        $fromInv = ProductInventory::where('warehouse_id', $from->id)->where('variant_id', $variant->id)->firstOrFail();
        $this->assertSame(7, $fromInv->quantity_available);
        $this->assertSame('in_transit', $transfer->status);

        $outMovement = InventoryMovement::where('reference_type', 'transfer')->where('reference_id', $transfer->id)->where('movement_type', 'out')->first();
        $this->assertNotNull($outMovement);
        $this->assertSame($from->id, $outMovement->warehouse_id);
        $this->assertSame($to->id, $outMovement->destination_warehouse_id);
        $this->assertSame($variant->id, $outMovement->variant_id);
        $this->assertSame(3, $outMovement->quantity);

        $transfer = $svc->receive($transfer, $user->id);

        $toInv = ProductInventory::where('warehouse_id', $to->id)->where('variant_id', $variant->id)->firstOrFail();
        $this->assertSame(3, $toInv->quantity_available);
        $this->assertSame('completed', $transfer->status);

        $inMovement = InventoryMovement::where('reference_type', 'transfer')->where('reference_id', $transfer->id)->where('movement_type', 'in')->first();
        $this->assertNotNull($inMovement);
        $this->assertSame($to->id, $inMovement->warehouse_id);
        $this->assertSame($from->id, $inMovement->destination_warehouse_id);
        $this->assertSame($variant->id, $inMovement->variant_id);
        $this->assertSame(3, $inMovement->quantity);
    }

    public function test_dispatch_fails_when_stock_insufficient(): void
    {
        $from = Warehouse::create(['code' => 'W1', 'name' => 'Warehouse 1']);
        $to = Warehouse::create(['code' => 'W2', 'name' => 'Warehouse 2']);

        $brand = Brand::create(['name' => 'Brand A', 'status' => 'active']);
        $product = Product::create(['sku_base' => 'SKU-BASE-1', 'name' => 'Producto 1', 'status' => 'active']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'brand_id' => $brand->id,
            'sku' => 'SKU-1',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        ProductInventory::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $from->id,
            'quantity_available' => 1,
            'quantity_reserved' => 0,
        ]);

        $svc = app(InventoryTransferService::class);
        $transfer = $svc->create([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'notes' => null,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2],
            ],
        ], $user->id);

        $this->expectException(\Exception::class);
        $svc->dispatch($transfer);
    }
}
