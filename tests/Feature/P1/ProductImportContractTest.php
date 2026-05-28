<?php

use App\Http\Requests\PreviewImportRequest;
use App\Imports\ProductImport;
use App\Jobs\ProcessProductImportRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportProfile;
use App\Models\ImportRun;
use App\Models\ImportRunRecord;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleChassis;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function productImportUploadedSpreadsheet(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $columnIndex => $value) {
            $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'product-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile(
        $path,
        'lightspeed.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

function productImportUploadedSpreadsheetWithSheets(array $sheets): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $sheetName => $rows) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle((string) $sheetName);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }
    }

    $spreadsheet->setActiveSheetIndex(0);

    $path = tempnam(sys_get_temp_dir(), 'product-import-sheets-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile(
        $path,
        'lightspeed-multiple-sheets.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

function productImportEnsureBrands(array $names): void
{
    foreach ($names as $name) {
        Brand::query()->firstOrCreate([
            'name' => $name,
        ], [
            'status' => 'active',
        ]);
    }
}

function productImportCreateVehicleScope(
    string $makeName = 'TOYOTA',
    string $modelName = 'YARIS',
    string $chassisCode = 'P90',
    string $engineCode = '1NZFE',
): array {
    $make = VehicleMake::query()->firstOrCreate(['name' => $makeName]);
    $model = VehicleModel::create([
        'make_id' => $make->id,
        'name' => $modelName,
        'year_from' => 2006,
        'year_to' => 2013,
        'generation' => $chassisCode,
    ]);
    $chassis = VehicleChassis::create([
        'model_id' => $model->id,
        'code' => $chassisCode,
        'name' => $chassisCode,
    ]);
    $engine = VehicleEngine::create([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_code' => $engineCode,
    ]);

    $model->chassis()->syncWithoutDetaching([$chassis->id]);
    $engine->models()->syncWithoutDetaching([$model->id]);

    return compact('make', 'model', 'chassis', 'engine');
}

it('accepts searchable ids and persists enriched product-level import data', function () {
    $category = Category::create([
        'name' => 'Import Category',
        'slug' => 'import-category',
    ]);

    $brand = Brand::create([
        'name' => 'Import Brand',
        'status' => 'active',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'W-IMP',
        'name' => 'Warehouse Import',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto importado',
            'sku' => 'IMP-001',
            'categoria' => $category->id,
            '__attributes' => [
                [
                    'name' => 'Amperaje',
                    'value' => '90',
                    'unit' => 'A',
                ],
            ],
            '__variants' => [
                [
                    'brand_id' => $brand->id,
                    'sku' => 'IMP-001-BRD',
                    'pricing' => [
                        'base_price' => 125,
                        'msrp' => 140,
                        'cost_base' => 50,
                        'cost_tax_rate' => 7,
                        'cost_miscellaneous' => 3,
                        'sale_tax_rate' => 7,
                    ],
                    'initial_inventory' => [
                        [
                            'warehouse_id' => $warehouse->id,
                            'stock_available' => 4,
                            'reorder_point' => 2,
                            'reorder_quantity' => 8,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::where('sku_base', 'IMP-001')->firstOrFail();
    $variant = $product->variants()->where('brand_id', $brand->id)->firstOrFail();

    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'Amperaje',
        'value' => '90',
        'unit' => 'A',
    ]);

    $this->assertDatabaseMissing('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'name' => 'Amperaje',
    ]);

    $this->assertDatabaseHas('product_inventory', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 4,
        'reorder_point' => 2,
        'reorder_quantity' => 8,
    ]);

    $pricing = ProductPricing::where('variant_id', $variant->id)->firstOrFail();

    expect((float) $pricing->cost)->toBe(56.5)
        ->and((float) $pricing->cost_total)->toBe(56.5)
        ->and((float) $pricing->cost_base)->toBe(50.0)
        ->and((float) $pricing->cost_miscellaneous)->toBe(3.0);
});

it('defaults imported variant tax rates to store tax rate when omitted', function () {
    Setting::set('store_tax_rate', 10, 'number', 'store');

    $category = Category::create([
        'name' => 'Import Tax Category',
        'slug' => 'import-tax-category',
    ]);

    $brand = Brand::create([
        'name' => 'Import Tax Brand',
        'status' => 'active',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'W-TAX-IMP',
        'name' => 'Warehouse Tax Import',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto impuesto default',
            'sku' => 'IMP-TAX-001',
            'categoria' => $category->id,
            '__variants' => [
                [
                    'brand_id' => $brand->id,
                    'sku' => 'IMP-TAX-001-BRD',
                    'pricing' => [
                        'base_price' => 125,
                        'cost_base' => 50,
                        'cost_miscellaneous' => 0,
                    ],
                    'initial_inventory' => [
                        [
                            'warehouse_id' => $warehouse->id,
                            'stock_available' => 1,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::where('sku_base', 'IMP-TAX-001')->firstOrFail();
    $pricing = ProductPricing::where('variant_id', $product->variants()->firstOrFail()->id)->firstOrFail();

    expect((int) $pricing->sale_tax_rate)->toBe(10)
        ->and((int) $pricing->cost_tax_rate)->toBe(10)
        ->and((int) $pricing->tax_rate)->toBe(10)
        ->and((float) $pricing->cost_total)->toBe(55.0);
});

it('groups repeated product rows as variants even when imported skus differ', function () {
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);
    productImportEnsureBrands(['Shell', 'Pennzoil']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => '10W30 cuarto mineral',
            'sku' => 'OIL-10W30-QT-SHELL',
            'system_id' => 'LS-001',
            'brand' => 'Shell',
            'categoria' => $category->name,
            'costo' => '5.35',
            'precio' => '6.42',
            'cantidad' => '10',
            'warehouse' => $warehouse->code,
        ],
        [
            '__row' => 3,
            'nombre' => '10W30 cuarto mineral',
            'sku' => 'OIL-10W30-QT-PENN',
            'system_id' => 'LS-002',
            'brand' => 'Pennzoil',
            'categoria' => $category->name,
            'costo' => '6.00',
            'precio' => '7.25',
            'cantidad' => '4',
            'warehouse' => $warehouse->code,
        ],
    ]);

    expect($import->successCount)->toBe(2)
        ->and($import->errorCount)->toBe(0)
        ->and(Product::query()->where('name', '10W30 cuarto mineral')->count())->toBe(1);

    $product = Product::query()->where('name', '10W30 cuarto mineral')->firstOrFail();
    $variants = ProductVariant::query()->where('product_id', $product->id)->orderBy('system_id')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('system_id')->all())->toBe(['LS-001', 'LS-002'])
        ->and($variants->pluck('sku')->all())->toBe(['OIL-10W30-QT-SHELL', 'OIL-10W30-QT-PENN'])
        ->and($product->sku_base)->toBe('OIL-10W30-QT-SHELL');

    $this->assertDatabaseHas('product_inventory', [
        'product_id' => $product->id,
        'variant_id' => $variants->first()->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 10,
    ]);

    $this->assertDatabaseHas('inventory_lots', [
        'product_id' => $product->id,
        'variant_id' => $variants->first()->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 10,
    ]);
});

it('rejects repeated product rows with the same brand as duplicate imported variants', function () {
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $category = Category::create([
        'name' => 'Suspension Direccion',
        'slug' => 'suspension-direccion',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 168,
            'nombre' => 'TERMINAL CREM HYUN KIA PICANTO 04-',
            'sku' => 'SR-5400',
            'system_id' => '210000020044',
            'brand' => '555',
            'categoria' => $category->name,
            'costo' => '10.70',
            'precio' => '17.00',
            'cantidad' => '2',
            'warehouse' => $warehouse->code,
        ],
        [
            '__row' => 169,
            'nombre' => 'TERMINAL CREM HYUN KIA PICANTO 04-',
            'sku' => 'SR-6380',
            'system_id' => '210000020045',
            'brand' => '555',
            'categoria' => $category->name,
            'costo' => '14.32',
            'precio' => '22.00',
            'cantidad' => '2',
            'warehouse' => $warehouse->code,
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(1);

    $product = Product::query()->where('name', 'TERMINAL CREM HYUN KIA PICANTO 04-')->firstOrFail();
    $variants = ProductVariant::query()->where('product_id', $product->id)->orderBy('system_id')->get();

    expect($variants)->toHaveCount(1)
        ->and($variants->first()->brand->name)->toBe('555')
        ->and($variants->first()->sku)->toBe('SR-5400')
        ->and($variants->first()->system_id)->toBe('210000020044');
});

it('does not group equal sku and name across different categories', function () {
    $categories = collect(['Aceite Motor', 'Filtros'])->map(fn (string $name): Category => Category::create([
        'name' => $name,
        'slug' => Str::slug($name),
    ]));
    productImportEnsureBrands(['Shell']);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto compartido',
            'sku' => 'SAME-001',
            'system_id' => 'LS-CAT-001',
            'brand' => 'Shell',
            'categoria' => $categories->first()->name,
            'cantidad' => '1',
            'warehouse' => $warehouse->code,
        ],
        [
            '__row' => 3,
            'nombre' => 'Producto compartido',
            'sku' => 'SAME-001',
            'system_id' => 'LS-CAT-002',
            'brand' => 'Shell',
            'categoria' => $categories->last()->name,
            'cantidad' => '1',
            'warehouse' => $warehouse->code,
        ],
    ]);

    expect($import->successCount)->toBe(2)
        ->and($import->errorCount)->toBe(0)
        ->and(Product::query()->where('sku_base', 'SAME-001')->count())->toBe(2);
});

it('reports missing categories without creating them during product import', function () {
    productImportEnsureBrands(['Generica']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto sin categoria',
            'sku' => 'NO-CAT-001',
            'brand' => 'Shell',
            'categoria' => 'Categoria Fantasma',
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1)
        ->and(Category::query()->where('name', 'Categoria Fantasma')->exists())->toBeFalse()
        ->and($import->errors[0]['error'])->toContain('no existe');
});

it('reports missing product brands in preview without creating them', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    Brand::create([
        'name' => 'Shibumi Parts',
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['10W30 cuarto mineral', 'OIL-001', 'LS-001', 'Shibumi', 'Aceite Motor'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.summary.missing_references.brands.0', 'Shibumi')
        ->assertJsonPath('data.summary.suggestions.brands.0.value', 'Shibumi')
        ->assertJsonPath('data.summary.suggestions.brands.0.suggestion', 'Shibumi Parts')
        ->assertJsonPath('data.summary.suggestions.brands.0.can_create', true);

    expect(Brand::query()->where('name', 'Shibumi')->exists())->toBeFalse();
});

it('allows quick creation of a missing product brand as active', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/brands', [
        'name' => 'Shibumi',
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Shibumi')
        ->assertJsonPath('data.status', 'active');

    expect(Brand::query()->where('name', 'Shibumi')->where('status', 'active')->exists())->toBeTrue();
});

it('does not create missing product brands during import', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Aceite con marca nueva',
            'sku' => 'OIL-MISS-BRAND',
            'brand' => 'Shibumi',
            'categoria' => 'Aceite Motor',
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1)
        ->and($import->errors[0]['error'])->toContain('La marca "Shibumi" no existe')
        ->and(Brand::query()->where('name', 'Shibumi')->exists())->toBeFalse();
});

it('uses the existing generic brand when the import row has no product brand', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $brand = Brand::create([
        'name' => 'Generica',
        'status' => 'active',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Aceite sin marca',
            'sku' => 'OIL-GENERIC',
            'system_id' => 'LS-GEN',
            'categoria' => 'Aceite Motor',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'OIL-GENERIC')->firstOrFail();
    expect($product->variants()->where('brand_id', $brand->id)->exists())->toBeTrue();
});

it('updates existing imported variant inventory from the file quantity', function () {
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $brand = Brand::create([
        'name' => 'Shell',
        'status' => 'active',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);

    $product = Product::create([
        'system_id' => 'P-0001',
        'name' => '10W30 cuarto mineral',
        'sku_base' => 'OIL-10W30-QT',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'OIL-10W30-QT',
        'system_id' => 'LS-001',
        'status' => 'active',
    ]);

    $variant->inventory()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 2,
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => '10W30 cuarto mineral',
            'sku' => 'OIL-10W30-QT',
            'system_id' => 'LS-001',
            'brand' => 'Shell',
            'categoria' => $category->name,
            'costo' => '6.00',
            'precio' => '7.25',
            'cantidad' => '5',
            'warehouse' => $warehouse->code,
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($variant->inventory()->where('warehouse_id', $warehouse->id)->firstOrFail()->quantity_available)->toBe(5);

    $this->assertDatabaseHas('inventory_lots', [
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 5,
    ]);
});

it('extracts category technical attributes from the product name and cleans matched segments', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'TIE', 'type' => 'string'],
            ['name' => 'LENGTH', 'type' => 'string'],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK M12x1.50TIE 220LENGTH Terminal Interior',
            'sku' => 'TIE-001',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-001')->firstOrFail();

    expect($product->name)->toBe('Terminal Interior');

    expect(ProductAttribute::query()
        ->where('product_id', $product->id)
        ->whereNull('variant_id')
        ->where('source', 'category')
        ->pluck('value', 'name')
        ->all())->toBe([
            'RACK' => 'M14x1.50',
            'TIE' => 'M12x1.50',
            'LENGTH' => '220',
        ]);
});

it('detects complete vehicle scope from product name while keeping vehicle text visible', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-vehicle-name',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'TIE', 'type' => 'string'],
        ],
    ]);
    productImportEnsureBrands(['555']);
    ['make' => $make, 'model' => $model, 'chassis' => $chassis, 'engine' => $engine] = productImportCreateVehicleScope();

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Terminal CREM TOYOTA YARIS P90 1NZFE M14x1.50RACK M12x1.50TIE',
            'sku' => 'TIE-VEHICLE-NAME-001',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-VEHICLE-NAME-001')->firstOrFail();

    expect($product->name)->toBe('Terminal CREM TOYOTA YARIS P90 1NZFE');

    $this->assertDatabaseHas('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'scope_type' => 'engine',
        'make_id' => $make->id,
        'model_id' => $model->id,
        'engine_id' => $engine->id,
    ]);

    $this->assertDatabaseHas('product_vehicle_compatibility', [
        'product_id' => $product->id,
        'scope_type' => 'chassis',
        'make_id' => $make->id,
        'model_id' => $model->id,
        'chassis_id' => $chassis->id,
    ]);

    expect(ProductAttribute::query()
        ->where('product_id', $product->id)
        ->whereNull('variant_id')
        ->pluck('value', 'name')
        ->all())->toBe([
            'RACK' => 'M14x1.50',
            'TIE' => 'M12x1.50',
        ]);
});

it('fails when vehicle text in product name does not resolve to a complete existing scope', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-missing-vehicle',
    ]);
    productImportEnsureBrands(['555']);
    productImportCreateVehicleScope(engineCode: '1NZFE');

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Terminal CREM TOYOTA YARIS P90 2NZFE',
            'sku' => 'TIE-VEHICLE-NAME-MISSING',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1)
        ->and($import->errors[0]['error'])->toContain('No se pudo resolver el vehiculo completo');
});

it('reports unresolved name-derived vehicle candidates in preview metadata', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-preview-missing-vehicle',
    ]);
    productImportEnsureBrands(['555']);

    $make = VehicleMake::query()->create(['name' => 'TOYOTA']);
    VehicleModel::query()->create([
        'make_id' => $make->id,
        'name' => 'YARIS',
        'year_from' => 2006,
        'year_to' => 2013,
        'generation' => 'P90',
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['Terminal CREM TOYOTA YARIS P90 1NZFE', 'TIE-MISSING-VEHICLE', 'LS-MISSING-VEHICLE', '555', 'Terminales'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.is_valid', false)
        ->assertJsonPath('data.rows.0.data.nombre', 'Terminal CREM TOYOTA YARIS P90 1NZFE')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.make', 'TOYOTA')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.model', 'YARIS')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.chassis', 'P90')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.engine', '1NZFE')
        ->assertJsonPath('data.summary.missing_vehicle_candidates.0.make', 'TOYOTA')
        ->assertJsonPath('data.summary.missing_vehicle_candidates.0.model', 'YARIS');

    expect($response->json('data.rows.0.errors.0'))->toContain('No se pudo resolver el vehiculo completo');
});

it('reports name-derived vehicle creation candidates when the vehicle catalog is empty', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-empty-vehicle-catalog',
    ]);
    productImportEnsureBrands(['555']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['Terminal CREM TOYOTA YARIS P90 1NZFE', 'TIE-EMPTY-VEHICLE', 'LS-EMPTY-VEHICLE', '555', 'Terminales'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.is_valid', false)
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.make', 'TOYOTA')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.model', 'YARIS')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.chassis', 'P90')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.engine', '1NZFE')
        ->assertJsonPath('data.summary.missing_vehicle_candidates.0.make', 'TOYOTA');
});

it('does not duplicate name-derived vehicle compatibility when explicit vehicle columns are present', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-explicit-vehicle',
    ]);
    productImportEnsureBrands(['555']);
    ['chassis' => $chassis, 'engine' => $engine] = productImportCreateVehicleScope();

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Terminal CREM TOYOTA YARIS P90 1NZFE',
            'sku' => 'TIE-VEHICLE-NAME-COLUMNS',
            'brand' => '555',
            'categoria' => $category->name,
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'code' => 'P90',
            'engine' => '1NZFE',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-VEHICLE-NAME-COLUMNS')->firstOrFail();

    expect($product->vehicleCompatibility()->where('engine_id', $engine->id)->count())->toBe(1)
        ->and($product->vehicleCompatibility()->where('chassis_id', $chassis->id)->count())->toBe(1);
});

it('fails when product name contains more than one complete vehicle candidate', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales-ambiguous-vehicle',
    ]);
    productImportEnsureBrands(['555']);
    productImportCreateVehicleScope();
    productImportCreateVehicleScope(chassisCode: 'P10', engineCode: '2NZFE');

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Terminal CREM TOYOTA YARIS P90 1NZFE TOYOTA YARIS P10 2NZFE',
            'sku' => 'TIE-VEHICLE-NAME-AMBIGUOUS',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1)
        ->and($import->errors[0]['error'])->toContain('mas de un vehiculo completo');
});

it('extracts technical attributes from extra categories cleans name and keeps their source category', function () {
    $primaryCategory = Category::create([
        'name' => 'Direccion',
        'slug' => 'direccion',
        'default_attributes' => [],
    ]);
    $extraCategory = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'TIE', 'type' => 'string'],
            ['name' => 'LENGTH', 'type' => 'string'],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK M12x1.50TIE 220LENGTH Terminal Exterior',
            'sku' => 'TIE-EXTRA-001',
            'brand' => '555',
            'categoria' => $primaryCategory->name,
            'categorias_extra' => $extraCategory->name,
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-EXTRA-001')->firstOrFail();

    expect($product->name)->toBe('Terminal Exterior');

    expect(ProductAttribute::query()
        ->where('product_id', $product->id)
        ->whereNull('variant_id')
        ->where('source', 'category')
        ->get(['name', 'value', 'source_category_id'])
        ->mapWithKeys(fn (ProductAttribute $attribute): array => [
            $attribute->name => [
                'value' => $attribute->value,
                'source_category_id' => $attribute->source_category_id,
            ],
        ])
        ->all())->toBe([
            'RACK' => ['value' => 'M14x1.50', 'source_category_id' => $extraCategory->id],
            'TIE' => ['value' => 'M12x1.50', 'source_category_id' => $extraCategory->id],
            'LENGTH' => ['value' => '220', 'source_category_id' => $extraCategory->id],
        ]);
});

it('keeps the original product name when all segments are extracted as technical attributes', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'TIE', 'type' => 'string'],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK M12x1.50TIE',
            'sku' => 'TIE-FALLBACK-001',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    $product = Product::query()->where('sku_base', 'TIE-FALLBACK-001')->firstOrFail();

    expect($product->name)->toBe('M14x1.50RACK M12x1.50TIE')
        ->and(ProductAttribute::query()->where('product_id', $product->id)->pluck('value', 'name')->all())->toBe([
            'RACK' => 'M14x1.50',
            'TIE' => 'M12x1.50',
        ]);
});

it('keeps invalid select technical segments in the product name without creating the attribute', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'LENGTH', 'type' => 'select', 'options' => ['200']],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK 220LENGTH Terminal Interior',
            'sku' => 'TIE-SELECT-001',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ]);

    $product = Product::query()->where('sku_base', 'TIE-SELECT-001')->firstOrFail();

    expect($product->name)->toBe('220LENGTH Terminal Interior')
        ->and(ProductAttribute::query()->where('product_id', $product->id)->pluck('value', 'name')->all())->toBe([
            'RACK' => 'M14x1.50',
        ])
        ->and($import->errors)->toBeEmpty();
});

it('uses the original technical name to keep same clean-name products separated', function () {
    $category = Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $rows = [
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK Terminal Interior',
            'sku' => 'TIE-M14',
            'system_id' => 'LS-RACK-M14',
            'brand' => '555',
            'categoria' => $category->name,
        ],
        [
            '__row' => 3,
            'nombre' => 'M12x1.50RACK Terminal Interior',
            'sku' => 'TIE-M12',
            'system_id' => 'LS-RACK-M12',
            'brand' => '555',
            'categoria' => $category->name,
        ],
    ];

    $import = new ProductImport(app(ProductService::class));
    $import->execute($rows);

    expect($import->successCount)->toBe(2)
        ->and($import->errorCount)->toBe(0)
        ->and(Product::query()->where('category_id', $category->id)->where('name', 'Terminal Interior')->count())->toBe(2);

    expect(Product::query()
        ->whereHas('attributes', fn ($query) => $query->where('name', 'RACK')->where('value', 'M14x1.50'))
        ->firstOrFail()
        ->variants()
        ->pluck('system_id')
        ->all())->toBe(['LS-RACK-M14']);

    expect(Product::query()
        ->whereHas('attributes', fn ($query) => $query->where('name', 'RACK')->where('value', 'M12x1.50'))
        ->firstOrFail()
        ->variants()
        ->pluck('system_id')
        ->all())->toBe(['LS-RACK-M12']);

    $secondImport = new ProductImport(app(ProductService::class));
    $secondImport->execute($rows);

    expect($secondImport->successCount)->toBe(2)
        ->and($secondImport->errorCount)->toBe(0)
        ->and(Product::query()->where('category_id', $category->id)->where('name', 'Terminal Interior')->count())->toBe(2)
        ->and(ProductVariant::query()->whereIn('system_id', ['LS-RACK-M14', 'LS-RACK-M12'])->count())->toBe(2);
});

it('prioritizes primary category technical attributes over duplicate extra category attributes', function () {
    $primaryCategory = Category::create([
        'name' => 'Principal',
        'slug' => 'principal',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string', 'unit' => 'principal'],
        ],
    ]);
    $extraCategory = Category::create([
        'name' => 'Extra',
        'slug' => 'extra',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string', 'unit' => 'extra'],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'M14x1.50RACK',
            'sku' => 'TIE-DUP-001',
            'brand' => '555',
            'categoria' => $primaryCategory->name,
            'categorias_extra' => $extraCategory->name,
        ],
    ]);

    $product = Product::query()->where('sku_base', 'TIE-DUP-001')->firstOrFail();
    $attribute = ProductAttribute::query()
        ->where('product_id', $product->id)
        ->where('name', 'RACK')
        ->firstOrFail();

    expect($attribute->value)->toBe('M14x1.50')
        ->and($attribute->unit)->toBe('principal')
        ->and($attribute->source_category_id)->toBe($primaryCategory->id)
        ->and($product->name)->toBe('M14x1.50RACK');
});

it('returns preview grouping metadata and technical attribute warnings without importing products', function () {
    Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'LENGTH', 'type' => 'select', 'options' => ['200']],
        ],
    ]);
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['555', 'CTR']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['nombre', 'sku', 'system_id', 'brand', 'categoria'],
        ['M14x1.50RACK 220LENGTH', 'TIE-555', 'LS-001', '555', 'Terminales'],
        ['M14x1.50RACK 220LENGTH', 'TIE-CTR', 'LS-002', 'CTR', 'Terminales'],
        ['M12x1.50RACK 220LENGTH', 'TIE-555-M12', 'LS-004', '555', 'Terminales'],
        ['Producto sin categoria', 'NO-CAT', 'LS-003', '555', 'Aceites Motor'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.count', 4)
        ->assertJsonPath('data.preview_limited', false)
        ->assertJsonPath('data.rows.0.data.nombre', '220LENGTH')
        ->assertJsonPath('data.rows.0.data.__original_name', 'M14x1.50RACK 220LENGTH')
        ->assertJsonPath('data.rows.0.data.__clean_name', '220LENGTH')
        ->assertJsonPath('data.rows.0.data.__attributes.0.name', 'RACK')
        ->assertJsonPath('data.rows.0.data.__attributes.0.value', 'M14x1.50')
        ->assertJsonPath('data.rows.0.data.__variant_preview.system_id', 'LS-001')
        ->assertJsonPath('data.rows.0.data.__variant_preview.sku', 'TIE-555')
        ->assertJsonPath('data.rows.0.data.__variant_preview.brand', '555')
        ->assertJsonPath('data.summary.groups.0.product_key', 'terminales|m14x1.50rack 220length')
        ->assertJsonPath('data.summary.groups.0.original_name', 'M14x1.50RACK 220LENGTH')
        ->assertJsonPath('data.summary.groups.0.clean_name', '220LENGTH')
        ->assertJsonPath('data.summary.groups.0.variant_count', 2)
        ->assertJsonPath('data.summary.groups.0.variant_skus', ['TIE-555', 'TIE-CTR'])
        ->assertJsonPath('data.summary.groups.0.variants.0.system_id', 'LS-001')
        ->assertJsonPath('data.summary.groups.0.variants.0.sku', 'TIE-555')
        ->assertJsonPath('data.summary.groups.0.sku_warning', 'El codigo cambia por variante; se importara como SKU de variante.')
        ->assertJsonPath('data.summary.productos_estimados', 3)
        ->assertJsonPath('data.summary.variantes_estimadas', 4)
        ->assertJsonPath('data.summary.missing_categories.0', 'Aceites Motor')
        ->assertJsonPath('data.summary.suggestions.categories.0.suggestion', 'Aceite Motor');

    expect(Product::query()->count())->toBe(0)
        ->and($response->json('data.rows.0.warnings.0'))->toContain('LENGTH');
});

it('returns preview detected attributes and select warnings from extra categories', function () {
    Category::create([
        'name' => 'Direccion',
        'slug' => 'direccion',
    ]);
    Category::create([
        'name' => 'Terminales',
        'slug' => 'terminales',
        'default_attributes' => [
            ['name' => 'RACK', 'type' => 'string'],
            ['name' => 'LENGTH', 'type' => 'select', 'options' => ['200']],
        ],
    ]);
    productImportEnsureBrands(['555']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['nombre', 'sku', 'system_id', 'brand', 'categoria', 'categorias_extra'],
        ['M14x1.50RACK 220LENGTH', 'TIE-EXTRA-PREVIEW', 'LS-EXTRA-001', '555', 'Direccion', 'Terminales'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.data.nombre', '220LENGTH')
        ->assertJsonPath('data.summary.detected_attributes.0', 'RACK');

    expect(Product::query()->count())->toBe(0)
        ->and($response->json('data.rows.0.warnings.0'))->toContain('LENGTH')
        ->and($response->json('data.rows.0.warnings.0'))->toContain('Terminales');
});

it('recommends and previews the product sheet when the first excel sheet is not products', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheetWithSheets([
        'Resumen' => [
            ['titulo', 'fecha'],
            ['No importar esta hoja', '2026-05-21'],
        ],
        'SAMPLE UPLOAD LIGHTSPEED' => [
            ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Quantity'],
            ['10W30 cuarto mineral', 'OIL-10W30-QT', 'LS-001', 'Shell', 'Aceite Motor', '5.35', '6.42', '10'],
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.selected_sheet.name', 'SAMPLE UPLOAD LIGHTSPEED')
        ->assertJsonPath('data.recommended_sheet.name', 'SAMPLE UPLOAD LIGHTSPEED')
        ->assertJsonPath('data.sheets.0.name', 'Resumen')
        ->assertJsonPath('data.sheets.1.is_recommended', true)
        ->assertJsonPath('data.rows.0.data.nombre', '10W30 cuarto mineral')
        ->assertJsonPath('data.rows.0.data.system_id', 'LS-001');
});

it('uses an explicitly selected sheet even when another sheet has a stronger import score', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheetWithSheets([
        'Productos Manuales' => [
            ['nombre', 'categoria'],
            ['Producto manual', 'Aceite Motor'],
        ],
        'Lightspeed' => [
            ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Quantity'],
            ['Producto recomendado', 'REC-001', 'LS-REC', 'Shell', 'Aceite Motor', '5.35', '6.42', '10'],
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
        'sheet_index' => 0,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.selected_sheet.name', 'Productos Manuales')
        ->assertJsonPath('data.recommended_sheet.name', 'Lightspeed')
        ->assertJsonPath('data.rows.0.data.nombre', 'Producto manual');
});

it('allows import files larger than 25mb and rejects files larger than 100mb', function () {
    $rules = (new PreviewImportRequest)->rules();

    $validLargeFile = UploadedFile::fake()->create(
        'lightspeed.xlsx',
        26000,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    $tooLargeFile = UploadedFile::fake()->create(
        'lightspeed.xlsx',
        102401,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    expect(Validator::make(['file' => $validLargeFile], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['file' => $tooLargeFile], $rules)->passes())->toBeFalse();
});

it('starts a queued full product import run from the uploaded file', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = UploadedFile::fake()->create(
        'lightspeed.xlsx',
        26000,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => $file,
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING)
        ->assertJsonStructure(['data' => ['import_run_id']]);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->entity)->toBe('products')
        ->and($importRun->original_filename)->toBe('lightspeed.xlsx')
        ->and($importRun->sheet_index)->toBe(1)
        ->and($importRun->sheet_name)->toBe('SAMPLE UPLOAD LIGHTSPEED')
        ->and($importRun->status)->toBe(ImportRun::STATUS_PENDING);

    Storage::disk('local')->assertExists($importRun->stored_path);
    Queue::assertPushed(ProcessProductImportRun::class);
});

it('processes the selected sheet in a full product import job', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);

    $file = productImportUploadedSpreadsheetWithSheets([
        'Resumen' => [
            ['titulo', 'fecha'],
            ['No importar esta hoja', '2026-05-21'],
        ],
        'SAMPLE UPLOAD LIGHTSPEED' => [
            ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Quantity', 'Warehouse'],
            ['10W30 cuarto mineral', 'OIL-10W30-QT', 'LS-001', 'Shell', 'Aceite Motor', '5.35', '6.42', '10', $warehouse->code],
        ],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'lightspeed-multiple-sheets.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'original_filename' => 'lightspeed-multiple-sheets.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->processed_rows)->toBe(1)
        ->and(Product::query()->where('sku_base', 'OIL-10W30-QT')->exists())->toBeTrue()
        ->and($importRun->summary['source_file_deleted'] ?? null)->toBeFalse();

    Storage::disk('local')->assertExists($storedPath);
});

it('does not reprocess a product import run that is already running', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['10W30', 'OIL-RUNNING', 'LS-RUNNING', 'Shell', 'Aceite Motor'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'running.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'running.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_RUNNING,
        'started_at' => now()->subMinute(),
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_RUNNING)
        ->and(Product::query()->where('sku_base', 'OIL-RUNNING')->exists())->toBeFalse()
        ->and(ImportRunRecord::query()->where('import_run_id', $importRun->id)->exists())->toBeFalse();

    Storage::disk('local')->assertExists($storedPath);
});

it('does not reprocess a product import run that is already completed', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['10W30', 'OIL-COMPLETED', 'LS-COMPLETED', 'Shell', 'Aceite Motor'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'completed.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'completed.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_COMPLETED,
        'processed_rows' => 1,
        'success_count' => 1,
        'finished_at' => now()->subMinute(),
        'results' => [],
        'errors' => [],
        'summary' => ['source_file_deleted' => true],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->processed_rows)->toBe(1)
        ->and(Product::query()->where('sku_base', 'OIL-COMPLETED')->exists())->toBeFalse();
});

it('returns full product import run progress', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'lightspeed.xlsx',
        'stored_path' => 'imports/lightspeed.xlsx',
        'status' => ImportRun::STATUS_RUNNING,
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'total_rows' => 42000,
        'processed_rows' => 1000,
        'success_count' => 990,
        'error_count' => 10,
        'results' => [],
        'errors' => [['row' => 7, 'label' => 'Producto', 'error' => 'Categoria no existe.']],
        'summary' => ['latest_errors_limited_to' => 100],
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/products/import/runs/{$importRun->id}");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_RUNNING)
        ->assertJsonPath('data.total_rows', 42000)
        ->assertJsonPath('data.sheet_index', 1)
        ->assertJsonPath('data.sheet_name', 'SAMPLE UPLOAD LIGHTSPEED')
        ->assertJsonPath('data.processed_rows', 1000)
        ->assertJsonPath('data.error_count', 10);
});

it('downloads the original source file for visible product import runs', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $storedPath = 'imports/source-download.xlsx';
    Storage::disk('local')->put($storedPath, 'source-file-content');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'archivo-original.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->get("/api/v1/products/import/runs/{$importRun->id}/source")
        ->assertOk()
        ->assertDownload('archivo-original.xlsx');
});

it('prevents managers from downloading another users original import file', function () {
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $otherManager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $storedPath = 'imports/foreign-source.xlsx';
    Storage::disk('local')->put($storedPath, 'source-file-content');

    $foreignRun = ImportRun::query()->create([
        'user_id' => $otherManager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'foreign-source.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($manager)
        ->get("/api/v1/products/import/runs/{$foreignRun->id}/source")
        ->assertNotFound();
});

it('returns source download metadata in product import history and detail', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/history-source.xlsx', 'source');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'history-source.xlsx',
        'stored_path' => 'imports/history-source.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'total_rows' => 3,
        'processed_rows' => 3,
        'success_count' => 3,
        'error_count' => 0,
        'results' => [],
        'errors' => [],
        'summary' => ['preview_async' => true],
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?per_page=5')
        ->assertOk()
        ->assertJsonPath('data.0.id', $importRun->id)
        ->assertJsonPath('data.0.has_source_file', true)
        ->assertJsonPath('data.0.preview_async', true)
        ->assertJsonPath('data.0.user.name', $admin->name)
        ->assertJsonPath('data.0.source_download_url', "/api/v1/products/import/runs/{$importRun->id}/source")
        ->assertJsonPath('data.0.errors_download_url', "/api/v1/products/import/runs/{$importRun->id}/errors.xlsx");

    $this->actingAs($admin)
        ->getJson("/api/v1/products/import/runs/{$importRun->id}")
        ->assertOk()
        ->assertJsonPath('data.has_source_file', true)
        ->assertJsonPath('data.preview_async', true)
        ->assertJsonPath('data.source_download_url', "/api/v1/products/import/runs/{$importRun->id}/source");
});

it('lists recent product import runs ordered by newest first with progress flags', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/pending.xlsx', 'pending');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $olderCompletedRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'completed.xlsx',
        'stored_path' => 'imports/completed.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'total_rows' => 10,
        'processed_rows' => 10,
        'success_count' => 8,
        'error_count' => 2,
        'failed_rows' => [[
            'row' => 7,
            'data' => ['nombre' => 'Aceite', 'categoria' => 'Fantasma'],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
        'finished_at' => now()->subMinutes(9),
    ]);
    $olderCompletedRun->forceFill([
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(9),
    ])->save();

    $newerPendingRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'pending.xlsx',
        'stored_path' => 'imports/pending.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'total_rows' => 20,
        'processed_rows' => 5,
        'success_count' => 4,
        'error_count' => 1,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $newerPendingRun->forceFill([
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinute(),
    ])->save();

    ImportRun::query()->create([
        'entity' => 'customers',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'customers.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/products/import/runs?per_page=10');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $newerPendingRun->id)
        ->assertJsonPath('data.0.status', ImportRun::STATUS_PENDING)
        ->assertJsonPath('data.0.progress_percent', 25)
        ->assertJsonPath('data.0.has_errors', true)
        ->assertJsonPath('data.0.has_source_file', true)
        ->assertJsonPath('data.0.can_cancel', true)
        ->assertJsonPath('data.1.id', $olderCompletedRun->id)
        ->assertJsonPath('data.1.status', ImportRun::STATUS_COMPLETED)
        ->assertJsonPath('data.1.progress_percent', 100)
        ->assertJsonPath('data.1.can_retry_failed', true)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 2);
});

it('filters recent product import runs for the import screen', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $retryableRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'lightspeed-errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'total_rows' => 10,
        'processed_rows' => 10,
        'success_count' => 8,
        'error_count' => 2,
        'failed_rows' => [[
            'row' => 3,
            'data' => ['nombre' => 'Filtro retry', 'categoria' => 'Fantasma'],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $retryableRun->forceFill([
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ])->save();

    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validation.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'total_rows' => 4,
        'processed_rows' => 4,
        'success_count' => 4,
        'error_count' => 0,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $validationRun->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ])->save();

    $runningRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_UPDATE_STOCK_PRICE,
        'original_filename' => 'stock-price.xlsx',
        'status' => ImportRun::STATUS_RUNNING,
        'total_rows' => 8,
        'processed_rows' => 2,
        'success_count' => 2,
        'error_count' => 0,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $runningRun->forceFill([
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?status=running')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $runningRun->id);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?run_type=validation&mode=validate_only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $validationRun->id);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?file=errors&has_errors=1&can_retry_failed=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $retryableRun->id);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $runningRun->id);
});

it('lets admins see product import runs from every user', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $adminRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'admin.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $managerRun = ImportRun::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'manager.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    ImportRun::query()->create([
        'entity' => 'customers',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'customers.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->getJson('/api/v1/products/import/runs?per_page=10');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($adminRun->id, $managerRun->id);
});

it('limits managers to their own product import runs', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);
    $otherManager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $ownRun = ImportRun::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'own-errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'error_count' => 1,
        'failed_rows' => [[
            'row' => 2,
            'data' => ['nombre' => 'Propio', 'categoria' => 'Fantasma'],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    ImportRun::query()->create([
        'user_id' => $otherManager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'foreign-errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'error_count' => 1,
        'failed_rows' => [[
            'row' => 3,
            'data' => ['nombre' => 'Ajeno', 'categoria' => 'Fantasma'],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($manager)->getJson('/api/v1/products/import/runs?file=errors&can_retry_failed=1');

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $ownRun->id);
});

it('returns not found when a manager tries to operate another users import run', function () {
    Queue::fake();

    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);
    $otherManager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $foreignRun = ImportRun::query()->create([
        'user_id' => $otherManager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'foreign.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'failed_rows' => [[
            'row' => 2,
            'data' => ['nombre' => 'Ajeno', 'categoria' => 'Fantasma'],
        ]],
        'errors' => [[
            'row' => 2,
            'label' => 'Ajeno',
            'data' => ['nombre' => 'Ajeno', 'categoria' => 'Fantasma'],
            'error' => 'La categoria no existe.',
        ]],
        'results' => [],
        'summary' => [],
    ]);

    $this->actingAs($manager)
        ->getJson("/api/v1/products/import/runs/{$foreignRun->id}")
        ->assertNotFound();

    $this->actingAs($manager)
        ->get("/api/v1/products/import/runs/{$foreignRun->id}/errors.xlsx")
        ->assertNotFound();

    $this->actingAs($manager)
        ->postJson("/api/v1/products/import/runs/{$foreignRun->id}/cancel")
        ->assertNotFound();

    $foreignRun->forceFill(['status' => ImportRun::STATUS_COMPLETED])->save();

    $this->actingAs($manager)
        ->postJson("/api/v1/products/import/runs/{$foreignRun->id}/retry-failed")
        ->assertNotFound();

    expect($foreignRun->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and(ImportRun::query()->where('parent_import_run_id', $foreignRun->id)->exists())->toBeFalse();

    Queue::assertNothingPushed();
});

it('lets admins operate product import runs from other users', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $foreignRun = ImportRun::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'foreign.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'failed_rows' => [[
            'row' => 2,
            'data' => ['nombre' => 'Ajeno', 'categoria' => 'Fantasma'],
        ]],
        'errors' => [[
            'row' => 2,
            'label' => 'Ajeno',
            'data' => ['nombre' => 'Ajeno', 'categoria' => 'Fantasma'],
            'error' => 'La categoria no existe.',
        ]],
        'results' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->getJson("/api/v1/products/import/runs/{$foreignRun->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $foreignRun->id);

    $this->actingAs($admin)
        ->get("/api/v1/products/import/runs/{$foreignRun->id}/errors.xlsx")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$foreignRun->id}/retry-failed")
        ->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING);

    $retryRun = ImportRun::query()->where('parent_import_run_id', $foreignRun->id)->firstOrFail();
    expect($retryRun->user_id)->toBe($admin->id);

    $pendingForeignRun = ImportRun::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'pending-foreign.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$pendingForeignRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_CANCEL_REQUESTED);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('returns detected columns suggested mapping and applies manual mapping in preview', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Item Name', 'Product Group', 'Variant Code'],
        ['Aceite manual', 'Aceite Motor', 'MAN-001'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
        'mapping' => [
            'item_name' => 'nombre',
            'product_group' => 'categoria',
            'variant_code' => 'system_id',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.detected_columns.0.key', 'item_name')
        ->assertJsonPath('data.suggested_mapping.item_name', 'ignore')
        ->assertJsonPath('data.required_fields.0', 'nombre')
        ->assertJsonPath('data.rows.0.data.nombre', 'Aceite manual')
        ->assertJsonPath('data.rows.0.data.categoria', 'Aceite Motor')
        ->assertJsonPath('data.rows.0.data.system_id', 'MAN-001');
});

it('does not suggest hidden legacy product columns in preview mapping', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Generica']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID', 'Warehouse', 'Make', 'Model', 'Engine', 'VIN'],
        ['Aceite legacy', 'Aceite Motor', 'LS-LEG-001', 'MAIN', 'Toyota', 'Yaris', '1NZFE', 'JTDBT933X01109408'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.suggested_mapping.description', 'nombre')
        ->assertJsonPath('data.suggested_mapping.category', 'categoria')
        ->assertJsonPath('data.suggested_mapping.system_id', 'system_id')
        ->assertJsonPath('data.suggested_mapping.warehouse', 'ignore')
        ->assertJsonPath('data.suggested_mapping.make', 'ignore')
        ->assertJsonPath('data.suggested_mapping.model', 'ignore')
        ->assertJsonPath('data.suggested_mapping.engine', 'ignore')
        ->assertJsonPath('data.suggested_mapping.vin', 'ignore');
});

it('validates a full product import without creating products', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID'],
        ['Aceite validado', 'Categoria Fantasma', 'LS-VAL-001'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING)
        ->assertJsonPath('data.mode', ImportRun::MODE_VALIDATE_ONLY);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->mode)->toBe(ImportRun::MODE_VALIDATE_ONLY)
        ->and($importRun->run_type)->toBe(ImportRun::TYPE_VALIDATION);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('queues large product previews instead of validating every row during the request', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 251))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-PREVIEW-{$index}",
            "LS-PREVIEW-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => productImportUploadedSpreadsheet($rows),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.async', true)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING)
        ->assertJsonPath('data.mode', ImportRun::MODE_VALIDATE_ONLY)
        ->assertJsonPath('data.run_type', ImportRun::TYPE_VALIDATION)
        ->assertJsonPath('data.total_rows', 251)
        ->assertJsonPath('data.preview_limited', false);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->run_type)->toBe(ImportRun::TYPE_VALIDATION)
        ->and($importRun->mode)->toBe(ImportRun::MODE_VALIDATE_ONLY)
        ->and($importRun->total_rows)->toBe(251);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('returns every row for small product previews and reports sheet totals', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 25))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-PREVIEW-SMALL-{$index}",
            "LS-PREVIEW-SMALL-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => productImportUploadedSpreadsheet($rows),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.count', 25)
        ->assertJsonPath('data.shown_count', 25)
        ->assertJsonPath('data.total_rows', 25)
        ->assertJsonPath('data.max_rows', 25)
        ->assertJsonPath('data.preview_limited', false)
        ->assertJsonPath('data.rows.0.data.nombre', 'Aceite 10W30 1')
        ->assertJsonPath('data.rows.24.data.nombre', 'Aceite 10W30 25');
});

it('reuses vehicle catalog lookups while validating repeated name-derived vehicles', function () {
    productImportCreateVehicleScope();
    Category::create([
        'name' => 'Filtros',
        'slug' => 'filtros',
    ]);
    productImportEnsureBrands(['Generica']);

    $rows = collect(range(1, 20))
        ->map(fn (int $index): array => [
            '__row' => $index + 1,
            'nombre' => "Filtro aceite TOYOTA YARIS P90 1NZFE {$index}",
            'categoria' => 'Filtros',
            'system_id' => "VH-CACHE-{$index}",
        ])
        ->all();

    DB::enableQueryLog();

    $import = new ProductImport(app(ProductService::class));
    $import->validateRows($rows);

    $vehicleModelQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'vehicle_models'))
        ->count();

    expect($import->errorCount)->toBe(0)
        ->and($vehicleModelQueries)->toBeLessThanOrEqual(3);
});

it('keeps full product validation chunk processing on a streaming row path', function () {
    $source = file_get_contents(app_path('Imports/BaseImport.php'));

    expect($source)->not->toContain('$rows = $this->extractRows($file, $sheetIndex, $sheetName, $mapping);')
        ->and($source)->not->toContain('$rows->chunk($chunkSize)');
});

it('runs validation jobs without creating products and stores row errors', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Generica']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID'],
        ['Aceite valido', 'Aceite Motor', 'LS-VAL-001'],
        ['Aceite invalido', 'Fantasma', 'LS-VAL-002'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'validate.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validate.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->processed_rows)->toBe(2)
        ->and($importRun->success_count)->toBe(1)
        ->and($importRun->error_count)->toBe(1)
        ->and(Product::query()->count())->toBe(0)
        ->and($importRun->errors[0]['error'])->toContain('no existe');
});

it('validates more than 200 product rows in a full validation job', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 501))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-10W30-{$index}",
            "LS-BULK-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $file = productImportUploadedSpreadsheet($rows);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'validate-501-products.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validate-501-products.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->processed_rows)->toBe(501)
        ->and($importRun->success_count)->toBe(501)
        ->and($importRun->error_count)->toBe(0)
        ->and(Product::query()->count())->toBe(0);
});

it('exposes every validated row in full validation run details', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 501))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-10W30-{$index}",
            "LS-DETAIL-{$index}",
            $index === 277 ? 'Marca Fantasma' : 'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $file = productImportUploadedSpreadsheet($rows);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'validate-detail-501-products.xlsx');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validate-detail-501-products.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $response = $this->actingAs($admin)->getJson("/api/v1/products/import/runs/{$importRun->id}");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_COMPLETED)
        ->assertJsonPath('data.processed_rows', 501)
        ->assertJsonPath('data.success_count', 500)
        ->assertJsonPath('data.error_count', 1)
        ->assertJsonPath('data.validation_rows_count', 501)
        ->assertJsonPath('data.validation_rows_limited', false)
        ->assertJsonPath('data.validation_rows.0.row', 2)
        ->assertJsonPath('data.validation_rows.0.is_valid', true)
        ->assertJsonPath('data.validation_rows.0.data.nombre', 'Aceite 10W30 1')
        ->assertJsonPath('data.validation_rows.0.errors', [])
        ->assertJsonPath('data.validation_rows.0.warnings', [])
        ->assertJsonPath('data.validation_rows.276.row', 278)
        ->assertJsonPath('data.validation_rows.276.is_valid', false)
        ->assertJsonPath('data.validation_rows.276.data.brand', 'Marca Fantasma')
        ->assertJsonPath('data.validation_rows.276.warnings', []);

    expect($response->json('data.validation_rows.276.errors.0'))->toContain('no existe')
        ->and(Product::query()->count())->toBe(0);
});

it('processes a pending full validation run immediately when the queue has not started it', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 501))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-PROCESS-NOW-{$index}",
            "LS-PROCESS-NOW-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $file = productImportUploadedSpreadsheet($rows);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'process-now-validation-501.xlsx');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'process-now-validation-501.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/process-now");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_COMPLETED)
        ->assertJsonPath('data.processed_rows', 501)
        ->assertJsonPath('data.success_count', 501)
        ->assertJsonPath('data.error_count', 0)
        ->assertJsonPath('data.validation_rows_count', 501)
        ->assertJsonPath('data.validation_rows_limited', false)
        ->assertJsonPath('data.validation_rows.500.row', 502)
        ->assertJsonPath('data.validation_rows.500.is_valid', true);

    expect(Product::query()->count())->toBe(0);
});

it('processes a pending full import run immediately when the queue has not started it', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor-process-now-import',
    ]);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'],
        ['Aceite 10W30 inmediato', 'OIL-PROCESS-NOW-IMPORT', 'LS-PROCESS-NOW-IMPORT', 'Shell', 'Aceite Motor'],
    ]);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'process-now-import.xlsx');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'process-now-import.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/process-now");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_COMPLETED)
        ->assertJsonPath('data.processed_rows', 1)
        ->assertJsonPath('data.success_count', 1)
        ->assertJsonPath('data.error_count', 0);

    expect(Product::query()->where('sku_base', 'OIL-PROCESS-NOW-IMPORT')->exists())->toBeTrue();
});

it('processes a pending retry-only import run immediately when the queue has not started it', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor-process-now-retry',
    ]);
    productImportEnsureBrands(['Shell']);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_RETRY_FAILED,
        'original_filename' => 'retry-import.xlsx',
        'stored_path' => null,
        'status' => ImportRun::STATUS_PENDING,
        'failed_rows' => [[
            'row' => 2,
            'data' => [
                '__row' => 2,
                'nombre' => 'Aceite retry inmediato',
                'sku' => 'OIL-PROCESS-NOW-RETRY',
                'system_id' => 'LS-PROCESS-NOW-RETRY',
                'brand' => 'Shell',
                'categoria' => 'Aceite Motor',
            ],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/process-now");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_COMPLETED)
        ->assertJsonPath('data.processed_rows', 1)
        ->assertJsonPath('data.success_count', 1)
        ->assertJsonPath('data.error_count', 0);

    expect(Product::query()->where('sku_base', 'OIL-PROCESS-NOW-RETRY')->exists())->toBeTrue();
});

it('rejects immediate processing for non pending, foreign, or missing-source import runs', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);
    $otherManager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    Storage::disk('local')->put('imports/ready.xlsx', 'placeholder');

    $completedValidationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'completed-validation.xlsx',
        'stored_path' => 'imports/ready.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $missingSourceValidationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'missing-validation.xlsx',
        'stored_path' => 'imports/missing.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $foreignValidationRun = ImportRun::query()->create([
        'user_id' => $otherManager->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'foreign-validation.xlsx',
        'stored_path' => 'imports/ready.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$completedValidationRun->id}/process-now")
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$missingSourceValidationRun->id}/process-now")
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->actingAs($manager)
        ->postJson("/api/v1/products/import/runs/{$foreignValidationRun->id}/process-now")
        ->assertNotFound();
});

it('reports missing product brands in full validation run summaries', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    Brand::create([
        'name' => 'Shibumi Parts',
        'status' => 'active',
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID', 'Brand'],
        ['Aceite con marca pendiente', 'Aceite Motor', 'LS-MISS-BRAND', 'Shibumi'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'missing-brand-validation.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'missing-brand-validation.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_count)->toBe(1)
        ->and($importRun->summary['missing_references']['brands'])->toBe(['Shibumi'])
        ->and($importRun->summary['suggestions']['brands'][0]['suggestion'])->toBe('Shibumi Parts')
        ->and(Brand::query()->where('name', 'Shibumi')->exists())->toBeFalse();
});

it('supports create only mode without updating existing variants', function () {
    Storage::fake('local');
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $brand = Brand::create([
        'name' => 'Shell',
        'status' => 'active',
    ]);

    $product = Product::create([
        'name' => '10W30',
        'sku_base' => 'OIL-001',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'OIL-001',
        'system_id' => 'LS-001',
        'status' => 'active',
    ]);

    $variant->pricing()->create([
        'product_id' => $product->id,
        'base_price' => 5,
        'cost' => 4,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price'],
        ['10W30', 'OIL-001', 'LS-001', 'Shell', 'Aceite Motor', '10', '12'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'create-only.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_ONLY,
        'original_filename' => 'create-only.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();
    $variant->pricing()->firstOrFail()->refresh();

    expect($importRun->success_count)->toBe(0)
        ->and($importRun->error_count)->toBe(1)
        ->and((float) $variant->pricing()->firstOrFail()->base_price)->toBe(5.0);
});

it('supports stock and price update mode without creating missing variants', function () {
    Storage::fake('local');
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $brand = Brand::create([
        'name' => 'Shell',
        'status' => 'active',
    ]);

    $warehouse = Warehouse::create([
        'code' => 'MAIN',
        'name' => 'Principal',
    ]);

    $product = Product::create([
        'name' => '10W30',
        'sku_base' => 'OIL-001',
        'category_id' => $category->id,
        'status' => 'active',
    ]);

    ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'OIL-001',
        'system_id' => 'LS-001',
        'status' => 'active',
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Quantity', 'Warehouse'],
        ['10W30', 'OIL-001', 'LS-001', 'Shell', 'Aceite Motor', '10', '12', '7', $warehouse->code],
        ['10W40', 'OIL-002', 'LS-404', 'Shell', 'Aceite Motor', '10', '12', '3', $warehouse->code],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'update-only.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_UPDATE_STOCK_PRICE,
        'original_filename' => 'update-only.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->success_count)->toBe(1)
        ->and($importRun->error_count)->toBe(1)
        ->and(ProductVariant::query()->where('system_id', 'LS-404')->exists())->toBeFalse()
        ->and((float) ProductVariant::query()->where('system_id', 'LS-001')->firstOrFail()->pricing()->firstOrFail()->base_price)->toBe(12.0);
});

it('exports import run errors as an excel file', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'errors.xlsx',
        'stored_path' => 'imports/errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'errors' => [[
            'row' => 7,
            'label' => 'Aceite',
            'column' => 'categoria',
            'value' => 'Fantasma',
            'error' => 'La categoria no existe.',
            'suggestion' => 'Aceite Motor',
        ]],
        'results' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->get("/api/v1/products/import/runs/{$importRun->id}/errors.xlsx");

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'errors-export-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Fila')
        ->and($sheet->getCell('G2')->getValue())->toBe('La categoria no existe.');
});

it('creates a retry import run linked to the original failed rows', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $originalRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'original.xlsx',
        'stored_path' => 'imports/original.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'mapping' => ['description' => 'nombre'],
        'errors' => [[
            'row' => 2,
            'label' => 'Aceite',
            'data' => ['nombre' => 'Aceite', 'categoria' => 'Fantasma'],
            'error' => 'La categoria no existe.',
        ]],
        'results' => [],
        'summary' => [],
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$originalRun->id}/retry-failed");

    $response->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING);

    $retryRun = ImportRun::query()->where('parent_import_run_id', $originalRun->id)->firstOrFail();

    expect($retryRun->mode)->toBe(ImportRun::MODE_RETRY_FAILED)
        ->and($retryRun->stored_path)->toBeNull()
        ->and($retryRun->failed_rows)->toHaveCount(1);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('rejects retry when the import run has no failed row data', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'original.xlsx',
        'stored_path' => 'imports/original.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'errors' => [['row' => 2, 'label' => 'Aceite', 'error' => 'La categoria no existe.']],
        'results' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$importRun->id}/retry-failed")
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    Queue::assertNothingPushed();
});

it('rejects retry when another retry is already pending for the same run', function () {
    Queue::fake();

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $originalRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'original.xlsx',
        'stored_path' => 'imports/original.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'failed_rows' => [['row' => 2, 'data' => ['nombre' => 'Aceite', 'categoria' => 'Fantasma']]],
        'errors' => [],
        'results' => [],
        'summary' => [],
    ]);

    ImportRun::query()->create([
        'entity' => 'products',
        'parent_import_run_id' => $originalRun->id,
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_RETRY_FAILED,
        'status' => ImportRun::STATUS_PENDING,
        'failed_rows' => $originalRun->failed_rows,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$originalRun->id}/retry-failed")
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    Queue::assertNothingPushed();
});

it('processes retry failed rows without requiring the original source file', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_RETRY_FAILED,
        'original_filename' => 'original.xlsx',
        'stored_path' => null,
        'status' => ImportRun::STATUS_PENDING,
        'failed_rows' => [[
            'row' => 2,
            'data' => [
                '__row' => 2,
                'nombre' => '10W30 retry',
                'sku' => 'OIL-RETRY',
                'system_id' => 'LS-RETRY',
                'brand' => 'Shell',
                'categoria' => 'Aceite Motor',
            ],
        ]],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->success_count)->toBe(1)
        ->and(Product::query()->where('sku_base', 'OIL-RETRY')->exists())->toBeTrue();
});

it('ignores a queued product import job when the import run no longer exists', function () {
    (new ProcessProductImportRun(999999))->handle(app(ProductService::class));

    expect(ImportRun::query()->whereKey(999999)->exists())->toBeFalse();
});

it('marks an import run as cancel requested and the job stops before processing chunks', function () {
    Storage::fake('local');

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID'],
        ['Aceite', 'Fantasma', 'LS-CANCEL'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'cancel.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'cancel.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_CANCEL_REQUESTED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($importRun->processed_rows)->toBe(0)
        ->and($importRun->summary['source_file_deleted'] ?? null)->toBeFalse();

    Storage::disk('local')->assertExists($storedPath);
});

it('marks a pending import run as failed when the source file is missing', function () {
    Storage::fake('local');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'missing.xlsx',
        'stored_path' => 'imports/missing.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($importRun->errors[0]['error'])->toContain('No se encontro el archivo almacenado')
        ->and(Product::query()->count())->toBe(0);
});

it('keeps the source file when a product import run fails unexpectedly', function () {
    Storage::fake('local');

    Storage::disk('local')->put('imports/broken.xlsx', 'not a spreadsheet');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'broken.xlsx',
        'stored_path' => 'imports/broken.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($importRun->finished_at)->not->toBeNull();

    Storage::disk('local')->assertExists('imports/broken.xlsx');
});

it('records created products and variants for an import run', function () {
    Storage::fake('local');
    Setting::set('store_tax_rate', 0, 'number', 'store');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price'],
        ['10W30', 'OIL-001', 'LS-AUDIT', 'Shell', 'Aceite Motor', '5', '7'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'audit.xlsx');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'audit.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect(ImportRunRecord::query()->where('import_run_id', $importRun->id)->where('model_type', Product::class)->exists())->toBeTrue()
        ->and(ImportRunRecord::query()->where('import_run_id', $importRun->id)->where('model_type', ProductVariant::class)->exists())->toBeTrue();
});

it('creates and lists product import profiles', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/profiles', [
        'name' => 'Lightspeed productos',
        'mapping' => ['description' => 'nombre', 'category' => 'categoria'],
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Lightspeed productos');

    $this->actingAs($admin)->getJson('/api/v1/products/import/profiles')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Lightspeed productos');

    expect(ImportProfile::query()->where('name', 'Lightspeed productos')->exists())->toBeTrue();
});

it('lists import profiles according to role visibility', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $adminProfile = ImportProfile::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'name' => 'Perfil admin',
        'mapping' => ['description' => 'nombre'],
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $managerProfile = ImportProfile::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'name' => 'Perfil manager',
        'mapping' => ['description' => 'nombre'],
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $adminResponse = $this->actingAs($admin)->getJson('/api/v1/products/import/profiles');

    $adminResponse->assertOk();
    expect(collect($adminResponse->json('data'))->pluck('id')->all())
        ->toContain($adminProfile->id, $managerProfile->id);

    $managerResponse = $this->actingAs($manager)->getJson('/api/v1/products/import/profiles');

    $managerResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $managerProfile->id);
});

it('stores import profiles per user without overwriting another users profile with the same name', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/profiles', [
        'name' => 'Lightspeed productos',
        'mapping' => ['description' => 'nombre'],
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/v1/products/import/profiles', [
        'name' => 'Lightspeed productos',
        'mapping' => ['category' => 'categoria'],
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
    ])->assertCreated();

    expect(ImportProfile::query()->where('name', 'Lightspeed productos')->count())->toBe(2)
        ->and(ImportProfile::query()->where('user_id', $admin->id)->firstOrFail()->mapping)->toBe(['description' => 'nombre'])
        ->and(ImportProfile::query()->where('user_id', $manager->id)->firstOrFail()->mapping)->toBe(['category' => 'categoria']);
});

it('enforces profile visibility when starting product import runs', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $adminProfile = ImportProfile::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'name' => 'Perfil admin',
        'mapping' => ['description' => 'nombre'],
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $managerProfile = ImportProfile::query()->create([
        'user_id' => $manager->id,
        'entity' => 'products',
        'name' => 'Perfil manager',
        'mapping' => ['description' => 'nombre'],
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $managerResponse = $this->actingAs($manager)->postJson('/api/v1/products/import', [
        'file' => UploadedFile::fake()->create('manager.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        'profile_id' => $adminProfile->id,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
    ]);

    $managerResponse->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(ImportRun::query()->count())->toBe(0);

    $adminResponse = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => UploadedFile::fake()->create('admin.xlsx', 12, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        'profile_id' => $managerProfile->id,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
    ]);

    $adminResponse->assertOk()
        ->assertJsonPath('success', true);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->user_id)->toBe($admin->id)
        ->and($importRun->import_profile_id)->toBe($managerProfile->id);

    Queue::assertPushed(ProcessProductImportRun::class);
});
