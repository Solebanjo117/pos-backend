<?php

namespace App\Console\Commands;

use App\Services\CashierQueueService;
use Illuminate\Console\Command;

class SyncCashierQueue extends Command
{
    protected $signature = 'cashier-queue:sync-due';

    protected $description = 'Envia a la cola de Caja ventas y gastos vencidos pendientes';

    public function handle(CashierQueueService $queue): int
    {
        $queue->syncPendingSources();
        $this->info('Cola de Caja sincronizada.');

        return self::SUCCESS;
    }
}
