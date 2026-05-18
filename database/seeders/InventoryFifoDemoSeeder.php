<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryFifoDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = $this->ensureUser();
            $warehouse = $this->ensureWarehouse();
            $supplier = $this->ensureSupplier();
            [$product, $variant] = $this->ensureProductAndVariant();

            $this->resetScenario($product, $variant, $warehouse);

            $this->receivePurchase(
                product: $product,
                variant: $variant,
                warehouse: $warehouse,
                supplier: $supplier,
                user: $user,
                quantity: 12,
                unitCost: 1980.00,
                miscCost: 120.00,
                receivedAt: now()->subDays(12),
                note: 'Demo FIFO compra inicial'
            );

            $sale = $this->createSale(
                product: $product,
                variant: $variant,
                warehouse: $warehouse,
                user: $user,
                quantity: 5,
                unitPrice: 3150.00,
                soldAt: now()->subDays(3)
            );

            $this->consumeSale($sale, $warehouse, $user);

            $this->receivePurchase(
                product: $product,
                variant: $variant,
                warehouse: $warehouse,
                supplier: $supplier,
                user: $user,
                quantity: 10,
                unitCost: 2080.00,
                miscCost: 95.00,
                receivedAt: now()->subDay(),
                note: 'Demo FIFO compra reciente'
            );

            $this->command?->info('Demo FIFO creado: Alternador 12V 90A / Valeo con 17 unidades, 2 lotes abiertos, 2 compras y 1 venta con asignacion FIFO.');
        });
    }

    private function ensureUser(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'admin@hermes.com'],
            [
                'name' => 'Admin Principal',
                'username' => 'admin',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }

    private function ensureWarehouse(): Warehouse
    {
        return Warehouse::query()->updateOrCreate(
            ['code' => 'WH-CDMX-01'],
            [
                'name' => 'Almacen Principal CDMX',
                'location' => 'CDMX',
                'capacity' => 1000,
                'status' => 'active',
                'notes' => 'Almacen demo para trazabilidad FIFO',
            ]
        );
    }

    private function ensureSupplier(): Supplier
    {
        return Supplier::query()->updateOrCreate(
            ['email' => 'compras@autopartes-central.test'],
            [
                'name' => 'AutoPartes Central',
                'contact_name' => 'Compras AutoPartes',
                'phone' => '+507 300-1000',
                'city' => 'Panama',
                'country' => 'Panama',
                'payment_terms' => 'Net 30',
                'lead_time_days' => 5,
                'status' => 'active',
            ]
        );
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function ensureProductAndVariant(): array
    {
        $brand = Brand::query()->updateOrCreate(
            ['name' => 'Valeo'],
            ['status' => 'active']
        );

        $product = Product::withTrashed()->updateOrCreate(
            ['sku_base' => 'ALT-12V-90A'],
            [
                'alternate_code' => 'ALT90A-12V',
                'name' => 'Alternador 12V 90A',
                'description' => 'Alternador demo con trazabilidad FIFO completa.',
                'status' => 'active',
                'batch_tracking' => true,
                'is_universal' => false,
            ]
        );
        $product->restore();

        $variant = ProductVariant::withTrashed()->updateOrCreate(
            [
                'product_id' => $product->id,
                'brand_id' => $brand->id,
            ],
            [
                'sku' => 'ALT-12V-90A-VAL',
                'upc' => '750100100002',
                'status' => 'active',
                'is_default' => false,
            ]
        );
        $variant->restore();

        ProductPricing::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
            ],
            [
                'cost_base' => 1980.00,
                'cost_miscellaneous' => 95.00,
                'cost' => 2075.00,
                'cost_tax_rate' => 7,
                'sale_tax_rate' => 7,
                'tax_rate' => 7,
                'tax_type' => 'standard',
                'base_price' => 3150.00,
                'msrp' => 3650.00,
                'margin_percentage' => 34.13,
                'max_discount_percentage' => 10,
                'pricing_formula' => 'costo * 1.45',
            ]
        );

        return [$product, $variant];
    }

    private function resetScenario(Product $product, ProductVariant $variant, Warehouse $warehouse): void
    {
        $movementIds = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where(function ($query) use ($warehouse): void {
                $query->where('warehouse_id', $warehouse->id)
                    ->orWhere('destination_warehouse_id', $warehouse->id);
            })
            ->pluck('id');

        $lotIds = InventoryLot::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->pluck('id');

        InventoryLotAllocation::query()
            ->whereIn('inventory_movement_id', $movementIds)
            ->orWhereIn('inventory_lot_id', $lotIds)
            ->delete();

        InventoryLot::query()->whereIn('id', $lotIds)->delete();
        InventoryMovement::query()->whereIn('id', $movementIds)->delete();

        SalesOrder::query()
            ->where('notes', 'like', 'Demo FIFO%')
            ->get()
            ->each(function (SalesOrder $order): void {
                $order->items()->delete();
                $order->forceDelete();
            });

        PurchaseOrder::query()
            ->where('notes', 'like', 'Demo FIFO%')
            ->get()
            ->each(function (PurchaseOrder $purchaseOrder): void {
                $purchaseOrder->items()->delete();
                $purchaseOrder->forceDelete();
            });

        ProductInventory::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'quantity_available' => 0,
                'quantity_reserved' => 0,
                'reorder_point' => 3,
                'reorder_quantity' => 8,
                'location_in_warehouse' => 'E2-11',
                'last_restocked_at' => now(),
            ]
        );
    }

    private function receivePurchase(
        Product $product,
        ProductVariant $variant,
        Warehouse $warehouse,
        Supplier $supplier,
        User $user,
        int $quantity,
        float $unitCost,
        float $miscCost,
        mixed $receivedAt,
        string $note
    ): void {
        $subtotal = round($quantity * $unitCost, 2);
        $taxAmount = round($subtotal * 0.07, 2);

        $purchaseOrder = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $user->id,
            'status' => 'received',
            'payment_status' => 'pending',
            'order_date' => $receivedAt->copy()->subDays(2)->toDateString(),
            'expected_date' => $receivedAt->copy()->subDay()->toDateString(),
            'received_date' => $receivedAt->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'shipping_cost' => 0,
            'total' => 0,
            'notes' => "Demo FIFO - {$note}",
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_ordered' => $quantity,
            'units_per_package' => 1,
            'quantity_received' => $quantity,
            'unit_cost' => $unitCost,
            'tax_rate' => 7,
            'tax_amount' => $taxAmount,
            'misc_cost' => $miscCost,
            'status' => 'received',
            'notes' => $note,
        ]);

        $purchaseOrder->calculateTotals();

        app(InventoryFifoService::class)->receiveStock([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'unit_cost_base' => $unitCost,
            'tax_amount' => $taxAmount,
            'misc_cost' => $miscCost,
            'reference_type' => 'purchase_order',
            'reference_id' => $purchaseOrder->id,
            'source_type' => 'purchase_order',
            'source_id' => $item->id,
            'supplier_id' => $supplier->id,
            'received_at' => $receivedAt,
            'batch_number' => 'FIFO-'.$receivedAt->format('Ymd').'-'.$purchaseOrder->id,
            'notes' => "Recepcion {$purchaseOrder->po_number} - {$note}",
            'user_id' => $user->id,
        ]);
    }

    private function createSale(
        Product $product,
        ProductVariant $variant,
        Warehouse $warehouse,
        User $user,
        int $quantity,
        float $unitPrice,
        mixed $soldAt
    ): SalesOrder {
        $order = SalesOrder::query()->create([
            'user_id' => $user->id,
            'date' => $soldAt->toDateString(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 0,
            'notes' => 'Demo FIFO - venta con detalle de lotes',
        ]);

        SalesOrderItem::query()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'seller_id' => $user->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => 0,
            'discount_percent' => 0,
            'tax_percent' => 7,
        ]);

        return $order->refresh();
    }

    private function consumeSale(SalesOrder $order, Warehouse $warehouse, User $user): void
    {
        foreach ($order->items as $item) {
            $fifoResult = app(InventoryFifoService::class)->consumeStock([
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $item->quantity,
                'reference_type' => 'sale',
                'reference_id' => $order->id,
                'sales_order_item_id' => $item->id,
                'notes' => "Venta demo FIFO {$order->order_number}",
                'user_id' => $user->id,
                'strict' => true,
            ]);

            if (($fifoResult['weighted_unit_cost'] ?? 0) > 0) {
                $item->forceFill(['unit_cost' => $fifoResult['weighted_unit_cost']])->save();
            }
        }
    }
}
