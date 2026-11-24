<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCode;
use App\Services\ProductCodeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara productos que quedaron sin número de parte principal (error corregido el 2026-11-24: al
 * guardar sin cambiar el principal, este se perdía). Elige el código igual al sku_base del
 * producto; si ninguno coincide, el más antiguo. Por defecto solo simula.
 */
class FixMissingPrimaryCodes extends Command
{
    protected $signature = 'catalog:fix-primary-codes {--apply : Guardar los cambios (sin esto, solo simula)}';

    protected $description = 'Marca un número de parte principal en los productos que se quedaron sin ninguno';

    public function handle(ProductCodeNormalizer $normalizer): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Aplicando cambios.' : 'Modo simulación: no se guarda nada (usa --apply).');

        $productIds = ProductCode::query()
            ->active()
            ->whereNull('variant_id')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('SUM(CASE WHEN is_primary THEN 1 ELSE 0 END) = 0')
            ->pluck('product_id');

        $rows = [];
        foreach ($productIds as $productId) {
            $product = Product::withTrashed()->find($productId);
            $codes = ProductCode::query()->active()->where('product_id', $productId)->whereNull('variant_id')->orderBy('id')->get();
            $skuBase = $normalizer->normalize((string) $product?->sku_base);
            $chosen = $codes->first(fn (ProductCode $code) => $skuBase !== '' && $code->normalized_code === $skuBase) ?? $codes->first();

            $rows[] = [$productId, (string) $product?->sku_base, $codes->pluck('code')->implode(', '), $chosen->code];

            if ($apply) {
                DB::transaction(function () use ($chosen, $product): void {
                    $chosen->forceFill(['is_primary' => true])->save();
                    if ($product && trim((string) $product->sku_base) === '') {
                        $product->forceFill(['sku_base' => $chosen->code])->saveQuietly();
                    }
                    activity()->performedOn($chosen)->withProperties(['product_id' => $chosen->product_id])
                        ->log('Número de parte marcado como principal (reparación)');
                });
            }
        }

        if ($rows === []) {
            $this->info('Todos los productos tienen su número principal.');

            return self::SUCCESS;
        }

        $this->table(['Producto', 'Número del producto', 'Códigos', 'Queda como principal'], $rows);
        $this->info(count($rows).' producto(s) '.($apply ? 'reparados.' : 'por reparar.'));

        return self::SUCCESS;
    }
}
