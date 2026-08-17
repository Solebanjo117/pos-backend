<?php

namespace App\Imports;

use App\Helpers\TaxRateHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\ImportRunRecord;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCode;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\Warehouse;
use App\Services\BrandResolver;
use App\Services\ProductCodeNormalizer;
use App\Services\ProductCodeService;
use App\Services\ProductImportBulkWriter;
use App\Services\ProductImportVehicleResolver;
use App\Services\ProductImportVendorResolver;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImport extends BaseImport
{
    public const FIELD_IGNORE = 'ignore';

    public const REQUIRED_FIELDS = ['nombre'];

    private const ACTION_CREATE = 'create';

    private const ACTION_UPDATE = 'update';

    private const ACTION_UNCHANGED = 'unchanged';

    private const ACTION_CONFLICT = 'conflict';

    private const ACTION_INVALID = 'invalid';

    private const RESOLUTION_EXISTING = 'existing';

    private const RESOLUTION_NEW_VARIANT = 'new_variant';

    private const RESOLUTION_NEW_PRODUCT = 'new_product';

    private const RESOLUTION_REVIEW = 'review';

    private const RESOLUTION_CONFLICT = 'conflict';

    private const LATEST_RESULT_LIMIT = 100;

    private const LEGACY_HIDDEN_FIELDS = [
        'imagen_url',
        'warehouse',
        'categorias_extra',
        'atributos',
    ];

    private string $mode = 'create_update';

    private ?int $importRunId = null;

    private array $missingBrands = [];

    private array $missingCategories = [];

    private array $brandSuggestions = [];

    private array $productIdsByImportKey = [];

    private array $productsByImportKey = [];

    private array $variantsBySystemId = [];

    private array $variantsByBrandSku = [];

    private array $variantSystemKeysByProduct = [];

    private array $variantBrandSkuKeysByProduct = [];

    private array $productMatchesByCategoryName = [];

    private array $productCodesByNormalized = [];

    private ?Collection $cachedCategories = null;

    private ?Collection $cachedBrands = null;

    private array $rowContextCache = [];

    private array $technicalCategoriesCache = [];

    private array $technicalNameCache = [];

    private array $categorySuggestionsCache = [];

    private array $brandSuggestionsCache = [];

    private array $actionCounts = [];

    private array $conflictCounts = [];

    private readonly ProductImportBulkWriter $bulkWriter;

    private readonly BrandResolver $brandResolver;

    private readonly ProductImportVendorResolver $vendorResolver;

    private readonly ProductImportVehicleResolver $vehicleResolver;

    private ?int $cachedDefaultTaxRate = null;

    private ?bool $hasProductWriteListeners = null;

    private bool $largeBatchFastPath = false;

    public function __construct(
        private readonly ProductService $productService,
        ?ProductImportBulkWriter $bulkWriter = null,
        ?BrandResolver $brandResolver = null,
        ?ProductImportVendorResolver $vendorResolver = null,
        ?ProductImportVehicleResolver $vehicleResolver = null,
        private readonly ?ProductCodeNormalizer $productCodeNormalizer = null,
    ) {
        $this->bulkWriter = $bulkWriter ?? app(ProductImportBulkWriter::class);
        $this->brandResolver = $brandResolver ?? app(BrandResolver::class);
        $this->vendorResolver = $vendorResolver ?? app(ProductImportVendorResolver::class);
        $this->vehicleResolver = $vehicleResolver ?? app(ProductImportVehicleResolver::class);
    }

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
            ['key' => 'categoria', 'label' => 'Categoria', 'type' => 'searchable', 'endpoint' => '/categories/all'],
            ['key' => 'costo', 'label' => 'Costo', 'type' => 'number'],
            ['key' => 'precio', 'label' => 'Precio', 'type' => 'number'],
            ['key' => 'cantidad', 'label' => 'Cantidad', 'type' => 'number'],
            ['key' => 'descripcion', 'label' => 'Descripcion', 'type' => 'text'],
            ['key' => 'vendor', 'label' => 'Vendor', 'type' => 'text'],
            ['key' => 'vendor_id', 'label' => 'Vendor ID', 'type' => 'text'],
            ['key' => 'carro', 'label' => 'Carro', 'type' => 'text'],
            ['key' => 'make', 'label' => 'MAKE', 'type' => 'text'],
            ['key' => 'model', 'label' => 'MODEL', 'type' => 'text'],
            ['key' => 'chasis', 'label' => 'CHASIS', 'type' => 'text'],
            ['key' => 'engine', 'label' => 'ENGINE', 'type' => 'text'],
            ['key' => 'vin', 'label' => 'VIN', 'type' => 'text'],
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
            'vendor' => 'Distribuidora Nacional',
            'vendor_id' => 'VEN-001',
            'carro' => 'TOYOTA YARIS P90 1NZFE NCP93L-BEMRK MTM',
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'chasis' => 'P90',
            'engine' => '1NZFE',
            'vin' => '',
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
        $identityIndex = $this->buildRowIdentityIndex($rowsForPreview);
        $shownCount = $rowsForPreview->count();
        $totalRows = (int) ($selectedSheet['row_count'] ?? $shownCount);
        $previewRows = $this->buildPreviewRows($rowsForPreview, $identityIndex['duplicate_counts'], $identityIndex);

        return [
            'rows' => $previewRows,
            'total_rows' => $totalRows,
            'max_rows' => $maxRows ?? $shownCount,
            'preview_limited' => $totalRows > $shownCount,
            'summary' => $this->buildPreviewSummary($rowsForPreview, $previewRows, $identityIndex),
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
        $missingRequiredHeaders = [];

        if (! $hasName) {
            $missingRequiredHeaders[] = 'nombre';
        }

        return [
            'matched_headers' => $matchedHeaders,
            'missing_required_headers' => $missingRequiredHeaders,
            'score' => $rowCount > 0 ? $score : 0,
            'is_compatible' => $rowCount > 0 && $hasName,
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
            'system_id', 'systemid', 'id_sistema', 'variant_code', 'variant_id' => 'system_id',
            'brand', 'marca', 'marca_producto', 'marca_variante', 'variant', 'variante', 'manufacturer', 'fabricante' => 'brand',
            'descripcion', 'product_description', 'detalle' => 'descripcion',
            'categoria', 'category' => 'categoria',
            'costo', 'cost', 'unit_cost', 'default_cost', 'avg_cost' => 'costo',
            'precio', 'price', 'retail_price', 'sale_price', 'unit_price' => 'precio',
            'cantidad', 'quantity', 'qty', 'stock', 'on_hand' => 'cantidad',
            'vendor', 'proveedor', 'supplier' => 'vendor',
            'vendor_id', 'vendorid', 'vendor_code', 'supplier_id', 'supplier_code', 'id_proveedor', 'proveedor_id' => 'vendor_id',
            'carro', 'vehiculo', 'vehicle', 'vehicle_text', 'auto' => 'carro',
            'make', 'marca_carro', 'vehicle_make' => 'make',
            'model', 'modelo', 'vehicle_model' => 'model',
            'chasis', 'chassis', 'chassis_code', 'vehicle_chassis' => 'chasis',
            'engine', 'motor', 'engine_code', 'vehicle_engine' => 'engine',
            'vin', 'numero_vin', 'vin_number' => 'vin',
            'imagen_url', 'url_imagen', 'image_url', 'image', 'imagen', 'foto', 'photo' => 'imagen_url',
            'warehouse', 'almacen', 'bodega' => 'warehouse',
            'categorias_extra', 'extra_categories', 'extra_category_ids' => 'categorias_extra',
            'atributos', 'attributes' => 'atributos',
            default => $header,
        };
    }

    protected function applyHeaderMapping(string $mappedHeader, string $sourceHeader, array $mapping): string
    {
        $target = parent::applyHeaderMapping($mappedHeader, $sourceHeader, $mapping);

        return $target === '' ? '' : $this->mapHeader($target);
    }

    public function validateRow(array $row, ?array $context = null): array
    {
        $context ??= $this->rowContext($row);
        $errors = [];

        if (trim((string) ($row['nombre'] ?? '')) === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        try {
            $this->parseProductAttributes($row['atributos'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        foreach (['costo' => 'costo', 'precio' => 'precio'] as $key => $label) {
            if (($row[$key] ?? null) !== null && trim((string) $row[$key]) !== '' && $this->parseMoney($row[$key]) === null) {
                $errors[] = "El campo {$label} debe ser numerico.";
            }
        }

        if (($row['cantidad'] ?? null) !== null && trim((string) $row['cantidad']) !== '' && $this->parseInteger($row['cantidad']) === null) {
            $errors[] = 'El campo cantidad debe ser numerico.';
        }

        $quantity = $this->parseInteger($row['cantidad'] ?? null);
        $warehouseName = $this->nullIfEmpty($row['warehouse'] ?? null);
        if ($quantity !== null && $quantity > 0 && $warehouseName !== null) {
            try {
                $this->resolveWarehouseId($warehouseName);
            } catch (\RuntimeException) {
                $errors[] = "El almacen '{$warehouseName}' no existe.";
            }
        }

        $errors = array_merge(
            $errors,
            $this->vendorResolver->validateRow($row),
            $this->vehicleResolver->validateRow($row)
        );

        return $errors;
    }

    public function processRow(array $row, ?array $context = null): mixed
    {
        if ($this->mode === ImportRun::MODE_VALIDATE_ONLY) {
            return ['id' => null];
        }

        $context ??= $this->rowContext($row);
        $category = $this->resolveCategoryForImport($row, true);
        $context = $this->buildRowContextForCategory($row, $category, $context['key'] ?? $this->rowContextKey($row), true);
        $categoryId = $category?->id;
        $extraCategoryIds = $context['extra_category_ids'];

        $technicalName = $context['technical_name'];
        $attributes = $this->resolveAttributes($row, $technicalName);
        $productName = $this->nullIfEmpty($technicalName['clean_name'] ?? null) ?? trim((string) ($row['nombre'] ?? ''));

        $productData = [
            'name' => $productName,
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'description' => $this->descriptionForRow($row, $productName),
            'image_url' => $this->imageUrlForRow($row),
            'category_id' => $categoryId,
            'status' => 'active',
            'attributes' => $attributes,
            '__import_product_key' => $this->productImportKey((int) ($categoryId ?? 0), (string) ($row['nombre'] ?? '')),
        ];

        if (! empty($extraCategoryIds)) {
            $productData['extra_category_ids'] = $extraCategoryIds;
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

        $resolvedVariants = [];
        if (is_array($variants) && ! empty($variants)) {
            foreach ($variants as $variantData) {
                $resolvedVariant = $this->createEnrichedVariant($product, $variantData);
                if ($resolvedVariant instanceof ProductVariant) {
                    $resolvedVariants[] = $resolvedVariant;
                }
            }
        }

        $this->syncVendorForImportedProduct($product, $resolvedVariants, $row);
        $this->syncVehicleForImportedProduct($product, $row);

        return $product;
    }

    public function execute(array $rows): void
    {
        $rowCollection = collect($rows);
        $identityIndex = $this->buildRowIdentityIndex($rowCollection);
        $duplicateKeys = $identityIndex['duplicate_counts'];
        $useLargeBatchFastPath = $this->mode !== ImportRun::MODE_VALIDATE_ONLY && count($rows) >= 500;
        $this->largeBatchFastPath = $useLargeBatchFastPath;

        $this->prepareLookupContext($rowCollection, $identityIndex);
        $pendingBulkRows = [];
        $identityPosition = 0;

        foreach ($rows as $row) {
            if ($this->shouldStopImport()) {
                $this->flushBulkRows($pendingBulkRows);

                return;
            }

            $duplicateKey = $identityIndex['row_conflict_keys'][$identityPosition] ?? '';
            $context = $this->rowContextAt($row, $identityPosition, $identityIndex);
            $previewProductData = array_key_exists($identityPosition, $identityIndex['row_preview_products'] ?? [])
                ? $identityIndex['row_preview_products'][$identityPosition]
                : $this->previewProductData($row, $context);
            $variantPreview = array_key_exists($identityPosition, $identityIndex['row_variant_previews'] ?? [])
                ? $identityIndex['row_variant_previews'][$identityPosition]
                : $this->previewVariantDataFromRow($row);
            $identityPosition++;
            $this->processedCount++;
            $rowNum = (int) ($row['__row'] ?? 0);
            $rowErrors = $this->validateRow($row, $context);
            $rowWarnings = $this->getWarningsForRow($row, $context);
            $previewData = $this->mapRowToPreview($row, $rowErrors, $rowWarnings, $context, $variantPreview, true);
            $operation = $useLargeBatchFastPath
                ? $this->fastRowOperationPreview($row, $previewData, $rowErrors, $duplicateKeys, $duplicateKey)
                : $this->rowOperationPreview($row, $previewData, $rowErrors, $duplicateKeys, $duplicateKey, $previewProductData, true);
            $this->rememberOperationSummary($operation);

            $preparedRow = [
                'key' => (string) $this->processedCount,
                'row' => $row,
                'row_num' => $rowNum,
                'row_errors' => $rowErrors,
                'operation' => $operation,
                'preview_data' => $previewData,
                'context' => $context,
                'bulk_payload' => $this->bulkPayloadForRow($row, $previewData, $operation, $rowErrors, $context),
            ];

            if ($preparedRow['bulk_payload'] !== null) {
                $pendingBulkRows[] = $preparedRow;

                continue;
            }

            $this->flushBulkRows($pendingBulkRows);
            $this->executePreparedRow($preparedRow);
        }

        $this->flushBulkRows($pendingBulkRows);
        $this->largeBatchFastPath = false;
    }

    public function executeAtomic(array $rows): void
    {
        $validationImport = (new self(
            $this->productService,
            $this->bulkWriter,
            $this->brandResolver,
            $this->vendorResolver,
            $this->vehicleResolver,
        ))->forMode(ImportRun::MODE_VALIDATE_ONLY);
        $validationImport->validateRows($rows);

        if ($validationImport->errorCount > 0) {
            $this->processedCount = count($rows);
            $this->successCount = 0;
            $this->errorCount = $validationImport->errorCount;
            $this->results = [];
            $this->errors = $validationImport->errors;
            $this->validationRows = $validationImport->validationRows;

            return;
        }

        DB::beginTransaction();

        try {
            $this->execute($rows);

            if ($this->errorCount > 0) {
                DB::rollBack();
                $this->successCount = 0;
                $this->results = [];

                return;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->processedCount = max($this->processedCount, count($rows));
            $this->successCount = 0;
            $this->results = [];

            if ($this->errorCount === 0) {
                $this->errorCount = 1;
                $this->rememberErrorEntry([
                    'row' => 0,
                    'label' => 'Importacion',
                    'data' => [],
                    'error' => $e->getMessage(),
                    'action_type' => self::ACTION_INVALID,
                    'change_preview' => [],
                    'conflicts' => [],
                ]);
            }
        } finally {
            $this->largeBatchFastPath = false;
        }
    }

    private function fastRowOperationPreview(array $row, array $previewData, array $errors, array $duplicateKeys, ?string $duplicateKey = null): array
    {
        return $this->rowOperationPreview($row, $previewData, $errors, $duplicateKeys, $duplicateKey);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $previewData
     * @param  array<string, mixed>  $operation
     * @param  array<int, string>  $rowErrors
     * @return array<string, mixed>|null
     */
    private function bulkPayloadForRow(array $row, array $previewData, array $operation, array $rowErrors, ?array $context = null): ?array
    {
        $context ??= $this->rowContext($row);

        if (
            ! in_array($this->mode, [ImportRun::MODE_CREATE_UPDATE, ImportRun::MODE_CREATE_ONLY], true)
            || $this->hasProductWriteListeners()
            || ($operation['action_type'] ?? null) !== self::ACTION_CREATE
            || ($operation['resolution_type'] ?? null) !== self::RESOLUTION_NEW_PRODUCT
            || $rowErrors !== []
            || ($operation['conflicts'] ?? []) !== []
            || ! empty($row['__variants'])
            || ! empty($previewData['__attributes'])
            || $this->vendorResolver->hasVendorPayload($row)
            || $this->vehicleResolver->hasVehiclePayload($row)
            || $this->splitDelimitedValues($row['categorias_extra'] ?? null) !== []
            || $this->splitDelimitedValues($row['atributos'] ?? null) !== []
        ) {
            return null;
        }

        $category = $context['category'];
        $brandName = $this->brandNameForRow($row);
        $brand = $brandName === null ? null : $this->resolveBrandForImport($brandName, false, false);
        $sku = $this->nullIfEmpty($row['sku'] ?? null);
        $cleanName = $this->nullIfEmpty($previewData['nombre'] ?? null);

        if (
            $category === null
            || $brand === null
            || $sku === null
            || $cleanName === null
            || ($category->default_attributes ?? []) !== []
        ) {
            return null;
        }

        $cost = $this->parseMoney($row['costo'] ?? null);
        $price = $this->parseMoney($row['precio'] ?? null);
        $quantity = $this->parseInteger($row['cantidad'] ?? null);
        $warehouseId = $quantity !== null && $quantity > 0
            ? $this->resolveWarehouseId($row['warehouse'] ?? null)
            : null;
        $productKey = $this->nullIfEmpty($previewData['__product_group_key'] ?? null)
            ?? $this->productImportKey((int) $category->id, (string) ($row['nombre'] ?? ''));

        return [
            'product_key' => $productKey,
            'import_key' => $this->productImportKey((int) $category->id, (string) ($row['nombre'] ?? '')),
            'product' => [
                'sku_base' => $sku,
                'name' => $cleanName,
                'description' => $this->descriptionForRow($row, $cleanName),
                'image_url' => $this->imageUrlForRow($row),
                'category_id' => (int) $category->id,
            ],
            'variant' => [
                'brand_id' => (int) $brand->id,
                'sku' => $sku,
                'normalized_code' => $this->codeNormalizer()->normalize($sku),
                'system_id' => $this->nullIfEmpty($row['system_id'] ?? null),
                'pricing' => $cost !== null || $price !== null
                    ? [
                        'base_price' => $price ?? 0,
                        'cost_base' => $cost ?? 0,
                        'tax_rate' => $this->defaultTaxRate(),
                    ]
                    : null,
                'inventory' => $warehouseId !== null && $quantity !== null && $quantity > 0
                    ? [
                        'warehouse_id' => $warehouseId,
                        'quantity' => $quantity,
                    ]
                    : null,
            ],
        ];
    }

    private function defaultTaxRate(): int
    {
        return $this->cachedDefaultTaxRate ??= TaxRateHelper::defaultRate();
    }

    private function hasProductWriteListeners(): bool
    {
        if ($this->hasProductWriteListeners !== null) {
            return $this->hasProductWriteListeners;
        }

        $dispatcher = Product::getEventDispatcher();
        if ($dispatcher === null) {
            return $this->hasProductWriteListeners = false;
        }

        return $this->hasProductWriteListeners = $dispatcher->hasListeners('eloquent.created: '.Product::class)
            || $dispatcher->hasListeners('eloquent.saved: '.Product::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pendingRows
     */
    private function flushBulkRows(array &$pendingRows): void
    {
        if ($pendingRows === []) {
            return;
        }

        $rows = $pendingRows;
        $pendingRows = [];
        $this->writeBulkRowsRecursively($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     */
    private function writeBulkRowsRecursively(array $preparedRows): void
    {
        try {
            $payloads = array_map(function (array $preparedRow): array {
                return array_merge(
                    ['key' => $preparedRow['key']],
                    $preparedRow['bulk_payload']
                );
            }, $preparedRows);
            $writeResults = $this->bulkWriter->write($payloads, $this->importRunId);
            $this->rememberBulkLookups($preparedRows, $writeResults);

            foreach ($preparedRows as $preparedRow) {
                $result = $writeResults[$preparedRow['key']];
                $this->rememberSuccessfulRow($preparedRow, $result['product_id']);
            }
        } catch (\Throwable) {
            $productGroups = collect($preparedRows)
                ->groupBy(fn (array $preparedRow): string => $preparedRow['bulk_payload']['product_key'])
                ->values();

            if ($productGroups->count() === 1) {
                foreach ($preparedRows as $preparedRow) {
                    $this->executePreparedRow($preparedRow);
                }

                return;
            }

            $middle = intdiv($productGroups->count(), 2);
            $firstHalf = $productGroups->take($middle)->flatten(1)->values()->all();
            $secondHalf = $productGroups->skip($middle)->flatten(1)->values()->all();
            $this->writeBulkRowsRecursively($firstHalf);
            $this->writeBulkRowsRecursively($secondHalf);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $preparedRows
     * @param  array<string, array{product_id: int, variant_id: int}>  $writeResults
     */
    private function rememberBulkLookups(array $preparedRows, array $writeResults): void
    {
        if ($this->largeBatchFastPath) {
            foreach ($preparedRows as $preparedRow) {
                $payload = $preparedRow['bulk_payload'];
                $result = $writeResults[$preparedRow['key']];
                $this->productIdsByImportKey[$payload['import_key']] = $result['product_id'];

                $systemId = $this->nullIfEmpty($payload['variant']['system_id'] ?? null);
                if ($systemId !== null) {
                    $this->variantsBySystemId[$systemId] = $result['variant_id'];
                    $this->variantSystemKeysByProduct[$this->productSystemKey($result['product_id'], $systemId)] = true;
                }

                $sku = $this->nullIfEmpty($payload['variant']['sku'] ?? null);
                $brandId = (int) ($payload['variant']['brand_id'] ?? 0);
                if ($sku !== null && $brandId > 0) {
                    $this->variantsByBrandSku[$this->brandSkuKey($brandId, $sku)] = $result['variant_id'];
                    $this->variantBrandSkuKeysByProduct[$this->productBrandSkuKey($result['product_id'], $brandId, $sku)] = true;
                }
            }

            return;
        }

        $productIds = collect($writeResults)->pluck('product_id')->unique()->values();
        $products = Product::query()
            ->with('attributes')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        $variantIds = collect($writeResults)->pluck('variant_id')->unique()->values();
        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        foreach ($preparedRows as $preparedRow) {
            $result = $writeResults[$preparedRow['key']];
            $product = $products->get($result['product_id']);
            $importKey = $preparedRow['bulk_payload']['import_key'];

            if ($product instanceof Product) {
                $this->productIdsByImportKey[$importKey] = $product->id;
                if (! $this->largeBatchFastPath) {
                    $this->productsByImportKey[$importKey] = $product;
                }
            }

            $variant = $variants->get($result['variant_id']);
            if ($variant instanceof ProductVariant) {
                $this->rememberVariantLookup($variant);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $preparedRow
     */
    private function executePreparedRow(array $preparedRow): void
    {
        $row = $preparedRow['row'];
        $rowErrors = $preparedRow['row_errors'];
        $operation = $preparedRow['operation'];
        $context = is_array($preparedRow['context'] ?? null) ? $preparedRow['context'] : null;

        try {
            $result = DB::transaction(function () use ($row, $rowErrors, $operation, $context) {
                if (! empty($operation['conflicts'])) {
                    throw new \RuntimeException(collect($operation['conflicts'])->pluck('message')->implode(' '));
                }

                if (! empty($rowErrors)) {
                    throw new \RuntimeException(implode(' ', $rowErrors));
                }

                return $this->processRow($row, $context);
            });

            $this->rememberSuccessfulRow(
                $preparedRow,
                $result instanceof Model ? $result->id : ($result['id'] ?? null)
            );
        } catch (\Throwable $e) {
            $this->errorCount++;
            $this->rememberErrorEntry([
                'row' => $preparedRow['row_num'],
                'label' => $row[$this->getLabelColumn()] ?? '',
                'data' => $row,
                'error' => $e->getMessage(),
                'action_type' => $operation['action_type'],
                'resolution_type' => $operation['resolution_type'] ?? self::RESOLUTION_REVIEW,
                'change_preview' => $operation['change_preview'],
                'conflicts' => $operation['conflicts'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $preparedRow
     */
    private function rememberSuccessfulRow(array $preparedRow, mixed $resultId): void
    {
        $row = $preparedRow['row'];
        $operation = $preparedRow['operation'];
        $this->successCount++;
        $this->rememberResultEntry([
            'row' => $preparedRow['row_num'],
            'status' => 'ok',
            'label' => $row[$this->getLabelColumn()] ?? '',
            'id' => $resultId,
            'action_type' => $operation['action_type'],
            'resolution_type' => $operation['resolution_type'] ?? self::RESOLUTION_REVIEW,
            'change_preview' => $operation['change_preview'],
        ]);
    }

    private function rememberResultEntry(array $entry): void
    {
        $this->results[] = $entry;

        if (count($this->results) > self::LATEST_RESULT_LIMIT) {
            $this->results = array_slice($this->results, -self::LATEST_RESULT_LIMIT);
        }
    }

    private function rememberErrorEntry(array $entry): void
    {
        $this->errors[] = $entry;
    }

    public function validateRows(array $rows): void
    {
        $this->validateRowsWithDuplicateKeys($rows, null);
    }

    public function validateRowsWithDuplicateKeys(array $rows, ?array $duplicateKeys): void
    {
        $previewRows = $this->buildPreviewRows(collect($rows), $duplicateKeys);

        foreach ($previewRows as $previewRow) {
            if ($this->shouldStopImport()) {
                return;
            }

            $this->processedCount++;
            $this->validationRows[] = $previewRow;
            $this->rememberOperationSummary($previewRow);

            if ($previewRow['is_valid']) {
                $this->successCount++;
                $this->rememberResultEntry([
                    'row' => $previewRow['row'],
                    'status' => 'ok',
                    'label' => $previewRow['data'][$this->getLabelColumn()] ?? '',
                    'id' => null,
                    'action_type' => $previewRow['action_type'],
                    'resolution_type' => $previewRow['resolution_type'] ?? self::RESOLUTION_REVIEW,
                    'change_preview' => $previewRow['change_preview'],
                ]);

                continue;
            }

            $this->errorCount++;
            $errorMessages = $previewRow['errors'] !== []
                ? $previewRow['errors']
                : collect($previewRow['conflicts'])->pluck('message')->all();
            $this->rememberErrorEntry([
                'row' => $previewRow['row'],
                'label' => $previewRow['data'][$this->getLabelColumn()] ?? '',
                'data' => $previewRow['data'],
                'error' => implode(' ', $errorMessages),
                'action_type' => $previewRow['action_type'],
                'resolution_type' => $previewRow['resolution_type'] ?? self::RESOLUTION_REVIEW,
                'change_preview' => $previewRow['change_preview'],
                'conflicts' => $previewRow['conflicts'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function stagingRowsForValidation(array $rows): array
    {
        $rowCollection = collect($rows);
        $identityIndex = $this->buildRowIdentityIndex($rowCollection);
        $this->prepareLookupContext($rowCollection, $identityIndex);
        $identityPosition = 0;

        return $rowCollection
            ->map(function (array $row) use ($identityIndex, &$identityPosition): array {
                $context = $this->rowContextAt($row, $identityPosition, $identityIndex);
                $variantPreview = array_key_exists($identityPosition, $identityIndex['row_variant_previews'] ?? [])
                    ? $identityIndex['row_variant_previews'][$identityPosition]
                    : $this->previewVariantDataFromRow($row);
                $identityPosition++;
                $warnings = $this->getWarningsForRow($row, $context);
                $previewData = $this->mapRowToPreview($row, [], $warnings, $context, $variantPreview, true);
                unset($previewData['imagen_url']);

                return [
                    'row' => (int) ($row['__row'] ?? 0),
                    'data' => array_merge($row, $previewData),
                    'source_data' => $row['__source_data'] ?? null,
                    'warnings' => $warnings,
                ];
            })
            ->values()
            ->all();
    }

    public function validateFileInChunks(UploadedFile|string $file, int $chunkSize = 500, ?callable $afterChunk = null, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): void
    {
        $this->forMode(ImportRun::MODE_VALIDATE_ONLY);

        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);
        $totalRows = (int) ($selectedSheet['row_count'] ?? 0);
        $duplicateKeys = $this->duplicateRowKeysForFile(
            $file,
            (int) $selectedSheet['index'],
            (string) $selectedSheet['name'],
            $mapping
        );
        $chunk = [];

        foreach ($this->extractRowsLazy($file, (int) $selectedSheet['index'], (string) $selectedSheet['name'], $mapping) as $row) {
            if ($this->shouldStopImport()) {
                return;
            }

            $chunk[] = $row;

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $this->validateRowsWithDuplicateKeys($chunk, $duplicateKeys);
            $chunk = [];

            if ($afterChunk !== null) {
                $shouldContinue = $afterChunk($this, $this->processedCount, $totalRows);
                if ($shouldContinue === false) {
                    return;
                }
            }
        }

        if ($chunk === []) {
            return;
        }

        $this->validateRowsWithDuplicateKeys($chunk, $duplicateKeys);

        if ($afterChunk !== null) {
            $afterChunk($this, $this->processedCount, $totalRows);
        }
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total_rows: int, has_more: bool}
     */
    public function fileRowsChunk(UploadedFile|string $file, int $offset, int $limit, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $resolvedSheetName = $sheetName;
        if ($resolvedSheetName === null || trim($resolvedSheetName) === '') {
            $resolvedSheetName = $sheetIndex === null || $sheetIndex === 0
                ? null
                : (string) ($this->resolveSelectedSheet($file, $sheetIndex, null, $mapping)['name'] ?? '');
        }

        $rows = [];
        $seenRows = 0;
        $hasMore = false;

        foreach ($this->extractRowsFromResolvedSheetLazy($file, $resolvedSheetName, $mapping) as $row) {
            if ($seenRows < $offset) {
                $seenRows++;

                continue;
            }

            if (count($rows) >= $limit) {
                $hasMore = true;

                break;
            }

            $rows[] = $row;
            $seenRows++;
        }

        return [
            'rows' => $rows,
            'total_rows' => $hasMore ? $seenRows + 1 : $seenRows,
            'has_more' => $hasMore,
        ];
    }

    public function duplicateRowKeysForFileSelection(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);

        return $this->duplicateRowKeysForFile(
            $file,
            (int) $selectedSheet['index'],
            (string) $selectedSheet['name'],
            $mapping
        );
    }

    private function duplicateRowKeysForFile(UploadedFile|string $file, int $sheetIndex, string $sheetName, array $mapping): array
    {
        return $this->buildRowIdentityIndex(
            $this->extractRowsLazy($file, $sheetIndex, $sheetName, $mapping),
            false
        )['duplicate_counts'];
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
        $brand = $this->resolveBrandForImport($brandName, false, false);

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

    private function buildPreviewRows(Collection $rows, ?array $duplicateKeys = null, ?array $identityIndex = null): array
    {
        $identityIndex ??= $this->buildRowIdentityIndex($rows);
        $this->prepareLookupContext($rows, $identityIndex);
        $duplicateKeys ??= $identityIndex['duplicate_counts'];
        $identityPosition = 0;

        return $rows
            ->map(function (array $row) use ($duplicateKeys, $identityIndex, &$identityPosition): array {
                $duplicateKey = $identityIndex['row_conflict_keys'][$identityPosition] ?? '';
                $context = $this->rowContextAt($row, $identityPosition, $identityIndex);
                $previewProductData = array_key_exists($identityPosition, $identityIndex['row_preview_products'] ?? [])
                    ? $identityIndex['row_preview_products'][$identityPosition]
                    : $this->previewProductData($row, $context);
                $variantPreview = array_key_exists($identityPosition, $identityIndex['row_variant_previews'] ?? [])
                    ? $identityIndex['row_variant_previews'][$identityPosition]
                    : $this->previewVariantDataFromRow($row);
                $identityPosition++;
                $rowErrors = $this->validateRow($row, $context);
                $rowWarnings = $this->getWarningsForRow($row, $context);
                $previewData = $this->mapRowToPreview($row, $rowErrors, $rowWarnings, $context, $variantPreview, true);
                $operation = $this->rowOperationPreview($row, $previewData, $rowErrors, $duplicateKeys, $duplicateKey, $previewProductData, true);
                $visibleData = array_merge($previewData, $operation);
                unset($visibleData['imagen_url']);

                return [
                    'row' => $row['__row'],
                    'data' => $visibleData,
                    'is_valid' => empty($rowErrors) && $operation['action_type'] !== self::ACTION_CONFLICT,
                    'errors' => $rowErrors,
                    'warnings' => $rowWarnings,
                    'action_type' => $operation['action_type'],
                    'resolution_type' => $operation['resolution_type'],
                    'change_preview' => $operation['change_preview'],
                    'conflicts' => $operation['conflicts'],
                    'source_data' => $row['__source_data'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int|string, array<string, mixed>>  $rows
     * @return array{
     *     product_groups: array<string, array{product_key: string, rows: int, variant_keys: array<string, true>}>,
     *     duplicate_counts: array<string, int>,
     *     variant_keys_by_product: array<string, array<string, true>>,
     *     row_product_keys: array<int, string>,
     *     row_variant_keys: array<int, string>,
     *     row_conflict_keys: array<int, string>,
     *     row_contexts: array<int, array<string, mixed>>,
     *     row_preview_products: array<int, ?array<string, mixed>>,
     *     row_variant_previews: array<int, ?array<string, mixed>>
     * }
     */
    private function buildRowIdentityIndex(iterable $rows, bool $storeRowKeys = true): array
    {
        $productGroups = [];
        $duplicateCounts = [];
        $variantKeysByProduct = [];
        $rowProductKeys = [];
        $rowVariantKeys = [];
        $rowConflictKeys = [];
        $rowContexts = [];
        $rowPreviewProducts = [];
        $rowVariantPreviews = [];
        $position = 0;

        foreach ($rows as $row) {
            $productKey = $this->productGroupingKey($row);
            $variantKey = $productKey !== '' ? $this->variantGroupingKey($row) : '';
            $normalizedCode = $this->codeNormalizer()->normalize($this->nullIfEmpty($row['sku'] ?? null));
            $conflictKey = $normalizedCode !== ''
                ? 'commercial-code:'.$normalizedCode
                : ($productKey !== '' ? $productKey.'|'.$variantKey : '');
            $context = $storeRowKeys ? $this->rowContext($row) : null;

            if ($storeRowKeys) {
                $rowProductKeys[$position] = $productKey;
                $rowVariantKeys[$position] = $variantKey;
                $rowConflictKeys[$position] = $conflictKey;
                $rowContexts[$position] = $context;
                $rowPreviewProducts[$position] = $context !== null ? $this->previewProductData($row, $context) : null;
                $rowVariantPreviews[$position] = $this->previewVariantDataFromRow($row);
            }

            if ($conflictKey !== '') {
                $duplicateCounts[$conflictKey] = (int) ($duplicateCounts[$conflictKey] ?? 0) + 1;

                if ($storeRowKeys) {
                    $variantKeysByProduct[$productKey] ??= [];
                    $variantKeysByProduct[$productKey][$variantKey] = true;
                    $productGroups[$productKey] ??= [
                        'product_key' => $productKey,
                        'rows' => 0,
                        'variant_keys' => [],
                    ];
                    $productGroups[$productKey]['rows']++;
                    $productGroups[$productKey]['variant_keys'][$variantKey] = true;
                }
            }

            $position++;
        }

        return [
            'product_groups' => $productGroups,
            'duplicate_counts' => $duplicateCounts,
            'variant_keys_by_product' => $variantKeysByProduct,
            'row_product_keys' => $rowProductKeys,
            'row_variant_keys' => $rowVariantKeys,
            'row_conflict_keys' => $rowConflictKeys,
            'row_contexts' => $rowContexts,
            'row_preview_products' => $rowPreviewProducts,
            'row_variant_previews' => $rowVariantPreviews,
        ];
    }

    private function rowContextAt(array $row, int $position, ?array $identityIndex = null): array
    {
        $context = $identityIndex['row_contexts'][$position] ?? null;

        return is_array($context) ? $context : $this->rowContext($row);
    }

    private function duplicateRowKeys(Collection $rows): array
    {
        return $this->buildRowIdentityIndex($rows, false)['duplicate_counts'];
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

    private function rowOperationPreview(array $row, array $previewData, array $errors, array $duplicateKeys, ?string $duplicateKey = null, ?array $previewProductData = null, bool $previewProductDataPrecomputed = false): array
    {
        $conflicts = [];
        $duplicateKey ??= $this->rowConflictKey($row);

        if ($duplicateKey !== '' && (int) ($duplicateKeys[$duplicateKey] ?? 0) > 1) {
            $conflicts[] = [
                'field' => 'fila',
                'type' => 'duplicate_in_file',
                'message' => 'Hay otra fila con la misma identidad de producto y variante en este archivo.',
            ];
        }

        $match = ['product' => null, 'variant' => null, 'conflicts' => []];
        try {
            $match = $this->previewExistingMatch($row, $previewProductData, $previewProductDataPrecomputed);
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
            'resolution_type' => $this->resolutionType(
                $row,
                $errors,
                $conflicts,
                $match['product'] ?? null,
                $match['variant'] ?? null,
            ),
            'change_preview' => $changePreview,
            'conflicts' => $conflicts,
        ];
    }

    private function resolutionType(
        array $row,
        array $errors,
        array $conflicts,
        ?Product $product,
        ?ProductVariant $variant
    ): string {
        if ($conflicts !== []) {
            return self::RESOLUTION_CONFLICT;
        }

        if ($errors !== []) {
            return self::RESOLUTION_REVIEW;
        }

        if ($product === null) {
            return self::RESOLUTION_NEW_PRODUCT;
        }

        if ($variant !== null) {
            return self::RESOLUTION_EXISTING;
        }

        $brandName = $this->brandNameForRow($row);
        $brand = $brandName !== null ? $this->resolveBrandForImport($brandName, false, false) : null;
        if ($brand === null) {
            return self::RESOLUTION_EXISTING;
        }

        return $product->variants()->where('brand_id', $brand->id)->exists()
            ? self::RESOLUTION_EXISTING
            : self::RESOLUTION_NEW_VARIANT;
    }

    /**
     * @return array{product: ?Product, variant: ?ProductVariant, conflicts: array<int, array<string, string>>}
     */
    private function previewExistingMatch(array $row, ?array $previewProductData = null, bool $previewProductDataPrecomputed = false): array
    {
        $conflicts = [];
        $variant = null;
        $product = null;
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        $brandName = $this->brandNameForRow($row);
        $brand = $brandName !== null ? $this->resolveBrandForImport($brandName, false, false) : null;
        $sku = $this->nullIfEmpty($row['sku'] ?? null);

        if ($sku !== null) {
            $normalizedCode = $this->codeNormalizer()->normalize($sku);
            $codeMatches = $this->productCodesByNormalized[$normalizedCode] ?? collect();
            $ownerCount = $codeMatches
                ->map(fn (ProductCode $code): string => $code->variant_id ? 'variant:'.$code->variant_id : 'product:'.$code->product_id)
                ->unique()
                ->count();

            if ($codeMatches->contains(fn (ProductCode $code): bool => $code->status === 'conflict') || $ownerCount > 1) {
                $conflicts[] = [
                    'field' => 'sku',
                    'type' => 'legacy_code_conflict',
                    'message' => 'El código comercial está marcado como conflicto histórico y requiere revisión.',
                ];
            } else {
                $matchedCode = $codeMatches->firstWhere('status', 'active');
                if ($matchedCode instanceof ProductCode) {
                    $variant = $matchedCode->variant;
                    $product = $matchedCode->product;
                    if ($variant !== null && $brand !== null && (int) $variant->brand_id !== (int) $brand->id) {
                        $conflicts[] = [
                            'field' => 'brand',
                            'type' => 'identity_mismatch',
                            'message' => 'El código comercial pertenece a una variante de otra marca.',
                        ];
                    }
                }
            }
        }

        if ($variant === null && $systemId !== null) {
            if (array_key_exists($systemId, $this->variantsBySystemId)) {
                $cachedVariant = $this->variantsBySystemId[$systemId];
                $variant = $cachedVariant instanceof ProductVariant
                    ? $cachedVariant
                    : (is_int($cachedVariant) ? ProductVariant::query()->whereKey($cachedVariant)->first() : null);
            } else {
                $variant = ProductVariant::query()
                    ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                    ->where('system_id', $systemId)
                    ->first();
            }
        }

        if ($variant === null && $brand !== null && $sku !== null) {
            $brandSkuKey = $this->brandSkuKey((int) $brand->id, $sku);
            if (array_key_exists($brandSkuKey, $this->variantsByBrandSku)) {
                $cachedVariant = $this->variantsByBrandSku[$brandSkuKey];
                $variant = $cachedVariant instanceof ProductVariant
                    ? $cachedVariant
                    : (is_int($cachedVariant) ? ProductVariant::query()->whereKey($cachedVariant)->first() : null);
            } else {
                $variant = ProductVariant::query()
                    ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                    ->where('brand_id', $brand->id)
                    ->where('sku', $sku)
                    ->first();
            }
        }

        $product ??= $variant?->product;
        if (! $previewProductDataPrecomputed) {
            $previewProductData ??= $this->previewProductData($row);
        }
        $productByName = $previewProductData !== null ? $this->findExistingProduct($previewProductData, false) : null;

        if ($product !== null && $productByName !== null && (int) $product->id !== (int) $productByName->id) {
            $conflicts[] = [
                'field' => 'system_id',
                'type' => 'identity_mismatch',
                'message' => 'El código/System ID apunta a un producto distinto al nombre y categoría detectados.',
            ];
        }

        $product ??= $productByName;

        return [
            'product' => $product,
            'variant' => $variant,
            'conflicts' => $conflicts,
        ];
    }

    private function previewProductData(array $row, ?array $context = null): ?array
    {
        $context ??= $this->rowContext($row);
        $category = $context['category'];
        $categoryId = $category instanceof Category ? (int) $category->id : null;
        $technicalName = $context['technical_name'];

        return [
            'name' => $technicalName['clean_name'],
            'sku_base' => $this->nullIfEmpty($row['sku'] ?? null),
            'category_id' => $categoryId,
            'image_url' => $this->imageUrlForRow($row),
            'attributes' => $this->previewAttributes($row, $technicalName),
            '__import_product_key' => $this->productImportKey((int) ($categoryId ?? 0), (string) ($row['nombre'] ?? '')),
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
        return 0;
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

    private function buildPreviewSummary(Collection $rows, array $previewRows = [], ?array $identityIndex = null): array
    {
        $identityIndex ??= $this->buildRowIdentityIndex($rows);
        $groups = [];
        $missingCategories = [];
        $missingBrands = [];
        $detectedAttributes = [];
        $categorySuggestions = [];
        $brandSuggestions = [];
        $identityPosition = 0;

        foreach ($rows as $row) {
            $context = $this->rowContextAt($row, $identityPosition, $identityIndex);
            $categoryName = trim((string) ($row['categoria'] ?? ''));
            $category = $context['category'];
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

            $technicalName = $context['technical_name'] ?? null;

            $productKey = $identityIndex['row_product_keys'][$identityPosition] ?? $this->productGroupingKey($row);
            if ($productKey !== '') {
                $variantKey = $identityIndex['row_variant_keys'][$identityPosition] ?? $this->variantGroupingKey($row);
                $variantSku = $this->nullIfEmpty($row['sku'] ?? null);
                $cleanName = $technicalName['clean_name'] ?? $this->productNameForGrouping($row, $context);
                $groups[$productKey] ??= [
                    'product_key' => $productKey,
                    'product_name' => $cleanName,
                    'original_name' => $this->originalProductName($row),
                    'clean_name' => $cleanName,
                    'attributes_fingerprint' => $context['attributes_fingerprint'],
                    'rows' => 0,
                    'variant_keys' => [],
                    'variant_skus' => [],
                    'variants' => [],
                ];
                $groups[$productKey]['rows']++;
                $groups[$productKey]['variant_keys'][$variantKey] = true;
                if ($variantSku !== null) {
                    $groups[$productKey]['variant_skus'][$variantSku] = $variantSku;
                }
                $variantPreview = array_key_exists($identityPosition, $identityIndex['row_variant_previews'] ?? [])
                    ? $identityIndex['row_variant_previews'][$identityPosition]
                    : $this->previewVariantDataFromRow($row);
                if ($variantPreview !== null) {
                    $groups[$productKey]['variants'][$variantPreview['variant_key']] ??= $variantPreview;
                }
            }

            if ($technicalName !== null) {
                foreach ($technicalName['attributes'] as $attribute) {
                    $detectedAttributes[] = $attribute['name'];
                }
            }

            $identityPosition++;
        }

        $actionCounts = $this->actionCountsFromPreviewRows($previewRows);
        $resolutionCounts = collect($previewRows)
            ->countBy(fn (array $row): string => (string) ($row['resolution_type'] ?? self::RESOLUTION_REVIEW))
            ->all();
        $conflictCounts = $this->conflictCountsFromPreviewRows($previewRows);

        return [
            'groups' => collect($groups)
                ->map(function (array $group): array {
                    $variantSkus = array_values(array_filter($group['variant_skus']));

                    return [
                        'product_key' => $group['product_key'],
                        'product_name' => $group['product_name'],
                        'original_name' => $group['original_name'],
                        'clean_name' => $group['clean_name'],
                        'attributes_fingerprint' => $group['attributes_fingerprint'],
                        'rows' => $group['rows'],
                        'variant_count' => count(array_filter($group['variant_keys'])),
                        'variant_skus' => $variantSkus,
                        'variants' => array_values($group['variants']),
                        'sku_warning' => count($variantSkus) > 1
                            ? 'El codigo cambia por variante; se importara como SKU de variante.'
                            : null,
                    ];
                })
                ->values()
                ->all(),
            'productos_estimados' => count($groups),
            'variantes_estimadas' => array_sum(array_map(
                fn (array $group): int => count(array_filter($group['variant_keys'])),
                $groups
            )),
            'missing_categories' => array_values(array_unique($missingCategories)),
            'missing_references' => [
                'brands' => array_values(array_unique($missingBrands)),
            ],
            'detected_attributes' => array_values(array_unique($detectedAttributes)),
            'suggestions' => [
                'categories' => collect($categorySuggestions)->unique('value')->values()->all(),
                'brands' => collect($brandSuggestions)->unique('value')->values()->all(),
            ],
            'action_counts' => $actionCounts,
            'resolution_counts' => $resolutionCounts,
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
            'missing_categories' => array_values($this->missingCategories),
            'missing_references' => [
                'brands' => array_values($this->missingBrands),
            ],
            'suggestions' => [
                'brands' => array_values($this->brandSuggestions),
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
            $this->missingCategories[$this->attributeNameKey($categoryName)] = $categoryName;
        }
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
        return $this->nullIfEmpty($row['brand'] ?? null) !== null
            || $this->nullIfEmpty($row['system_id'] ?? null) !== null
            || $this->parseMoney($row['costo'] ?? null) !== null
            || $this->parseMoney($row['precio'] ?? null) !== null
            || $this->parseInteger($row['cantidad'] ?? null) !== null;
    }

    private function brandExists(string $brandName): bool
    {
        return $this->resolveBrandForImport($brandName, false, false) !== null;
    }

    private function rememberMissingBrand(string $brandName): void
    {
        $this->missingBrands[$this->attributeNameKey($brandName)] = $brandName;
        $suggestion = $this->suggestBrand($brandName);

        if ($suggestion !== null) {
            $this->brandSuggestions[$this->attributeNameKey($brandName)] = [
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
        return $this->brandResolver->all();
    }

    private function brandByName(string $brandName): ?Brand
    {
        return $this->brandResolver->findByName($brandName);
    }

    private function resolveBrandForImport(mixed $value, bool $createMissing, bool $allowGenericFallback): ?Brand
    {
        $brandName = $this->cleanReferenceName($value);
        if ($brandName === null) {
            return $allowGenericFallback ? $this->ensureGenericBrand() : null;
        }

        $brand = $this->brandByName($brandName);
        if ($brand instanceof Brand) {
            return $brand;
        }

        if (! $this->isSafeReferenceName($brandName, 255)) {
            return $allowGenericFallback ? $this->ensureGenericBrand() : null;
        }

        $suggestion = $this->suggestBrand($brandName);
        if ($this->shouldUseSuggestedReference($suggestion)) {
            $suggestedBrand = $this->brandByName((string) ($suggestion['name'] ?? ''));
            if ($suggestedBrand instanceof Brand) {
                return $suggestedBrand;
            }
        }

        if ($this->isAmbiguousReferenceSuggestion($suggestion)) {
            return $allowGenericFallback ? $this->ensureGenericBrand() : null;
        }

        if (! $createMissing) {
            return null;
        }

        [$brand, $created] = $this->brandResolver->firstOrCreateByName($brandName);
        $this->brandSuggestionsCache = [];
        if ($created) {
            $this->recordImportModel(Brand::class, $brand->id, 'created', ['name' => $brand->name]);
        }

        return $brand;
    }

    private function ensureGenericBrand(): Brand
    {
        $brand = $this->brandByName('Generica');
        if ($brand instanceof Brand) {
            return $brand;
        }

        [$brand, $created] = $this->brandResolver->firstOrCreateByName('Generica');
        $this->brandSuggestionsCache = [];
        if ($created) {
            $this->recordImportModel(Brand::class, $brand->id, 'created', ['name' => $brand->name]);
        }

        return $brand;
    }

    private function productGroupingKey(array $row): string
    {
        $category = $this->normalizeImportKey((string) ($row['categoria'] ?? ''));
        if ($category === '') {
            $category = 'uncategorized';
        }
        $name = $this->normalizeImportKey($this->originalProductName($row));

        if ($name === '') {
            return '';
        }

        return $category.'|'.$name;
    }

    /**
     * @return array{
     *     key: string,
     *     category: ?Category,
     *     extra_category_ids: array<int, int>,
     *     technical_categories: Collection<int, Category>,
     *     technical_name: array{original_name: string, clean_name: string, attributes: array<int, array<string, mixed>>, warnings: array<int, string>, matched_segments: array<int, array{segment: string, offset: int, length: int}>},
     *     attributes_fingerprint: string
     * }
     */
    private function rowContext(array $row): array
    {
        $cacheKey = $this->rowContextKey($row);
        if (array_key_exists($cacheKey, $this->rowContextCache)) {
            return $this->rowContextCache[$cacheKey];
        }

        $category = $this->resolveCategoryForImport($row, false);

        return $this->rowContextCache[$cacheKey] = $this->buildRowContextForCategory($row, $category, $cacheKey, false);
    }

    private function buildRowContextForCategory(array $row, ?Category $category, string $cacheKey, bool $createExtraCategories): array
    {
        $extraCategoryIds = $category !== null
            ? $this->safeResolveExtraCategoryIds($row['categorias_extra'] ?? null, (int) $category->id, $createExtraCategories)
            : [];
        $technicalCategories = $category !== null
            ? $this->categoriesForTechnicalExtractionCached($category, $extraCategoryIds)
            : collect();
        $technicalName = $category !== null
            ? $this->analyzeTechnicalNameCached((string) ($row['nombre'] ?? ''), $technicalCategories)
            : [
                'original_name' => (string) ($row['nombre'] ?? ''),
                'clean_name' => (string) ($row['nombre'] ?? ''),
                'attributes' => [],
                'warnings' => [],
                'matched_segments' => [],
            ];

        return [
            'key' => $cacheKey,
            'category' => $category,
            'extra_category_ids' => $extraCategoryIds,
            'technical_categories' => $technicalCategories,
            'technical_name' => $technicalName,
            'attributes_fingerprint' => $this->attributesFingerprint($technicalName['attributes'] ?? []),
        ];
    }

    private function rowContextKey(array $row): string
    {
        return implode('|', [
            'category='.$this->normalizeImportKey((string) ($row['categoria'] ?? '')),
            'name='.$this->normalizeImportKey((string) ($row['nombre'] ?? '')),
            'extra='.$this->normalizeImportKey((string) ($row['categorias_extra'] ?? '')),
            'attributes='.$this->normalizeImportKey($this->rowContextAttributesSignature($row['atributos'] ?? null)),
        ]);
    }

    private function rowContextAttributesSignature(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    private function variantGroupingKey(array $row): string
    {
        $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
        if ($systemId !== null) {
            return 'system:'.$systemId;
        }

        $sku = $this->nullIfEmpty($row['sku'] ?? null);
        if ($sku !== null) {
            return 'brand:'.$this->normalizeImportKey($this->brandNameForRow($row) ?? 'Sin marca').'|code:'.$this->codeNormalizer()->normalize($sku);
        }

        return 'brand:'.$this->normalizeImportKey($this->brandNameForRow($row) ?? 'Sin marca').'|name:'.$this->normalizeImportKey($this->originalProductName($row));
    }

    private function productNameForGrouping(array $row, ?array $context = null): string
    {
        $context ??= $this->rowContext($row);

        return $context['category'] instanceof Category
            ? (string) $context['technical_name']['clean_name']
            : (string) ($row['nombre'] ?? '');
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

    private function prepareLookupContext(Collection $rows, ?array $identityIndex = null): void
    {
        $identityIndex ??= $this->buildRowIdentityIndex($rows);
        $systemIds = [];
        $brandSkuPairs = [];
        $productLookupKeys = [];
        $categoryIds = [];
        $productNames = [];
        $normalizedCodes = [];
        $position = 0;

        foreach ($rows as $row) {
            $systemId = $this->nullIfEmpty($row['system_id'] ?? null);
            if ($systemId !== null) {
                $systemIds[$systemId] = $systemId;
            }

            $sku = $this->nullIfEmpty($row['sku'] ?? null);
            if ($sku !== null) {
                $normalizedCode = $this->codeNormalizer()->normalize($sku);
                if ($normalizedCode !== '') {
                    $normalizedCodes[$normalizedCode] = $normalizedCode;
                }
            }
            $brandName = $this->brandNameForRow($row);
            $brand = $brandName !== null ? $this->resolveBrandForImport($brandName, false, false) : null;

            if ($brand !== null && $sku !== null) {
                $brandSkuKey = $this->brandSkuKey((int) $brand->id, $sku);
                $brandSkuPairs[$brandSkuKey] = ['brand_id' => (int) $brand->id, 'sku' => $sku];
            }

            $productData = $identityIndex['row_preview_products'][$position] ?? null;
            if ($productData !== null) {
                $categoryId = (int) ($productData['category_id'] ?? 0);
                $name = mb_strtolower(trim((string) ($productData['name'] ?? '')));

                if ($categoryId > 0 && $name !== '') {
                    $lookupKey = $this->productLookupKey($categoryId, $name);
                    $categoryIds[$categoryId] = $categoryId;
                    $productNames[$name] = $name;
                    $productLookupKeys[$lookupKey] = true;
                }
            }

            $position++;
        }

        if ($normalizedCodes !== []) {
            foreach ($normalizedCodes as $normalizedCode) {
                $this->productCodesByNormalized[$normalizedCode] ??= collect();
            }

            ProductCode::query()
                ->whereIn('normalized_code', array_values($normalizedCodes))
                ->whereIn('status', ['active', 'conflict'])
                ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'variant.brand', 'variant.pricing', 'variant.inventory'])
                ->get()
                ->groupBy('normalized_code')
                ->each(function (Collection $codes, string $normalizedCode): void {
                    $this->productCodesByNormalized[$normalizedCode] = $codes;
                });
        }

        if ($systemIds !== []) {
            foreach ($systemIds as $systemId) {
                $this->variantsBySystemId[$systemId] ??= null;
            }

            ProductVariant::query()
                ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                ->whereIn('system_id', array_values($systemIds))
                ->get()
                ->each(function (ProductVariant $variant): void {
                    if ($variant->system_id !== null) {
                        $this->variantsBySystemId[$variant->system_id] = $variant;
                        $this->variantSystemKeysByProduct[$this->productSystemKey((int) $variant->product_id, $variant->system_id)] = true;
                    }
                });
        }

        if ($brandSkuPairs !== []) {
            foreach ($brandSkuPairs as $pair) {
                $this->variantsByBrandSku[$this->brandSkuKey($pair['brand_id'], $pair['sku'])] ??= null;
            }

            ProductVariant::query()
                ->with(['product.category', 'product.attributes', 'product.vehicleCompatibility', 'brand', 'pricing', 'inventory'])
                ->whereIn('brand_id', array_values(array_unique(array_column($brandSkuPairs, 'brand_id'))))
                ->whereIn('sku', array_values(array_unique(array_column($brandSkuPairs, 'sku'))))
                ->get()
                ->each(function (ProductVariant $variant): void {
                    if ($variant->sku !== null && $variant->brand_id !== null) {
                        $this->variantsByBrandSku[$this->brandSkuKey((int) $variant->brand_id, $variant->sku)] = $variant;
                        $this->variantBrandSkuKeysByProduct[$this->productBrandSkuKey((int) $variant->product_id, (int) $variant->brand_id, $variant->sku)] = true;
                    }
                });
        }

        if ($categoryIds === []) {
            return;
        }

        foreach (array_keys($productLookupKeys) as $key) {
            $this->productMatchesByCategoryName[$key] ??= collect();
        }

        Product::query()
            ->with('attributes')
            ->whereIn('category_id', array_values($categoryIds))
            ->whereIn(DB::raw('LOWER(name)'), array_values($productNames))
            ->get()
            ->each(function (Product $product) use ($productLookupKeys): void {
                $key = $this->productLookupKey((int) $product->category_id, mb_strtolower(trim((string) $product->name)));
                if (! isset($productLookupKeys[$key])) {
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

    private function codeNormalizer(): ProductCodeNormalizer
    {
        return $this->productCodeNormalizer ?? app(ProductCodeNormalizer::class);
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

    private function findExistingProduct(array $productData, bool $matchCommercialCode = true): ?Product
    {
        $name = mb_strtolower(trim((string) $productData['name']));
        $sku = $this->nullIfEmpty($productData['sku_base'] ?? null);
        $importKey = $this->nullIfEmpty($productData['__import_product_key'] ?? null);

        if ($matchCommercialCode && $sku !== null) {
            $normalizedCode = $this->codeNormalizer()->normalize($sku);
            $codeMatches = $this->productCodesByNormalized[$normalizedCode] ?? collect();
            $owners = $codeMatches
                ->map(fn (ProductCode $code): string => $code->variant_id ? 'variant:'.$code->variant_id : 'product:'.$code->product_id)
                ->unique();

            if ($codeMatches->contains(fn (ProductCode $code): bool => $code->status === 'conflict') || $owners->count() > 1) {
                throw new \RuntimeException('El código comercial está en conflicto histórico y no puede resolverse automáticamente.');
            }

            $matchedCode = $codeMatches->firstWhere('status', 'active');
            if ($matchedCode instanceof ProductCode) {
                return $matchedCode->product;
            }
        }

        if ($importKey !== null && isset($this->productIdsByImportKey[$importKey])) {
            $cachedProduct = $this->productsByImportKey[$importKey] ?? null;
            $product = $cachedProduct instanceof Product
                ? $cachedProduct
                : Product::query()->find(is_int($cachedProduct) ? $cachedProduct : $this->productIdsByImportKey[$importKey]);
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

    private function resolveExtraCategoryIds(mixed $value, int $primaryCategoryId, bool $createMissing = false): array
    {
        return collect($this->splitDelimitedValues($value))
            ->map(function ($categoryName) use ($createMissing): ?int {
                $category = $this->resolveCategoryNameForImport($categoryName, $createMissing);

                return $category instanceof Category ? (int) $category->id : null;
            })
            ->filter()
            ->reject(fn (int $categoryId) => $categoryId === $primaryCategoryId)
            ->unique()
            ->values()
            ->all();
    }

    private function safeResolveExtraCategoryIds(mixed $value, int $primaryCategoryId, bool $createMissing = false): array
    {
        try {
            return $this->resolveExtraCategoryIds($value, $primaryCategoryId, $createMissing);
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function categoriesForTechnicalExtraction(Category $primaryCategory, array $extraCategoryIds = []): Collection
    {
        $cacheKey = $primaryCategory->id.'|'.implode(',', $extraCategoryIds);
        if (array_key_exists($cacheKey, $this->technicalCategoriesCache)) {
            return $this->technicalCategoriesCache[$cacheKey];
        }

        $extraCategories = collect($extraCategoryIds)
            ->map(fn (int $categoryId): ?Category => $this->categoryById($categoryId))
            ->filter();

        return $this->technicalCategoriesCache[$cacheKey] = collect([$primaryCategory])
            ->concat($extraCategories)
            ->unique('id')
            ->values();
    }

    private function categoriesForTechnicalExtractionCached(Category $primaryCategory, array $extraCategoryIds = []): Collection
    {
        return $this->categoriesForTechnicalExtraction($primaryCategory, $extraCategoryIds);
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

    protected function getWarningsForRow(array $row, ?array $context = null): array
    {
        $context ??= $this->rowContext($row);
        $warnings = [];
        $category = $context['category'];
        $categoryName = $this->cleanReferenceName($row['categoria'] ?? null);

        if ($category instanceof Category) {
            if ($categoryName !== null && ! ($this->findCategoryByNameOrCode($categoryName) instanceof Category)) {
                $suggestion = $this->suggestCategory($categoryName);
                if ($this->shouldUseSuggestedReference($suggestion)) {
                    $warnings[] = "Categoria '{$categoryName}' se asociara con '{$suggestion['name']}' por coincidencia normalizada.";
                }
            }
            $warnings = array_merge($warnings, $context['technical_name']['warnings']);
        } elseif ($categoryName === null) {
            $warnings[] = 'Categoria pendiente de clasificar; el producto se importara sin categoria.';
        } elseif (! $this->isSafeReferenceName($categoryName, 100) || preg_match('/^\d+$/', $categoryName) === 1) {
            $warnings[] = "Categoria '{$categoryName}' no es valida; el producto quedara pendiente de clasificar.";
        } else {
            $suggestion = $this->suggestCategory($categoryName);
            if ($this->shouldUseSuggestedReference($suggestion)) {
                $warnings[] = "Categoria '{$categoryName}' se asociara con '{$suggestion['name']}' por coincidencia normalizada.";
            } elseif ($this->isAmbiguousReferenceSuggestion($suggestion)) {
                $warnings[] = "Categoria '{$categoryName}' se parece a '{$suggestion['name']}'; se dejara pendiente para evitar duplicados.";
            } else {
                $warnings[] = "Categoria '{$categoryName}' se creara automaticamente.";
            }
        }

        foreach ($this->splitDelimitedValues($row['categorias_extra'] ?? null) as $extraCategoryName) {
            $extraName = $this->cleanReferenceName($extraCategoryName);
            if ($extraName === null) {
                continue;
            }
            if (! $this->isSafeReferenceName($extraName, 100)) {
                $warnings[] = "Categoria extra '{$extraName}' no es valida y se omitira.";
            } elseif ($this->resolveCategoryNameForImport($extraName, false) === null) {
                $warnings[] = "Categoria extra '{$extraName}' se creara automaticamente si la categoria principal se resuelve.";
            }
        }

        $rawBrandName = $this->cleanReferenceName($row['brand'] ?? null);
        $brandName = $this->brandNameForRow($row);
        if ($rawBrandName === null && $this->rowHasVariantPayload($row)) {
            $warnings[] = 'Marca vacia; la variante se asignara a Generica.';
        } elseif ($rawBrandName !== null && $this->brandByName($rawBrandName) === null) {
            $suggestion = $this->suggestBrand($rawBrandName);
            if ($this->shouldUseSuggestedReference($suggestion)) {
                $warnings[] = "Marca '{$rawBrandName}' se asociara con '{$suggestion['name']}' por coincidencia normalizada.";
            } elseif ($this->isAmbiguousReferenceSuggestion($suggestion)) {
                $warnings[] = "Marca '{$rawBrandName}' se parece a '{$suggestion['name']}'; la variante se asignara a Generica para evitar duplicados.";
            } elseif ($brandName !== null && ! $this->isSafeReferenceName($brandName, 255)) {
                $warnings[] = "Marca '{$brandName}' no es valida; la variante se asignara a Generica.";
            } else {
                $warnings[] = "Marca '{$rawBrandName}' se creara automaticamente.";
            }
        }

        if (($row['imagen_url'] ?? null) !== null && trim((string) $row['imagen_url']) !== '' && $this->imageUrlForRow($row) === null) {
            $warnings[] = 'URL de imagen invalida; se guardara sin imagen.';
        }

        if (! empty($row['__compatibility']) || ! empty($row['__vehicle_creation_candidate'])) {
            $warnings[] = 'Compatibilidad vehicular pendiente; el producto se importara sin compatibilidad para completarla despues.';
        }

        return array_values(array_unique($warnings));
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

    private function resolveCategoryForImport(array $row, bool $createMissing): ?Category
    {
        return $this->resolveCategoryNameForImport($row['categoria'] ?? null, $createMissing);
    }

    private function resolveCategoryNameForImport(mixed $value, bool $createMissing): ?Category
    {
        $categoryName = $this->cleanReferenceName($value);
        if ($categoryName === null) {
            return null;
        }

        $category = $this->findCategoryByNameOrCode($categoryName);
        if ($category instanceof Category) {
            return $category;
        }

        if (preg_match('/^\d+$/', $categoryName) === 1 || ! $this->isSafeReferenceName($categoryName, 100)) {
            return null;
        }

        $suggestion = $this->suggestCategory($categoryName);
        if ($this->shouldUseSuggestedReference($suggestion)) {
            $suggestedCategory = $this->findCategoryByNameOrCode($suggestion['name'] ?? null);
            if ($suggestedCategory instanceof Category) {
                return $suggestedCategory;
            }
        }

        if ($this->isAmbiguousReferenceSuggestion($suggestion) || ! $createMissing) {
            return null;
        }

        $category = Category::query()->create(['name' => $categoryName]);
        $this->cachedCategories = null;
        $this->categorySuggestionsCache = [];
        $this->recordImportModel(Category::class, $category->id, 'created', ['name' => $category->name]);

        return $category;
    }

    private function cleanReferenceName(mixed $value): ?string
    {
        $name = trim((string) ($value ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name === '' ? null : $name;
    }

    private function isSafeReferenceName(string $name, int $maxLength): bool
    {
        if (mb_strlen($name) > $maxLength) {
            return false;
        }

        return preg_match('/[A-Za-z0-9]/', Str::ascii($name)) === 1;
    }

    private function shouldUseSuggestedReference(?array $suggestion): bool
    {
        return $suggestion !== null && (float) ($suggestion['score'] ?? 0) >= 92;
    }

    private function isAmbiguousReferenceSuggestion(?array $suggestion): bool
    {
        $score = (float) ($suggestion['score'] ?? 0);

        return $score >= 70 && $score < 92;
    }

    private function descriptionForRow(array $row, string $fallbackName): ?string
    {
        return $this->nullIfEmpty($row['descripcion'] ?? null) ?? $fallbackName;
    }

    protected function mapRowToPreview(array $row, ?array $errors = null, ?array $warnings = null, ?array $context = null, ?array $variantPreview = null, bool $variantPreviewPrecomputed = false): array
    {
        $mapped = parent::mapRowToPreview($row, $errors, $warnings);
        $context ??= $this->rowContext($row);

        foreach (['costo', 'precio'] as $moneyField) {
            $formatted = $this->formatPreviewMoney($row[$moneyField] ?? null);

            if ($formatted !== null) {
                $mapped[$moneyField] = $formatted;
            }
        }

        if ($context['category'] !== null) {
            $mapped['categoria'] = (int) $context['category']->id;
            $mapped['__category_label'] = $context['category']->name;
            $mapped['nombre'] = $context['technical_name']['clean_name'];
            $mapped['__original_name'] = $context['technical_name']['original_name'];
            $mapped['__clean_name'] = $context['technical_name']['clean_name'];
            $mapped['__attributes_fingerprint'] = $context['attributes_fingerprint'];
            $mapped['__attributes'] = $this->previewAttributes($row, $context['technical_name']);
        }

        $mapped['__product_group_key'] = $this->productGroupingKey($row);
        $mapped['__product_key'] = $mapped['__product_group_key'];
        $mapped['__variant_preview'] = $variantPreviewPrecomputed ? $variantPreview : ($variantPreview ?? $this->previewVariantDataFromRow($row));

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
        $cacheKey = implode('|', [
            'name='.$name,
            'categories='.$categories
                ->map(fn (Category $category): string => (string) $category->id)
                ->implode(','),
        ]);

        if (array_key_exists($cacheKey, $this->technicalNameCache)) {
            return $this->technicalNameCache[$cacheKey];
        }

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

        return $this->technicalNameCache[$cacheKey] = [
            'original_name' => $name,
            'clean_name' => $cleanName !== '' ? $cleanName : trim($name),
            'attributes' => $attributes,
            'warnings' => $warnings,
            'matched_segments' => $matchedSegments,
        ];
    }

    private function analyzeTechnicalNameCached(string $name, Collection $categories): array
    {
        return $this->analyzeTechnicalName($name, $categories);
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

    private function createEnrichedVariant(Product $product, array $variantData): ?ProductVariant
    {
        $brandId = null;
        if (! empty($variantData['brand_id'])) {
            $brandId = (int) $variantData['brand_id'];
        } elseif (! empty($variantData['brand']['name'])) {
            $brandId = $this->resolveExistingBrandId($variantData['brand']['name']);
        }

        if (! $brandId) {
            return null;
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

        if ($variantSku !== '') {
            $variant->loadMissing('codes');
            $normalizedVariantSku = $this->codeNormalizer()->normalize($variantSku);
            $hasImportedCode = $variant->codes->contains(
                fn (ProductCode $code): bool => $code->normalized_code === $normalizedVariantSku
            );

            if (! $hasImportedCode) {
                $codes = $variant->codes->map(fn (ProductCode $code): array => [
                    'id' => $code->id,
                    'code' => $code->code,
                    'type' => $code->type,
                    'is_primary' => (bool) $code->is_primary,
                ])->values()->all();
                $codes[] = [
                    'code' => $variantSku,
                    'type' => 'sku',
                    'is_primary' => $codes === [],
                ];
                app(ProductCodeService::class)->syncForVariant($variant, $codes);
            }
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

        return $variant->refresh();
    }

    /**
     * @param  array<int, ProductVariant>  $variants
     */
    private function syncVendorForImportedProduct(Product $product, array $variants, array $row): void
    {
        $supplier = $this->vendorResolver->resolve($row);
        if ($supplier === null) {
            return;
        }

        $supplierCost = $this->parseMoney($row['costo'] ?? null);
        $targets = $variants === [] ? [null] : $variants;

        foreach ($targets as $variant) {
            [$productSupplier, $created] = $this->vendorResolver->sync(
                $product,
                $variant instanceof ProductVariant ? $variant : null,
                $supplier,
                $supplierCost
            );
            $this->recordImportModel(ProductSupplier::class, $productSupplier->id, $created ? 'created' : 'updated', [
                'supplier_id' => $supplier->id,
            ]);
        }
    }

    private function syncVehicleForImportedProduct(Product $product, array $row): void
    {
        foreach ($this->vehicleResolver->sync($product, $row) as $compatibility) {
            $this->recordImportModel(
                ProductVehicleCompatibility::class,
                $compatibility->id,
                $compatibility->wasRecentlyCreated ? 'created' : 'updated',
                ['scope_type' => $compatibility->scope_type]
            );
        }
    }

    private function rememberVariantLookup(ProductVariant $variant): void
    {
        $variant->loadMissing('codes');
        foreach ($variant->codes as $code) {
            $this->productCodesByNormalized[$code->normalized_code] = collect([$code->loadMissing(['product', 'variant'])]);
        }

        if ($variant->system_id !== null) {
            $this->variantsBySystemId[$variant->system_id] = $this->largeBatchFastPath ? $variant->id : $variant;
            $this->variantSystemKeysByProduct[$this->productSystemKey((int) $variant->product_id, $variant->system_id)] = true;
        }

        if ($variant->sku !== null && $variant->brand_id !== null) {
            $this->variantsByBrandSku[$this->brandSkuKey((int) $variant->brand_id, $variant->sku)] = $this->largeBatchFastPath ? $variant->id : $variant;
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
            if (array_key_exists($systemId, $this->variantsBySystemId)) {
                $cachedVariant = $this->variantsBySystemId[$systemId];
                $variant = $cachedVariant instanceof ProductVariant
                    ? $cachedVariant
                    : (is_int($cachedVariant) ? ProductVariant::query()->whereKey($cachedVariant)->first() : null);
            } else {
                $variant = ProductVariant::query()->where('system_id', $systemId)->first();
            }
            if ($variant && (int) $variant->product_id !== (int) $product->id) {
                throw new \RuntimeException("El System ID '{$systemId}' ya pertenece a otro producto.");
            }

            if ($variant) {
                return $variant;
            }
        }

        $brandSkuKey = $this->brandSkuKey($brandId, $sku);
        if (array_key_exists($brandSkuKey, $this->variantsByBrandSku)) {
            $cachedVariant = $this->variantsByBrandSku[$brandSkuKey];
            $variant = $cachedVariant instanceof ProductVariant
                ? $cachedVariant
                : (is_int($cachedVariant) ? ProductVariant::query()->whereKey($cachedVariant)->first() : null);
            if ($variant && (int) $variant->product_id === (int) $product->id) {
                return $variant;
            }
        }

        return $product->variants()
            ->where('brand_id', $brandId)
            ->first();
    }

    private function resolveExistingBrandId(mixed $value): int
    {
        $brand = $this->resolveBrandForImport($value, true, true);

        if (! $brand) {
            throw new \RuntimeException('La marca seleccionada no se pudo resolver.');
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
