<?php

namespace App\Console\Commands;

use App\Services\FinancialSnapshotService;
use Illuminate\Console\Command;

class RebuildFinancialSnapshots extends Command
{
    protected $signature = 'financial:snapshot:rebuild
        {--from= : Fecha inicial YYYY-MM-DD}
        {--to= : Fecha final YYYY-MM-DD}
        {--warehouse_id= : Sucursal especifica}
        {--force : Recalcular snapshots bloqueados}';

    protected $description = 'Reconstruye snapshots financieros diarios desde movimientos transaccionales.';

    public function handle(FinancialSnapshotService $snapshots): int
    {
        $warehouseId = $this->option('warehouse_id') !== null
            ? (int) $this->option('warehouse_id')
            : null;

        $count = $snapshots->rebuildRange(
            $this->option('from') ?: null,
            $this->option('to') ?: null,
            $warehouseId,
            (bool) $this->option('force')
        );

        $this->info("Snapshots reconstruidos: {$count}");

        return self::SUCCESS;
    }
}
