<?php

namespace App\Imports;

use App\Helpers\TaxRateHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\ImportRunRecord;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImport extends BaseImport
{
    public const FIELD_IGNORE = 'ignore';

    public const REQUIRED_FIELDS = ['nombre', 'categoria'];

    private const ACTION_CREATE = 'create';

    private const ACTION_UPDATE = 'update';

    private const ACTION_UNCHANGED = 'unchanged';

    private const ACTION_CONFLICT = 'conflict';

    private const ACTION_INVALID = 'invalid';

    private const LEGACY_HIDDEN_FIELDS = [
        'imagen_url',
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

    private array $missingCategories = [];

    private array $brandSuggestions = [];

    private array $missingVehicleCandidates = [];

    private array $productIdsByImportKey = [];

    private array $productsByImportKey = [];

    private array $variantsBySystemId = [];

    private array $variantsByBrandSku = [];

    private array $variantSystemKeysByProduct = [];

    private array $variantBrandSkuKeysByProduct = [];

    private array $productMatchesByCategoryName = [];

    private ?Collection $cachedCategories = null;

    private ?Collection $cachedBrands = null;

    private ?Collection $cachedVehicleCatalog = null;

    private array $categorySuggestionsCache = [];

    private array $brandSuggestionsCache = [];

    private array $vehicleNameResolutionCache = [];

    private array $vehicleCandidateCache = [];

    private array $actionCounts = [];

    private array $conflictCounts = [];

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
     * @return array{rows: array<int, array<string, mixed>>, total_rows: int, max_rows: int, preview_limited: bool, summary: array<string, mixed>, sheets: array<int, array<string, mixed>>, selected_sheet: array<string, mixed>, recommended_sheet: ?array<string, mixed>, sheet_detection_warning: ?string}
     */
    public function previewWithSummary(UploadedFile $file, ?int $maxRows = 200, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $metadata = $this->previewMetadata($file, $sheetIndex, $sheetName, $mapping);
        $selectedSheet = $metadata['selected_sheet'];
        $rowsForPreview = $this->extractRows($file, $sheetIndex, $sheetName, $mapping, $maxRows);
        $shownCount = $rowsForPreview->count();
        $totalRows = (int) ($selectedSheet['row_count'] ?? $shownCount);
        $previewRows = $this->buildPreviewRows($rowsForPreview);

        return [
            'rows' => $previewRows,
            'total_rows' => $totalRows,
            'max_rows' => $maxRows ?? $shownCount,
            'preview_limited' => $totalRows > $shownCount,
            'summary' => $this->buildPreviewSummary($rowsForPreview, $previewRows),
            'sheets' => $metadata['sheets'],
            'selected_sheet' => $selectedSheet,
            'recommended_sheet' => $metadata['recommended_sheet'],
            'detected_columns' => $metadata['detected_columns'],
            'suggested_mapping' => $metadata['suggested_mapping'],
            'required_fields' => $metadata['required_fields'],
            'sheet_detection_warning' => $metadata['sheet_detection_warning'],
        ];
    }

    public function previewMetadata(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $sheets = $this->inspectSheets($file, $mapping);
        $recommendedSheet = collect($sheets)->firstWhere('is_recommended', true);

        if ($recommendedSheet === null && $sheetIndex === null && ($sheetName === null || trim($sheetName) === '')) {
            throw new \RuntimeException('No se encontro una hoja compatible con productos. Selecciona una hoja o revisa los encabezados.');
        }

        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);

        return [
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
        $labels = $sheet['source_header_labels'] ?? [];

        return collect($sheet['source_headers'] ?? [])
            ->map(fn (string $header): array => [
                'key' => $header,
                'label' => $labels[$header] ?? $header,
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
            'costo', 'cost', 'unit_cost', 'default_cost', 'avg_cost' => 'costo',
            'precio', 'price', 'retail_price', 'sale_price', 'unit_price' => 'precio',
            'cantidad', 'quantity', 'qty', 'stock', 'on_hand' => 'cantidad',
            'imagen_url', 'url_imagen', 'image_url', 'image', 'imagen', 'foto', 'photo' => 'imagen_url',
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
        } elseif ($this->findCategoryByNameOrCode($row['categoria']) === null) {
            $this->rememberMissingCategory((string) $row['categoria']);
            $errors[] = "La categoria '{$row['categoria']}' no existe. Creala primero para evitar duplicados.";
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
                $vehicleMatch = $this->resolveVehicleCompatibilityFromName((string) $row['nombre']);
                if ($vehicleMatch === null && ($candidate = $this->vehicleCreationCandidateFromName((string) $row['nombre'])) !== null) {
                    $this->rememberMissingVehicleCandidate($candidate);
                    $errors[] = 'No se pudo resolver el vehiculo completo del nombre. Verifica que marca, modelo, chasis y motor existan en el catalogo.';
                }
            } catch (\RuntimeException $e) {
                if (($candidate = $this->vehicleCreationCandidateFromName((string) $row['nombre'])) !== null) {
                    $this->rememberMissingVehicleCandidate($candidate);
                }
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

        if (($row['imagen_url'] ?? null) !== null && trim((string) $row['imagen_url']) !== '' && $this->imageUrlForRow($row) === null) {
            $errors[] = 'La URL de imagen debe ser una URL valida.';
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        if ($this->mode === ImportRun::MODE_VALIDATE_ONLY) {
            return ['id' => null];
        }

        $category = $this->findCategoryByNameOrCode($row['categoria']);
        if (! $category) {
            throw new \RuntimeException("La categoria '{$row['categoria']}' no existe. Creala primero para evitar duplicados.");
        }
        $categoryId = (int) $category->id;

        $extraCategoryIds = $this->resolveExtraCategoryIds($row['categorias_extra'] ?? null, $categoryId);
        $technicalCategories = $this->categoriesForTechnicalExtraction($category, $extraCategoryIds);
        $technicalName = $this->analyzeTechnicalName((string) ($row['nombre'] ?? ''), $technicalCategories);
        $attributes = $this->resolveAttributes($row, $technicalName);

        $productData = [
            'name' => $technicalName['clean_name'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'image_url' => $this->imageUrlForRow($row),
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

    public function execute(array $rows): void
    {
        $this->prepareLookupContext(collect($rows));
        $rowCollection = collect($rows);
        $duplicateKeys = $this->duplicateRowKeys($rowCollection);

        foreach ($rows as $row) {
            $this->processedCount++;
            $rowNum = (int) ($row['__row'] ?? 0);
            $rowErrors = $this->validateRow($row);
            $rowWarnings = $this->getWarningsForRow($row);
            $previewData = $this->mapRowToPreview($row, $rowErrors, $rowWarnings);
            $operation = $this->rowOperationPreview($row, $previewData, $rowErrors, $duplicateKeys);
            $this->rememberOperationSummary($operation);

            try {
                $result = DB::transaction(function () use ($row, $rowErrors, $operation) {
                    if (! empty($operation['conflicts'])) {
                        throw new \RuntimeException(collect($operation['conflicts'])->pluck('message')->implode(' '));
                    }

                    if (! empty($rowErrors)) {
                        throw new \RuntimeException(implode(' ', $rowErrors));
                    }

                    return $this->processRow($row);
                });

                $this->successCount++;
                $this->results[] = [
                    'row' => $rowNum,
                    'status' => 'ok',
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'id' => $result instanceof Model ? $result->id : ($result['id'] ?? null),
                    'action_type' => $operation['action_type'],
                    'change_preview' => $operation['change_preview'],
                ];
            } catch (\Throwable $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row' => $rowNum,
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'data' => $row,
                    'error' => $e->getMessage(),
                    'action_type' => $operation['action_type'],
                    'change_preview' => $operation['change_preview'],
                    'conflicts' => $operation['conflicts'],
                ];
            }
        }
    }

    public function validateRows(array $rows): void
    {
        $this->validateRowsWithDuplicateKeys($rows, null);
    }

    public function validateRowsWithDuplicateKeys(array $rows, ?array $duplicateKeys): void
    {
        $previewRows = $this->buildPreviewRows(collect($rows), $duplicateKeys);

        foreach ($previewRows as $previewRow) {
            $this->processedCount++;
            $this->validationRows[] = $previewRow;
            $this->rememberOperationSummary($previewRow);

            if ($previewRow['is_valid']) {
                $this->successCount++;
                $this->results[] = [
                    'row' => $previewRow['row'],
                    'status' => 'ok',
                    'label' => $previewRow['data'][$this->getLabelColumn()] ?? '',
                    'id' => null,
                    'action_type' => $previewRow['action_type'],
                    'change_preview' => $previewRow['change_preview'],
                ];

                continue;
            }

            $this->errorCount++;
            $errorMessages = $previewRow['errors'] !== []
                ? $previewRow['errors']
                : collect($previewRow['conflicts'])->pluck('message')->all();
            $this->errors[] = [
                'row' => $previewRow['row'],
                'label' => $previewRow['data'][$this->getLabelColumn()] ?? '',
                'data' => $previewRow['data'],
                'error' => implode(' ', $errorMessages),
                'action_type' => $previewRow['action_type'],
                'change_preview' => $previewRow['change_preview'],
                'conflicts' => $previewRow['conflicts'],
            ];
        }
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
            $key = $this->productSystemKey((int) $product->id, $systemId);
            if (array_key_exists($key, $this->variantSystemKeysByProduct)) {
                return $this->variantSystemKeysByProduct[$key];
            }

            return ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('system_id', $systemId)
                ->exists();
        }

        $brandName = $this->nullIfEmpty($row['brand'] ?? null) ?? 'Generica';
        $brand = $this->brandByName($brandName);

        if (! $brand) {
            return false;
        }

        $sku = $this->nullIfEmpty($row['sku'] ?? null) ?? ($product->sku_base ?? '');
        $brandSkuKey = $this->productBrandSkuKey((int) $product->id, (int) $brand->id, $sku);
        if (array_key_exists($brandSkuKey, $this->variantBrandSkuKeysByProduct)) {
            return $this->variantBrandSkuKeysByProduct[$brandSkuKey];
        }

        return $product->variants()
            ->where('brand_id', $brand->id)
            ->where('sku', $sku)
            ->exists();
    }

    private function buildPreviewRows(Collection $rows, ?array $duplicateKeys = null): array
    {
        $this->prepareLookupContext($rows);
        $duplicateKeys ??= $this->duplicateRowKeys($rows);

        return $rows
            ->map(function (array $row) use ($duplicateKeys): array {
                $rowErrors = $this->validateRow($row);
                $rowWarnings = $this->getWarningsForRow($row);
                $previewData = $this->mapRowToPreview($row, $rowErrors, $rowWarnings);
                $operation = $this->rowOperationPreview($row, $previewData, $rowErrors, $duplicateKeys);
                $visibleData = array_merge($previewData, $operation);
                unset($visibleData['imagen_url']);

                return [
                    'row' => $row['__row'],
                    'data' => $visibleData,
                    'is_valid' => empty($rowErrors) && $operation['action_type'] !== self::ACTION_CONFLICT,
                    'errors' => $rowErrors,
                    'warnings' => $rowWarnings,
                    'action_type' => $operation['action_type'],
                    'change_preview' => $operation['change_preview'],
                    'conflicts' => $operation['conflicts'],
                    'source_data' => $row['__source_data'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function duplicateRowKeys(Collection $rows): array
    {
        return $rows
            ->map(fn (array $row): string => $this->rowConflictKey($row))
            ->filter()
            ->countBy()
            ->all();
    }

    public function duplicateRowKeysForRows(array $rows): array
    {
        return $this->duplicateRowKeys(collect($rows));
    }

    private function rowConflictKey(array $row): string
    {
        $productKey = $this->productGroupingKey($row);
        if ($productKey === '') {
            return '';
        }

        return $productKey.'|'.$this->variantGroupingKey($row);
    }

    private function rowOperationPreview(array $row, array $previewData, array $errors, array $duplicateKeys): array
    {
        $conflicts = [];
        $duplicateKey = $this->rowConflictKey($row);

        if ($duplicateKey !== '' && (int) ($duplicateKeys[$duplicateKey] ?? 0) > 1) {
            $conflicts[] = [
                'field' => 'fila',
                'type' => 'duplicate_in_file',
                'message' => 'Hay otra fila con la misma identidad de producto y variante en este archivo.',
            ];
        }

        $match = ['product' => null, 'variant' => null, 'conflicts' => []];
        try {
            $match = $this->previewExistingMatch($row);
        } catch (\RuntimeException $e) {
            $conflicts[] = [
                'field' => 'producto',
                'type' => 'database_conflict',
                'message' => $e->getMessage(),
            ];
        }

        $conflicts = array_merge($conflicts, $match['conflicts'] ?? []);
        $changePreview = $this->buildChangePreview($row, $previewData, $match['product'] ?? null, $match['variant'] ?? null);

        if ($conflicts !== []) {
            $actionType = self::ACTION_CONFLICT;
        } elseif ($errors !== []) {
            $actionType = self::ACTION_INVALID;
        } elseif (($match['product'] ?? null) === null) {
            $actionType = self::ACTION_CREATE;
        } elseif ($changePreview === []) {
            $actionType = self::ACTION_UNCHANGED;
        } else {
            $actionType = self::ACTION_UPDATE;
        }

        return [
            'action_type' => $actionType,
            'change_preview' => $changePreview,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array{product: ?Product, variant: ?ProductVariant, conflicts: array<int, array<string, string>>}
     */
    private function previewExistingMatch(array $row): array
    {
        $conflicts = [];
        $variant = null;
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        $brandName = $this->brandNameForRow($row);
        $brand = $brandName !== null ? $this->brandByName($brandName) : null;
        $sku = $this->nullIfEmpty($row['sku'] ?? null);

        if ($systemId !== null) {
            $variant = array_key_exists($systemId, $this->variantsBySystemId)
                ? $this->variantsBySystemId[$systemId]
                : ProductVariant::query()
                    ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                    ->where('system_id', $systemId)
                    ->first();
        }

        if ($variant === null && $brand !== null && $sku !== null) {
            $brandSkuKey = $this->brandSkuKey((int) $brand->id, $sku);
            $variant = array_key_exists($brandSkuKey, $this->variantsByBrandSku)
                ? $this->variantsByBrandSku[$brandSkuKey]
                : ProductVariant::query()
                    ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                    ->where('brand_id', $brand->id)
                    ->where('sku', $sku)
                    ->first();
        }

        $product = $variant?->product;
        $previewProductData = $this->previewProductData($row);
        $productByName = $previewProductData !== null ? $this->findExistingProduct($previewProductData) : null;

        if ($product !== null && $productByName !== null && (int) $product->id !== (int) $productByName->id) {
            $conflicts[] = [
                'field' => 'system_id',
                'type' => 'identity_mismatch',
                'message' => 'El SKU/System ID apunta a un producto distinto al nombre y categoria detectados.',
            ];
        }

        $product ??= $productByName;

        return [
            'product' => $product,
            'variant' => $variant,
            'conflicts' => $conflicts,
        ];
    }

    private function previewProductData(array $row): ?array
    {
        $category = $this->findCategoryByNameOrCode($row['categoria'] ?? null);
        if ($category === null) {
            return null;
        }

        $categoryId = (int) $category->id;
        $extraCategoryIds = $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, $categoryId);
        $technicalName = $this->analyzeTechnicalName(
            (string) ($row['nombre'] ?? ''),
            $this->categoriesForTechnicalExtraction($category, $extraCategoryIds),
        );

        return [
            'name' => $technicalName['clean_name'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'category_id' => $categoryId,
            'image_url' => $this->imageUrlForRow($row),
            'attributes' => $this->previewAttributes($row, $technicalName),
            '__import_product_key' => $this->productImportKey($categoryId, (string) ($row['nombre'] ?? '')),
        ];
    }

    private function buildChangePreview(array $row, array $previewData, ?Product $product, ?ProductVariant $variant): array
    {
        $changes = [];
        $fields = [
            'nombre' => ['label' => 'Nombre', 'current' => $product?->name, 'imported' => $previewData['nombre'] ?? null],
            'sku' => ['label' => 'SKU', 'current' => $variant?->sku ?? $product?->sku_base, 'imported' => $row['sku'] ?? null],
            'system_id' => ['label' => 'System ID', 'current' => $variant?->system_id, 'imported' => $row['system_id'] ?? null],
            'brand' => ['label' => 'Marca / Variante', 'current' => $variant?->brand?->name, 'imported' => $this->brandNameForRow($row)],
            'categoria' => ['label' => 'Categoria', 'current' => $product?->category?->name, 'imported' => $row['categoria'] ?? null],
            'costo' => ['label' => 'Costo', 'current' => $this->formatPreviewMoney($variant?->pricing?->cost_base), 'imported' => $this->formatPreviewMoney($row['costo'] ?? null)],
            'precio' => ['label' => 'Precio', 'current' => $this->formatPreviewMoney($variant?->pricing?->base_price), 'imported' => $this->formatPreviewMoney($row['precio'] ?? null)],
            'cantidad' => ['label' => 'Cantidad', 'current' => $variant?->inventory?->sum('quantity_available'), 'imported' => $this->parseInteger($row['cantidad'] ?? null)],
            'descripcion' => ['label' => 'Descripcion', 'current' => $product?->description, 'imported' => $row['descripcion'] ?? null],
            'compatibilidad' => ['label' => 'Compatibilidad', 'current' => $product?->vehicleCompatibility?->count(), 'imported' => $this->previewCompatibilityCount($previewData)],
            'variante' => ['label' => 'Variante detectada', 'current' => $this->previewVariantKeyForExistingVariant($variant), 'imported' => $previewData['__variant_preview']['variant_key'] ?? null],
        ];

        foreach ($fields as $field => $definition) {
            $imported = $this->normalizePreviewValue($definition['imported'] ?? null);
            if ($imported === null && $field !== 'compatibilidad') {
                continue;
            }

            $current = $this->normalizePreviewValue($definition['current'] ?? null);
            if ($current === $imported) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $definition['label'],
                'current' => $current,
                'imported' => $imported,
            ];
        }

        return $changes;
    }

    private function imageUrlForRow(array $row): ?string
    {
        $imageUrl = $this->nullIfEmpty($row['imagen_url'] ?? $row['image_url'] ?? null);

        if ($imageUrl === null) {
            return null;
        }

        if (! filter_var($imageUrl, FILTER_VALIDATE_URL) || ! Str::startsWith($imageUrl, ['http://', 'https://'])) {
            return null;
        }

        return $imageUrl;
    }

    private function previewCompatibilityCount(array $previewData): int
    {
        $compatibility = $previewData['__compatibility'] ?? $previewData['__vehicle_compatibility'] ?? [];
        if (! is_array($compatibility)) {
            return 0;
        }

        if (array_is_list($compatibility)) {
            return count($compatibility);
        }

        return collect($compatibility)
            ->filter(fn (mixed $scope): bool => is_array($scope))
            ->sum(fn (array $scope): int => count($scope));
    }

    private function normalizePreviewValue(mixed $value): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $string = trim((string) $value);

        if (is_numeric($string)) {
            return (float) $string;
        }

        return $string === '' ? null : $string;
    }

    private function previewVariantKeyForExistingVariant(?ProductVariant $variant): ?string
    {
        if ($variant === null) {
            return null;
        }

        if ($this->nullIfEmpty($variant->system_id) !== null) {
            return 'system:'.$variant->system_id;
        }

        if ($this->nullIfEmpty($variant->sku) !== null) {
            return 'sku:'.$variant->sku;
        }

        return null;
    }

    private function rememberOperationSummary(array $operation): void
    {
        $actionType = (string) ($operation['action_type'] ?? self::ACTION_INVALID);
        $this->actionCounts[$actionType] = (int) ($this->actionCounts[$actionType] ?? 0) + 1;

        foreach (($operation['conflicts'] ?? []) as $conflict) {
            $type = (string) ($conflict['type'] ?? 'conflict');
            $this->conflictCounts[$type] = (int) ($this->conflictCounts[$type] ?? 0) + 1;
        }
    }

    private function actionCountsFromPreviewRows(array $previewRows): array
    {
        return collect($previewRows)
            ->countBy(fn (array $row): string => (string) ($row['action_type'] ?? self::ACTION_INVALID))
            ->all();
    }

    private function conflictCountsFromPreviewRows(array $previewRows): array
    {
        return collect($previewRows)
            ->flatMap(fn (array $row): array => $row['conflicts'] ?? [])
            ->countBy(fn (array $conflict): string => (string) ($conflict['type'] ?? 'conflict'))
            ->all();
    }

    private function buildPreviewSummary(Collection $rows, array $previewRows = []): array
    {
        $groups = [];
        $missingCategories = [];
        $missingBrands = [];
        $detectedAttributes = [];
        $categorySuggestions = [];
        $brandSuggestions = [];
        $missingVehicleCandidates = [];

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

            if (! $this->hasExplicitVehicleColumns($row) && trim((string) ($row['nombre'] ?? '')) !== '') {
                try {
                    $vehicleMatch = $this->resolveVehicleCompatibilityFromName((string) $row['nombre']);
                    if ($vehicleMatch === null) {
                        $candidate = $this->vehicleCreationCandidateFromName((string) $row['nombre']);
                        if ($candidate !== null) {
                            $missingVehicleCandidates[] = $candidate;
                        }
                    }
                } catch (\RuntimeException) {
                    $candidate = $this->vehicleCreationCandidateFromName((string) $row['nombre']);
                    if ($candidate !== null) {
                        $missingVehicleCandidates[] = $candidate;
                    }
                }
            }
        }

        $actionCounts = $this->actionCountsFromPreviewRows($previewRows);
        $conflictCounts = $this->conflictCountsFromPreviewRows($previewRows);

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
            'missing_vehicle_candidates' => collect($missingVehicleCandidates)
                ->unique(fn (array $candidate): string => $this->vehicleCandidateKey($candidate))
                ->values()
                ->all(),
            'suggestions' => [
                'categories' => collect($categorySuggestions)->unique('value')->values()->all(),
                'brands' => collect($brandSuggestions)->unique('value')->values()->all(),
            ],
            'action_counts' => $actionCounts,
            'conflict_counts' => $conflictCounts,
            'unchanged_count' => (int) ($actionCounts[self::ACTION_UNCHANGED] ?? 0),
            'conflict_count' => collect($conflictCounts)->sum(),
        ];
    }

    private function suggestCategory(string $value): ?array
    {
        $cacheKey = $this->attributeNameKey($value);
        if (array_key_exists($cacheKey, $this->categorySuggestionsCache)) {
            return $this->categorySuggestionsCache[$cacheKey];
        }

        $normalizedValue = $this->attributeNameKey($value);
        $best = null;

        foreach ($this->categories() as $category) {
            $normalizedCategory = $this->attributeNameKey($category->name);
            similar_text($normalizedValue, $normalizedCategory, $score);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'name' => $category->name,
                    'score' => round($score, 2),
                ];
            }
        }

        return $this->categorySuggestionsCache[$cacheKey] = ($best !== null && $best['score'] >= 70) ? $best : null;
    }

    public function referenceSummary(): array
    {
        return [
            'missing_categories' => array_values(array_unique($this->missingCategories)),
            'missing_references' => [
                'brands' => array_values(array_unique($this->missingBrands)),
            ],
            'missing_vehicle_candidates' => collect($this->missingVehicleCandidates)
                ->unique(fn (array $candidate): string => $this->vehicleCandidateKey($candidate))
                ->values()
                ->all(),
            'suggestions' => [
                'brands' => collect($this->brandSuggestions)->unique('value')->values()->all(),
            ],
            'action_counts' => $this->actionCounts,
            'conflict_counts' => $this->conflictCounts,
            'unchanged_count' => (int) ($this->actionCounts[self::ACTION_UNCHANGED] ?? 0),
            'conflict_count' => collect($this->conflictCounts)->sum(),
        ];
    }

    private function rememberMissingCategory(string $categoryName): void
    {
        $categoryName = trim($categoryName);
        if ($categoryName !== '') {
            $this->missingCategories[] = $categoryName;
        }
    }

    private function rememberMissingVehicleCandidate(array $candidate): void
    {
        $this->missingVehicleCandidates[] = $candidate;
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
        return $this->brandByName($brandName) !== null;
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
        $cacheKey = $this->attributeNameKey($value);
        if (array_key_exists($cacheKey, $this->brandSuggestionsCache)) {
            return $this->brandSuggestionsCache[$cacheKey];
        }

        return $this->brandSuggestionsCache[$cacheKey] = $this->suggestNameFromCollection($this->brands(), $value);
    }

    private function suggestModelName(string $modelClass, string $value): ?array
    {
        $collection = $modelClass === Brand::class ? $this->brands() : $modelClass::query()->select(['id', 'name'])->get();

        return $this->suggestNameFromCollection($collection, $value);
    }

    private function suggestNameFromCollection(Collection $models, string $value): ?array
    {
        $normalizedValue = $this->attributeNameKey($value);
        $best = null;

        foreach ($models as $model) {
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

    private function categories(): Collection
    {
        if ($this->cachedCategories === null) {
            $this->cachedCategories = Category::query()->get();
        }

        return $this->cachedCategories;
    }

    private function categoryById(int $categoryId): ?Category
    {
        return $this->categories()
            ->first(fn (Category $category): bool => (int) $category->id === $categoryId);
    }

    private function brands(): Collection
    {
        if ($this->cachedBrands === null) {
            $this->cachedBrands = Brand::query()->select(['id', 'name'])->get();
        }

        return $this->cachedBrands;
    }

    private function brandByName(string $brandName): ?Brand
    {
        $normalized = $this->attributeNameKey($brandName);

        return $this->brands()
            ->first(fn (Brand $brand): bool => $this->attributeNameKey($brand->name) === $normalized);
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

    private function prepareLookupContext(Collection $rows): void
    {
        $systemIds = $rows
            ->map(fn (array $row): ?string => $this->nullIfEmpty($row['system_id'] ?? null))
            ->filter()
            ->unique()
            ->values();

        if ($systemIds->isNotEmpty()) {
            $systemIds->each(function (string $systemId): void {
                $this->variantsBySystemId[$systemId] ??= null;
            });

            ProductVariant::query()
                ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                ->whereIn('system_id', $systemIds)
                ->get()
                ->each(function (ProductVariant $variant): void {
                    if ($variant->system_id !== null) {
                        $this->variantsBySystemId[$variant->system_id] = $variant;
                        $this->variantSystemKeysByProduct[$this->productSystemKey((int) $variant->product_id, $variant->system_id)] = true;
                    }
                });
        }

        $brandSkuPairs = $rows
            ->map(function (array $row): ?array {
                $sku = $this->nullIfEmpty($row['sku'] ?? null);
                $brandName = $this->brandNameForRow($row);
                $brand = $brandName !== null ? $this->brandByName($brandName) : null;

                if ($brand === null || $sku === null) {
                    return null;
                }

                return ['brand_id' => (int) $brand->id, 'sku' => $sku];
            })
            ->filter()
            ->unique(fn (array $pair): string => $this->brandSkuKey($pair['brand_id'], $pair['sku']))
            ->values();

        if ($brandSkuPairs->isNotEmpty()) {
            $brandSkuPairs->each(function (array $pair): void {
                $this->variantsByBrandSku[$this->brandSkuKey($pair['brand_id'], $pair['sku'])] ??= null;
            });

            ProductVariant::query()
                ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                ->whereIn('brand_id', $brandSkuPairs->pluck('brand_id')->unique()->values())
                ->whereIn('sku', $brandSkuPairs->pluck('sku')->unique()->values())
                ->get()
                ->each(function (ProductVariant $variant): void {
                    if ($variant->sku !== null && $variant->brand_id !== null) {
                        $this->variantsByBrandSku[$this->brandSkuKey((int) $variant->brand_id, $variant->sku)] = $variant;
                        $this->variantBrandSkuKeysByProduct[$this->productBrandSkuKey((int) $variant->product_id, (int) $variant->brand_id, $variant->sku)] = true;
                    }
                });
        }

        $productLookupKeys = collect();
        $categoryIds = collect();
        $productNames = collect();

        $rows->each(function (array $row) use ($productLookupKeys, $categoryIds, $productNames): void {
            $productData = $this->previewProductData($row);
            if ($productData === null) {
                return;
            }

            $categoryId = (int) ($productData['category_id'] ?? 0);
            $name = mb_strtolower(trim((string) ($productData['name'] ?? '')));

            if ($categoryId <= 0 || $name === '') {
                return;
            }

            $categoryIds->push($categoryId);
            $productNames->push($name);
            $productLookupKeys->push($this->productLookupKey($categoryId, $name));
        });

        if ($categoryIds->isEmpty()) {
            return;
        }

        $productLookupKeys->each(function (string $key): void {
            $this->productMatchesByCategoryName[$key] ??= collect();
        });

        Product::query()
            ->with('attributes')
            ->whereIn('category_id', $categoryIds->unique()->values())
            ->whereIn(DB::raw('LOWER(name)'), $productNames->unique()->values())
            ->get()
            ->each(function (Product $product) use ($productLookupKeys): void {
                $key = $this->productLookupKey((int) $product->category_id, mb_strtolower(trim((string) $product->name)));
                if (! $productLookupKeys->contains($key)) {
                    return;
                }

                $this->productMatchesByCategoryName[$key] ??= collect();
                $this->productMatchesByCategoryName[$key]->push($product);
            });
    }

    private function brandSkuKey(int $brandId, string $sku): string
    {
        return $brandId.'|'.$sku;
    }

    private function productSystemKey(int $productId, string $systemId): string
    {
        return $productId.'|'.$systemId;
    }

    private function productBrandSkuKey(int $productId, int $brandId, string $sku): string
    {
        return $productId.'|'.$brandId.'|'.$sku;
    }

    private function productLookupKey(int $categoryId, string $name): string
    {
        return $categoryId.'|'.mb_strtolower(trim($name));
    }

    private function findExistingProduct(array $productData): ?Product
    {
        $name = mb_strtolower(trim((string) $productData['name']));
        $sku = $this->nullIfEmpty($productData['sku_base'] ?? null);
        $importKey = $this->nullIfEmpty($productData['__import_product_key'] ?? null);

        if ($importKey !== null && isset($this->productIdsByImportKey[$importKey])) {
            $product = $this->productsByImportKey[$importKey] ?? Product::query()->find($this->productIdsByImportKey[$importKey]);
            if ($product !== null) {
                $this->productsByImportKey[$importKey] = $product;

                return $product;
            }
        }

        $matches = $this->productMatchesByCategoryName[$this->productLookupKey((int) ($productData['category_id'] ?? 0), $name)]
            ?? Product::query()
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

        if (array_key_exists($normalizedName, $this->vehicleNameResolutionCache)) {
            $cached = $this->vehicleNameResolutionCache[$normalizedName];
            if (is_array($cached) && ($cached['__error'] ?? null)) {
                throw new \RuntimeException((string) $cached['__error']);
            }

            return $cached;
        }

        $modelSignalFound = false;
        $matches = [];

        $this->vehicleCatalog()
            ->each(function (array $entry) use ($normalizedName, &$modelSignalFound, &$matches): void {
                /** @var VehicleModel $model */
                $model = $entry['model'];
                $make = $entry['make'];
                if (! $make) {
                    return;
                }

                if (! $this->vehicleTextContains($normalizedName, $make->name) || ! $this->vehicleTextContains($normalizedName, $model->name)) {
                    return;
                }

                $modelSignalFound = true;

                foreach ($entry['chassis'] as $chassis) {
                    if (! $chassis instanceof VehicleChassis) {
                        continue;
                    }

                    foreach ($entry['engines'] as $engine) {
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
            $this->vehicleNameResolutionCache[$normalizedName] = [
                '__error' => 'Se detecto mas de un vehiculo completo en el nombre del producto. Usa las columnas de vehiculo para importar una compatibilidad especifica.',
            ];

            throw new \RuntimeException('Se detecto mas de un vehiculo completo en el nombre del producto. Usa las columnas de vehiculo para importar una compatibilidad especifica.');
        }

        if ($matches->isEmpty()) {
            if ($modelSignalFound) {
                $this->vehicleNameResolutionCache[$normalizedName] = [
                    '__error' => 'No se pudo resolver el vehiculo completo del nombre. Verifica que marca, modelo, chasis y motor existan en el catalogo.',
                ];

                throw new \RuntimeException('No se pudo resolver el vehiculo completo del nombre. Verifica que marca, modelo, chasis y motor existan en el catalogo.');
            }

            return $this->vehicleNameResolutionCache[$normalizedName] = null;
        }

        $match = $matches->first();

        return $this->vehicleNameResolutionCache[$normalizedName] = [
            'label' => $match['label'],
            'compatibility' => [
                'makes' => [['make_id' => $match['make_id']]],
                'models' => [['model_id' => $match['model_id']]],
                'chassis' => [['chassis_id' => $match['chassis_id']]],
                'engines' => [['engine_id' => $match['engine_id']]],
            ],
        ];
    }

    private function vehicleCreationCandidateFromName(string $name): ?array
    {
        $normalizedName = $this->vehicleNameKey($name);
        if ($normalizedName === '') {
            return null;
        }

        if (array_key_exists($normalizedName, $this->vehicleCandidateCache)) {
            return $this->vehicleCandidateCache[$normalizedName];
        }

        $candidates = [];

        $this->vehicleCatalog()
            ->each(function (array $entry) use ($normalizedName, &$candidates): void {
                /** @var VehicleModel $model */
                $model = $entry['model'];
                $make = $entry['make'];
                if (! $make) {
                    return;
                }

                if (! $this->vehicleTextContains($normalizedName, $make->name) || ! $this->vehicleTextContains($normalizedName, $model->name)) {
                    return;
                }

                $tailTokens = $this->vehicleTailTokensAfterModel($normalizedName, $make->name, $model->name);

                $candidates[] = [
                    'make_id' => (int) $make->id,
                    'make' => $make->name,
                    'model_id' => (int) $model->id,
                    'model' => $model->name,
                    'chassis' => $tailTokens[0] ?? null,
                    'engine' => $tailTokens[1] ?? null,
                    'source' => 'nombre',
                    'can_create' => true,
                    'label' => trim(implode(' ', array_filter([
                        $make->name,
                        $model->name,
                        $tailTokens[0] ?? null,
                        $tailTokens[1] ?? null,
                    ]))),
                ];
            });

        return $this->vehicleCandidateCache[$normalizedName] = collect($candidates)
            ->sortBy(fn (array $candidate): string => $candidate['label'])
            ->first() ?? $this->heuristicVehicleCreationCandidateFromName($normalizedName);
    }

    private function vehicleCatalog(): Collection
    {
        if ($this->cachedVehicleCatalog !== null) {
            return $this->cachedVehicleCatalog;
        }

        $directChassisByModel = VehicleChassis::query()
            ->whereNotNull('model_id')
            ->get()
            ->groupBy('model_id');

        $this->cachedVehicleCatalog = VehicleModel::query()
            ->with(['make', 'chassis', 'engines', 'enginesMany'])
            ->get()
            ->map(function (VehicleModel $model) use ($directChassisByModel): array {
                return [
                    'model' => $model,
                    'make' => $model->make,
                    'chassis' => $model->chassis
                        ->concat($directChassisByModel->get($model->id, collect()))
                        ->unique('id')
                        ->values(),
                    'engines' => $model->engines
                        ->concat($model->enginesMany)
                        ->unique('id')
                        ->values(),
                ];
            })
            ->values();

        return $this->cachedVehicleCatalog;
    }

    private function heuristicVehicleCreationCandidateFromName(string $normalizedName): ?array
    {
        $tokens = collect(explode(' ', $normalizedName))
            ->map(fn (string $token): string => strtoupper(trim($token)))
            ->filter(fn (string $token): bool => $token !== '')
            ->values();

        if ($tokens->count() < 4) {
            return null;
        }

        $tail = $tokens->slice(-4)->values();
        $make = (string) $tail[0];
        $model = (string) $tail[1];
        $chassis = (string) $tail[2];
        $engine = (string) $tail[3];

        if (
            preg_match('/^[A-Z]{2,}$/', $make) !== 1
            || preg_match('/^[A-Z][A-Z0-9]{1,}$/', $model) !== 1
            || ! $this->looksLikeVehicleCode($chassis)
            || ! $this->looksLikeVehicleCode($engine)
        ) {
            return null;
        }

        return [
            'make_id' => null,
            'make' => $make,
            'model_id' => null,
            'model' => $model,
            'chassis' => $chassis,
            'engine' => $engine,
            'source' => 'nombre',
            'can_create' => true,
            'label' => $tail->implode(' '),
        ];
    }

    private function looksLikeVehicleCode(string $value): bool
    {
        return preg_match('/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{2,12}$/', $value) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function vehicleTailTokensAfterModel(string $normalizedName, string $makeName, string $modelName): array
    {
        $makeModel = $this->vehicleNameKey($makeName.' '.$modelName);
        if ($makeModel === '') {
            return [];
        }

        if (preg_match('/(?:^| )'.preg_quote($makeModel, '/').'(?: |$)(.*)$/', $normalizedName, $matches) !== 1) {
            return [];
        }

        return collect(explode(' ', trim((string) ($matches[1] ?? ''))))
            ->map(fn (string $token): string => strtoupper($token))
            ->filter(fn (string $token): bool => $token !== '')
            ->take(2)
            ->values()
            ->all();
    }

    private function vehicleCandidateKey(array $candidate): string
    {
        return mb_strtolower(implode('|', [
            $candidate['make_id'] ?? '',
            $candidate['model_id'] ?? '',
            $candidate['chassis'] ?? '',
            $candidate['engine'] ?? '',
        ]));
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
            ->map(function ($categoryName): int {
                $category = $this->findCategoryByNameOrCode($categoryName);
                if (! $category) {
                    throw new \RuntimeException("No se encontro el registro '{$categoryName}'.");
                }

                return (int) $category->id;
            })
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
            ->map(fn (int $categoryId): ?Category => $this->categoryById($categoryId))
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
            return $this->categoryById((int) $normalized);
        }

        $key = $this->attributeNameKey($normalized);

        return $this->categories()
            ->first(fn (Category $category): bool => $this->attributeNameKey($category->name) === $key || $this->attributeNameKey((string) $category->slug) === $key);
    }

    protected function mapRowToPreview(array $row, ?array $errors = null, ?array $warnings = null): array
    {
        $mapped = parent::mapRowToPreview($row, $errors, $warnings);

        foreach (['costo', 'precio'] as $moneyField) {
            $formatted = $this->formatPreviewMoney($row[$moneyField] ?? null);

            if ($formatted !== null) {
                $mapped[$moneyField] = $formatted;
            }
        }

        $category = $this->findCategoryByNameOrCode($row['categoria'] ?? null);

        if ($category !== null) {
            $mapped['categoria'] = (int) $category->id;
            $mapped['__category_label'] = $category->name;

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
                } else {
                    $mapped['__vehicle_creation_candidate'] = $this->vehicleCreationCandidateFromName((string) ($row['nombre'] ?? ''));
                }
            } catch (\RuntimeException) {
                $mapped['__vehicle_compatibility'] = null;
                $mapped['__vehicle_creation_candidate'] = $this->vehicleCreationCandidateFromName((string) ($row['nombre'] ?? ''));
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
            'costo' => $this->formatPreviewMoney($row['costo'] ?? null),
            'precio' => $this->formatPreviewMoney($row['precio'] ?? null),
            'cantidad' => $this->parseInteger($row['cantidad'] ?? null),
            'warehouse' => $this->nullIfEmpty($row['warehouse'] ?? null),
        ];
    }

    private function formatPreviewMoney(mixed $value): ?string
    {
        $parsed = $this->parseMoney($value);

        if ($parsed === null) {
            return null;
        }

        return number_format($parsed, 2, '.', '');
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

        $this->rememberVariantLookup($variant->refresh());

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

    private function rememberVariantLookup(ProductVariant $variant): void
    {
        if ($variant->system_id !== null) {
            $this->variantsBySystemId[$variant->system_id] = $variant;
            $this->variantSystemKeysByProduct[$this->productSystemKey((int) $variant->product_id, $variant->system_id)] = true;
        }

        if ($variant->sku !== null && $variant->brand_id !== null) {
            $this->variantsByBrandSku[$this->brandSkuKey((int) $variant->brand_id, $variant->sku)] = $variant;
            $this->variantBrandSkuKeysByProduct[$this->productBrandSkuKey((int) $variant->product_id, (int) $variant->brand_id, $variant->sku)] = true;
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
            $variant = array_key_exists($systemId, $this->variantsBySystemId)
                ? $this->variantsBySystemId[$systemId]
                : ProductVariant::query()->where('system_id', $systemId)->first();
            if ($variant && (int) $variant->product_id !== (int) $product->id) {
                throw new \RuntimeException("El System ID '{$systemId}' ya pertenece a otro producto.");
            }

            if ($variant) {
                return $variant;
            }
        }

        $brandSkuKey = $this->brandSkuKey($brandId, $sku);
        if (array_key_exists($brandSkuKey, $this->variantsByBrandSku)) {
            $variant = $this->variantsByBrandSku[$brandSkuKey];
            if ($variant && (int) $variant->product_id === (int) $product->id) {
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

        $brand = $this->brandByName($brandName);

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
