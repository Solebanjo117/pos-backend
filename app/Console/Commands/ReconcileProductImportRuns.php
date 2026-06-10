<?php

namespace App\Console\Commands;

use App\Services\ProductImportRunReconciler;
use Illuminate\Console\Command;

class ReconcileProductImportRuns extends Command
{
    protected $signature = 'products:import-reconcile
        {--pending-minutes=15 : Minutos para marcar como huerfano un import pendiente sin worker}
        {--running-minutes=60 : Minutos sin heartbeat para reconciliar un import en ejecucion}
        {--cancel-minutes=10 : Minutos para cerrar una cancelacion solicitada sin actividad}';

    protected $description = 'Reconcilia imports de productos huerfanos para que no queden indefinidamente en cola';

    public function handle(ProductImportRunReconciler $reconciler): int
    {
        $summary = $reconciler->reconcileOrphaned(null, [
            'pending_minutes' => $this->integerOption('pending-minutes'),
            'running_minutes' => $this->integerOption('running-minutes'),
            'cancel_requested_minutes' => $this->integerOption('cancel-minutes'),
            'reconciled_by_command' => true,
        ]);

        $this->info(sprintf(
            'Imports revisados: %d. Reconciliados: %d. Fallidos: %d. Cancelados: %d. Reencolados: %d.',
            $summary['checked'],
            $summary['reconciled'],
            $summary['failed'],
            $summary['cancelled'],
            $summary['requeued']
        ));

        return self::SUCCESS;
    }

    private function integerOption(string $name): int
    {
        return max(1, (int) $this->option($name));
    }
}
