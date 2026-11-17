<?php

namespace App\Services;

use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proveedores de cada marca (variante): con qué código la vende cada proveedor y a qué costo.
 *
 * Reglas (decisión del jefe, 2026-11-17):
 * - El costo de un proveedor es lo que se pagó en su última compra (se actualiza al recibir).
 * - El proveedor por defecto es el más barato; se guarda en is_preferred y lo calcula el
 *   sistema (no se marca a mano). Al hacer un pedido se puede escoger otro.
 */
class VariantSupplierService
{
    public function __construct(private readonly ProductCodeNormalizer $normalizer) {}

    /**
     * @return Collection<int, ProductSupplier>
     */
    public function forVariant(ProductVariant $variant): Collection
    {
        return ProductSupplier::query()
            ->with('supplier:id,name')
            ->where('variant_id', $variant->id)
            ->orderByDesc('is_preferred')
            ->orderBy('supplier_cost')
            ->orderBy('id')
            ->get();
    }

    /**
     * Agrega o cambia un proveedor de la marca.
     *
     * @param  array{supplier_code?: ?string, supplier_cost?: float|int|string|null, lead_time_days?: ?int, minimum_order_quantity?: ?int}  $data
     */
    public function upsert(ProductVariant $variant, int $supplierId, array $data): ProductSupplier
    {
        return DB::transaction(function () use ($variant, $supplierId, $data): ProductSupplier {
            $attributes = ['product_id' => $variant->product_id];

            if (array_key_exists('supplier_code', $data)) {
                $code = trim((string) ($data['supplier_code'] ?? ''));
                $attributes['supplier_code'] = $code !== '' ? $code : null;
                $attributes['supplier_code_normalized'] = $code !== '' ? $this->normalizer->normalize($code) : null;
            }
            if (array_key_exists('supplier_cost', $data) && $data['supplier_cost'] !== null) {
                $attributes['supplier_cost'] = round((float) $data['supplier_cost'], 2);
            }
            if (array_key_exists('lead_time_days', $data) && $data['lead_time_days'] !== null) {
                $attributes['lead_time_days'] = (int) $data['lead_time_days'];
            }
            if (array_key_exists('minimum_order_quantity', $data) && $data['minimum_order_quantity'] !== null) {
                $attributes['minimum_order_quantity'] = max(1, (int) $data['minimum_order_quantity']);
            }

            $link = ProductSupplier::query()->firstOrNew(['variant_id' => $variant->id, 'supplier_id' => $supplierId]);
            if (! $link->exists) {
                $link->fill(['supplier_cost' => 0, 'lead_time_days' => 0, 'minimum_order_quantity' => 1, 'is_preferred' => false]);
            }
            $link->fill($attributes)->save();

            $this->refreshDefault($variant->id);

            return $link->fresh(['supplier:id,name']);
        });
    }

    public function remove(ProductVariant $variant, int $supplierId): void
    {
        DB::transaction(function () use ($variant, $supplierId): void {
            ProductSupplier::query()->where('variant_id', $variant->id)->where('supplier_id', $supplierId)->delete();
            $this->refreshDefault($variant->id);
        });
    }

    /**
     * Una compra recibida: el costo de ese proveedor pasa a ser lo que se pagó.
     */
    public function recordPurchase(int $productId, int $variantId, int $supplierId, float $unitCost, mixed $purchasedAt = null): void
    {
        if ($unitCost <= 0) {
            return;
        }

        DB::transaction(function () use ($productId, $variantId, $supplierId, $unitCost, $purchasedAt): void {
            $link = ProductSupplier::query()->firstOrNew(['variant_id' => $variantId, 'supplier_id' => $supplierId]);
            if (! $link->exists) {
                $link->fill(['product_id' => $productId, 'lead_time_days' => 0, 'minimum_order_quantity' => 1, 'is_preferred' => false]);
            }
            $link->fill([
                'supplier_cost' => round($unitCost, 2),
                'last_purchased_at' => $purchasedAt ?? now(),
            ])->save();

            $this->refreshDefault($variantId);
        });
    }

    /**
     * Marca como proveedor por defecto (is_preferred) al más barato de la marca. Los costos en 0 son
     * desconocidos y solo cuentan si ninguno tiene costo. Empate: la compra más reciente, luego el
     * vínculo más antiguo.
     */
    public function refreshDefault(int $variantId): void
    {
        $links = ProductSupplier::query()->where('variant_id', $variantId)->get();
        if ($links->isEmpty()) {
            return;
        }

        $withCost = $links->filter(fn (ProductSupplier $link) => (float) $link->supplier_cost > 0);
        $candidates = $withCost->isNotEmpty() ? $withCost : $links;
        $cheapest = $candidates
            ->sort(function (ProductSupplier $a, ProductSupplier $b): int {
                return [(float) $a->supplier_cost, -($a->last_purchased_at?->getTimestamp() ?? 0), $a->id]
                    <=> [(float) $b->supplier_cost, -($b->last_purchased_at?->getTimestamp() ?? 0), $b->id];
            })
            ->first();

        ProductSupplier::query()->where('variant_id', $variantId)->where('id', '!=', $cheapest->id)->where('is_preferred', true)->update(['is_preferred' => false]);
        if (! $cheapest->is_preferred) {
            ProductSupplier::query()->whereKey($cheapest->id)->update(['is_preferred' => true]);
        }
    }
}
