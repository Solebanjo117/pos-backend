<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportReferenceBatchService
{
    public function __construct(private readonly BrandResolver $brandResolver) {}

    public function createReferences(array $categories, array $brands): array
    {
        $categoryResult = $this->emptyReferenceResult();
        $brandResult = $this->emptyReferenceResult();

        DB::transaction(function () use ($categories, $brands, &$categoryResult, &$brandResult): void {
            $categoryResult = $this->createCategories($categories);
            $brandResult = $this->createBrands($brands);
        });

        return [
            'categories' => $categoryResult,
            'brands' => $brandResult,
            'summary' => $this->summary([$categoryResult, $brandResult]),
        ];
    }

    private function createCategories(array $names): array
    {
        $result = $this->emptyReferenceResult();
        $seen = [];

        foreach ($names as $name) {
            $normalized = $this->normalizedName($name);
            if ($normalized === '') {
                $result['skipped'][] = ['name' => $name, 'message' => 'Referencia vacia omitida.'];

                continue;
            }

            if (isset($seen[$normalized])) {
                $result['skipped'][] = ['name' => trim((string) $name), 'message' => 'Referencia duplicada en el lote.'];

                continue;
            }
            $seen[$normalized] = true;

            if (mb_strlen(trim((string) $name)) > 100) {
                $result['errors'][] = ['name' => trim((string) $name), 'message' => 'La categoria no puede superar 100 caracteres.'];

                continue;
            }

            $category = $this->categoryByName((string) $name);
            if ($category instanceof Category) {
                $result['existing'][] = $this->categoryPayload($category);

                continue;
            }

            $result['created'][] = $this->categoryPayload(Category::query()->create([
                'name' => trim((string) $name),
            ]));
        }

        return $result;
    }

    private function createBrands(array $names): array
    {
        $result = $this->emptyReferenceResult();
        $seen = [];

        foreach ($names as $name) {
            $normalized = $this->normalizedName($name);
            if ($normalized === '') {
                $result['skipped'][] = ['name' => $name, 'message' => 'Referencia vacia omitida.'];

                continue;
            }

            if (isset($seen[$normalized])) {
                $result['skipped'][] = ['name' => trim((string) $name), 'message' => 'Referencia duplicada en el lote.'];

                continue;
            }
            $seen[$normalized] = true;

            [$brand, $created] = $this->brandResolver->firstOrCreateByName($name);
            if (! $created) {
                $result['existing'][] = $this->brandPayload($brand);

                continue;
            }

            $result['created'][] = $this->brandPayload($brand);
        }

        return $result;
    }

    private function emptyReferenceResult(): array
    {
        return [
            'created' => [],
            'existing' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    private function summary(array $results): array
    {
        return [
            'created_count' => collect($results)->sum(fn (array $result): int => count($result['created'])),
            'existing_count' => collect($results)->sum(fn (array $result): int => count($result['existing'])),
            'skipped_count' => collect($results)->sum(fn (array $result): int => count($result['skipped'])),
            'error_count' => collect($results)->sum(fn (array $result): int => count($result['errors'])),
        ];
    }

    private function categoryByName(string $name): ?Category
    {
        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->first();
    }

    private function normalizedName(mixed $value): string
    {
        return mb_strtolower(trim(Str::ascii((string) $value)));
    }

    private function categoryPayload(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
        ];
    }

    private function brandPayload(Brand $brand): array
    {
        return [
            'id' => (int) $brand->id,
            'name' => $brand->name,
            'status' => $brand->status,
        ];
    }
}
