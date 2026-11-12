<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductMergeService;
use Illuminate\Console\Command;

class MergeDuplicateProducts extends Command
{
    protected $signature = 'catalog:merge-duplicate-products
        {--apply : Unir los productos después de aprobar el reporte}
        {--skip= : IDs de producto (separados por coma) cuyos grupos no se tocan}';

    protected $description = 'Reporta y, opcionalmente, une productos repetidos (fichas distintas con el mismo número de parte)';

    public function handle(ProductMergeService $merger): int
    {
        $groups = $merger->findGroups();
        if ($groups->isEmpty()) {
            $this->info('No se encontraron productos repetidos.');

            return self::SUCCESS;
        }

        $skip = collect(explode(',', (string) $this->option('skip')))->map(fn ($id) => (int) trim($id))->filter()->all();
        $names = Product::query()->whereIn('id', $groups->pluck('product_ids')->flatten())->pluck('name', 'id');
        $rows = [];
        $merged = 0;
        $variantsMoved = 0;

        foreach ($groups as $group) {
            $duplicates = array_values(array_diff($group['product_ids'], [$group['survivor_id']]));
            $status = match (true) {
                array_intersect($group['product_ids'], $skip) !== [] => 'Omitido (--skip)',
                $group['conflict'] !== null => 'No se une: '.$group['conflict'],
                default => $this->option('apply') ? 'Unido' : 'Se unirá',
            };

            if ($this->option('apply') && in_array($status, ['Unido'], true)) {
                $result = $merger->merge($group['survivor_id'], $duplicates);
                $merged++;
                $variantsMoved += $result['variants_moved'];
            }

            $rows[] = [
                implode(', ', $group['part_numbers']),
                '#'.$group['survivor_id'].' '.mb_strimwidth((string) ($names[$group['survivor_id']] ?? ''), 0, 60, '…'),
                implode(', ', array_map(fn ($id) => '#'.$id, $duplicates)),
                $status,
            ];
        }

        $this->table(['Número de parte', 'Se conserva', 'Se unen a él', 'Estado'], $rows);
        $this->line($this->option('apply')
            ? "Grupos unidos: {$merged}. Marcas movidas: {$variantsMoved}."
            : 'Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

        return self::SUCCESS;
    }
}
