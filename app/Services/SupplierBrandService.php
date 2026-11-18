<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué marcas suministra un proveedor (y qué proveedores tiene una marca), de dos fuentes que conviven
 * (decisión de Luis, 2026-11-18):
 * - "Por sus piezas": calculado de las piezas de esa marca que tienen ese proveedor (product_suppliers).
 * - "Marcada a mano": la lista brand_supplier, para cuando se sabe que el proveedor trae la marca pero
 *   todavía no hay piezas registradas con él.
 * Si una marca tiene piezas, cuenta como "por sus piezas" aunque también esté marcada a mano.
 */
class SupplierBrandService
{
    /**
     * @return Collection<int, array{id: int, name: string, manual: bool, pieces: int, origin: string}>
     */
    public function forSupplier(Supplier $supplier): Collection
    {
        $pieces = ProductSupplier::query()
            ->join('product_variants', 'product_variants.id', '=', 'product_suppliers.variant_id')
            ->where('product_suppliers.supplier_id', $supplier->id)
            ->whereNull('product_variants.deleted_at')
            ->groupBy('product_variants.brand_id')
            ->pluck(DB::raw('count(*)'), 'product_variants.brand_id');
        $manual = $supplier->brands()->pluck('brands.id');

        return $this->rows(
            Brand::query()->whereIn('id', $pieces->keys()->merge($manual)->unique())->get(['id', 'name']),
            $pieces,
            $manual,
        );
    }

    /**
     * @return Collection<int, array{id: int, name: string, manual: bool, pieces: int, origin: string}>
     */
    public function forBrand(Brand $brand): Collection
    {
        $pieces = ProductSupplier::query()
            ->join('product_variants', 'product_variants.id', '=', 'product_suppliers.variant_id')
            ->where('product_variants.brand_id', $brand->id)
            ->whereNull('product_variants.deleted_at')
            ->groupBy('product_suppliers.supplier_id')
            ->pluck(DB::raw('count(*)'), 'product_suppliers.supplier_id');
        $manual = $brand->suppliers()->pluck('suppliers.id');

        return $this->rows(
            Supplier::query()->whereIn('id', $pieces->keys()->merge($manual)->unique())->get(['id', 'name']),
            $pieces,
            $manual,
        );
    }

    /**
     * Marcas que el proveedor suministra, de cualquiera de las dos fuentes.
     *
     * @return Collection<int, int>
     */
    public function brandIdsForSupplier(int $supplierId): Collection
    {
        $supplier = Supplier::query()->find($supplierId);

        return $supplier ? $this->forSupplier($supplier)->pluck('id')->map(fn ($id) => (int) $id) : collect();
    }

    /**
     * @param  Collection<int, mixed>  $models
     * @param  Collection<int|string, mixed>  $pieces
     * @param  Collection<int, mixed>  $manual
     * @return Collection<int, array{id: int, name: string, manual: bool, pieces: int, origin: string}>
     */
    private function rows(Collection $models, Collection $pieces, Collection $manual): Collection
    {
        $manualIds = $manual->map(fn ($id) => (int) $id);

        return $models
            ->map(function ($model) use ($pieces, $manualIds): array {
                $count = (int) ($pieces[$model->id] ?? 0);

                return [
                    'id' => (int) $model->id,
                    'name' => (string) $model->name,
                    'manual' => $manualIds->contains((int) $model->id),
                    'pieces' => $count,
                    'origin' => $count > 0 ? 'pieces' : 'manual',
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
