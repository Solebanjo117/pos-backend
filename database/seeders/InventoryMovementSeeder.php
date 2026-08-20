<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryMovementService;
use Illuminate\Database\Seeder;

class InventoryMovementSeeder extends Seeder
{
    protected $inventoryService;

    public function __construct(InventoryMovementService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function run(): void
    {
        echo "🌱 Creando movimientos de inventario de prueba...\n";

        $products = Product::limit(5)->get();
        $warehouses = Warehouse::all();

        if ($products->isEmpty() || $warehouses->isEmpty()) {
            echo "⚠️  No hay productos o almacenes. Ejecuta los seeders primero.\n";

            return;
        }

        // Crear 10 movimientos de entrada
        foreach ($products as $product) {
            $warehouse = $warehouses->random();

            $this->inventoryService->recordInbound([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => rand(50, 200),
                'reference_type' => 'purchase',
                'reference_id' => rand(1000, 9999),
                'notes' => 'Entrada inicial de inventario',
            ]);

            echo "✅ Entrada creada: {$product->name} -> {$warehouse->name}\n";
        }

        // Crear 5 movimientos de salida
        for ($i = 0; $i < 5; $i++) {
            $product = $products->random();
            $warehouse = $warehouses->random();

            try {
                $this->inventoryService->recordOutbound([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => rand(5, 20),
                    'reference_type' => 'sale',
                    'reference_id' => rand(1000, 9999),
                    'notes' => 'Venta de prueba',
                ]);

                echo "✅ Salida creada: {$product->name} <- {$warehouse->name}\n";
            } catch (\Exception $e) {
                echo "⚠️  Salida omitida (sin stock): {$product->name}\n";
            }
        }

        // Crear 3 transferencias
        for ($i = 0; $i < 3; $i++) {
            $product = $products->random();
            $originWarehouse = $warehouses->random();
            $destinationWarehouse = $warehouses->where('id', '!=', $originWarehouse->id)->random();

            try {
                $this->inventoryService->recordTransfer([
                    'product_id' => $product->id,
                    'warehouse_id' => $originWarehouse->id,
                    'destination_warehouse_id' => $destinationWarehouse->id,
                    'quantity' => rand(5, 15),
                    'notes' => 'Transferencia de prueba',
                ]);

                echo "✅ Transferencia creada: {$product->name}: {$originWarehouse->name} -> {$destinationWarehouse->name}\n";
            } catch (\Exception $e) {
                echo "⚠️  Transferencia omitida (sin stock): {$product->name}\n";
            }
        }

        echo "✨ Seeder de movimientos completado\n";
    }
}
