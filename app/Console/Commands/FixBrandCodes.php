<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVariant;
use App\Services\ProductCodeNormalizer;
use App\Services\ProductCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las marcas ya no tienen código propio (decisión del 2026-11-17): su SKU es el número principal del
 * producto. Este comando acomoda las marcas cuyo SKU era distinto: ese número pasa a ser un número de
 * parte adicional del producto (no se pierde para buscar) y la marca queda con el principal. Los SKU
 * que son restos de una importación (sufijo "-XXX-XXXXXX") se descartan.
 */
class FixBrandCodes extends Command
{
    protected $signature = 'catalog:fix-brand-codes
        {--principal=* : Cambiar el número principal de un producto, como VIEJO:NUEVO (el nuevo debe ser uno de sus números)}
        {--apply : Aplicar después de aprobar el reporte}';

    protected $description = 'Pasa los códigos de marca distintos a números de parte del producto y deja cada marca con el número principal';

    /** Restos de importación, p. ej. "48830A-78B00-END-0D252D". */
    private const IMPORT_ARTIFACT = '/-[A-Z]{3}-[0-9A-F]{6}$/i';

    public function handle(ProductCodeNormalizer $normalizer, ProductCodeService $codes): int
    {
        $principalChanges = $this->principalChanges($normalizer);
        if ($principalChanges === null) {
            return self::INVALID;
        }

        $plan = $this->plan($normalizer, $principalChanges);
        if ($plan->isEmpty()) {
            $this->info('Todas las marcas ya usan el número principal de su producto.');

            return self::SUCCESS;
        }

        $this->table(
            ['Producto', 'Principal', 'Marca', 'Su código', 'Qué se hace'],
            $plan->map(fn (array $row) => [
                '#'.$row['product_id'],
                $row['principal'],
                $row['brand'] ?? '—',
                $row['variant_sku'] ?? '—',
                $row['action_label'],
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->line('Modo simulación: no se modificó ningún registro. Use --apply solo después de aprobar este reporte.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $codes): void {
            foreach ($plan->groupBy('product_id') as $productId => $rows) {
                $product = Product::query()->findOrFail($productId);
                $principal = $rows->first()['principal'];
                $extra = $rows->where('action', 'add')->pluck('variant_sku')->all();

                $list = collect([['code' => $principal, 'type' => 'sku', 'is_primary' => true]])
                    ->merge(ProductCode::query()->active()->where('product_id', $product->id)->whereNull('variant_id')->get()
                        ->map(fn (ProductCode $code) => ['code' => $code->code, 'type' => $code->type, 'is_primary' => false]))
                    ->merge(collect($extra)->map(fn (string $code) => ['code' => $code, 'type' => 'alternate', 'is_primary' => false]))
                    ->unique(fn (array $item) => app(ProductCodeNormalizer::class)->normalize($item['code']))
                    ->values()
                    ->all();

                // Guarda los números del producto (el principal primero) y deja sus marcas con el principal.
                $codes->syncForProduct($product, $list);
                ProductVariant::query()->where('product_id', $product->id)->update(['sku' => $principal]);

                activity()
                    ->performedOn($product)
                    ->withProperties(['principal' => $principal, 'numeros_agregados' => $extra, 'marcas' => $rows->pluck('variant_sku', 'brand')->all()])
                    ->log('Códigos de marca pasados a números de parte del producto');
            }
        });

        $this->line('Productos acomodados: '.$plan->pluck('product_id')->unique()->count().'.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null product_id => nuevo principal
     */
    private function principalChanges(ProductCodeNormalizer $normalizer): ?array
    {
        $changes = [];
        foreach ((array) $this->option('principal') as $pair) {
            [$old, $new] = array_pad(explode(':', (string) $pair, 2), 2, '');
            $product = Product::query()->get(['id', 'sku_base'])->first(fn (Product $p) => $normalizer->normalize($p->sku_base) === $normalizer->normalize($old));
            if (! $product || trim($new) === '') {
                $this->error("No se encontró un producto con número principal {$old}.");

                return null;
            }

            $known = ProductVariant::query()->where('product_id', $product->id)->pluck('sku')
                ->merge(ProductCode::query()->active()->where('product_id', $product->id)->pluck('code'))
                ->map(fn ($code) => $normalizer->normalize((string) $code));
            if (! $known->contains($normalizer->normalize($new))) {
                $this->error("{$new} no es uno de los números del producto #{$product->id}.");

                return null;
            }

            $changes[$product->id] = trim($new);
        }

        return $changes;
    }

    /**
     * @param  array<int, string>  $principalChanges
     * @return Collection<int, array<string, mixed>>
     */
    private function plan(ProductCodeNormalizer $normalizer, array $principalChanges): Collection
    {
        $productIds = ProductVariant::query()
            ->whereHas('product')
            ->with('product:id,sku_base')
            ->get(['id', 'product_id', 'sku'])
            ->filter(fn (ProductVariant $v) => $normalizer->normalize($v->sku) !== $normalizer->normalize($principalChanges[$v->product_id] ?? $v->product->sku_base))
            ->pluck('product_id')
            ->merge(array_keys($principalChanges))
            ->unique();

        $rows = collect();
        foreach (Product::query()->with('variants.brand:id,name')->whereIn('id', $productIds)->orderBy('id')->get() as $product) {
            $principal = $principalChanges[$product->id] ?? trim((string) $product->sku_base);
            $principalKey = $normalizer->normalize($principal);
            $productCodes = ProductCode::query()->active()->where('product_id', $product->id)->whereNull('variant_id')->pluck('normalized_code');

            foreach ($product->variants as $variant) {
                $sku = trim((string) $variant->sku);
                $key = $normalizer->normalize($sku);
                if ($key === $principalKey) {
                    continue;
                }

                [$action, $label] = match (true) {
                    $key === '' => ['assign', 'Toma el número principal'],
                    (bool) preg_match(self::IMPORT_ARTIFACT, $sku) => ['discard', 'Se descarta (resto de importación); toma el principal'],
                    $productCodes->contains($key) || $normalizer->normalize((string) $product->sku_base) === $key => ['assign', 'Ya es número del producto; la marca toma el principal'],
                    $this->usedByOtherProduct($key, $product->id) => ['assign', 'Número de otro producto: no se agrega; la marca toma el principal'],
                    default => ['add', 'Se agrega como número de parte adicional; la marca toma el principal'],
                };

                $rows->push([
                    'product_id' => $product->id,
                    'principal' => $principal,
                    'brand' => $variant->brand?->name,
                    'variant_sku' => $sku !== '' ? $sku : null,
                    'action' => $action,
                    'action_label' => $label,
                ]);
            }

            if (isset($principalChanges[$product->id]) && ! $rows->contains('product_id', $product->id)) {
                $rows->push(['product_id' => $product->id, 'principal' => $principal, 'brand' => null, 'variant_sku' => null, 'action' => 'principal', 'action_label' => 'Cambia el número principal']);
            }
        }

        return $rows;
    }

    private function usedByOtherProduct(string $normalizedCode, int $productId): bool
    {
        return ProductCode::query()->active()->where('normalized_code', $normalizedCode)->where('product_id', '!=', $productId)->exists();
    }
}
