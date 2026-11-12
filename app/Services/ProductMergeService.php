<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une productos repetidos: fichas distintas que venden el mismo número de parte. Las marcas
 * (variantes) pasan al producto que se conserva con su inventario, precios e historial, y los
 * productos repetidos quedan eliminados con borrado lógico (se pueden consultar).
 */
class ProductMergeService
{
    /**
     * Tablas con product_id que se tratan aparte (choques de índices únicos o deduplicación).
     *
     * @var array<int, string>
     */
    private const SPECIAL_TABLES = [
        'products',
        'product_variants',
        'product_codes',
        'product_pricing',
        'product_attributes',
        'product_vehicle_compatibility',
        'product_extra_categories',
        'product_suppliers',
        'product_batches',
        'product_vins',
        'oem_references',
        'inventory',
    ];

    /**
     * Columnas que identifican un mismo vehículo en la compatibilidad.
     *
     * @var array<int, string>
     */
    private const COMPATIBILITY_KEY = ['scope_type', 'make_id', 'model_id', 'engine_id', 'chassis_id', 'all_engines', 'all_chassis', 'raw_vehicle_key'];

    public function __construct(private readonly ProductCodeNormalizer $normalizer) {}

    /**
     * Grupos de productos (activos) unidos por un número de parte de variante en común. Si A y B
     * comparten un número y B y C otro, los tres forman un grupo.
     *
     * @return Collection<int, array{product_ids: array<int, int>, part_numbers: array<int, string>, survivor_id: int, conflict: ?string}>
     */
    public function findGroups(): Collection
    {
        $productsByCode = [];
        $displayCode = [];
        ProductVariant::query()
            ->whereHas('product')
            ->get(['id', 'product_id', 'sku'])
            ->each(function (ProductVariant $variant) use (&$productsByCode, &$displayCode): void {
                $normalized = $this->normalizer->normalize((string) $variant->sku);
                if ($normalized !== '') {
                    $productsByCode[$normalized][$variant->product_id] = true;
                    $displayCode[$normalized] ??= trim((string) $variant->sku);
                }
            });

        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            $parent[$id] ??= $id;

            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };

        $codesByRoot = [];
        foreach ($productsByCode as $normalized => $productIds) {
            $ids = array_keys($productIds);
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $parent[$find($id)] = $find($ids[0]);
            }
        }

        $groups = [];
        foreach (array_keys($parent) as $id) {
            $groups[$find($id)][] = $id;
        }
        foreach ($productsByCode as $normalized => $productIds) {
            $ids = array_keys($productIds);
            if (count($ids) > 1) {
                $codesByRoot[$find($ids[0])][] = $displayCode[$normalized];
            }
        }

        return collect($groups)
            ->map(function (array $ids, int $root) use ($codesByRoot): array {
                sort($ids);

                return [
                    'product_ids' => $ids,
                    'part_numbers' => $codesByRoot[$root] ?? [],
                    'survivor_id' => $this->chooseSurvivor($ids),
                    'conflict' => $this->brandConflict($ids),
                ];
            })
            ->sortBy(fn (array $group) => $group['product_ids'][0])
            ->values();
    }

    /**
     * Se conserva el producto con más marcas; si empatan, el más antiguo (id menor).
     *
     * @param  array<int, int>  $productIds
     */
    public function chooseSurvivor(array $productIds): int
    {
        $counts = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, count(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        return collect($productIds)
            ->sortBy([fn ($a, $b) => ((int) ($counts[$b] ?? 0)) <=> ((int) ($counts[$a] ?? 0)), fn ($a, $b) => $a <=> $b])
            ->first();
    }

    /**
     * Una marca solo puede estar una vez por producto: si dos de los productos tienen la misma marca
     * (variantes activas), no se pueden unir sin decidir qué hacer con ellas.
     *
     * @param  array<int, int>  $productIds
     */
    public function brandConflict(array $productIds): ?string
    {
        $clash = ProductVariant::query()
            ->with('brand:id,name')
            ->whereIn('product_id', $productIds)
            ->get(['id', 'product_id', 'brand_id'])
            ->groupBy(fn (ProductVariant $variant) => (string) $variant->brand_id)
            ->filter(fn (Collection $variants) => $variants->pluck('product_id')->unique()->count() > 1)
            ->map(fn (Collection $variants) => $variants->first()->brand?->name ?? 'sin marca')
            ->values();

        return $clash->isEmpty() ? null : 'La marca '.$clash->implode(', ').' está en más de uno de estos productos.';
    }

    /**
     * @param  array<int, int>  $duplicateIds
     * @return array{variants_moved: int}
     */
    public function merge(int $survivorId, array $duplicateIds): array
    {
        $duplicateIds = array_values(array_diff(array_map('intval', $duplicateIds), [$survivorId]));
        $conflict = $this->brandConflict([$survivorId, ...$duplicateIds]);
        if ($conflict !== null) {
            throw new \RuntimeException($conflict);
        }

        return DB::transaction(function () use ($survivorId, $duplicateIds): array {
            $survivor = Product::query()->lockForUpdate()->findOrFail($survivorId);
            $moved = 0;
            $merged = [];

            foreach ($duplicateIds as $duplicateId) {
                $duplicate = Product::query()->lockForUpdate()->findOrFail($duplicateId);
                $moved += $this->moveVariants($survivorId, $duplicateId);
                $this->moveProductLevelRows($survivorId, $duplicateId);
                $this->moveHistoryRows($survivorId, $duplicateId);
                $merged[] = ['id' => $duplicate->id, 'name' => $duplicate->name, 'sku_base' => $duplicate->sku_base];
                $duplicate->delete();
            }

            activity()
                ->performedOn($survivor)
                ->withProperties(['merged_products' => $merged])
                ->log('Productos repetidos unidos');

            return ['variants_moved' => $moved];
        });
    }

    /**
     * Mueve marcas puntuales (variantes) a otro producto, con su existencia, precios e historial.
     * Sirve para corregir una marca cargada en el producto equivocado.
     *
     * @param  array<int, int>  $variantIds
     */
    public function moveVariantsTo(int $targetProductId, array $variantIds): int
    {
        return DB::transaction(function () use ($targetProductId, $variantIds): int {
            $target = Product::query()->lockForUpdate()->findOrFail($targetProductId);
            $variants = ProductVariant::query()->with('brand:id,name')->whereIn('id', $variantIds)->lockForUpdate()->get();
            if ($variants->count() !== count(array_unique($variantIds))) {
                throw new \RuntimeException('Alguna de las marcas indicadas no existe o está eliminada.');
            }

            $targetBrands = ProductVariant::withTrashed()->where('product_id', $targetProductId)->pluck('brand_id')->map(fn ($id) => (string) $id)->all();
            $clash = $variants->filter(fn (ProductVariant $variant) => in_array((string) $variant->brand_id, $targetBrands, true));
            if ($clash->isNotEmpty()) {
                throw new \RuntimeException('El producto destino ya tiene la marca '.$clash->map(fn ($variant) => $variant->brand?->name)->implode(', ').'.');
            }

            $moved = 0;
            foreach ($variants->groupBy('product_id') as $sourceProductId => $sourceVariants) {
                $moved += $this->moveVariants($targetProductId, (int) $sourceProductId, $sourceVariants->pluck('id')->all());
            }

            activity()
                ->performedOn($target)
                ->withProperties(['moved_variants' => $variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'from_product_id' => $variant->product_id,
                    'brand' => $variant->brand?->name,
                    'sku' => $variant->sku,
                ])->values()->all()])
                ->log('Marcas movidas a este producto');

            return $moved;
        });
    }

    /**
     * @param  array<int, int>|null  $onlyVariantIds
     */
    private function moveVariants(int $survivorId, int $duplicateId, ?array $onlyVariantIds = null): int
    {
        $survivorHasDefault = ProductVariant::query()->where('product_id', $survivorId)->where('is_default', true)->exists();
        $survivorBrands = ProductVariant::withTrashed()->where('product_id', $survivorId)->pluck('brand_id')->map(fn ($id) => (string) $id)->all();

        $variants = ProductVariant::withTrashed()->where('product_id', $duplicateId)
            ->when($onlyVariantIds !== null, fn ($query) => $query->whereIn('id', $onlyVariantIds))
            ->get();
        $moved = 0;
        foreach ($variants as $variant) {
            // Solo una variante ya eliminada puede chocar aquí (las activas se validaron antes): queda en el producto repetido.
            if (in_array((string) $variant->brand_id, $survivorBrands, true)) {
                continue;
            }

            $isDefault = $variant->is_default && ! $survivorHasDefault && ! $variant->trashed();
            $survivorHasDefault = $survivorHasDefault || $isDefault;
            DB::table('product_variants')->where('id', $variant->id)->update(['product_id' => $survivorId, 'is_default' => $isDefault]);
            $survivorBrands[] = (string) $variant->brand_id;
            $moved += $variant->trashed() ? 0 : 1;

            foreach ($this->tablesWith('variant_id') as $table) {
                if (Schema::hasColumn($table, 'product_id')) {
                    DB::table($table)->where('variant_id', $variant->id)->update(['product_id' => $survivorId]);
                }
            }
        }

        return $moved;
    }

    /**
     * Datos del producto (no de una marca): se pasan sin duplicar lo que el producto conservado ya tiene.
     */
    private function moveProductLevelRows(int $survivorId, int $duplicateId): void
    {
        // Códigos del producto: pasan como secundarios (el principal es el del producto conservado).
        DB::table('product_codes')->where('product_id', $duplicateId)->whereNull('variant_id')
            ->update(['product_id' => $survivorId, 'is_primary' => false]);

        if (! DB::table('product_pricing')->where('product_id', $survivorId)->whereNull('variant_id')->exists()) {
            DB::table('product_pricing')->where('product_id', $duplicateId)->whereNull('variant_id')->update(['product_id' => $survivorId]);
        }

        $survivorAttributes = DB::table('product_attributes')->where('product_id', $survivorId)->pluck('name')->map(fn ($name) => mb_strtolower(trim((string) $name)))->all();
        DB::table('product_attributes')->where('product_id', $duplicateId)->get()
            ->reject(fn ($row) => in_array(mb_strtolower(trim((string) $row->name)), $survivorAttributes, true))
            ->each(fn ($row) => DB::table('product_attributes')->where('id', $row->id)->update(['product_id' => $survivorId]));

        // Vehículos compatibles: se agregan los que falten. El carro por defecto del producto repetido
        // solo se conserva si el producto conservado no tiene uno.
        $compatibilityKey = fn ($row): string => json_encode(array_map(fn ($column) => $row->{$column} ?? null, self::COMPATIBILITY_KEY));
        // (is_default no existe en bases sin la migración del carro por defecto).
        $hasDefaultColumn = Schema::hasColumn('product_vehicle_compatibility', 'is_default');
        $survivorVehicles = DB::table('product_vehicle_compatibility')->where('product_id', $survivorId)->get();
        $survivorHasDefaultVehicle = $survivorVehicles->contains(fn ($row) => (bool) ($row->is_default ?? false));
        $survivorKeys = $survivorVehicles->map($compatibilityKey)->all();
        DB::table('product_vehicle_compatibility')->where('product_id', $duplicateId)->get()
            ->reject(fn ($row) => in_array($compatibilityKey($row), $survivorKeys, true))
            ->each(function ($row) use ($survivorId, $hasDefaultColumn, &$survivorHasDefaultVehicle): void {
                $isDefault = (bool) ($row->is_default ?? false) && ! $survivorHasDefaultVehicle;
                $survivorHasDefaultVehicle = $survivorHasDefaultVehicle || $isDefault;
                DB::table('product_vehicle_compatibility')->where('id', $row->id)
                    ->update(['product_id' => $survivorId, ...($hasDefaultColumn ? ['is_default' => $isDefault] : [])]);
            });

        $survivorCategory = Product::query()->whereKey($survivorId)->value('category_id');
        DB::table('product_extra_categories')->where('product_id', $duplicateId)->get()
            ->reject(fn ($row) => (int) $row->category_id === (int) $survivorCategory)
            ->each(fn ($row) => DB::table('product_extra_categories')->insertOrIgnore(['product_id' => $survivorId, 'category_id' => $row->category_id]));

        $this->moveUnlessExists('product_suppliers', $survivorId, $duplicateId, ['supplier_id'], fn ($query) => $query->whereNull('variant_id'));
        $this->moveUnlessExists('product_batches', $survivorId, $duplicateId, ['batch_number']);
        $this->moveUnlessExists('product_vins', $survivorId, $duplicateId, ['vin']);
        $this->moveUnlessExists('oem_references', $survivorId, $duplicateId, ['oem_code']);

        // Existencias por almacén (tabla resumen): se suman si el producto conservado ya tiene fila en ese almacén.
        foreach (DB::table('inventory')->where('product_id', $duplicateId)->get() as $row) {
            $target = DB::table('inventory')->where('product_id', $survivorId)->where('warehouse_id', $row->warehouse_id)->first();
            if ($target) {
                DB::table('inventory')->where('id', $target->id)->update([
                    'stock_available' => $target->stock_available + $row->stock_available,
                    'stock_reserved' => $target->stock_reserved + $row->stock_reserved,
                    'stock_on_order' => $target->stock_on_order + $row->stock_on_order,
                ]);
                DB::table('inventory')->where('id', $row->id)->delete();
            } else {
                DB::table('inventory')->where('id', $row->id)->update(['product_id' => $survivorId]);
            }
        }

        if (Schema::hasTable('alternative_products')) {
            foreach (['primary_product_id', 'alternative_product_id'] as $column) {
                $other = $column === 'primary_product_id' ? 'alternative_product_id' : 'primary_product_id';
                foreach (DB::table('alternative_products')->where($column, $duplicateId)->get() as $row) {
                    if ((int) $row->{$other} !== $survivorId) {
                        DB::table('alternative_products')->insertOrIgnore([
                            ...collect((array) $row)->except('id')->all(),
                            $column => $survivorId,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Historial y movimientos (ventas, compras, inventario...) que apuntan al producto repetido sin marca.
     */
    private function moveHistoryRows(int $survivorId, int $duplicateId): void
    {
        foreach ($this->tablesWith('product_id') as $table) {
            if (! in_array($table, self::SPECIAL_TABLES, true)) {
                DB::table($table)->where('product_id', $duplicateId)->update(['product_id' => $survivorId]);
            }
        }
    }

    /**
     * @param  array<int, string>  $keyColumns
     */
    private function moveUnlessExists(string $table, int $survivorId, int $duplicateId, array $keyColumns, ?\Closure $scope = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $scoped = fn () => DB::table($table)->when($scope, fn ($query) => $scope($query));
        foreach ($scoped()->where('product_id', $duplicateId)->get() as $row) {
            $exists = $scoped()->where('product_id', $survivorId)
                ->where(fn ($query) => collect($keyColumns)->each(fn ($column) => $query->where($column, $row->{$column})))
                ->exists();
            if (! $exists) {
                DB::table($table)->where('id', $row->id)->update(['product_id' => $survivorId]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function tablesWith(string $column): array
    {
        static $cache = [];

        return $cache[$column] ??= collect(Schema::getTables())
            ->filter(fn (array $table) => ($table['schema'] ?? null) === null || in_array($table['schema'], ['public', 'main'], true))
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, $column))
            ->values()
            ->all();
    }
}
