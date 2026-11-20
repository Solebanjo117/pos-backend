<?php

namespace App\Console\Commands;

use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Toda marca debe tener su registro de existencia en el almacén (aunque sea 0): sin él no aparece en la
 * exportación ni en algunos reportes. Las marcas nuevas ya lo reciben al crearse; este comando completa
 * las que vinieron de cargas anteriores. Usa los mismos valores que una marca nueva (existencia 0,
 * punto y cantidad de reorden 0).
 */
class EnsureBrandInventory extends Command
{
    protected $signature = 'catalog:ensure-brand-inventory
        {--warehouse= : ID del almacén (por defecto, el primero)}
        {--apply : Crear los registros después de aprobar el reporte}';

    protected $description = 'Crea el registro de existencia en 0 de las marcas que no lo tienen';

    public function handle(): int
    {
        $warehouse = $this->option('warehouse')
            ? Warehouse::query()->find($this->option('warehouse'))
            : Warehouse::query()->orderBy('id')->first();

        if (! $warehouse) {
            $this->error('No se encontró el almacén.');

            return self::INVALID;
        }

        $variants = ProductVariant::query()
            ->whereHas('product')
            ->whereDoesntHave('inventory', fn ($query) => $query->where('warehouse_id', $warehouse->id))
            ->with(['product:id,sku_base', 'brand:id,name'])
            ->orderBy('product_id')
            ->get();

        if ($variants->isEmpty()) {
            $this->info("Todas las marcas ya tienen registro de existencia en {$warehouse->name}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Número de parte', 'Marca', 'Estado'],
            $variants->map(fn (ProductVariant $variant) => [$variant->product?->sku_base, $variant->brand?->name, $variant->status])->all(),
        );

        if (! $this->option('apply')) {
            $this->line("Se crearían {$variants->count()} registros en {$warehouse->name}. Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($variants, $warehouse): void {
            foreach ($variants as $variant) {
                ProductInventory::query()->firstOrCreate(
                    ['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id],
                    ['quantity_available' => 0, 'quantity_reserved' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0],
                );
            }
        });

        $this->line("Registros creados: {$variants->count()}.");

        return self::SUCCESS;
    }
}
