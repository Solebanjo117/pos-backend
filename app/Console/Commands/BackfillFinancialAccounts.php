<?php

namespace App\Console\Commands;

use App\Models\CapitalMovement;
use App\Models\FinancialAdjustment;
use App\Models\OperationalExpense;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrderPayment;
use App\Services\FinancialLiquiditySyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class BackfillFinancialAccounts extends Command
{
    protected $signature = 'financial-accounts:backfill {--apply : Escribe los movimientos; sin esta opción solo simula}';

    protected $description = 'Reconcilia movimientos históricos de liquidez con Banco sin asignar';

    public function handle(FinancialLiquiditySyncService $syncService): int
    {
        $models = [
            SalesOrderPayment::class,
            PurchaseOrderPayment::class,
            OperationalExpense::class,
            CapitalMovement::class,
            FinancialAdjustment::class,
        ];
        $candidates = 0;
        $created = 0;

        foreach ($models as $modelClass) {
            $modelClass::query()->orderBy('id')->chunkById(200, function ($records) use ($syncService, &$candidates, &$created): void {
                foreach ($records as $record) {
                    $candidates++;
                    if (! $this->option('apply')) {
                        continue;
                    }

                    /** @var Model $record */
                    if ($syncService->sync($record)) {
                        $created++;
                    }
                }
            });
        }

        $this->table(
            ['Modo', 'Registros revisados', 'Movimientos conciliados'],
            [[
                $this->option('apply') ? 'Aplicación' : 'Simulación',
                $candidates,
                $this->option('apply') ? $created : 'No escritos',
            ]],
        );

        return self::SUCCESS;
    }
}
