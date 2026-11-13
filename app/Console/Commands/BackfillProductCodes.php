<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCode;
use App\Services\ProductCodeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crea el código principal de cada producto en product_codes a partir de su número de parte
 * (products.sku_base). Solo usa el sku_base: los SKU de las variantes no se cargan porque se
 * repiten entre marcas del mismo número de parte.
 */
class BackfillProductCodes extends Command
{
    protected $signature = 'catalog:backfill-product-codes
        {--apply : Crear los códigos después de aprobar el reporte}';

    protected $description = 'Reporta y, opcionalmente, crea el código principal de cada producto (product_codes) desde su número de parte';

    public function handle(ProductCodeNormalizer $normalizer): int
    {
        $plan = $this->plan($normalizer);
        $toCreate = $plan->where('status', 'create');

        $summary = $plan->countBy('status');
        $labels = [
            'create' => $this->option('apply') ? 'Código creado' : 'Se creará el código',
            'exists' => 'Ya tenía su código',
            'no_part_number' => 'Sin número de parte',
            'has_other_primary' => 'Ya tiene otro código principal (no se toca)',
            'conflict' => 'Número usado por otro producto (no se crea)',
        ];
        $this->table(
            ['Resultado', 'Productos'],
            collect($labels)->map(fn (string $label, string $key) => [$label, $summary[$key] ?? 0])->values()->all(),
        );

        $problems = $plan->whereIn('status', ['has_other_primary', 'conflict']);
        if ($problems->isNotEmpty()) {
            $this->table(
                ['Producto', 'Número de parte', 'Detalle'],
                $problems->map(fn (array $row) => ['#'.$row['product_id'], $row['code'], $row['detail']])->values()->all(),
            );
        }

        if (! $this->option('apply')) {
            $this->line('Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toCreate): void {
            foreach ($toCreate as $row) {
                ProductCode::create([
                    'product_id' => $row['product_id'],
                    'variant_id' => null,
                    'code' => $row['code'],
                    'normalized_code' => $row['normalized_code'],
                    'type' => 'sku',
                    'status' => 'active',
                    'is_primary' => true,
                    'metadata' => ['migrated_from' => ['sku_base']],
                ]);
            }

            if ($toCreate->isNotEmpty()) {
                activity()
                    ->withProperties(['product_ids' => $toCreate->pluck('product_id')->values()->all()])
                    ->log('Códigos principales creados desde el número de parte');
            }
        });

        $this->line("Códigos creados: {$toCreate->count()}.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{product_id: int, code: string, normalized_code: string, status: string, detail: string}>
     */
    private function plan(ProductCodeNormalizer $normalizer): Collection
    {
        $products = Product::query()->orderBy('id')->get(['id', 'sku_base']);
        $activeCodes = ProductCode::query()->active()->get(['product_id', 'variant_id', 'normalized_code', 'is_primary']);
        $ownerByCode = $activeCodes->groupBy('normalized_code')->map(fn (Collection $codes) => $codes->pluck('product_id')->unique()->values());
        $partNumberOwners = $products
            ->groupBy(fn (Product $product) => $normalizer->normalize($product->sku_base))
            ->map(fn (Collection $group) => $group->pluck('id'));

        return $products->map(function (Product $product) use ($normalizer, $activeCodes, $ownerByCode, $partNumberOwners): array {
            $code = trim((string) $product->sku_base);
            $normalized = $normalizer->normalize($code);
            $row = ['product_id' => $product->id, 'code' => $code, 'normalized_code' => $normalized, 'detail' => ''];

            if ($normalized === '') {
                return [...$row, 'status' => 'no_part_number'];
            }

            $owners = $ownerByCode->get($normalized, collect());
            if ($owners->contains($product->id)) {
                return [...$row, 'status' => 'exists'];
            }

            $otherOwners = $owners->merge($partNumberOwners->get($normalized, collect()))->unique()->reject(fn ($id) => $id === $product->id);
            if ($otherOwners->isNotEmpty()) {
                return [...$row, 'status' => 'conflict', 'detail' => 'También en #'.$otherOwners->implode(', #')];
            }

            $otherPrimary = $activeCodes->first(fn (ProductCode $existing) => $existing->product_id === $product->id
                && $existing->variant_id === null
                && $existing->is_primary);
            if ($otherPrimary) {
                return [...$row, 'status' => 'has_other_primary', 'detail' => 'Principal actual: '.$otherPrimary->normalized_code];
            }

            return [...$row, 'status' => 'create'];
        });
    }
}
