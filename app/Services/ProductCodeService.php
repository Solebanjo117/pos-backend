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
        // Número de parte compartido: si el producto tiene varias marcas (variantes) que comparten su
        // número de parte (sku_base), ese código es del PRODUCTO. Ninguna variante se lo apropia, así
        // las marcas no chocan entre sí, y cada variante conserva su SKU.
        $inheritedCode = null;
        $sharedPartNumber = $variant ? $this->sharedPartNumber($product) : '';
        if ($sharedPartNumber !== '') {
            $codes = collect($codes)
                ->reject(function (array $item) use ($sharedPartNumber, &$inheritedCode): bool {
                    $isShared = $this->normalizer->normalize((string) ($item['code'] ?? '')) === $sharedPartNumber;
                    if ($isShared) {
                        $inheritedCode = trim((string) $item['code']);
                    }

                    return $isShared;
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

            if ($variant && $incomingNormalized->isNotEmpty()) {
                ProductCode::query()
                    ->active()
                    ->where('product_id', $product->id)
                    ->whereNull('variant_id')
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
     * Número de parte del producto (normalizado) cuando lo comparten sus marcas: el producto tiene
     * más de una variante y su sku_base no está vacío. Cadena vacía si no aplica.
     */
    private function sharedPartNumber(Product $product): string
    {
        $partNumber = $this->normalizer->normalize((string) $product->sku_base);
        if ($partNumber === '') {
            return '';
        }

        return $product->variants()->count() > 1 ? $partNumber : '';
    }

    /**
     * Registra el número de parte como código del producto (sin variante) si todavía no existe un
     * código activo igual. Si lo tiene otro producto (posible duplicado), no se toca: la búsqueda
     * sigue encontrándolo por el sku_base.
     */
    private function ensureProductLevelCode(Product $product, string $code): void
    {
        $normalized = $this->normalizer->normalize($code);
        if (ProductCode::query()->active()->where('normalized_code', $normalized)->exists()) {
            return;
        }

        $hasPrimary = ProductCode::query()
            ->active()
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->where('is_primary', true)
            ->exists();

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
            // Con número de parte compartido, el SKU de la variante sigue siendo ese número.
            $variant->forceFill([
                'sku' => $inheritedCode ?? $primary['code'] ?? null,
                'upc' => $upc['code'] ?? null,
            ])->save();

            if ($variant->is_default) {
                $product->forceFill([
                    'sku_base' => $inheritedCode ?? $primary['code'] ?? null,
                ])->save();
            }

            return;
        }

        $product->forceFill([
            'sku_base' => $primary['code'] ?? null,
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
