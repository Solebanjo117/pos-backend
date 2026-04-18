<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\ProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ProductImport
{
    public array $results = [];

    public array $errors = [];

    public int $successCount = 0;

    public int $errorCount = 0;

    public function __construct(private readonly ProductService $productService)
    {
    }

    public function preview(UploadedFile $file, int $maxRows = 200): array
    {
        [$rows] = $this->extractRows($file);

        return $rows
            ->take($maxRows)
            ->map(function (array $row): array {
                $rowErrors = $this->validateRow($row);

                return [
                    'row' => $row['__row'],
                    'nombre' => $row['name'] ?? '',
                    'sku' => $row['sku'] ?? '',
                    'descripcion' => $row['description'] ?? '',
                    'marca' => $row['brand'] ?? '',
                    'categoria' => $row['category'] ?? '',
                    'costo' => $row['cost'] ?? '',
                    'precio' => $row['price'] ?? '',
                    'sku_variante' => $row['variant_sku'] ?? '',
                    'proveedor' => $row['supplier'] ?? '',
                    'is_valid' => empty($rowErrors),
                    'errors' => $rowErrors,
                ];
            })
            ->values()
            ->all();
    }

    public function import(UploadedFile $file): void
    {
        [$rows] = $this->extractRows($file);

        foreach ($rows as $row) {
            $rowNum = (int) ($row['__row'] ?? 0);

            try {
                $rowErrors = $this->validateRow($row);
                if (!empty($rowErrors)) {
                    throw new \RuntimeException(implode(' ', $rowErrors));
                }

                $brandId = $this->resolveBrandId($row['brand']);
                $categoryId = $this->resolveCategoryId($row['category']);
                $cost = $this->parseMoney($row['cost'] ?? null);
                $price = $this->parseMoney($row['price'] ?? null);

                $productData = [
                    'name' => $row['name'],
                    'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
                    'description' => $this->nullIfEmpty($row['description'] ?? null),
                    'brand_id' => $brandId,
                    'category_id' => $categoryId,
                    'sku' => $this->nullIfEmpty($row['variant_sku'] ?? null) ?: $this->nullIfEmpty($row['sku'] ?? null),
                    'status' => 'active',
                ];

                if ($cost !== null || $price !== null) {
                    $basePrice = $price ?? 0.0;
                    $storeTaxRate = (int) round((float) Setting::get('store_tax_rate', 7));

                    $productData['pricing'] = [
                        'base_price' => $basePrice,
                        'msrp' => $basePrice,
                        'cost' => $cost ?? 0.0,
                        'tax_rate' => $storeTaxRate,
                        'sale_tax_rate' => $storeTaxRate,
                        'cost_tax_rate' => $storeTaxRate,
                        'tax_type' => 'standard',
                        'max_discount_percentage' => 0,
                    ];
                }

                $product = $this->productService->createProduct($productData);

                $supplierName = $this->nullIfEmpty($row['supplier'] ?? null);
                if ($supplierName) {
                    $supplierId = $this->resolveSupplierId($supplierName);
                    if (!$supplierId) {
                        throw new \RuntimeException("Proveedor '{$supplierName}' no encontrado.");
                    }

                    $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
                    if ($variant) {
                        $variant->suppliers()->syncWithoutDetaching([
                            $supplierId => [
                                'supplier_cost' => $cost ?? 0,
                                'is_preferred' => true,
                            ],
                        ]);
                    }
                }

                $this->successCount++;
                $this->results[] = [
                    'row' => $rowNum,
                    'status' => 'ok',
                    'name' => $row['name'],
                    'product_id' => $product->id,
                ];
            } catch (\Throwable $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row' => $rowNum,
                    'name' => $row['name'] ?? '',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    private function extractRows(UploadedFile $file): array
    {
        $sheet = Excel::toCollection(null, $file)->first() ?? collect();

        if ($sheet->isEmpty()) {
            return [collect()];
        }

        $rawHeaders = $sheet->shift();
        $headers = collect($rawHeaders?->toArray() ?? [])->map(fn ($header) => $this->normalizeHeader((string) $header))->all();

        $rows = collect();
        foreach ($sheet->values() as $index => $row) {
            $values = $row->toArray();
            $assoc = [];

            foreach ($headers as $position => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $this->cleanCell($values[$position] ?? null);
            }

            $assoc['__row'] = $index + 2;

            if ($this->isEmptyDataRow($assoc)) {
                continue;
            }

            $rows->push($assoc);
        }

        return [$rows];
    }

    private function validateRow(array $row): array
    {
        $errors = [];

        $name = trim((string) ($row['name'] ?? ''));
        $brandName = trim((string) ($row['brand'] ?? ''));
        $categoryName = trim((string) ($row['category'] ?? ''));

        if ($name === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        if ($brandName === '') {
            $errors[] = 'El campo marca es obligatorio.';
        } elseif (!$this->resolveBrandId($brandName)) {
            $errors[] = "Marca '{$brandName}' no encontrada.";
        }

        if ($categoryName === '') {
            $errors[] = 'El campo categoria es obligatorio.';
        } elseif (!$this->resolveCategoryId($categoryName)) {
            $errors[] = "Categoria '{$categoryName}' no encontrada.";
        }

        $price = $this->parseMoney($row['price'] ?? null);
        if ($price !== null && $price < 0) {
            $errors[] = 'El precio no puede ser negativo.';
        }

        $cost = $this->parseMoney($row['cost'] ?? null);
        if ($cost !== null && $cost < 0) {
            $errors[] = 'El costo no puede ser negativo.';
        }

        return $errors;
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = str_replace([' ', '-', '.'], '_', $normalized);

        return match ($normalized) {
            'nombre', 'name' => 'name',
            'sku' => 'sku',
            'descripcion', 'description' => 'description',
            'marca', 'brand' => 'brand',
            'categoria', 'category' => 'category',
            'costo', 'cost' => 'cost',
            'precio', 'price' => 'price',
            'sku_variante', 'variant_sku', 'sku_variant' => 'variant_sku',
            'proveedor', 'supplier' => 'supplier',
            default => $normalized,
        };
    }

    private function cleanCell(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function parseMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', (string) $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace(',', '', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function resolveBrandId(string $name): ?int
    {
        return Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');
    }

    private function resolveCategoryId(string $name): ?int
    {
        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');
    }

    private function resolveSupplierId(string $name): ?int
    {
        return Supplier::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');
    }

    private function isEmptyDataRow(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === '__row') {
                continue;
            }

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
