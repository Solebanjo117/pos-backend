<?php

namespace App\Imports;

use App\Helpers\TaxRateHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\ImportRunRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\ProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductImport extends BaseImport
{
    public const FIELD_IGNORE = 'ignore';

    public const REQUIRED_FIELDS = ['nombre', 'categoria'];

    private const LEGACY_HIDDEN_FIELDS = [
        'warehouse',
        'categorias_extra',
        'atributos',
        'make',
        'model',
        'year',
        'code',
        'engine',
        'vin',
    ];

    private string $mode = 'create_update';

    private ?int $importRunId = null;

    private array $missingBrands = [];

    private array $brandSuggestions = [];

    private array $productIdsByImportKey = [];

    public function __construct(private readonly ProductService $productService) {}

    public function forMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function forImportRun(?int $importRunId): self
    {
        $this->importRunId = $importRunId;

        return $this;
    }

    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'text'],
            ['key' => 'system_id', 'label' => 'System ID', 'type' => 'text'],
            ['key' => 'brand', 'label' => 'Marca / Variante', 'type' => 'text'],
            ['key' => 'categoria', 'label' => 'Categoria', 'type' => 'searchable', 'required' => true, 'endpoint' => '/categories/all'],
            ['key' => 'costo', 'label' => 'Costo', 'type' => 'number'],
            ['key' => 'precio', 'label' => 'Precio', 'type' => 'number'],
            ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'number'],
            ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Bujia Iridium',
            'sku' => 'SKU-001',
            'system_id' => '10000001',
            'brand' => 'NGK',
            'categoria' => 'Encendido',
            'costo' => '4.50',
            'precio' => '7.99',
            'cantidad' => '12',
            'descripcion' => 'Bujia para motor gasolina',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'producto';
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, max_rows: int, summary: array<string, mixed>, sheets: array<int, array<string, mixed>>, selected_sheet: array<string, mixed>, recommended_sheet: ?array<string, mixed>, sheet_detection_warning: ?string}
     */
    public function previewWithSummary(UploadedFile $file, int $maxRows = 200, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $sheets = $this->inspectSheets($file, $mapping);
        $recommendedSheet = collect($sheets)->firstWhere('is_recommended', true);

        if ($recommendedSheet === null && $sheetIndex === null && ($sheetName === null || trim($sheetName) === '')) {
            throw new \RuntimeException('No se encontro una hoja compatible con productos. Selecciona una hoja o revisa los encabezados.');
        }

        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);
        $rows = $this->extractRows($file, $sheetIndex, $sheetName, $mapping);
        $previewRows = $rows
            ->take($maxRows)
            ->map(function (array $row): array {
                $rowErrors = $this->validateRow($row);
                $rowWarnings = $this->getWarningsForRow($row);

                return [
                    'row' => $row['__row'],
                    'data' => $this->mapRowToPreview($row, $rowErrors, $rowWarnings),
                    'is_valid' => empty($rowErrors),
                    'errors' => $rowErrors,
                    'warnings' => $rowWarnings,
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $previewRows,
            'max_rows' => $maxRows,
            'summary' => $this->buildPreviewSummary($rows->take($maxRows)),
            'sheets' => $sheets,
            'selected_sheet' => $selectedSheet,
            'recommended_sheet' => $recommendedSheet,
            'detected_columns' => $this->detectedColumns($selectedSheet),
            'suggested_mapping' => $this->suggestedMapping($selectedSheet),
            'required_fields' => self::REQUIRED_FIELDS,
            'sheet_detection_warning' => $selectedSheet['is_compatible']
                ? null
                : 'La hoja seleccionada no parece tener encabezados completos de productos.',
        ];
    }

    public function validFields(): array
    {
        return collect($this->getColumns())
            ->pluck('key')
            ->merge(self::LEGACY_HIDDEN_FIELDS)
            ->push(self::FIELD_IGNORE)
            ->values()
            ->all();
    }

    private function detectedColumns(array $sheet): array
    {
        return collect($sheet['source_headers'] ?? [])
            ->map(fn (string $header): array => [
                'key' => $header,
                'label' => $header,
                'suggested_field' => $this->suggestedFieldForHeader($header),
            ])
            ->values()
            ->all();
    }

    private function suggestedMapping(array $sheet): array
    {
        return collect($sheet['source_headers'] ?? [])
            ->mapWithKeys(fn (string $header): array => [$header => $this->suggestedFieldForHeader($header)])
            ->all();
    }

    private function suggestedFieldForHeader(string $header): string
    {
        $mapped = $this->mapHeader($header);
        $visibleFields = collect($this->getColumns())->pluck('key')->all();

        return in_array($mapped, $visibleFields, true)
            ? $mapped
            : self::FIELD_IGNORE;
    }

    protected function analyzeSheetHeaders(array $headers, int $rowCount): array
    {
        $headerSet = collect($headers)->flip();
        $matchedHeaders = [];
        $score = 0;

        $headerWeights = [
            'nombre' => 5,
            'system_id' => 4,
            'sku' => 4,
            'brand' => 3,
            'categoria' => 5,
            'costo' => 2,
            'precio' => 2,
            'cantidad' => 2,
        ];

        foreach ($headerWeights as $header => $weight) {
            if ($headerSet->has($header)) {
                $matchedHeaders[] = $header;
                $score += $weight;
            }
        }

        $hasName = $headerSet->has('nombre');
        $hasCategory = $headerSet->has('categoria');
        $hasIdentityOrOperationalData = $headerSet->has('system_id')
            || $headerSet->has('sku')
            || $headerSet->has('brand')
            || $headerSet->has('costo')
            || $headerSet->has('precio')
            || $headerSet->has('cantidad');
        $missingRequiredHeaders = [];

        if (! $hasName) {
            $missingRequiredHeaders[] = 'nombre';
        }

        if (! $hasCategory) {
            $missingRequiredHeaders[] = 'categoria';
        }

        if (! $hasIdentityOrOperationalData) {
            $missingRequiredHeaders[] = 'system_id/sku/brand/costo/precio/cantidad';
        }

        return [
            'matched_headers' => $matchedHeaders,
            'missing_required_headers' => $missingRequiredHeaders,
            'score' => $rowCount > 0 ? $score : 0,
            'is_compatible' => $rowCount > 0 && $hasName && $hasCategory && $hasIdentityOrOperationalData,
        ];
    }

    protected function recommendedSheetIndex(array $sheets): int
    {
        $recommendedSheet = collect($sheets)
            ->filter(fn (array $sheet): bool => (bool) $sheet['is_compatible'])
            ->sortByDesc(fn (array $sheet): int => (int) $sheet['score'])
            ->first();

        if ($recommendedSheet === null) {
            throw new \RuntimeException('No se encontro una hoja compatible con productos. Selecciona una hoja o revisa los encabezados.');
        }

        return (int) $recommendedSheet['index'];
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name', 'description', 'item', 'producto', 'product' => 'nombre',
            'sku', 'sku_base', 'code', 'codigo', 'custom_sku' => 'sku',
            'system_id', 'systemid', 'id_sistema' => 'system_id',
            'brand', 'marca_producto', 'marca_variante', 'variant', 'variante', 'manufacturer', 'fabricante' => 'brand',
            'descripcion', 'product_description', 'detalle' => 'descripcion',
            'categoria', 'category' => 'categoria',
            'costo', 'cost', 'unit_cost', 'avg_cost' => 'costo',
            'precio', 'price', 'retail_price', 'sale_price', 'unit_price' => 'precio',
            'cantidad', 'quantity', 'qty', 'stock', 'on_hand' => 'cantidad',
            'warehouse', 'almacen', 'bodega' => 'warehouse',
            'categorias_extra', 'extra_categories', 'extra_category_ids' => 'categorias_extra',
            'atributos', 'attributes' => 'atributos',
            'make', 'marca', 'vehicle_make' => 'make',
            'model', 'modelo', 'vehicle_model' => 'model',
            'year', 'ano', 'years', 'year_range' => 'year',
            'code', 'codigo', 'chassis', 'chassis_code' => 'code',
            'engine', 'motor', 'engine_code' => 'engine',
            'vin', 'numero_vin', 'vin_number' => 'vin',
            default => $header,
        };
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (trim((string) ($row['nombre'] ?? '')) === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        if (trim((string) ($row['categoria'] ?? '')) === '') {
            $errors[] = 'El campo categoria es obligatorio.';
        } else {
            try {
                $this->resolveExistingIdByNameOrCode(Category::class, $row['categoria'], ['name', 'slug']);
            } catch (\RuntimeException) {
                $errors[] = "La categoria '{$row['categoria']}' no existe. Creala primero para evitar duplicados.";
            }
        }

        $brandName = $this->brandNameForRow($row);
        if ($brandName !== null && ! $this->brandExists($brandName)) {
            $this->rememberMissingBrand($brandName);
            $errors[] = "La marca \"{$brandName}\" no existe. Creala antes de importar.";
        }

        try {
            $this->parseProductAttributes($row['atributos'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        if (! $this->hasExplicitVehicleColumns($row) && trim((string) ($row['nombre'] ?? '')) !== '') {
            try {
                $this->resolveVehicleCompatibilityFromName((string) $row['nombre']);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($this->splitDelimitedValues($row['vin'] ?? null) as $vin) {
            if (mb_strlen($vin) > 40) {
                $errors[] = "El VIN '{$vin}' no puede tener mas de 40 caracteres.";
            }
        }

        foreach (['costo' => 'costo', 'precio' => 'precio'] as $key => $label) {
            if (($row[$key] ?? null) !== null && trim((string) $row[$key]) !== '' && $this->parseMoney($row[$key]) === null) {
                $errors[] = "El campo {$label} debe ser numerico.";
            }
        }

        if (($row['cantidad'] ?? null) !== null && trim((string) $row['cantidad']) !== '' && $this->parseInteger($row['cantidad']) === null) {
            $errors[] = 'El campo cantidad debe ser numerico.';
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        if ($this->mode === ImportRun::MODE_VALIDATE_ONLY) {
            return ['id' => null];
        }

        $categoryId = $this->resolveExistingIdByNameOrCode(Category::class, $row['categoria'], ['name', 'slug']);
        $category = Category::query()->findOrFail($categoryId);

        $extraCategoryIds = $this->resolveExtraCategoryIds($row['categorias_extra'] ?? null, $categoryId);
        $technicalCategories = $this->categoriesForTechnicalExtraction($category, $extraCategoryIds);
        $technicalName = $this->analyzeTechnicalName((string) ($row['nombre'] ?? ''), $technicalCategories);
        $attributes = $this->resolveAttributes($row, $technicalName);

        $productData = [
            'name' => $technicalName['clean_name'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'category_id' => $categoryId,
            'status' => 'active',
            'attributes' => $attributes,
            '__import_product_key' => $this->productImportKey($categoryId, (string) ($row['nombre'] ?? '')),
        ];

        if (! empty($extraCategoryIds)) {
            $productData['extra_category_ids'] = $extraCategoryIds;
        }

        $compatibility = $this->hasExplicitVehicleColumns($row)
            ? $this->resolveVehicleCompatibility($row)
            : ($this->resolveVehicleCompatibilityFromName((string) ($row['nombre'] ?? ''))['compatibility'] ?? []);

        if ($compatibility !== []) {
            $productData['vehicle_compatibility'] = $compatibility;
        }

        $vins = $this->splitDelimitedValues($row['vin'] ?? null);
        if ($vins !== []) {
            $productData['vins'] = array_map(fn (string $vin): string => strtoupper(trim($vin)), $vins);
        }

        $existingProduct = $this->findExistingProduct($productData);

        if ($this->mode === ImportRun::MODE_CREATE_ONLY && $existingProduct !== null && $this->rowVariantExists($existingProduct, $row)) {
            throw new \RuntimeException('El producto o variante ya existe y el modo actual solo permite crear nuevos.');
        }

        if ($this->mode === ImportRun::MODE_UPDATE_STOCK_PRICE && $existingProduct === null) {
            throw new \RuntimeException('No se encontro el producto existente para actualizar stock/precio.');
        }

        $product = $existingProduct;
        if ($product === null) {
            if ($this->mode === ImportRun::MODE_UPDATE_STOCK_PRICE) {
                throw new \RuntimeException('No se encontro el producto existente para actualizar stock/precio.');
            }
            $product = $this->productService->createProduct($productData);
            $this->recordImportModel(Product::class, $product->id, 'created', ['name' => $product->name]);
        } else {
            $this->recordImportModel(Product::class, $product->id, 'updated', ['name' => $product->name]);
        }

        $this->productIdsByImportKey[$productData['__import_product_key']] = $product->id;

        $variants = $row['__variants'] ?? [];
        $rowVariant = $this->buildVariantDataFromRow($row);
        if ($rowVariant !== null) {
            $variants[] = $rowVariant;
        }

        if (is_array($variants) && ! empty($variants)) {
            foreach ($variants as $variantData) {
                $this->createEnrichedVariant($product, $variantData);
            }
        }

        return $product;
    }

    public function validateFileInChunks(UploadedFile|string $file, int $chunkSize = 500, ?callable $afterChunk = null, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): void
    {
        $this->forMode(ImportRun::MODE_VALIDATE_ONLY);
        $this->validateInChunks($file, $chunkSize, $afterChunk, $sheetIndex, $sheetName, $mapping);
    }

    private function rowVariantExists(Product $product, array $row): bool
    {
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        if ($systemId !== null) {
            return ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('system_id', $systemId)
                ->exists();
        }

        $brandName = $this->nullIfEmpty($row['brand'] ?? null) ?? 'Generica';
        $brand = Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])->first();

        if (! $brand) {
            return false;
        }

        return $product->variants()
            ->where('brand_id', $brand->id)
            ->where('sku', $this->nullIfEmpty($row['sku'] ?? null) ?? ($product->sku_base ?? ''))
            ->exists();
    }

    private function buildPreviewSummary(Collection $rows): array
    {
        $groups = [];
        $missingCategories = [];
        $missingBrands = [];
        $detectedAttributes = [];
        $categorySuggestions = [];
        $brandSuggestions = [];

        foreach ($rows as $row) {
            $categoryName = trim((string) ($row['categoria'] ?? ''));
            $category = $this->findCategoryByNameOrCode($categoryName);
            $brandName = $this->brandNameForRow($row);

            if ($categoryName !== '' && $category === null) {
                $missingCategories[] = $categoryName;
                $suggestion = $this->suggestCategory($categoryName);
                if ($suggestion !== null) {
                    $categorySuggestions[] = [
                        'value' => $categoryName,
                        'suggestion' => $suggestion['name'],
                        'score' => $suggestion['score'],
                    ];
                }
            }

            if ($brandName !== null && ! $this->brandExists($brandName)) {
                $missingBrands[] = $brandName;
                $suggestion = $this->suggestBrand($brandName);
                if ($suggestion !== null) {
                    $brandSuggestions[] = [
                        'value' => $brandName,
                        'suggestion' => $suggestion['name'],
                        'score' => $suggestion['score'],
                        'can_create' => true,
                    ];
                }
            }

            $technicalName = null;
            if ($category !== null) {
                $extraCategoryIds = $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, (int) $category->id);
                $technicalName = $this->analyzeTechnicalName(
                    (string) ($row['nombre'] ?? ''),
                    $this->categoriesForTechnicalExtraction($category, $extraCategoryIds),
                );
            }

            $productKey = $this->productGroupingKey($row);
            if ($productKey !== '') {
                $variantSku = $this->nullIfEmpty($row['sku'] ?? null);
                $groups[$productKey] ??= [
                    'product_key' => $productKey,
                    'product_name' => $technicalName['clean_name'] ?? $this->productNameForGrouping($row),
                    'original_name' => $this->originalProductName($row),
                    'clean_name' => $technicalName['clean_name'] ?? $this->productNameForGrouping($row),
                    'attributes_fingerprint' => $this->attributesFingerprint($technicalName['attributes'] ?? []),
                    'rows' => 0,
                    'variant_keys' => [],
                    'variant_skus' => [],
                    'variants' => [],
                ];
                $groups[$productKey]['rows']++;
                $groups[$productKey]['variant_keys'][] = $this->variantGroupingKey($row);
                if ($variantSku !== null) {
                    $groups[$productKey]['variant_skus'][] = $variantSku;
                }
                $variantPreview = $this->previewVariantDataFromRow($row);
                if ($variantPreview !== null) {
                    $groups[$productKey]['variants'][] = $variantPreview;
                }
            }

            if ($technicalName !== null) {
                foreach ($technicalName['attributes'] as $attribute) {
                    $detectedAttributes[] = $attribute['name'];
                }
            }
        }

        return [
            'groups' => collect($groups)
                ->map(function (array $group): array {
                    $variantSkus = collect($group['variant_skus'])->filter()->unique()->values();

                    return [
                        'product_key' => $group['product_key'],
                        'product_name' => $group['product_name'],
                        'original_name' => $group['original_name'],
                        'clean_name' => $group['clean_name'],
                        'attributes_fingerprint' => $group['attributes_fingerprint'],
                        'rows' => $group['rows'],
                        'variant_count' => collect($group['variant_keys'])->filter()->unique()->count(),
                        'variant_skus' => $variantSkus->all(),
                        'variants' => collect($group['variants'])
                            ->unique(fn (array $variant): string => $variant['variant_key'])
                            ->values()
                            ->all(),
                        'sku_warning' => $variantSkus->count() > 1
                            ? 'El codigo cambia por variante; se importara como SKU de variante.'
                            : null,
                    ];
                })
                ->values()
                ->all(),
            'productos_estimados' => count($groups),
            'variantes_estimadas' => collect($groups)
                ->sum(fn (array $group): int => collect($group['variant_keys'])->filter()->unique()->count()),
            'missing_categories' => array_values(array_unique($missingCategories)),
            'missing_references' => [
                'brands' => array_values(array_unique($missingBrands)),
            ],
            'detected_attributes' => array_values(array_unique($detectedAttributes)),
            'suggestions' => [
                'categories' => collect($categorySuggestions)->unique('value')->values()->all(),
                'brands' => collect($brandSuggestions)->unique('value')->values()->all(),
            ],
        ];
    }

    private function suggestCategory(string $value): ?array
    {
        $normalizedValue = $this->attributeNameKey($value);
        $best = null;

        foreach (Category::query()->select(['id', 'name'])->get() as $category) {
            $normalizedCategory = $this->attributeNameKey($category->name);
            similar_text($normalizedValue, $normalizedCategory, $score);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'name' => $category->name,
                    'score' => round($score, 2),
                ];
            }
        }

        return ($best !== null && $best['score'] >= 70) ? $best : null;
    }

    public function referenceSummary(): array
    {
        return [
            'missing_references' => [
                'brands' => array_values(array_unique($this->missingBrands)),
            ],
            'suggestions' => [
                'brands' => collect($this->brandSuggestions)->unique('value')->values()->all(),
            ],
        ];
    }

    private function brandNameForRow(array $row): ?string
    {
        $brandName = $this->nullIfEmpty($row['brand'] ?? null);

        if ($brandName !== null) {
            return $brandName;
        }

        return $this->rowHasVariantPayload($row) ? 'Generica' : null;
    }

    private function rowHasVariantPayload(array $row): bool
    {
        return $this->nullIfEmpty($row['system_id'] ?? null) !== null
            || $this->parseMoney($row['costo'] ?? null) !== null
            || $this->parseMoney($row['precio'] ?? null) !== null
            || $this->parseInteger($row['cantidad'] ?? null) !== null;
    }

    private function brandExists(string $brandName): bool
    {
        return Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($brandName))])
            ->exists();
    }

    private function rememberMissingBrand(string $brandName): void
    {
        $this->missingBrands[] = $brandName;
        $suggestion = $this->suggestBrand($brandName);

        if ($suggestion !== null) {
            $this->brandSuggestions[] = [
                'value' => $brandName,
                'suggestion' => $suggestion['name'],
                'score' => $suggestion['score'],
                'can_create' => true,
            ];
        }
    }

    private function suggestBrand(string $value): ?array
    {
        return $this->suggestModelName(Brand::class, $value);
    }

    private function suggestModelName(string $modelClass, string $value): ?array
    {
        $normalizedValue = $this->attributeNameKey($value);
        $best = null;

        foreach ($modelClass::query()->select(['id', 'name'])->get() as $model) {
            $normalizedName = $this->attributeNameKey($model->name);
            similar_text($normalizedValue, $normalizedName, $score);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'name' => $model->name,
                    'score' => round($score, 2),
                ];
            }
        }

        return ($best !== null && $best['score'] >= 70) ? $best : null;
    }

    private function productGroupingKey(array $row): string
    {
        $category = $this->normalizeImportKey((string) ($row['categoria'] ?? ''));
        $name = $this->normalizeImportKey($this->originalProductName($row));

        if ($category === '' || $name === '') {
            return '';
        }

        return $category.'|'.$name;
    }

    private function variantGroupingKey(array $row): string
    {
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        if ($systemId !== null) {
            return 'system:'.$systemId;
        }

        $sku = $this->nullIfEmpty($row['sku'] ?? null);
        if ($sku !== null) {
            return 'sku:'.$sku;
        }

        return 'brand:'.$this->normalizeImportKey((string) ($row['brand'] ?? 'Generica')).'|name:'.$this->normalizeImportKey($this->originalProductName($row));
    }

    private function productNameForGrouping(array $row): string
    {
        $name = (string) ($row['nombre'] ?? '');
        $categoryName = trim((string) ($row['categoria'] ?? ''));

        if ($name === '' || $categoryName === '') {
            return $name;
        }

        $category = $this->findCategoryByNameOrCode($categoryName);
        if ($category === null) {
            return $name;
        }

        $extraCategoryIds = $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, (int) $category->id);

        return (string) $this->analyzeTechnicalName(
            $name,
            $this->categoriesForTechnicalExtraction($category, $extraCategoryIds),
        )['clean_name'];
    }

    private function originalProductName(array $row): string
    {
        return (string) ($row['nombre'] ?? '');
    }

    private function productImportKey(int $categoryId, string $originalName): string
    {
        return $categoryId.'|'.$this->normalizeImportKey($originalName);
    }

    private function normalizeImportKey(string $value): string
    {
        return mb_strtolower(trim(Str::ascii($value)));
    }

    private function findExistingProduct(array $productData): ?Product
    {
        $name = mb_strtolower(trim((string) $productData['name']));
        $sku = $this->nullIfEmpty($productData['sku_base'] ?? null);
        $importKey = $this->nullIfEmpty($productData['__import_product_key'] ?? null);

        if ($importKey !== null && isset($this->productIdsByImportKey[$importKey])) {
            $product = Product::query()->find($this->productIdsByImportKey[$importKey]);
            if ($product !== null) {
                return $product;
            }
        }

        $matches = Product::query()
            ->with('attributes')
            ->where('category_id', $productData['category_id'] ?? null)
            ->whereRaw('LOWER(name) = ?', [$name])
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        $attributes = is_array($productData['attributes'] ?? null) ? $productData['attributes'] : [];
        if (! empty($attributes)) {
            $attributeMatches = $matches
                ->filter(fn (Product $product): bool => $this->productMatchesImportAttributes($product, $attributes))
                ->values();

            if ($attributeMatches->count() === 1) {
                return $attributeMatches->first();
            }

            if ($attributeMatches->isEmpty()) {
                return null;
            }

            throw new \RuntimeException('Hay mas de un producto con el mismo nombre limpio en esta categoria. Revisa los atributos antes de importar.');
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($sku !== null) {
            $skuMatch = $matches->first(fn (Product $product): bool => $product->sku_base === $sku);
            if ($skuMatch !== null) {
                return $skuMatch;
            }
        }

        throw new \RuntimeException('Hay mas de un producto con el mismo nombre limpio en esta categoria. Revisa los atributos antes de importar.');
    }

    private function productMatchesImportAttributes(Product $product, array $attributes): bool
    {
        return $this->attributesFingerprint($product->attributes->all()) === $this->attributesFingerprint($attributes);
    }

    private function attributesFingerprint(array $attributes): string
    {
        $parts = collect($attributes)
            ->map(function ($attribute): ?string {
                $name = $attribute instanceof ProductAttribute
                    ? $attribute->name
                    : ($attribute['name'] ?? '');
                $value = $attribute instanceof ProductAttribute
                    ? $attribute->value
                    : ($attribute['value'] ?? '');
                $sourceCategoryId = $attribute instanceof ProductAttribute
                    ? $attribute->source_category_id
                    : ($attribute['source_category_id'] ?? null);

                $nameKey = $this->attributeNameKey((string) $name);
                if ($nameKey === '') {
                    return null;
                }

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                return $nameKey.'='.trim((string) $value).'@'.($sourceCategoryId ?? '');
            })
            ->filter()
            ->sort()
            ->values();

        return $parts->implode('|');
    }

    private function buildVariantDataFromRow(array $row): ?array
    {
        $brandName = $this->nullIfEmpty($row['brand'] ?? null);
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        $cost = $this->parseMoney($row['costo'] ?? null);
        $price = $this->parseMoney($row['precio'] ?? null);
        $quantity = $this->parseInteger($row['cantidad'] ?? null);

        if ($brandName === null && $systemId === null && $cost === null && $price === null && $quantity === null) {
            return null;
        }

        if ($brandName === null) {
            $brandName = 'Generica';
        }

        $variantData = [
            'brand' => ['name' => $brandName],
            'sku' => $this->nullIfEmpty($row['sku'] ?? null),
            'system_id' => $systemId,
        ];

        if ($cost !== null || $price !== null) {
            $variantData['pricing'] = [
                'base_price' => $price ?? 0,
                'cost_base' => $cost ?? 0,
                'cost_miscellaneous' => 0,
            ];
        }

        if ($quantity !== null && $quantity > 0) {
            $warehouseId = $this->resolveWarehouseId($row['warehouse'] ?? null);
            if ($warehouseId !== null) {
                $variantData['initial_inventory'] = [[
                    'warehouse_id' => $warehouseId,
                    'stock_available' => $quantity,
                ]];
            }
        }

        return $variantData;
    }

    private function resolveWarehouseId(mixed $value): ?int
    {
        $warehouseName = $this->nullIfEmpty($value);

        if ($warehouseName !== null) {
            return $this->resolveExistingIdByNameOrCode(Warehouse::class, $warehouseName, ['name', 'code']);
        }

        return Warehouse::query()->orderBy('id')->value('id');
    }

    private function hasExplicitVehicleColumns(array $row): bool
    {
        foreach (['make', 'model', 'code', 'engine', 'vin'] as $key) {
            if ($this->nullIfEmpty($row[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function resolveVehicleCompatibility(array $row): array
    {
        $makeName = $this->nullIfEmpty($row['make'] ?? null);
        if ($makeName === null) {
            return [];
        }

        $makeId = $this->resolveIdOrCreateByName(VehicleMake::class, 'name', $makeName);
        $compatibility = [
            'makes' => [['make_id' => $makeId]],
        ];

        $modelName = $this->nullIfEmpty($row['model'] ?? null);
        if ($modelName === null) {
            return $compatibility;
        }

        [$yearFrom, $yearTo] = $this->parseYearRange($row['year'] ?? null);
        $generation = $this->nullIfEmpty($row['code'] ?? null);
        $model = VehicleModel::query()
            ->where('make_id', $makeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($modelName)])
            ->when($generation, fn ($query) => $query->whereRaw('LOWER(generation) = ?', [mb_strtolower($generation)]))
            ->when(! $generation && $yearFrom !== null, fn ($query) => $query->where('year_from', $yearFrom)->where('year_to', $yearTo ?? $yearFrom))
            ->first();

        if (! $model) {
            $model = VehicleModel::create(array_filter([
                'make_id' => $makeId,
                'name' => $modelName,
                'year_from' => $yearFrom,
                'year_to' => $yearTo ?? $yearFrom,
                'generation' => $generation,
            ], fn ($value) => $value !== null));
        }

        $compatibility['models'] = [['model_id' => $model->id]];

        $chassisCode = $this->nullIfEmpty($row['code'] ?? null);
        if ($chassisCode !== null) {
            $chassis = VehicleChassis::query()
                ->where(function ($query) use ($model) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($chassisCode)])
                ->first();

            if (! $chassis) {
                $chassis = VehicleChassis::create(array_filter([
                    'code' => strtoupper(trim($chassisCode)),
                    'name' => strtoupper(trim($chassisCode)),
                    'model_id' => $model->id,
                    'year_from' => $yearFrom,
                    'year_to' => $yearTo ?? $yearFrom,
                ], fn ($value) => $value !== null));
            }

            $model->chassis()->syncWithoutDetaching([$chassis->id]);
            $compatibility['chassis'] = [['chassis_id' => $chassis->id]];
        }

        $engineCode = $this->nullIfEmpty($row['engine'] ?? null);
        if ($engineCode !== null) {
            $engine = VehicleEngine::query()
                ->where(function ($query) use ($model) {
                    $query->where('model_id', $model->id)
                        ->orWhereHas('models', fn ($modelsQuery) => $modelsQuery->where('vehicle_models.id', $model->id));
                })
                ->whereRaw('LOWER(engine_code) = ?', [mb_strtolower($engineCode)])
                ->first();

            if (! $engine) {
                $engine = VehicleEngine::create([
                    'model_id' => $model->id,
                    'engine_code' => strtoupper(trim($engineCode)),
                ]);
            }

            $engine->models()->syncWithoutDetaching([$model->id]);
            $compatibility['engines'] = [['engine_id' => $engine->id]];
        }

        return $compatibility;
    }

    private function parseYearRange(mixed $yearValue): array
    {
        $yearStr = trim((string) ($yearValue ?? ''));
        if ($yearStr === '') {
            return [null, null];
        }

        if (preg_match('/^(\d{4})\s*(?:-|\x{2013})\s*(\d{4})$/u', $yearStr, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (preg_match('/^(\d{4})$/', $yearStr, $matches) === 1) {
            $year = (int) $matches[1];

            return [$year, $year];
        }

        return [null, null];
    }

    private function resolveVehicleCompatibilityFromName(string $name): ?array
    {
        $normalizedName = $this->vehicleNameKey($name);
        if ($normalizedName === '') {
            return null;
        }

        $modelSignalFound = false;
        $matches = [];

        VehicleModel::query()
            ->with(['make', 'chassis', 'engines', 'enginesMany'])
            ->get()
            ->each(function (VehicleModel $model) use ($normalizedName, &$modelSignalFound, &$matches): void {
                $make = $model->make;
                if (! $make) {
                    return;
                }

                if (! $this->vehicleTextContains($normalizedName, $make->name) || ! $this->vehicleTextContains($normalizedName, $model->name)) {
                    return;
                }

                $modelSignalFound = true;

                $chassisRecords = $model->chassis
                    ->concat(VehicleChassis::query()
                        ->where('model_id', $model->id)
                        ->get())
                    ->unique('id')
                    ->values();
                $engineRecords = $model->engines
                    ->concat($model->enginesMany)
                    ->unique('id')
                    ->values();

                foreach ($chassisRecords as $chassis) {
                    if (! $chassis instanceof VehicleChassis) {
                        continue;
                    }

                    foreach ($engineRecords as $engine) {
                        if (! $engine instanceof VehicleEngine) {
                            continue;
                        }

                        $offset = $this->vehicleCandidateOffset($normalizedName, [
                            [$make->name, $model->name, $chassis->code, $engine->engine_code],
                            [$make->name, $model->name, $engine->engine_code, $chassis->code],
                            [$engine->engine_code, $make->name, $model->name, $chassis->code],
                        ]);

                        if ($offset === null) {
                            continue;
                        }

                        $matches[] = [
                            'key' => "{$make->id}:{$model->id}:{$chassis->id}:{$engine->id}",
                            'offset' => $offset,
                            'make_id' => (int) $make->id,
                            'model_id' => (int) $model->id,
                            'chassis_id' => (int) $chassis->id,
                            'engine_id' => (int) $engine->id,
                            'label' => "{$make->name} {$model->name} {$chassis->code} {$engine->engine_code}",
                        ];
                    }
                }
            });

        $matches = collect($matches)
            ->unique('key')
            ->sortBy('offset')
            ->values();

        if ($matches->count() > 1) {
            throw new \RuntimeException('Se detecto mas de un vehiculo completo en el nombre del producto. Usa las columnas de vehiculo para importar una compatibilidad especifica.');
        }

        if ($matches->isEmpty()) {
            if ($modelSignalFound) {
                throw new \RuntimeException('No se pudo resolver el vehiculo completo del nombre. Verifica que marca, modelo, chasis y motor existan en el catalogo.');
            }

            return null;
        }

        $match = $matches->first();

        return [
            'label' => $match['label'],
            'compatibility' => [
                'makes' => [['make_id' => $match['make_id']]],
                'models' => [['model_id' => $match['model_id']]],
                'chassis' => [['chassis_id' => $match['chassis_id']]],
                'engines' => [['engine_id' => $match['engine_id']]],
            ],
        ];
    }

    private function vehicleCandidateOffset(string $normalizedName, array $candidateSequences): ?int
    {
        $offsets = [];

        foreach ($candidateSequences as $sequence) {
            $phrase = $this->vehicleNameKey(implode(' ', array_filter($sequence)));
            if ($phrase === '') {
                continue;
            }

            if (preg_match('/(?:^| )'.preg_quote($phrase, '/').'(?: |$)/', $normalizedName, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $offsets[] = (int) $matches[0][1];
            }
        }

        if ($offsets === []) {
            return null;
        }

        return min($offsets);
    }

    private function vehicleTextContains(string $normalizedText, string $value): bool
    {
        $needle = $this->vehicleNameKey($value);
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?:^| )'.preg_quote($needle, '/').'(?: |$)/', $normalizedText) === 1;
    }

    private function vehicleNameKey(string $value): string
    {
        $normalized = Str::ascii(mb_strtolower(trim($value)));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private function resolveExtraCategoryIds(mixed $value, int $primaryCategoryId): array
    {
        return collect($this->splitDelimitedValues($value))
            ->map(fn ($categoryName) => $this->resolveExistingIdByNameOrCode(Category::class, $categoryName, ['name', 'slug']))
            ->reject(fn (int $categoryId) => $categoryId === $primaryCategoryId)
            ->unique()
            ->values()
            ->all();
    }

    private function safeResolveExtraCategoryIds(mixed $value, int $primaryCategoryId): array
    {
        try {
            return $this->resolveExtraCategoryIds($value, $primaryCategoryId);
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function categoriesForTechnicalExtraction(Category $primaryCategory, array $extraCategoryIds = []): Collection
    {
        $extraCategories = collect($extraCategoryIds)
            ->map(fn (int $categoryId): ?Category => Category::query()->find($categoryId))
            ->filter();

        return collect([$primaryCategory])
            ->concat($extraCategories)
            ->unique('id')
            ->values();
    }

    private function resolveAttributes(array $row, array $technicalName): array
    {
        $uiAttributes = $row['__attributes'] ?? [];
        if (is_array($uiAttributes) && ! empty($uiAttributes)) {
            $attributes = array_values(array_filter(
                $uiAttributes,
                fn ($attribute) => ! empty($attribute['name']) && ! empty($attribute['value'])
            ));

            return $this->mergeTechnicalAttributes($attributes, $technicalName);
        }

        return $this->mergeTechnicalAttributes($this->parseProductAttributes($row['atributos'] ?? null), $technicalName);
    }

    private function mergeTechnicalAttributes(array $attributes, array $technicalName): array
    {
        $existingNames = collect($attributes)
            ->map(fn (array $attribute): string => $this->attributeNameKey((string) ($attribute['name'] ?? '')))
            ->filter()
            ->flip();

        foreach ($technicalName['attributes'] as $attribute) {
            $key = $this->attributeNameKey($attribute['name']);
            if (! $existingNames->has($key)) {
                $attributes[] = $attribute;
                $existingNames->put($key, true);
            }
        }

        return $attributes;
    }

    protected function getWarningsForRow(array $row): array
    {
        $category = $this->findCategoryByNameOrCode($row['categoria'] ?? null);
        if ($category === null) {
            return [];
        }

        $extraCategoryIds = $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, (int) $category->id);

        return $this->analyzeTechnicalName(
            (string) ($row['nombre'] ?? ''),
            $this->categoriesForTechnicalExtraction($category, $extraCategoryIds),
        )['warnings'];
    }

    private function findCategoryByNameOrCode(mixed $value): ?Category
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $normalized) === 1) {
            return Category::query()->find((int) $normalized);
        }

        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized)])
            ->orWhereRaw('LOWER(slug) = ?', [mb_strtolower($normalized)])
            ->first();
    }

    protected function mapRowToPreview(array $row, ?array $errors = null, ?array $warnings = null): array
    {
        $mapped = parent::mapRowToPreview($row, $errors, $warnings);
        $category = $this->findCategoryByNameOrCode($row['categoria'] ?? null);

        if ($category !== null) {
            $extraCategoryIds = $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, (int) $category->id);
            $technicalName = $this->analyzeTechnicalName(
                (string) ($row['nombre'] ?? ''),
                $this->categoriesForTechnicalExtraction($category, $extraCategoryIds),
            );
            $mapped['nombre'] = $technicalName['clean_name'];
            $mapped['__original_name'] = $technicalName['original_name'];
            $mapped['__clean_name'] = $technicalName['clean_name'];
            $mapped['__attributes_fingerprint'] = $this->attributesFingerprint($technicalName['attributes']);
            $mapped['__attributes'] = $this->previewAttributes($row, $technicalName);
        }

        if (! $this->hasExplicitVehicleColumns($row)) {
            try {
                $vehicleMatch = $this->resolveVehicleCompatibilityFromName((string) ($row['nombre'] ?? ''));
                if ($vehicleMatch !== null) {
                    $mapped['__vehicle_compatibility'] = $vehicleMatch['compatibility'];
                    $mapped['__vehicle_label'] = $vehicleMatch['label'];
                }
            } catch (\RuntimeException) {
                $mapped['__vehicle_compatibility'] = null;
            }
        }

        $mapped['__product_group_key'] = $this->productGroupingKey($row);
        $mapped['__product_key'] = $mapped['__product_group_key'];
        $mapped['__variant_preview'] = $this->previewVariantDataFromRow($row);

        return $mapped;
    }

    private function previewAttributes(array $row, array $technicalName): array
    {
        try {
            return $this->resolveAttributes($row, $technicalName);
        } catch (\RuntimeException) {
            return $technicalName['attributes'] ?? [];
        }
    }

    private function previewVariantDataFromRow(array $row): ?array
    {
        if (! $this->rowHasVariantPayload($row)) {
            return null;
        }

        $variantKey = $this->variantGroupingKey($row);

        return [
            'variant_key' => $variantKey,
            'system_id' => $this->nullIfEmpty($row['system_id'] ?? null),
            'sku' => $this->nullIfEmpty($row['sku'] ?? null),
            'brand' => $this->nullIfEmpty($row['brand'] ?? null) ?? 'Generica',
            'costo' => $this->parseMoney($row['costo'] ?? null),
            'precio' => $this->parseMoney($row['precio'] ?? null),
            'cantidad' => $this->parseInteger($row['cantidad'] ?? null),
            'warehouse' => $this->nullIfEmpty($row['warehouse'] ?? null),
        ];
    }

    /**
     * @return array{original_name: string, clean_name: string, attributes: array<int, array<string, mixed>>, warnings: array<int, string>, matched_segments: array<int, array{segment: string, offset: int, length: int}>}
     */
    private function analyzeTechnicalName(string $name, Collection $categories): array
    {
        $attributes = [];
        $warnings = [];
        $seenAttributeNames = [];
        $matchedSegments = [];

        foreach ($categories as $category) {
            if (! $category instanceof Category) {
                continue;
            }

            foreach ($this->technicalAttributeDefinitions($category) as $alias => $definition) {
                $attributeName = $definition['name'];
                $attributeKey = $this->attributeNameKey($attributeName);
                if ($attributeKey === '' || isset($seenAttributeNames[$attributeKey])) {
                    continue;
                }

                $pattern = '/(?<![A-Za-z0-9])([A-Za-z0-9.,\/+\-]+)\s*'.preg_quote($alias, '/').'\b/i';

                if (preg_match($pattern, $name, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $value = trim($matches[1][0]);
                if ($value === '') {
                    continue;
                }

                $type = $definition['type'] ?? 'string';
                if ($type === 'select') {
                    $option = $this->matchingSelectOption($value, $definition['options'] ?? []);
                    if ($option === null) {
                        $warnings[] = "El valor '{$value}' no coincide con las opciones del atributo {$attributeName} de la categoria {$category->name}.";

                        continue;
                    }
                    $value = $option;
                }

                $attributes[] = [
                    'name' => $attributeName,
                    'value' => $value,
                    'unit' => $definition['unit'] ?? null,
                    'source' => 'category',
                    'source_category_id' => $category->id,
                ];
                $seenAttributeNames[$attributeKey] = true;
                $matchedSegments[] = [
                    'segment' => $matches[0][0],
                    'offset' => $matches[0][1],
                    'length' => strlen($matches[0][0]),
                ];
            }
        }

        $cleanName = $this->cleanTechnicalSegmentsFromName($name, $matchedSegments);

        return [
            'original_name' => $name,
            'clean_name' => $cleanName !== '' ? $cleanName : trim($name),
            'attributes' => $attributes,
            'warnings' => $warnings,
            'matched_segments' => $matchedSegments,
        ];
    }

    private function cleanTechnicalSegmentsFromName(string $name, array $matchedSegments): string
    {
        $cleanName = $name;

        foreach (collect($matchedSegments)->sortByDesc('offset') as $segment) {
            $cleanName = substr_replace(
                $cleanName,
                '',
                (int) $segment['offset'],
                (int) $segment['length'],
            );
        }

        return trim((string) preg_replace('/\s+/', ' ', $cleanName));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function technicalAttributeDefinitions(Category $category): array
    {
        $definitions = [];

        foreach ($category->default_attributes as $definition) {
            $name = trim((string) ($definition['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $canonical = $this->attributeNameKey($name);
            $definitions[$canonical] = $definition + ['name' => $name];

            if ($canonical === 'length') {
                $definitions['lenght'] = $definition + ['name' => $name];
                $definitions['largo'] = $definition + ['name' => $name];
            }
        }

        return $definitions;
    }

    private function matchingSelectOption(string $value, mixed $options): ?string
    {
        if (! is_array($options)) {
            return null;
        }

        $normalizedValue = $this->attributeNameKey($value);
        foreach ($options as $option) {
            $optionValue = trim((string) $option);
            if ($this->attributeNameKey($optionValue) === $normalizedValue) {
                return $optionValue;
            }
        }

        return null;
    }

    private function attributeNameKey(string $name): string
    {
        return mb_strtolower(trim(Str::ascii($name)));
    }

    private function createEnrichedVariant(Product $product, array $variantData): void
    {
        $brandId = null;
        if (! empty($variantData['brand_id'])) {
            $brandId = (int) $variantData['brand_id'];
        } elseif (! empty($variantData['brand']['name'])) {
            $brandId = $this->resolveExistingBrandId($variantData['brand']['name']);
        }

        if (! $brandId) {
            return;
        }

        $importSystemId = $this->nullIfEmpty($variantData['system_id'] ?? null);
        $variantSku = $this->nullIfEmpty($variantData['sku'] ?? null) ?? ($product->sku_base ?? '');
        $variant = $this->findExistingVariant($product, $brandId, $variantSku, $importSystemId);

        if ($variant === null) {
            if ($this->mode === ImportRun::MODE_UPDATE_STOCK_PRICE) {
                throw new \RuntimeException('No se encontro la variante existente para actualizar stock/precio.');
            }
            $variant = $this->productService->createVariant($product, [
                'brand_id' => $brandId,
                'sku' => $variantSku,
                'status' => 'active',
                'is_default' => false,
                'system_id' => $importSystemId,
            ]);
            $this->recordImportModel(ProductVariant::class, $variant->id, 'created', ['sku' => $variant->sku]);
        } else {
            if ($this->mode === ImportRun::MODE_CREATE_ONLY) {
                throw new \RuntimeException('La variante ya existe y el modo actual solo permite crear nuevos.');
            }
            $this->recordImportModel(ProductVariant::class, $variant->id, 'updated', ['sku' => $variant->sku]);
        }

        if ($importSystemId !== null && $variant->system_id !== $importSystemId) {
            $variant->update(['system_id' => $importSystemId]);
        }

        if (! empty($variantData['pricing']) && $variant) {
            $pricing = $variant->pricing()->updateOrCreate([], $this->buildVariantPricingPayload(
                $product->id,
                $variant->id,
                $variantData['pricing']
            ));
            $pricing->calculateMargin();
            $pricing->save();
        }

        $initialInventory = $variantData['initial_inventory'] ?? [];
        if (is_array($initialInventory) && ! empty($initialInventory)) {
            foreach ($initialInventory as $inventoryData) {
                if (empty($inventoryData['warehouse_id'])) {
                    continue;
                }

                $inventory = $variant->inventory()->updateOrCreate([
                    'warehouse_id' => (int) $inventoryData['warehouse_id'],
                ], [
                    'product_id' => $product->id,
                    'quantity_available' => (int) ($inventoryData['stock_available'] ?? 0),
                    'reorder_point' => (int) ($inventoryData['reorder_point'] ?? 0),
                    'reorder_quantity' => (int) ($inventoryData['reorder_quantity'] ?? 0),
                ]);

                $variant->loadMissing('pricing');
                app(\App\Services\InventoryFifoService::class)->ensureOpeningLotForInventory(
                    $product->id,
                    $variant->id,
                    (int) $inventoryData['warehouse_id'],
                    (int) $inventory->quantity_available,
                    (float) ($variant->pricing?->cost_total ?? 0)
                );
            }
        }
    }

    private function recordImportModel(string $modelType, ?int $modelId, string $action, array $summary = []): void
    {
        if ($this->importRunId === null) {
            return;
        }

        ImportRunRecord::query()->firstOrCreate([
            'import_run_id' => $this->importRunId,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'action' => $action,
        ], [
            'summary' => $summary,
        ]);
    }

    private function findExistingVariant(Product $product, int $brandId, string $sku, ?string $systemId): ?ProductVariant
    {
        if ($systemId !== null) {
            $variant = ProductVariant::query()->where('system_id', $systemId)->first();
            if ($variant && (int) $variant->product_id !== (int) $product->id) {
                throw new \RuntimeException("El System ID '{$systemId}' ya pertenece a otro producto.");
            }

            if ($variant) {
                return $variant;
            }
        }

        return $product->variants()
            ->where('brand_id', $brandId)
            ->where('sku', $sku)
            ->first();
    }

    private function resolveExistingBrandId(mixed $value): int
    {
        $brandName = trim((string) $value);
        if ($brandName === '') {
            throw new \RuntimeException('La marca seleccionada no existe.');
        }

        $brand = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])
            ->first();

        if (! $brand) {
            throw new \RuntimeException("La marca \"{$brandName}\" no existe. Creala antes de importar.");
        }

        return $brand->id;
    }

    private function buildVariantPricingPayload(int $productId, int $variantId, array $pricingData): array
    {
        $saleTaxRate = TaxRateHelper::resolve($pricingData['sale_tax_rate'] ?? $pricingData['tax_rate'] ?? null);
        $costTaxRate = array_key_exists('cost_tax_rate', $pricingData)
            ? TaxRateHelper::resolve($pricingData['cost_tax_rate'])
            : $saleTaxRate;
        $costBase = $this->parseMoney($pricingData['cost_base'] ?? null);
        $costMiscellaneous = $this->parseMoney($pricingData['cost_miscellaneous'] ?? null) ?? 0;
        $legacyCost = $this->parseMoney($pricingData['cost'] ?? null);

        $payload = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'base_price' => (float) ($pricingData['base_price'] ?? 0),
            'msrp' => (float) ($pricingData['msrp'] ?? 0),
            'tax_rate' => $saleTaxRate,
            'sale_tax_rate' => $saleTaxRate,
            'cost_tax_rate' => $costTaxRate,
            'tax_type' => $pricingData['tax_type'] ?? 'standard',
            'max_discount_percentage' => (float) ($pricingData['max_discount_percentage'] ?? 0),
            'pricing_formula' => $this->nullIfEmpty($pricingData['pricing_formula'] ?? null),
        ];

        if ($costBase !== null) {
            $payload['cost_base'] = $costBase;
            $payload['cost_miscellaneous'] = $costMiscellaneous;
            $payload['cost'] = round($costBase * (1 + ($costTaxRate / 100)) + $costMiscellaneous, 2);
        } else {
            $payload['cost'] = $legacyCost ?? 0;
        }

        return $payload;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
