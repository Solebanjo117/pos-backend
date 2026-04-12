<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearTransactionalData extends Command
{
    protected $signature = 'app:clear-data {--force : Skip confirmation prompt} {--no-backup : Skip backup before clearing}';

    protected $description = 'Clear transactional data while preserving stock and customer balances';

    public function handle(): int
    {
        if (!$this->option('force')) {
            $confirmed = $this->confirm('Esto borrara datos transaccionales (ventas, compras, cotizaciones, sesiones POS, movimientos). Deseas continuar?');
            if (!$confirmed) {
                $this->warn('Operacion cancelada.');
                return self::FAILURE;
            }
        }

        if (!$this->option('no-backup')) {
            $this->info('Creando backup antes del reinicio...');
            $backupCode = Artisan::call('db:backup');
            if ($backupCode !== self::SUCCESS) {
                $this->error('No se pudo crear backup. Operacion cancelada.');
                $this->line(trim(Artisan::output()));
                return self::FAILURE;
            }
            $this->line(trim(Artisan::output()));
        }

        $driver = DB::getDriverName();
        $tables = $this->transactionalTables();

        try {
            if ($driver === 'pgsql') {
                DB::beginTransaction();
                foreach ($tables as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }
                    DB::statement("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
                    $this->line("- Limpiada: {$table}");
                }
                DB::commit();
            } else {
                Schema::disableForeignKeyConstraints();
                foreach ($tables as $table) {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }
                    DB::table($table)->truncate();
                    $this->line("- Limpiada: {$table}");
                }
                Schema::enableForeignKeyConstraints();
            }
        } catch (\Throwable $e) {
            if ($driver === 'pgsql' && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($driver !== 'pgsql') {
                Schema::enableForeignKeyConstraints();
            }

            $this->error('Error limpiando datos: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Ejecutando seeder esencial...');
        $seedCode = Artisan::call('db:seed', [
            '--class' => 'EssentialSeeder',
            '--force' => true,
        ]);

        if ($seedCode !== self::SUCCESS) {
            $this->error('Datos limpiados, pero fallo el seeder esencial.');
            $this->line(trim(Artisan::output()));
            return self::FAILURE;
        }

        $this->line(trim(Artisan::output()));
        $this->info('Datos transaccionales reiniciados correctamente. Se conservaron stock y balances.');

        return self::SUCCESS;
    }

    /**
     * Tables considered transactional for Sprint #23d.
     *
     * Stock tables (`product_inventory`) and customer balances (`customers`) are preserved on purpose.
     */
    private function transactionalTables(): array
    {
        return [
            'sales_return_items',
            'sales_returns',
            'sales_order_payments',
            'sales_order_items',
            'sales_orders',
            'sales_quote_items',
            'sales_quotes',
            'cash_movements',
            'pos_sessions',
            'purchase_order_payments',
            'purchase_order_items',
            'purchase_orders',
            'inventory_transfer_items',
            'inventory_transfers',
            'inventory_movements',
            'work_order_items',
            'work_orders',
            'supply_list_items',
            'activity_log',
        ];
    }
}
