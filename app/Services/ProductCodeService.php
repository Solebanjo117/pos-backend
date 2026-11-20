<?php

namespace App\Services;

use App\Exceptions\ProductCodeConflictException;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCodeService
{
    public function __construct(private readonly ProductCodeNormalizer $normalizer) {}

    /**
     * @param  array<int, array<string, mixed>>  $codes
     * @return array<int, array{code: string, normalized_code: string, type: string, is_primary: bool}>
     */
    public function normalizePayload(array $codes): array
    {
        $normalized = collect($codes)
            ->map(function (array $item): ?array {
                $code = trim((string) ($item['code'] ?? ''));
                $normalizedCode = $this->normalizer->normalize($code);

                if ($normalizedCode === '') {
                    return null;
                }

                return [
                    'code' => $code,
                    'normalized_code' => $normalizedCode,
                    'type' => $this->normalizedType((string) ($item['type'] ?? 'manual')),
                    'is_primary' => (bool) ($item['is_primary'] ?? false),
                ];
            })
            ->filter()
            ->values();

        $duplicates = $normalized->groupBy('normalized_code')->filter(fn ($items) => $items->count() > 1);
        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'codes' => 'La lista contiene códigos comerciales equivalentes o repetidos.',
            ]);
        }

        if ($normalized->where('is_primary', true)->count() > 1) {
            throw ValidationException::withMessages([
                'codes' => 'Solo un código puede ser el principal.',
            ]);
        }

        if ($normalized->isNotEmpty() && $normalized->where('is_primary', true)->isEmpty()) {
            $first = $normalized->first();
            $first['is_primary'] = true;
            $normalized->put(0, $first);
        }

        return $normalized->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $codes
     */
    public function syncForProduct(Product $product, array $codes): void
    {
        $this->sync($product, null, $codes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $codes
     */
    public function syncForVariant(ProductVariant $variant, array $codes): void
    {
        $variant->loadMissing('product');
        $this->sync($variant->product, $variant, $codes);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function productCodesFromPayload(array $data): array
    {
        if (array_key_exists('codes', $data)) {
            return is_array($data['codes']) ? $data['codes'] : [];
        }

        return $this->legacyCodes([
            ['code' => $data['sku_base'] ?? null, 'type' => 'sku', 'is_primary' => true],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function variantCodesFromPayload(array $data, ?string $fallbackSku = null): array
    {
        if (array_key_exists('codes', $data)) {
            return is_array($data['codes']) ? $data['codes'] : [];
        }

        return $this->legacyCodes([
            ['code' => $data['sku'] ?? $fallbackSku, 'type' => 'sku', 'is_primary' => true],
            ['code' => $data['upc'] ?? null, 'type' => 'upc', 'is_primary' => false],
        ]);
    }

    public function findActive(string $code): ?ProductCode
    {
        $normalizedCode = $this->normalizer->normalize($code);
        if ($normalizedCode === '') {
            return null;
        }

        return ProductCode::query()
            ->active()
            ->where('normalized_code', $normalizedCode)
            ->with(['product.codes', 'product.variants.codes', 'variant.codes'])
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $codes
     */
    private function sync(Product $product, ?ProductVariant $variant, array $codes): void
    {
        // Los números de parte son del PRODUCTO (sku_base y sus códigos sin variante), tenga una marca
        // o varias. Una marca (variante) nunca se los apropia: en su lista solo quedan sus códigos
        // propios, y su SKU sigue siendo el número de parte si lo usa.
        $inheritedCode = null;
        if ($variant) {
            $partNumber = $this->normalizer->normalize((string) $product->sku_base);
            $productOwned = ProductCode::query()
                ->active()
                ->where('product_id', $product->id)
                ->whereNull('variant_id')
                ->pluck('normalized_code')
                ->push($partNumber)
                ->filter()
                ->all();

            $codes = collect($codes)
                ->reject(function (array $item) use ($partNumber, $productOwned, &$inheritedCode): bool {
                    $normalized = $this->normalizer->normalize((string) ($item['code'] ?? ''));
                    if ($normalized !== '' && $normalized === $partNumber) {
                        $inheritedCode = trim((string) $item['code']);
                    }

                    return $normalized !== '' && in_array($normalized, $productOwned, true);
                })
                ->values()
                ->all();
        }

        $normalizedCodes = $this->normalizePayload($codes);

        DB::transaction(function () use ($product, $variant, $normalizedCodes, $inheritedCode): void {
            if ($inheritedCode !== null) {
                $this->ensureProductLevelCode($product, $inheritedCode);
            }

            $ownerQuery = ProductCode::query()
                ->active()
                ->where('product_id', $product->id)
                ->when($variant, fn ($query) => $query->where('variant_id', $variant->id), fn ($query) => $query->whereNull('variant_id'));

            $existing = (clone $ownerQuery)->lockForUpdate()->get()->keyBy('normalized_code');
            $incomingNormalized = collect($normalizedCodes)->pluck('normalized_code');

            (clone $ownerQuery)->whereNotIn('normalized_code', $incomingNormalized->all())->delete();

            // Un código que se registra como del producto deja de ser de sus marcas (pasa al producto).
            if (! $variant && $incomingNormalized->isNotEmpty()) {
                ProductCode::query()
                    ->active()
                    ->where('product_id', $product->id)
                    ->whereNotNull('variant_id')
                    ->whereIn('normalized_code', $incomingNormalized->all())
                    ->lockForUpdate()
                    ->delete();
            }

            foreach ($normalizedCodes as $codeData) {
                $conflict = ProductCode::query()
                    ->active()
                    ->where('normalized_code', $codeData['normalized_code'])
                    ->where(function ($query) use ($product, $variant): void {
                        $query->where('product_id', '!=', $product->id);
                        if ($variant) {
                            $query->orWhereNull('variant_id')->orWhere('variant_id', '!=', $variant->id);
                        } else {
                            $query->orWhereNotNull('variant_id');
                        }
                    })
                    ->first();

                if ($conflict) {
                    throw new ProductCodeConflictException($conflict);
                }
            }

            (clone $ownerQuery)->update(['is_primary' => false]);

            try {
                foreach ($normalizedCodes as $codeData) {
                    $code = $existing->get($codeData['normalized_code']);
                    $payload = [
                        ...$codeData,
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'status' => 'active',
                    ];

                    if ($code) {
                        $code->update($payload);
                    } else {
                        ProductCode::query()->create($payload);
                    }
                }
            } catch (QueryException $exception) {
                $conflict = ProductCode::query()
                    ->active()
                    ->whereIn('normalized_code', $incomingNormalized->all())
                    ->where(function ($query) use ($product, $variant): void {
                        $query->where('product_id', '!=', $product->id);
                        if ($variant) {
                            $query->orWhereNull('variant_id')->orWhere('variant_id', '!=', $variant->id);
                        } else {
                            $query->orWhereNotNull('variant_id');
                        }
                    })
                    ->first();

                if ($conflict) {
                    throw new ProductCodeConflictException($conflict);
                }

                throw $exception;
            }

            $this->syncLegacyAliases($product, $variant, $normalizedCodes, $inheritedCode);
        });
    }

    /**
     * Registra el número de parte como código del producto (sin variante) si todavía no existe un
     * código activo igual. Si lo tiene una marca del mismo producto (datos guardados antes), pasa al
     * producto. Si lo tiene otro producto (posible duplicado), no se toca: la búsqueda sigue
     * encontrándolo por el sku_base.
     */
    private function ensureProductLevelCode(Product $product, string $code): void
    {
        $normalized = $this->normalizer->normalize($code);
        $hasPrimary = ProductCode::query()
            ->active()
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->where('is_primary', true)
            ->exists();

        $existing = ProductCode::query()->active()->where('normalized_code', $normalized)->first();
        if ($existing) {
            if ($existing->product_id === $product->id && $existing->variant_id !== null) {
                $existing->update(['variant_id' => null, 'is_primary' => ! $hasPrimary]);
            }

            return;
        }

        ProductCode::query()->create([
            'product_id' => $product->id,
            'variant_id' => null,
            'code' => $code,
            'normalized_code' => $normalized,
            'type' => 'sku',
            'status' => 'active',
            'is_primary' => ! $hasPrimary,
            'metadata' => ['shared_part_number' => true],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $codes
     */
    private function syncLegacyAliases(Product $product, ?ProductVariant $variant, array $codes, ?string $inheritedCode = null): void
    {
        $primary = collect($codes)->firstWhere('is_primary', true);
        $upc = collect($codes)->firstWhere('type', 'upc');

        if ($variant) {
            // Las marcas no tienen código propio (2026-11-17): su SKU es el número principal del
            // producto. Solo si el producto no tiene número se usa el de la marca. Los códigos de una
            // marca nunca cambian el número de parte del producto.
            $principal = $this->normalizer->normalize((string) $product->sku_base) !== '' ? trim((string) $product->sku_base) : null;
            $variant->forceFill([
                'sku' => $principal ?? $inheritedCode ?? $primary['code'] ?? null,
                'upc' => $upc['code'] ?? null,
            ])->save();

            return;
        }

        // Todas las marcas usan el número principal del producto como SKU.
        $newPartNumber = $primary['code'] ?? null;
        if ($newPartNumber !== null) {
            $product->variants()->update(['sku' => $newPartNumber]);
        }

        $product->forceFill([
            'sku_base' => $newPartNumber,
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $codes
     * @return array<int, array<string, mixed>>
     */
    private function legacyCodes(array $codes): array
    {
        return collect($codes)
            ->filter(fn (array $code) => is_string($code['code'] ?? null) && trim($code['code']) !== '')
            ->values()
            ->all();
    }

    private function normalizedType(string $type): string
    {
        return in_array($type, ['sku', 'upc', 'alternate', 'manual', 'legacy_generated'], true)
            ? $type
            : 'manual';
    }
}
