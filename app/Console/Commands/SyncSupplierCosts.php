<?php

namespace App\Console\Commands;

use App\Models\InventoryLot;
use App\Services\VariantSupplierService;
use Illuminate\Console\Command;

/**
 * Llena el costo de cada proveedor de una marca con las compras que ya se recibieron (antes de que el
 * sistema lo hiciera solo). Recorre los lotes de compras de la más vieja a la más nueva, así queda el
 * costo de la última compra. Se puede correr varias veces.
 */
class SyncSupplierCosts extends Command
{
    protected $signature = 'catalog:sync-supplier-costs
        {--apply : Guardar después de aprobar el reporte}';

    protected $description = 'Llena el costo por proveedor de cada marca con las compras ya recibidas';

    public function handle(VariantSupplierService $suppliers): int
    {
        $lots = InventoryLot::query()
            ->with(['supplier:id,name', 'variant.brand:id,name', 'product:id,sku_base'])
            ->whereNotNull('supplier_id')
            ->whereNotNull('variant_id')
            ->whereIn('source_type', ['purchase_order', 'supply_list'])
            ->where('unit_cost_base', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        if ($lots->isEmpty()) {
            $this->info('No hay compras recibidas con proveedor.');

            return self::SUCCESS;
        }

        $latest = $lots->groupBy(fn (InventoryLot $lot) => $lot->variant_id.'-'.$lot->supplier_id)->map->last();
        $this->table(
            ['Número de parte', 'Marca', 'Proveedor', 'Costo última compra', 'Fecha'],
            $latest->map(fn (InventoryLot $lot) => [
                $lot->product?->sku_base,
                $lot->variant?->brand?->name,
                $lot->supplier?->name,
                number_format((float) $lot->unit_cost_base, 2),
                $lot->received_at?->format('Y-m-d'),
            ])->values()->all(),
        );

        if (! $this->option('apply')) {
            $this->line('Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

            return self::SUCCESS;
        }

        foreach ($lots as $lot) {
            $suppliers->recordPurchase((int) $lot->product_id, (int) $lot->variant_id, (int) $lot->supplier_id, (float) $lot->unit_cost_base, $lot->received_at);
        }

        $this->line('Costos por proveedor actualizados: '.$latest->count().'.');

        return self::SUCCESS;
    }
}
