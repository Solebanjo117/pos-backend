<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductVariant;
use App\Services\ProductCodeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileProductCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:reconcile-codes
                            {--dry-run : Audita sin escribir datos (comportamiento predeterminado)}
                            {--apply : Guarda la conciliación; sin esta opción solo audita}
                            {--json : Emite el resumen en JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audita y migra los códigos comerciales heredados de productos y variantes';

    /**
     * Execute the console command.
     */
    public function handle(ProductCodeNormalizer $normalizer): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use --dry-run o --apply, no ambas opciones a la vez.');

            return self::INVALID;
        }

        $candidates = $this->candidates($normalizer);
        $grouped = $candidates->groupBy('normalized_code');
        $primaryCodeByOwner = $candidates
            ->groupBy('owner_key')
            ->map(function (Collection $items): string {
                $preferred = $items->firstWhere('source', 'variant_sku')
                    ?? $items->firstWhere('source', 'sku_base')
                    ?? $items->first();

                return (string) $preferred['normalized_code'];
            });
        $conflicted = $grouped->filter(fn (Collection $items) => $items->pluck('owner_key')->unique()->count() > 1);
        $sameOwnerDuplicates = $grouped->filter(function (Collection $items): bool {
            return $items->count() > 1 && $items->pluck('owner_key')->unique()->count() === 1;
        });

        $summary = [
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'candidate_codes' => $candidates->count(),
            'unique_normalized_codes' => $grouped->count(),
            'same_owner_duplicates' => $sameOwnerDuplicates->count(),
            'cross_owner_conflicts' => $conflicted->count(),
            'legacy_generated' => $candidates->where('type', 'legacy_generated')->count(),
            'products_without_codes' => Product::query()->whereNull('sku_base')->whereNull('alternate_code')->count(),
            'variants_without_codes' => ProductVariant::query()->whereNull('sku')->whereNull('upc')->count(),
        ];

        if ($this->option('apply')) {
            DB::transaction(function () use ($grouped, $primaryCodeByOwner): void {
                foreach ($grouped as $items) {
                    $hasConflict = $items->pluck('owner_key')->unique()->count() > 1;

                    foreach ($items->groupBy('owner_key') as $ownerItems) {
                        $candidate = $ownerItems->sortByDesc('is_primary')->first();

                        $code = ProductCode::withTrashed()->updateOrCreate(
                            [
                                'product_id' => $candidate['product_id'],
                                'variant_id' => $candidate['variant_id'],
                                'normalized_code' => $candidate['normalized_code'],
                            ],
                            [
                                'code' => $candidate['code'],
                                'type' => $candidate['type'],
                                'status' => $hasConflict ? 'conflict' : 'active',
                                'is_primary' => ! $hasConflict
                                    && $primaryCodeByOwner->get($candidate['owner_key']) === $candidate['normalized_code'],
                                'metadata' => [
                                    'migrated_from' => $ownerItems->pluck('source')->values()->all(),
                                ],
                            ]
                        );
                        if ($code->trashed()) {
                            $code->restore();
                        }
                    }
                }
            });
        }

        if ($this->option('json')) {
            $report = [
                ...$summary,
                'cross_owner_conflict_details' => $this->groupDetails($conflicted),
                'same_owner_duplicate_details' => $this->groupDetails($sameOwnerDuplicates),
                'legacy_generated_details' => $candidates
                    ->where('type', 'legacy_generated')
                    ->map(fn (array $candidate): array => $this->candidateDetail($candidate))
                    ->values()
                    ->all(),
            ];

            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Métrica', 'Resultado'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values());
            if (! $this->option('apply')) {
                $this->warn('Auditoría completada sin escrituras. Use --apply después de revisar los conflictos.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function groupDetails(Collection $groups): array
    {
        return $groups
            ->map(fn (Collection $items, string $normalizedCode): array => [
                'normalized_code' => $normalizedCode,
                'owners' => $items
                    ->map(fn (array $candidate): array => $this->candidateDetail($candidate))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidateDetail(array $candidate): array
    {
        return [
            'product_id' => $candidate['product_id'],
            'variant_id' => $candidate['variant_id'],
            'owner' => $candidate['owner_key'],
            'code' => $candidate['code'],
            'source' => $candidate['source'],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function candidates(ProductCodeNormalizer $normalizer): Collection
    {
        $candidates = collect();

        Product::query()->with(['defaultVariant:id,product_id'])->orderBy('id')->chunkById(500, function ($products) use ($candidates, $normalizer): void {
            foreach ($products as $product) {
                $variantId = $product->defaultVariant?->id;
                $this->appendCandidate($candidates, $normalizer, $product, $variantId, $product->sku_base, 'sku_base', true);
                $this->appendCandidate($candidates, $normalizer, $product, $variantId, $product->alternate_code, 'alternate_code', false);
            }
        });

        ProductVariant::query()->orderBy('id')->chunkById(500, function ($variants) use ($candidates, $normalizer): void {
            foreach ($variants as $variant) {
                $product = new Product(['id' => $variant->product_id]);
                $product->id = $variant->product_id;
                $this->appendCandidate($candidates, $normalizer, $product, $variant->id, $variant->sku, 'variant_sku', true);
                $this->appendCandidate($candidates, $normalizer, $product, $variant->id, $variant->upc, 'variant_upc', false);
            }
        });

        return $candidates;
    }

    private function appendCandidate(
        Collection $candidates,
        ProductCodeNormalizer $normalizer,
        Product $product,
        ?int $variantId,
        ?string $code,
        string $source,
        bool $isPrimary
    ): void {
        $normalizedCode = $normalizer->normalize($code);
        if ($normalizedCode === '') {
            return;
        }

        $type = match ($source) {
            'variant_upc' => 'upc',
            'alternate_code' => 'alternate',
            default => preg_match('/^[\pL]{3}[^\pL\pN]*\d{4}$/u', (string) $code) ? 'legacy_generated' : 'sku',
        };

        $candidates->push([
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'owner_key' => $variantId ? 'variant:'.$variantId : 'product:'.$product->id,
            'code' => trim((string) $code),
            'normalized_code' => $normalizedCode,
            'type' => $type,
            'source' => $source,
            'is_primary' => $isPrimary,
        ]);
    }
}
