<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use Illuminate\Database\Seeder;

class ProductInventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = Product::with('pricing')->get();
        $warehouses = Warehouse::all();

        if ($products->isEmpty() || $warehouses->isEmpty()) {
            $this->command->warn('No products or warehouses found. Please seed them first.');

            return;
        }

        $this->command->info("Creating inventory for {$products->count()} products across {$warehouses->count()} warehouses...");

        foreach ($products as $product) {
            // Create inventory in 1-3 random warehouses per product
            $warehousesToStock = $warehouses->random(rand(1, min(3, $warehouses->count())));

            foreach ($warehousesToStock as $warehouse) {
                $quantityAvailable = rand(0, 500);

                $inventory = ProductInventory::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity_available' => $quantityAvailable,
                    'quantity_reserved' => rand(0, 50),
                    'reorder_point' => rand(10, 50),
                    'reorder_quantity' => rand(50, 200),
                    'location_in_warehouse' => 'Pasillo '.chr(rand(65, 72)).'-'.rand(1, 20),
                    'last_restocked_at' => now()->subDays(rand(0, 30)),
                ]);

                app(InventoryFifoService::class)->ensureOpeningLotForInventory(
                    $product->id,
                    null,
                    $warehouse->id,
                    (int) $inventory->quantity_available,
                    (float) ($product->pricing->first()?->cost ?? $product->pricing->first()?->cost_base ?? 0)
                );
            }
        }

        $this->command->info('Product inventory seeded successfully!');
    }
}
