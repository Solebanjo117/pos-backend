<?php

use App\Http\Requests\PreviewImportRequest;
use App\Imports\ProductImport;
use App\Jobs\ProcessProductImportRun;
use App\Jobs\RevalidateProductImportRunRows;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportProfile;
use App\Models\ImportRun;
use App\Models\ImportRunRecord;
use App\Models\ImportRunRow;
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
use Illuminate\Support\Facades\RateLimiter;
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

function productImportUploadedCsv(array $rows, string $filename = 'item_listings_local_matches.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'product-import-csv-').'.csv';
    $handle = fopen($path, 'wb');

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return new UploadedFile(
        $path,
        $filename,
        'text/csv',
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

it('classifies product preview rows as create update unchanged or conflict with change metadata', function () {
    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Aceite existente',
            'sku' => 'OIL-EXIST',
            'system_id' => 'LS-EXIST',
            'brand' => 'Shell',
            'categoria' => 'Aceite Motor',
            'costo' => '5.00',
            'precio' => '7.00',
            'descripcion' => 'Descripcion vigente',
        ],
        [
            '__row' => 3,
            'nombre' => 'Aceite sin cambios',
            'sku' => 'OIL-SAME',
            'system_id' => 'LS-SAME',
            'brand' => 'Shell',
            'categoria' => 'Aceite Motor',
            'costo' => '6.00',
            'precio' => '9.00',
            'descripcion' => 'Descripcion estable',
        ],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Detalle'],
        ['Aceite nuevo', 'OIL-NEW', 'LS-NEW', 'Shell', 'Aceite Motor', '3.00', '4.00', 'Nuevo'],
        ['Aceite existente', 'OIL-EXIST', 'LS-EXIST', 'Shell', 'Aceite Motor', '4.480000000', '$8.00', ''],
        ['Aceite sin cambios', 'OIL-SAME', 'LS-SAME', 'Shell', 'Aceite Motor', '6.00', '9.00', ''],
        ['Aceite duplicado', 'OIL-DUP', 'LS-DUP', 'Shell', 'Aceite Motor', '1.00', '2.00', 'Duplicado'],
        ['Aceite duplicado', 'OIL-DUP', 'LS-DUP', 'Shell', 'Aceite Motor', '1.00', '2.00', 'Duplicado'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.action_type', 'create')
        ->assertJsonPath('data.rows.1.action_type', 'update')
        ->assertJsonPath('data.rows.2.action_type', 'unchanged')
        ->assertJsonPath('data.rows.3.action_type', 'conflict')
        ->assertJsonPath('data.rows.4.action_type', 'conflict')
        ->assertJsonPath('data.summary.action_counts.create', 1)
        ->assertJsonPath('data.summary.action_counts.update', 1)
        ->assertJsonPath('data.summary.action_counts.unchanged', 1)
        ->assertJsonPath('data.summary.action_counts.conflict', 2)
        ->assertJsonPath('data.summary.unchanged_count', 1);

    $updateChanges = collect($response->json('data.rows.1.change_preview'));
    expect($updateChanges->pluck('field')->all())->toContain('precio')
        ->toContain('costo')
        ->not->toContain('descripcion');

    expect($updateChanges->firstWhere('field', 'costo'))
        ->toMatchArray([
            'current' => '5.00',
            'imported' => '4.48',
        ])
        ->and($updateChanges->firstWhere('field', 'precio'))
        ->toMatchArray([
            'current' => '7.00',
            'imported' => '8.00',
        ]);
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

it('imports without compatibility when vehicle text in product name does not resolve to a complete existing scope', function () {
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

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-VEHICLE-NAME-MISSING')->firstOrFail();

    expect($product->vehicleCompatibility()->count())->toBe(0);
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
        ->assertJsonPath('data.rows.0.is_valid', true)
        ->assertJsonPath('data.rows.0.data.nombre', 'Terminal CREM TOYOTA YARIS P90 1NZFE')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.make', 'TOYOTA')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.model', 'YARIS')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.chassis', 'P90')
        ->assertJsonPath('data.rows.0.data.__vehicle_creation_candidate.engine', '1NZFE')
        ->assertJsonPath('data.summary.missing_vehicle_candidates.0.make', 'TOYOTA')
        ->assertJsonPath('data.summary.missing_vehicle_candidates.0.model', 'YARIS');

    expect($response->json('data.rows.0.errors'))->toBe([]);
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
        ->assertJsonPath('data.rows.0.is_valid', true)
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

it('imports without compatibility when product name contains more than one complete vehicle candidate', function () {
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

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::query()->where('sku_base', 'TIE-VEHICLE-NAME-AMBIGUOUS')->firstOrFail();

    expect($product->vehicleCompatibility()->count())->toBe(0);
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

it('ignores stale sheet names when processing csv product imports', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedCsv([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Cost', 'Price', 'Quantity'],
        ['10W30 cuarto mineral', 'OIL-CSV', 'LS-CSV', 'Shell', 'Aceite Motor', '5.35', '6.42', '10'],
    ]);

    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'item_listings_local_matches.csv');

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'item_listings_local_matches.csv',
        'stored_path' => $storedPath,
        'sheet_index' => 0,
        'sheet_name' => 'Worksheet',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    expect($importRun->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->success_count)->toBe(1)
        ->and(ProductVariant::query()->where('system_id', 'LS-CSV')->exists())->toBeTrue()
        ->and($importRun->errors)->toBe([]);
});

it('allows import files larger than 25mb and rejects files larger than 100mb', function () {
    $rules = (new PreviewImportRequest)->rules();

    $validLargePath = tempnam(sys_get_temp_dir(), 'product-import-large-').'.csv';
    file_put_contents($validLargePath, "Description,Category,System ID\nAceite grande,Aceites,LS-LARGE\n".str_repeat(' ', 26 * 1024 * 1024));
    $validLargeFile = new UploadedFile($validLargePath, 'lightspeed.csv', 'text/csv', null, true);

    $tooLargeFile = UploadedFile::fake()->create(
        'lightspeed.csv',
        102401,
        'text/csv'
    );

    expect(Validator::make(['file' => $validLargeFile], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['file' => $tooLargeFile], $rules)->passes())->toBeFalse();
});

it('rejects product import files when the detected content does not match the format', function () {
    $rules = (new PreviewImportRequest)->rules();

    $validCsv = productImportUploadedCsv([
        ['Description', 'Category', 'System ID'],
        ['Aceite valido', 'Aceites', 'LS-MIME-OK'],
    ]);

    $fakeSpreadsheet = UploadedFile::fake()->create(
        'lightspeed.xlsx',
        12,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    $wrongMime = UploadedFile::fake()->create(
        'lightspeed.xlsx',
        12,
        'text/plain'
    );

    expect(Validator::make(['file' => $validCsv], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['file' => $fakeSpreadsheet], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['file' => $wrongMime], $rules)->passes())->toBeFalse();
});

it('rejects direct full product imports without a validated run or advanced confirmation', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedCsv([
        ['Description', 'Category', 'System ID'],
        ['Aceite directo', 'Aceites', 'LS-DIRECT-BLOCKED'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => $file,
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Primero valide el archivo completo antes de importarlo.');

    expect(ImportRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('starts a queued full product import run from a completed validation run', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'System ID'],
        ['Aceite validado', 'Aceite Motor', 'LS-VALIDATED-IMPORT'],
    ]);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'validated-import.xlsx');

    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validated-import.xlsx',
        'stored_path' => $storedPath,
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'mapping' => ['Description' => 'nombre', 'Category' => 'categoria'],
        'status' => ImportRun::STATUS_COMPLETED,
        'processed_rows' => 1,
        'success_count' => 1,
        'error_count' => 0,
        'results' => [],
        'errors' => [],
        'summary' => ['validation_rows_count' => 1],
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING)
        ->assertJsonStructure(['data' => ['import_run_id']]);

    $importRun = ImportRun::query()
        ->where('run_type', ImportRun::TYPE_IMPORT)
        ->firstOrFail();

    expect($importRun->entity)->toBe('products')
        ->and($importRun->parent_import_run_id)->toBe($validationRun->id)
        ->and($importRun->stored_path)->not->toBe($storedPath)
        ->and($importRun->original_filename)->toBe('validated-import.xlsx')
        ->and($importRun->sheet_index)->toBe(1)
        ->and($importRun->sheet_name)->toBe('SAMPLE UPLOAD LIGHTSPEED')
        ->and($importRun->mapping)->toBe(['Description' => 'nombre', 'Category' => 'categoria'])
        ->and($importRun->status)->toBe(ImportRun::STATUS_PENDING)
        ->and($importRun->summary['source_validation_error_count'])->toBe(0)
        ->and($importRun->summary['source_validation_conflict_count'])->toBe(0);

    Storage::disk('local')->assertExists($validationRun->stored_path);
    Storage::disk('local')->assertExists($importRun->stored_path);
    Queue::assertPushed(ProcessProductImportRun::class);
});

it('persists selective validation row edits and marks the validation run dirty', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'original_filename' => 'editable.xlsx',
        'stored_path' => 'imports/editable.xlsx', 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 0, 'validated_rows_revision' => 0, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    $row = ImportRunRow::query()->create([
        'import_run_id' => $importRun->id, 'row_number' => 2, 'status' => 'error',
        'action_type' => 'invalid', 'label' => 'Aceite anterior',
        'data' => ['nombre' => 'Aceite anterior', 'categoria' => 'Fantasma', 'brand' => 'Shell'],
        'errors' => ['La categoria no existe.'], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $response = $this->actingAs($admin)->patchJson("/api/v1/products/import/runs/{$importRun->id}/rows", [
        'rows_revision' => 0,
        'rows' => [['id' => $row->id, 'updated_at' => $row->updated_at?->toISOString(), 'data' => ['nombre' => 'Aceite corregido', 'sku' => '', 'categoria' => 'Aceites', 'brand' => 'Shell']]],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows_revision', 1)
        ->assertJsonPath('data.rows_dirty', true)
        ->assertJsonPath('data.full_revalidation_status', 'required')
        ->assertJsonPath('data.rows.0.data.nombre', 'Aceite corregido')
        ->assertJsonPath('data.rows.0.data.sku', '')
        ->assertJsonPath('data.rows.0.is_valid', true);

    $importRun->refresh();
    $row->refresh();
    expect($importRun->summary['rows_revision'])->toBe(1)
        ->and($importRun->summary['groups'][0]['product_key'])->toBe('aceites|aceite corregido')
        ->and($row->data['sku'])->toBe('')
        ->and($row->is_edited)->toBeTrue()
        ->and($row->validated_at)->not->toBeNull();
});

it('rejects stale selective validation row edits', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['rows_revision' => 2, 'validation_rows_count' => 1],
    ]);
    $row = ImportRunRow::query()->create(['import_run_id' => $importRun->id, 'row_number' => 2, 'status' => 'valid', 'data' => ['nombre' => 'Aceite']]);

    $this->actingAs($admin)->patchJson("/api/v1/products/import/runs/{$importRun->id}/rows", [
        'rows_revision' => 1,
        'rows' => [['id' => $row->id, 'updated_at' => $row->updated_at?->toISOString(), 'data' => ['nombre' => 'Aceite editado']]],
    ])->assertStatus(409);
});

it('queues full persisted row revalidation and blocks importing dirty validation runs', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Storage::disk('local')->put('imports/dirty.xlsx', 'source');
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'original_filename' => 'dirty.xlsx',
        'stored_path' => 'imports/dirty.xlsx', 'status' => ImportRun::STATUS_COMPLETED, 'error_count' => 0,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 1, 'validated_rows_revision' => 0, 'rows_dirty' => true, 'full_revalidation_status' => 'required'],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $importRun->id,
        'row_number' => 2,
        'status' => 'valid',
        'data' => ['nombre' => 'Aceite', 'categoria' => 'Aceites'],
        'errors' => [],
        'warnings' => [],
        'conflicts' => [],
        'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', ['validated_import_run_id' => $importRun->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'La validacion seleccionada tiene cambios pendientes de revalidacion completa.');

    $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/revalidate")
        ->assertOk()
        ->assertJsonPath('data.full_revalidation_status', 'pending');

    $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/revalidate")
        ->assertOk()
        ->assertJsonPath('data.full_revalidation_status', 'pending');

    Queue::assertPushed(RevalidateProductImportRunRows::class, 1);
});

it('creates an immutable persisted row snapshot when importing a validated run', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Storage::disk('local')->put('imports/validated-snapshot.xlsx', 'source');
    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'original_filename' => 'validated-snapshot.xlsx',
        'stored_path' => 'imports/validated-snapshot.xlsx', 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 3, 'validated_rows_revision' => 3, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    $sourceRow = ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 2, 'status' => 'valid',
        'action_type' => 'create', 'label' => 'Aceite corregido',
        'data' => ['nombre' => 'Aceite corregido', 'categoria' => 'Aceites'],
        'errors' => [], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', ['validated_import_run_id' => $validationRun->id])->assertOk();
    $importRun = ImportRun::query()->where('parent_import_run_id', $validationRun->id)->firstOrFail();
    $snapshot = $importRun->rows()->firstOrFail();
    $sourceRow->update(['data' => ['nombre' => 'Cambio posterior']]);

    expect($snapshot->data['nombre'])->toBe('Aceite corregido')
        ->and($importRun->summary['source_rows_revision'])->toBe(3)
        ->and($importRun->summary['use_persisted_rows_snapshot'])->toBeTrue();
});

it('copies persisted row source data when importing a validated run snapshot', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Storage::disk('local')->put('imports/source-data-snapshot.xlsx', 'source');
    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'original_filename' => 'source-data-snapshot.xlsx',
        'stored_path' => 'imports/source-data-snapshot.xlsx', 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 0, 'validated_rows_revision' => 0, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 2, 'status' => 'valid',
        'action_type' => 'create', 'label' => 'Aceite fuente',
        'data' => ['nombre' => 'Aceite fuente', 'categoria' => 'Aceites', 'costo' => '1.00'],
        'source_data' => ['description' => 'Aceite fuente', 'category' => 'Aceites', 'default_cost' => '1.00'],
        'errors' => [], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', ['validated_import_run_id' => $validationRun->id])->assertOk();

    $snapshot = ImportRun::query()
        ->where('parent_import_run_id', $validationRun->id)
        ->firstOrFail()
        ->rows()
        ->firstOrFail();

    expect($snapshot->source_data)->toBe([
        'description' => 'Aceite fuente',
        'category' => 'Aceites',
        'default_cost' => '1.00',
    ]);
});

it('revalidates every persisted row and clears the dirty validation state', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 4, 'validated_rows_revision' => 3, 'rows_dirty' => true, 'full_revalidation_status' => 'pending'],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $importRun->id, 'row_number' => 2, 'status' => 'error',
        'data' => ['nombre' => 'Aceite corregido', 'categoria' => 'Aceites', 'brand' => 'Shell'],
        'errors' => ['Error anterior'], 'warnings' => [], 'conflicts' => [], 'change_preview' => [], 'is_edited' => true,
    ]);

    (new RevalidateProductImportRunRows($importRun->id))->handle(app(ProductService::class), app(\App\Services\ProductImportRunRowService::class));
    $importRun->refresh();
    $row = $importRun->rows()->firstOrFail();

    expect($importRun->summary['rows_dirty'])->toBeFalse()
        ->and($importRun->summary['full_revalidation_status'])->toBe('completed')
        ->and($importRun->summary['validated_rows_revision'])->toBe(4)
        ->and($importRun->summary['groups'][0]['product_key'])->toBe('aceites|aceite corregido')
        ->and($importRun->error_count)->toBe(0)
        ->and($row->status)->toBe('valid')
        ->and($row->is_edited)->toBeTrue();
});

it('persists source data for validated rows and remaps operational fields in the same run', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);

    $file = productImportUploadedCsv([
        ['Description', 'Category', 'Brand', 'Default Cost', 'Price', 'Qty.', 'Details'],
        ['Aceite operativo', 'Aceites', 'Shell', '5.25', '7.50', '4', 'Descripcion rapida'],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => $file,
        'mapping' => [
            'description' => 'nombre',
            'category' => 'categoria',
            'brand' => 'brand',
            'default_cost' => 'ignore',
            'price' => 'ignore',
            'qty' => 'ignore',
            'details' => 'ignore',
        ],
    ])->assertOk();

    $importRun = ImportRun::query()->firstOrFail();
    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class), app(\App\Services\ProductImportRunRowService::class));
    $importRun->refresh();
    $row = $importRun->rows()->firstOrFail();

    expect($row->source_data)->toMatchArray([
        'default_cost' => '5.25',
        'price' => '7.50',
        'qty' => '4',
        'details' => 'Descripcion rapida',
    ]);

    $response = $this->actingAs($admin)->patchJson("/api/v1/products/import/runs/{$importRun->id}/mapping", [
        'rows_revision' => 0,
        'mapping' => [
            'description' => 'nombre',
            'category' => 'categoria',
            'brand' => 'brand',
            'default_cost' => 'costo',
            'price' => 'precio',
            'qty' => 'cantidad',
            'details' => 'descripcion',
        ],
        'visible_row_ids' => [$row->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.import_run_id', $importRun->id)
        ->assertJsonPath('data.rows_revision', 1)
        ->assertJsonPath('data.rows_dirty', true)
        ->assertJsonPath('data.full_revalidation_status', 'pending')
        ->assertJsonPath('data.rows.0.data.costo', '5.25')
        ->assertJsonPath('data.rows.0.data.precio', '7.50')
        ->assertJsonPath('data.rows.0.data.cantidad', '4')
        ->assertJsonPath('data.rows.0.data.descripcion', 'Descripcion rapida');

    $importRun->refresh();
    expect(ImportRun::query()->count())->toBe(1)
        ->and($importRun->mapping['default_cost'])->toBe('costo')
        ->and($importRun->summary['rows_revision'])->toBe(1)
        ->and($importRun->summary['pending_operational_mapping'])->toBe($importRun->mapping);

    Queue::assertPushed(RevalidateProductImportRunRows::class, 1);

    (new RevalidateProductImportRunRows($importRun->id))->handle(app(ProductService::class), app(\App\Services\ProductImportRunRowService::class));

    $importRun->refresh();
    $row->refresh();
    expect($importRun->summary['full_revalidation_status'])->toBe('completed')
        ->and($importRun->summary['rows_dirty'])->toBeFalse()
        ->and($importRun->summary['validated_rows_revision'])->toBe(1)
        ->and($row->data['costo'])->toBe('5.25')
        ->and($row->data['precio'])->toBe('7.50')
        ->and($row->data['cantidad'])->toBe('4')
        ->and($row->data['descripcion'])->toBe('Descripcion rapida');
});

it('requires full restart when persisted mapping changes structural fields or lacks source data', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'mapping' => ['description' => 'nombre', 'category' => 'categoria'],
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 0, 'validated_rows_revision' => 0, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    $row = ImportRunRow::query()->create([
        'import_run_id' => $importRun->id,
        'row_number' => 2,
        'status' => 'valid',
        'data' => ['nombre' => 'Aceite', 'categoria' => 'Aceites'],
        'errors' => [],
        'warnings' => [],
        'conflicts' => [],
        'change_preview' => [],
    ]);

    $this->actingAs($admin)->patchJson("/api/v1/products/import/runs/{$importRun->id}/mapping", [
        'rows_revision' => 0,
        'mapping' => ['item_name' => 'nombre', 'category' => 'categoria'],
        'visible_row_ids' => [$row->id],
    ])->assertStatus(409)
        ->assertJsonPath('data.requires_full_restart', true);

    $importRun->update(['mapping' => ['description' => 'nombre', 'category' => 'categoria']]);

    $this->actingAs($admin)->patchJson("/api/v1/products/import/runs/{$importRun->id}/mapping", [
        'rows_revision' => 0,
        'mapping' => ['description' => 'nombre', 'category' => 'categoria', 'default_cost' => 'costo'],
        'visible_row_ids' => [$row->id],
    ])->assertStatus(409)
        ->assertJsonPath('data.requires_full_restart', true);
});

it('revalidates persisted rows in chunks and detects duplicates across distant chunks', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'summary' => ['validation_rows_count' => 5000, 'rows_revision' => 2, 'validated_rows_revision' => 1, 'rows_dirty' => true, 'full_revalidation_status' => 'pending'],
    ]);

    foreach (array_chunk(range(1, 5000), 500) as $chunk) {
        ImportRunRow::query()->insert(collect($chunk)->map(function (int $index) use ($importRun): array {
            $isDuplicate = in_array($index, [1, 4998], true);

            return [
                'import_run_id' => $importRun->id,
                'row_number' => $index + 1,
                'status' => 'valid',
                'action_type' => 'create',
                'label' => "Aceite {$index}",
                'data' => json_encode([
                    'nombre' => $isDuplicate ? 'Aceite duplicado distante' : "Aceite {$index}",
                    'sku' => $isDuplicate ? 'DUP-DISTANT' : "OIL-{$index}",
                    'system_id' => $isDuplicate ? null : "SYS-{$index}",
                    'brand' => 'Shell',
                    'categoria' => 'Aceites',
                ], JSON_UNESCAPED_UNICODE),
                'errors' => json_encode([], JSON_UNESCAPED_UNICODE),
                'warnings' => json_encode([], JSON_UNESCAPED_UNICODE),
                'conflicts' => json_encode([], JSON_UNESCAPED_UNICODE),
                'change_preview' => json_encode([], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all());
    }

    (new RevalidateProductImportRunRows($importRun->id))->handle(app(ProductService::class), app(\App\Services\ProductImportRunRowService::class));
    $importRun->refresh();

    $source = file_get_contents(app_path('Jobs/RevalidateProductImportRunRows.php'));
    expect($source)->not->toContain('executableRows(')
        ->and($importRun->processed_rows)->toBe(5000)
        ->and($importRun->total_rows)->toBe(5000)
        ->and($importRun->summary['rows_dirty'])->toBeFalse()
        ->and($importRun->summary['full_revalidation_status'])->toBe('completed')
        ->and($importRun->summary['retryable_failed_rows_count'])->toBe(2)
        ->and($importRun->rows()->where('status', 'error')->count())->toBe(2)
        ->and($importRun->rows()->where('row_number', 2)->firstOrFail()->conflicts[0]['type'])->toBe('duplicate_in_file')
        ->and($importRun->rows()->where('row_number', 4999)->firstOrFail()->conflicts[0]['type'])->toBe('duplicate_in_file');
});

it('revalidates persisted rows after creating missing references in batch', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    productImportEnsureBrands(['Shell']);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'error_count' => 1,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 1, 'validated_rows_revision' => 0, 'rows_dirty' => true, 'full_revalidation_status' => 'required', 'missing_categories' => ['Aceites']],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $importRun->id, 'row_number' => 2, 'status' => 'error',
        'action_type' => 'invalid', 'label' => 'Aceite nuevo',
        'data' => ['nombre' => 'Aceite nuevo', 'categoria' => 'Aceites', 'brand' => 'Shell'],
        'errors' => ['La categoria no existe.'], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/references/batch', [
        'categories' => ['Aceites'],
        'brands' => [],
    ])->assertCreated()
        ->assertJsonPath('data.summary.created_count', 1);

    $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/revalidate")
        ->assertOk()
        ->assertJsonPath('data.full_revalidation_status', 'pending');

    Queue::assertPushed(RevalidateProductImportRunRows::class, 1);
    (new RevalidateProductImportRunRows($importRun->id))->handle(app(ProductService::class), app(\App\Services\ProductImportRunRowService::class));
    $importRun->refresh();

    expect($importRun->error_count)->toBe(0)
        ->and($importRun->summary['rows_dirty'])->toBeFalse()
        ->and($importRun->summary['missing_categories'] ?? [])->toBe([])
        ->and($importRun->rows()->firstOrFail()->status)->toBe('valid');
});

it('imports corrected persisted snapshot rows instead of rereading the original file', function () {
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);
    Storage::disk('local')->put('imports/snapshot-source.xlsx', 'not a spreadsheet');
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE, 'status' => ImportRun::STATUS_PENDING,
        'original_filename' => 'snapshot-source.xlsx', 'stored_path' => 'imports/snapshot-source.xlsx',
        'summary' => ['use_persisted_rows_snapshot' => true],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $importRun->id, 'row_number' => 2, 'status' => 'valid', 'action_type' => 'create',
        'data' => ['nombre' => 'Aceite desde snapshot', 'categoria' => 'Aceites', 'brand' => 'Shell'],
        'errors' => [], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));
    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->success_count)->toBe(1)
        ->and(Product::query()->where('name', 'Aceite desde snapshot')->exists())->toBeTrue();
});

it('imports only valid persisted snapshot rows from a validation run with errors', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Category::create(['name' => 'Aceites', 'slug' => 'aceites']);
    productImportEnsureBrands(['Shell']);
    Storage::disk('local')->put('imports/partial-validation.xlsx', 'source');
    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'original_filename' => 'partial-validation.xlsx', 'stored_path' => 'imports/partial-validation.xlsx',
        'processed_rows' => 2, 'success_count' => 1, 'error_count' => 1,
        'summary' => ['validation_rows_count' => 2, 'rows_revision' => 0, 'validated_rows_revision' => 0, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 2, 'status' => 'valid', 'action_type' => 'create',
        'data' => ['nombre' => 'Aceite valido', 'categoria' => 'Aceites', 'brand' => 'Shell'],
        'errors' => [], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 3, 'status' => 'error', 'action_type' => 'invalid',
        'data' => ['nombre' => 'Aceite excluido', 'categoria' => 'Fantasma', 'brand' => 'Shell'],
        'errors' => ["La categoria 'Fantasma' no existe."], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
    ])->assertOk();

    $importRun = ImportRun::query()->where('parent_import_run_id', $validationRun->id)->firstOrFail();
    expect($importRun->rows()->count())->toBe(2)
        ->and($importRun->summary['source_validation_error_count'])->toBe(1);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));
    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->success_count)->toBe(1)
        ->and($importRun->error_count)->toBe(0)
        ->and(Product::query()->where('name', 'Aceite valido')->exists())->toBeTrue()
        ->and(Product::query()->where('name', 'Aceite excluido')->exists())->toBeFalse()
        ->and($importRun->rows()->where('row_number', 3)->firstOrFail()->status)->toBe('error');
});

it('rejects partial validated imports without valid persisted rows', function () {
    Queue::fake();
    Storage::fake('local');
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    Storage::disk('local')->put('imports/invalid-validation.xlsx', 'source');
    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id, 'entity' => 'products', 'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY, 'status' => ImportRun::STATUS_COMPLETED,
        'original_filename' => 'invalid-validation.xlsx', 'stored_path' => 'imports/invalid-validation.xlsx',
        'processed_rows' => 1, 'success_count' => 0, 'error_count' => 1,
        'summary' => ['validation_rows_count' => 1, 'rows_revision' => 0, 'validated_rows_revision' => 0, 'rows_dirty' => false, 'full_revalidation_status' => 'completed'],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 2, 'status' => 'error', 'action_type' => 'invalid',
        'data' => ['nombre' => 'Aceite excluido', 'categoria' => 'Fantasma'],
        'errors' => ["La categoria 'Fantasma' no existe."], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'La validacion seleccionada no tiene filas validas para importar.');

    expect(ImportRun::query()->where('run_type', ImportRun::TYPE_IMPORT)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('does not delete shared product import source files while another run still references them', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $storedPath = 'imports/shared-source.xlsx';
    Storage::disk('local')->put($storedPath, 'source');

    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'shared-source.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => ['validation_rows_count' => 1],
    ]);
    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'parent_import_run_id' => $validationRun->id,
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'shared-source.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)->deleteJson("/api/v1/products/import/runs/{$validationRun->id}")
        ->assertOk();

    Storage::disk('local')->assertExists($storedPath);

    $this->actingAs($admin)->deleteJson("/api/v1/products/import/runs/{$importRun->id}")
        ->assertOk();

    Storage::disk('local')->assertMissing($storedPath);
});

it('rejects importing from validation runs that are not completed', function (string $status, int $expectedStatus) {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validation.xlsx',
        'stored_path' => 'imports/validation.xlsx',
        'status' => $status,
        'results' => [],
        'errors' => [],
        'summary' => ['validation_rows_count' => 1],
    ]);
    Storage::disk('local')->put('imports/validation.xlsx', 'xlsx');

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
    ])->assertStatus($expectedStatus)
        ->assertJsonPath('success', false);

    expect(ImportRun::query()->where('run_type', ImportRun::TYPE_IMPORT)->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    [ImportRun::STATUS_PENDING, 409],
    [ImportRun::STATUS_RUNNING, 409],
    [ImportRun::STATUS_FAILED, 409],
    [ImportRun::STATUS_CANCELLED, 409],
]);

it('rejects importing from a completed validation run when its source file is missing', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'missing.xlsx',
        'stored_path' => 'imports/missing.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => ['validation_rows_count' => 1],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
    ])->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No se encontro el archivo validado para importar.');

    Queue::assertNothingPushed();
});

it('allows importing valid rows from completed validation runs with excluded issues', function (array $overrides) {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Storage::disk('local')->put('imports/blocked-validation.xlsx', 'xlsx');

    $summary = array_merge(['validation_rows_count' => 1], $overrides['summary'] ?? []);
    $validationRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'blocked-validation.xlsx',
        'stored_path' => 'imports/blocked-validation.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'error_count' => $overrides['error_count'] ?? 0,
        'results' => [],
        'errors' => [],
        'summary' => $summary,
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 2, 'status' => 'valid', 'action_type' => 'create',
        'data' => ['nombre' => 'Aceite valido', 'categoria' => 'Aceites'],
        'errors' => [], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);
    ImportRunRow::query()->create([
        'import_run_id' => $validationRun->id, 'row_number' => 3, 'status' => 'error', 'action_type' => 'invalid',
        'data' => ['nombre' => 'Aceite excluido', 'categoria' => 'Fantasma'],
        'errors' => ['Fila excluida.'], 'warnings' => [], 'conflicts' => [], 'change_preview' => [],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'validated_import_run_id' => $validationRun->id,
    ])->assertOk()
        ->assertJsonPath('success', true);

    expect(ImportRun::query()->where('run_type', ImportRun::TYPE_IMPORT)->exists())->toBeTrue();
    Queue::assertPushed(ProcessProductImportRun::class);
})->with([
    'errors' => [['error_count' => 1]],
    'conflicts' => [['summary' => ['conflict_count' => 1]]],
    'categories' => [['summary' => ['missing_categories' => ['Fantasma']]]],
    'brands' => [['summary' => ['missing_references' => ['brands' => ['Fantasma']]]]],
    'vehicles' => [['summary' => ['missing_vehicle_candidates' => [['label' => 'Toyota Yaris']]]]],
]);

it('allows advanced direct full imports only with the exact confirmation', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedCsv([
        ['Description', 'Category', 'System ID'],
        ['Aceite urgente', 'Aceites', 'LS-FORCE-WRONG'],
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => $file,
        'force_without_preview' => true,
        'force_confirmation' => 'IMPORTAR',
    ])->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(ImportRun::query()->count())->toBe(0);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite urgente', 'Aceites', 'LS-FORCE-OK'],
        ]),
        'sheet_index' => 1,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'force_without_preview' => true,
        'force_confirmation' => 'IMPORTAR SIN PREVIEW',
        'force_reason' => 'Carga urgente aprobada por gerencia.',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->summary['force_without_preview'] ?? null)->toBeTrue()
        ->and($importRun->summary['force_reason'] ?? null)->toBe('Carga urgente aprobada por gerencia.');

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
        'summary' => [
            'preview_async' => true,
            'action_counts' => [
                'create' => 2,
                'unchanged' => 1,
            ],
            'conflict_count' => 1,
            'unchanged_count' => 1,
        ],
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?per_page=5')
        ->assertOk()
        ->assertJsonPath('data.0.id', $importRun->id)
        ->assertJsonPath('data.0.has_source_file', true)
        ->assertJsonPath('data.0.preview_async', true)
        ->assertJsonPath('data.0.action_counts.create', 2)
        ->assertJsonPath('data.0.conflict_count', 1)
        ->assertJsonPath('data.0.unchanged_count', 1)
        ->assertJsonPath('data.0.user.name', $admin->name)
        ->assertJsonPath('data.0.source_download_url', "/api/v1/products/import/runs/{$importRun->id}/source")
        ->assertJsonPath('data.0.errors_download_url', "/api/v1/products/import/runs/{$importRun->id}/errors.xlsx");

    $this->actingAs($admin)
        ->getJson("/api/v1/products/import/runs/{$importRun->id}")
        ->assertOk()
        ->assertJsonPath('data.has_source_file', true)
        ->assertJsonPath('data.preview_async', true)
        ->assertJsonPath('data.action_counts.unchanged', 1)
        ->assertJsonPath('data.conflict_count', 1)
        ->assertJsonPath('data.unchanged_count', 1)
        ->assertJsonPath('data.source_download_url', "/api/v1/products/import/runs/{$importRun->id}/source");
});

it('deletes a terminal product import run and its stored source file', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $storedPath = 'imports/item_listings_local_matches.csv';
    Storage::disk('local')->put($storedPath, 'Description,Category');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'item_listings_local_matches.csv',
        'stored_path' => $storedPath,
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_COMPLETED,
        'total_rows' => 10,
        'processed_rows' => 10,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    ImportRunRecord::query()->create([
        'import_run_id' => $importRun->id,
        'model_type' => Product::class,
        'model_id' => null,
        'action' => 'created',
        'summary' => ['name' => 'Aceite'],
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/products/import/runs/{$importRun->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Importacion eliminada del historial.');

    expect(ImportRun::query()->whereKey($importRun->id)->exists())->toBeFalse()
        ->and(ImportRunRecord::query()->where('import_run_id', $importRun->id)->exists())->toBeFalse();

    Storage::disk('local')->assertMissing($storedPath);
});

it('does not delete an active product import run', function () {
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $storedPath = 'imports/running.csv';
    Storage::disk('local')->put($storedPath, 'Description,Category');

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'running.csv',
        'stored_path' => $storedPath,
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_RUNNING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/products/import/runs/{$importRun->id}")
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No se puede eliminar una importacion en proceso.');

    expect(ImportRun::query()->whereKey($importRun->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($storedPath);
});

it('reports whether product import runs can be deleted', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $completedRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'completed.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $runningRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'running.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_RUNNING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/products/import/runs?per_page=10')
        ->assertOk()
        ->assertJsonPath('data.0.id', $runningRun->id)
        ->assertJsonPath('data.0.can_delete', false)
        ->assertJsonPath('data.0.delete_block_reason', 'No se puede eliminar una importacion en proceso.')
        ->assertJsonPath('data.1.id', $completedRun->id)
        ->assertJsonPath('data.1.can_delete', true)
        ->assertJsonPath('data.1.delete_block_reason', null);

    $this->actingAs($admin)
        ->getJson("/api/v1/products/import/runs/{$runningRun->id}")
        ->assertOk()
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath('data.delete_block_reason', 'No se puede eliminar una importacion en proceso.');
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
        ->assertJsonPath('data.status', ImportRun::STATUS_CANCELLED);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('returns detected columns suggested mapping and applies manual mapping in preview', function () {
    $category = Category::create([
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
        ->assertJsonPath('data.rows.0.data.categoria', $category->id)
        ->assertJsonPath('data.rows.0.data.__category_label', 'Aceite Motor')
        ->assertJsonPath('data.rows.0.data.system_id', 'MAN-001');
});

it('keeps original csv header labels and suggests lightspeed product mapping', function () {
    $category = Category::create([
        'name' => 'FILTRO',
        'slug' => 'filtro',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedCsv([
        [
            'System ID',
            'UPC',
            'EAN',
            'Custom SKU',
            'Manufact. SKU',
            'Item',
            'Vendor ID',
            'Qty.',
            'Price',
            'Tax',
            'Brand',
            'Publish to eCom',
            'Season',
            'Department',
            'MSRP',
            'Tax Class',
            'Default Cost',
            'Vendor',
            'Category',
            'Subcategory 1',
            'Subcategory 2',
            'Subcategory 3',
            'Subcategory 4',
            'Subcategory 5',
            'Subcategory 6',
            'Subcategory 7',
            'Subcategory 8',
            'Subcategory 9',
        ],
        [
            '210000000298',
            '',
            '',
            'JT425K',
            '35330-0W020 N/A',
            'FILTRO ATM TOY YARIS',
            '35330-0W021',
            '2',
            '$10.00',
            'Yes',
            '',
            'No',
            '',
            '',
            '0.00',
            'Item',
            '4.480000000',
            'RENZO WBS',
            'FILTRO',
            'FILTRO ATM',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.detected_columns.0.key', 'system_id')
        ->assertJsonPath('data.detected_columns.0.label', 'System ID')
        ->assertJsonPath('data.detected_columns.3.key', 'custom_sku')
        ->assertJsonPath('data.detected_columns.3.label', 'Custom SKU')
        ->assertJsonPath('data.detected_columns.7.key', 'qty')
        ->assertJsonPath('data.detected_columns.7.label', 'Qty.')
        ->assertJsonPath('data.detected_columns.11.key', 'publish_to_ecom')
        ->assertJsonPath('data.detected_columns.11.label', 'Publish to eCom')
        ->assertJsonPath('data.detected_columns.15.key', 'tax_class')
        ->assertJsonPath('data.detected_columns.15.label', 'Tax Class')
        ->assertJsonPath('data.detected_columns.16.key', 'default_cost')
        ->assertJsonPath('data.detected_columns.16.label', 'Default Cost')
        ->assertJsonPath('data.detected_columns.18.key', 'category')
        ->assertJsonPath('data.detected_columns.18.label', 'Category')
        ->assertJsonPath('data.detected_columns.27.key', 'subcategory_9')
        ->assertJsonPath('data.detected_columns.27.label', 'Subcategory 9')
        ->assertJsonPath('data.suggested_mapping.system_id', 'system_id')
        ->assertJsonPath('data.suggested_mapping.custom_sku', 'sku')
        ->assertJsonPath('data.suggested_mapping.item', 'nombre')
        ->assertJsonPath('data.suggested_mapping.qty', 'cantidad')
        ->assertJsonPath('data.suggested_mapping.price', 'precio')
        ->assertJsonPath('data.suggested_mapping.brand', 'brand')
        ->assertJsonPath('data.suggested_mapping.category', 'categoria')
        ->assertJsonPath('data.suggested_mapping.default_cost', 'costo')
        ->assertJsonPath('data.suggested_mapping.upc', 'ignore')
        ->assertJsonPath('data.suggested_mapping.ean', 'ignore')
        ->assertJsonPath('data.suggested_mapping.manufact_sku', 'ignore')
        ->assertJsonPath('data.suggested_mapping.vendor_id', 'ignore')
        ->assertJsonPath('data.suggested_mapping.tax', 'ignore')
        ->assertJsonPath('data.suggested_mapping.publish_to_ecom', 'ignore')
        ->assertJsonPath('data.suggested_mapping.season', 'ignore')
        ->assertJsonPath('data.suggested_mapping.department', 'ignore')
        ->assertJsonPath('data.suggested_mapping.tax_class', 'ignore')
        ->assertJsonPath('data.suggested_mapping.vendor', 'ignore')
        ->assertJsonPath('data.suggested_mapping.subcategory_1', 'ignore')
        ->assertJsonPath('data.suggested_mapping.subcategory_9', 'ignore')
        ->assertJsonPath('data.suggested_mapping.msrp', 'ignore')
        ->assertJsonPath('data.rows.0.data.nombre', 'FILTRO ATM TOY YARIS')
        ->assertJsonPath('data.rows.0.data.sku', 'JT425K')
        ->assertJsonPath('data.rows.0.data.system_id', '210000000298')
        ->assertJsonPath('data.rows.0.data.categoria', $category->id)
        ->assertJsonPath('data.rows.0.data.__category_label', 'FILTRO')
        ->assertJsonPath('data.rows.0.data.precio', '10.00')
        ->assertJsonPath('data.rows.0.data.cantidad', '2')
        ->assertJsonPath('data.rows.0.data.costo', '4.48');
});

it('keeps missing category text in preview when no category matches', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'Variant Code'],
        ['Aceite sin categoria', 'Categoria Fantasma', 'MISS-CAT-001'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.data.categoria', 'Categoria Fantasma')
        ->assertJsonPath('data.rows.0.errors.0', "La categoria 'Categoria Fantasma' no existe. Creala primero para evitar duplicados.")
        ->assertJsonPath('data.summary.missing_categories.0', 'Categoria Fantasma');
});

it('honors explicit ignore mappings before automatic product preview mapping', function () {
    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor-ignore',
    ]);
    productImportEnsureBrands(['Generica']);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Category', 'Variant Code'],
        ['Aceite ignorado', 'Aceite Motor', 'IGN-001'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
        'mapping' => [
            'description' => 'ignore',
            'category' => 'categoria',
            'variant_code' => 'system_id',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.data.nombre', '')
        ->assertJsonPath('data.rows.0.data.categoria', $category->id)
        ->assertJsonPath('data.rows.0.data.__category_label', 'Aceite Motor')
        ->assertJsonPath('data.rows.0.data.system_id', 'IGN-001')
        ->assertJsonPath('data.rows.0.errors.0', 'El campo nombre es obligatorio.');
});

it('validate route returns and persists detected column metadata for a product import file', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $file = productImportUploadedSpreadsheet([
        ['Nombre', 'SKU', 'System ID', 'Brand', 'Categoria', 'Costo', 'Precio', 'Cantidad', 'Imagen URL', 'Descripcion'],
        ['Bujia Iridium', 'SKU-001', '10000001', 'NGK', 'Encendido', '4.50', '7.99', '12', 'https://cdn.example.test/bujia.jpg', 'Bujia para motor gasolina'],
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => $file,
    ]);

    $expectedMapping = [
        'nombre' => 'nombre',
        'sku' => 'sku',
        'system_id' => 'system_id',
        'brand' => 'brand',
        'categoria' => 'categoria',
        'costo' => 'costo',
        'precio' => 'precio',
        'cantidad' => 'cantidad',
        'imagen_url' => 'ignore',
        'descripcion' => 'descripcion',
    ];

    $response->assertOk()
        ->assertJsonPath('data.detected_columns.0.key', 'nombre')
        ->assertJsonPath('data.detected_columns.8.key', 'imagen_url')
        ->assertJsonPath('data.suggested_mapping', $expectedMapping);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->mapping)->toBe([])
        ->and($importRun->summary['detected_columns'][0]['key'])->toBe('nombre')
        ->and($importRun->summary['detected_columns'][8]['key'])->toBe('imagen_url')
        ->and($importRun->summary['suggested_mapping'])->toBe($expectedMapping)
        ->and($importRun->summary)->toHaveKeys([
            'sheets',
            'selected_sheet',
            'recommended_sheet',
            'detected_columns',
            'suggested_mapping',
            'required_fields',
            'sheet_detection_warning',
        ]);

    Queue::assertPushed(ProcessProductImportRun::class);
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

it('restarts a terminal full product validation run in place when mapping changes', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $firstResponse = $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite anterior', 'Aceites', 'LS-VALIDATE-OLD'],
        ]),
        'mapping' => [
            'Description' => 'nombre',
            'Category' => 'categoria',
            'System ID' => 'system_id',
        ],
    ]);

    $firstResponse->assertOk();

    $importRun = ImportRun::query()->firstOrFail();
    $originalStoredPath = $importRun->stored_path;
    $importRun->rows()->create([
        'row_number' => 2,
        'status' => 'error',
        'data' => ['nombre' => 'Aceite anterior'],
        'errors' => ['Error anterior'],
        'warnings' => [],
        'conflicts' => [],
        'change_preview' => [],
    ]);
    $importRun->records()->create([
        'model_type' => Product::class,
        'model_id' => null,
        'action' => 'validated',
        'summary' => ['name' => 'Aceite anterior'],
    ]);
    $importRun->update([
        'status' => ImportRun::STATUS_COMPLETED,
        'processed_rows' => 1,
        'success_count' => 0,
        'error_count' => 1,
        'results' => [['row' => 2]],
        'errors' => [['row' => 2, 'error' => 'Error anterior']],
        'failed_rows' => [['row' => 2, 'error' => 'Error anterior']],
        'summary' => ['old' => true],
        'finished_at' => now(),
    ]);

    $secondResponse = $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite actualizado', 'Aceites', 'LS-VALIDATE-NEW'],
        ]),
        'import_run_id' => $importRun->id,
        'mapping' => [
            'Description' => 'descripcion',
            'Category' => 'categoria',
            'System ID' => 'system_id',
        ],
    ]);

    $secondResponse->assertOk()
        ->assertJsonPath('data.import_run_id', $importRun->id)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING);

    $importRun->refresh();

    expect(ImportRun::query()->count())->toBe(1)
        ->and($importRun->status)->toBe(ImportRun::STATUS_PENDING)
        ->and($importRun->mapping)->toBe([
            'Description' => 'descripcion',
            'Category' => 'categoria',
            'System ID' => 'system_id',
        ])
        ->and($importRun->processed_rows)->toBe(0)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_count)->toBe(0)
        ->and($importRun->results)->toBe([])
        ->and($importRun->errors)->toBe([])
        ->and($importRun->failed_rows)->toBe([])
        ->and($importRun->summary['old'] ?? null)->toBeNull()
        ->and($importRun->rows()->exists())->toBeFalse()
        ->and($importRun->records()->exists())->toBeFalse()
        ->and($importRun->stored_path)->not->toBe($originalStoredPath);

    Storage::disk('local')->assertMissing($originalStoredPath);
    Storage::disk('local')->assertExists($importRun->stored_path);
    Queue::assertPushed(ProcessProductImportRun::class, 2);
});

it('rejects restarting an active full product validation run in place', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'running.xlsx',
        'stored_path' => 'imports/running.xlsx',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'status' => ImportRun::STATUS_RUNNING,
        'mapping' => ['Description' => 'nombre'],
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    Storage::disk('local')->put($importRun->stored_path, 'old');

    $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite actualizado', 'Aceites', 'LS-VALIDATE-NEW'],
        ]),
        'import_run_id' => $importRun->id,
        'mapping' => ['Description' => 'descripcion'],
    ])->assertStatus(409)
        ->assertJsonPath('message', 'La validacion anterior sigue en proceso. Espere a que termine antes de volver a validarla.');

    expect($importRun->refresh()->stored_path)->toBe('imports/running.xlsx')
        ->and($importRun->mapping)->toBe(['Description' => 'nombre']);
    Storage::disk('local')->assertExists('imports/running.xlsx');
    Queue::assertNothingPushed();
});

it('persists variant grouping metadata after validating a full product import file', function () {
    Queue::fake();
    Storage::fake('local');

    Category::create(['name' => 'Terminales', 'slug' => 'terminales']);
    productImportEnsureBrands(['555', 'CTR']);

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/validate', [
        'file' => productImportUploadedSpreadsheet([
            ['nombre', 'sku', 'system_id', 'brand', 'categoria'],
            ['Terminal Rack', 'TIE-555', 'LS-001', '555', 'Terminales'],
            ['Terminal Rack', 'TIE-CTR', 'LS-002', 'CTR', 'Terminales'],
        ]),
    ]);

    $response->assertOk();

    $importRun = ImportRun::query()->firstOrFail();
    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));

    $importRun->refresh();

    expect($importRun->summary['groups'][0]['product_key'])->toBe('terminales|terminal rack')
        ->and($importRun->summary['groups'][0]['variant_count'])->toBe(2)
        ->and($importRun->summary['groups'][0]['variant_skus'])->toBe(['TIE-555', 'TIE-CTR'])
        ->and($importRun->summary['productos_estimados'])->toBe(1)
        ->and($importRun->summary['variantes_estimadas'])->toBe(2);
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

it('updates an existing terminal async product preview run instead of creating another one', function () {
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

    $firstRows = collect(range(1, 251))
        ->map(fn (int $index): array => [
            "Aceite 10W30 {$index}",
            "OIL-PREVIEW-{$index}",
            "LS-PREVIEW-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $firstResponse = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => productImportUploadedSpreadsheet($firstRows),
        'mapping' => [
            'Description' => 'nombre',
            'Custom SKU' => 'sku',
            'System ID' => 'system_id',
            'Brand' => 'marca',
            'Category' => 'categoria',
        ],
    ]);

    $firstResponse->assertOk()
        ->assertJsonPath('data.async', true);

    $importRun = ImportRun::query()->firstOrFail();
    $originalStoredPath = $importRun->stored_path;
    Storage::disk('local')->assertExists($originalStoredPath);

    $importRun->records()->create([
        'model_type' => Product::class,
        'model_id' => null,
        'action' => 'validated',
        'summary' => ['name' => 'Aceite anterior'],
    ]);
    $importRun->update([
        'status' => ImportRun::STATUS_COMPLETED,
        'processed_rows' => 251,
        'success_count' => 250,
        'error_count' => 1,
        'results' => [['row' => 2]],
        'errors' => [['row' => 3]],
        'failed_rows' => [['row' => 3]],
        'summary' => ['preview_async' => true, 'old' => true],
        'finished_at' => now(),
    ]);

    $secondRows = collect(range(1, 252))
        ->map(fn (int $index): array => [
            "Aceite 20W50 {$index}",
            "OIL-REPREVIEW-{$index}",
            "LS-REPREVIEW-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $secondResponse = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => productImportUploadedSpreadsheet($secondRows),
        'import_run_id' => $importRun->id,
        'mapping' => [
            'Description' => 'nombre',
            'Custom SKU' => 'sku',
            'System ID' => 'system_id',
            'Brand' => 'marca',
            'Category' => 'categoria',
        ],
    ]);

    $secondResponse->assertOk()
        ->assertJsonPath('data.async', true)
        ->assertJsonPath('data.import_run_id', $importRun->id)
        ->assertJsonPath('data.status', ImportRun::STATUS_PENDING)
        ->assertJsonPath('data.total_rows', 252);

    $importRun->refresh();

    expect(ImportRun::query()->count())->toBe(1)
        ->and($importRun->status)->toBe(ImportRun::STATUS_PENDING)
        ->and($importRun->total_rows)->toBe(252)
        ->and($importRun->processed_rows)->toBe(0)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_count)->toBe(0)
        ->and($importRun->results)->toBe([])
        ->and($importRun->errors)->toBe([])
        ->and($importRun->failed_rows)->toBe([])
        ->and($importRun->summary['preview_async'])->toBeTrue()
        ->and($importRun->summary['old'] ?? null)->toBeNull()
        ->and($importRun->records()->exists())->toBeFalse()
        ->and($importRun->stored_path)->not->toBe($originalStoredPath);

    Storage::disk('local')->assertMissing($originalStoredPath);
    Storage::disk('local')->assertExists($importRun->stored_path);
    Queue::assertPushed(ProcessProductImportRun::class, 2);
});

it('does not let managers update another users async product preview run', function () {
    Queue::fake();
    Storage::fake('local');

    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);
    $otherManager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    productImportEnsureBrands(['Shell']);

    $importRun = ImportRun::query()->create([
        'user_id' => $otherManager->id,
        'entity' => 'products',
        'original_filename' => 'other.xlsx',
        'stored_path' => 'imports/other.xlsx',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => ['preview_async' => true],
    ]);
    Storage::disk('local')->put($importRun->stored_path, 'old');

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

    $this->actingAs($manager)
        ->postJson('/api/v1/products/import/preview', [
            'file' => productImportUploadedSpreadsheet($rows),
            'import_run_id' => $importRun->id,
        ])
        ->assertNotFound();

    expect($importRun->refresh()->stored_path)->toBe('imports/other.xlsx');
    Storage::disk('local')->assertExists('imports/other.xlsx');
    Queue::assertNothingPushed();
});

it('rejects repreviewing an active async product preview run', function () {
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

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'running.xlsx',
        'stored_path' => 'imports/running.xlsx',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'status' => ImportRun::STATUS_RUNNING,
        'mapping' => ['Description' => 'nombre'],
        'results' => [],
        'errors' => [],
        'summary' => ['preview_async' => true],
    ]);
    Storage::disk('local')->put($importRun->stored_path, 'old');

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

    $this->actingAs($admin)
        ->postJson('/api/v1/products/import/preview', [
            'file' => productImportUploadedSpreadsheet($rows),
            'import_run_id' => $importRun->id,
            'mapping' => ['Description' => 'descripcion'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'La previsualizacion anterior sigue en proceso. Espere a que termine antes de volver a previsualizar.');

    $importRun->refresh();

    expect($importRun->stored_path)->toBe('imports/running.xlsx')
        ->and($importRun->mapping)->toBe(['Description' => 'nombre']);
    Storage::disk('local')->assertExists('imports/running.xlsx');
    Queue::assertNothingPushed();
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

it('persists fifteen hundred product validation rows outside the import run summary', function () {
    Storage::fake('local');

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor-1500',
    ]);
    productImportEnsureBrands(['Shell']);

    $rows = collect(range(1, 1500))
        ->map(fn (int $index): array => [
            "Aceite 15W40 {$index}",
            "OIL-15W40-{$index}",
            "LS-1500-{$index}",
            'Shell',
            'Aceite Motor',
        ])
        ->prepend(['Description', 'Custom SKU', 'System ID', 'Brand', 'Category'])
        ->all();

    $file = productImportUploadedSpreadsheet($rows);
    $storedPath = Storage::disk('local')->putFileAs('imports', $file, 'validate-1500-products.xlsx');
    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'validate-1500-products.xlsx',
        'stored_path' => $storedPath,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    (new ProcessProductImportRun($importRun->id))->handle(app(ProductService::class));
    $importRun->refresh();

    expect($importRun->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($importRun->processed_rows)->toBe(1500)
        ->and(array_key_exists('validation_rows', $importRun->summary))->toBeFalse()
        ->and($importRun->summary['validation_rows_count'])->toBe(1500)
        ->and(ImportRunRow::query()->where('import_run_id', $importRun->id)->count())->toBe(1500);
});

it('persists validated product rows and exposes them through paginated run rows endpoints', function () {
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
        ->assertJsonPath('data.validation_rows_limited', true)
        ->assertJsonCount(0, 'data.validation_rows');

    $rowsResponse = $this->actingAs($admin)->getJson("/api/v1/products/import/runs/{$importRun->id}/rows?per_page=25&page=12");

    $rowsResponse->assertOk()
        ->assertJsonPath('meta.total', 501)
        ->assertJsonPath('meta.current_page', 12)
        ->assertJsonPath('meta.valid_count', 500)
        ->assertJsonPath('meta.error_count', 1)
        ->assertJsonPath('meta.warning_count', 0)
        ->assertJsonPath('meta.conflict_count', 0)
        ->assertJsonPath('meta.action_counts.create', 500)
        ->assertJsonPath('meta.action_counts.invalid', 1)
        ->assertJsonPath('meta.retryable_failed_rows_count', 1)
        ->assertJsonPath('data.1.row', 278)
        ->assertJsonPath('data.1.is_valid', false)
        ->assertJsonPath('data.1.action_type', 'invalid')
        ->assertJsonPath('data.1.data.brand', 'Marca Fantasma')
        ->assertJsonPath('data.1.warnings', []);

    $errorsResponse = $this->actingAs($admin)->getJson("/api/v1/products/import/runs/{$importRun->id}/errors?per_page=10");

    $errorsResponse->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.row', 278)
        ->assertJsonPath('data.0.is_valid', false);

    expect(ImportRunRow::query()->where('import_run_id', $importRun->id)->count())->toBe(501)
        ->and($errorsResponse->json('data.0.errors.0'))->toContain('no existe')
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
        ->assertJsonPath('data.validation_rows_limited', true)
        ->assertJsonPath('data.action_counts.create', 501)
        ->assertJsonCount(0, 'data.validation_rows');

    expect(ImportRunRow::query()->where('import_run_id', $importRun->id)->count())->toBe(501)
        ->and(Product::query()->count())->toBe(0);
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

it('uses stock and price update mode when importing edited preview rows', function () {
    Setting::set('store_tax_rate', 0, 'number', 'store');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor-preview-mode',
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

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/execute', [
        'mode' => ImportRun::MODE_UPDATE_STOCK_PRICE,
        'rows' => [
            [
                '__row' => 2,
                'nombre' => '10W30',
                'sku' => 'OIL-001',
                'system_id' => 'LS-001',
                'brand' => 'Shell',
                'categoria' => 'Aceite Motor',
                'costo' => '10',
                'precio' => '12',
                'cantidad' => '7',
                'warehouse' => $warehouse->code,
            ],
            [
                '__row' => 3,
                'nombre' => '10W40',
                'sku' => 'OIL-002',
                'system_id' => 'LS-404',
                'brand' => 'Shell',
                'categoria' => 'Aceite Motor',
                'costo' => '10',
                'precio' => '12',
                'cantidad' => '3',
                'warehouse' => $warehouse->code,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.success_count', 1)
        ->assertJsonPath('data.error_count', 1);

    expect(ProductVariant::query()->where('system_id', 'LS-404')->exists())->toBeFalse()
        ->and((float) ProductVariant::query()->where('system_id', 'LS-001')->firstOrFail()->pricing()->firstOrFail()->base_price)->toBe(12.0);
});

it('previews and stores product image urls from imported spreadsheets', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Filtros',
        'slug' => 'filtros-image-url',
    ]);
    productImportEnsureBrands(['Mann']);

    $file = productImportUploadedSpreadsheet([
        ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category', 'Image URL'],
        ['Filtro Aceite', 'FIL-001', 'LS-FIL-001', 'Mann', 'Filtros', 'https://cdn.example.test/filtro.jpg'],
    ]);

    $preview = $this->actingAs($admin)->postJson('/api/v1/products/import/preview', [
        'file' => $file,
    ]);

    $preview->assertOk()
        ->assertJsonPath('data.detected_columns.5.suggested_field', 'ignore')
        ->assertJsonMissingPath('data.rows.0.data.imagen_url');

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/execute', [
        'rows' => [
            [
                '__row' => 2,
                'nombre' => 'Filtro Aceite',
                'sku' => 'FIL-001',
                'system_id' => 'LS-FIL-001',
                'brand' => 'Mann',
                'categoria' => 'Filtros',
                'imagen_url' => 'https://cdn.example.test/filtro.jpg',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.success_count', 1)
        ->assertJsonPath('data.error_count', 0);

    expect(Product::query()->where('sku_base', 'FIL-001')->firstOrFail()->image_url)
        ->toBe('https://cdn.example.test/filtro.jpg');
});

it('rejects invalid product image urls during import validation', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Filtros',
        'slug' => 'filtros-invalid-image-url',
    ]);
    productImportEnsureBrands(['Mann']);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/validate-rows', [
        'rows' => [
            [
                '__row' => 2,
                'nombre' => 'Filtro Aceite',
                'sku' => 'FIL-001',
                'system_id' => 'LS-FIL-001',
                'brand' => 'Mann',
                'categoria' => 'Filtros',
                'imagen_url' => 'no-es-url',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rows.0.is_valid', false)
        ->assertJsonPath('data.rows.0.errors.0', 'La URL de imagen debe ser una URL valida.');
});

it('validates only submitted product import rows without creating products', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/validate-rows', [
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'rows' => [
            [
                '__row' => 2,
                'nombre' => 'Aceite valido',
                'sku' => 'OIL-VALID-001',
                'categoria' => 'Aceite Motor',
            ],
            [
                '__row' => 27,
                'nombre' => 'Aceite invalido',
                'sku' => 'OIL-INVALID-001',
                'categoria' => 'Categoria Fantasma',
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.error_count', 1)
        ->assertJsonPath('data.rows.0.row', 2)
        ->assertJsonPath('data.rows.0.is_valid', true)
        ->assertJsonPath('data.rows.0.action_type', 'create')
        ->assertJsonPath('data.rows.1.row', 27)
        ->assertJsonPath('data.rows.1.is_valid', false)
        ->assertJsonPath('data.rows.1.action_type', 'invalid')
        ->assertJsonPath('data.summary.action_counts.create', 1)
        ->assertJsonPath('data.summary.action_counts.invalid', 1);

    expect($response->json('data.rows.1.errors.0'))->toContain('no existe')
        ->and(Product::query()->count())->toBe(0);
});

it('creates missing product import categories and brands in one backend batch', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Aceite Motor',
        'slug' => 'aceite-motor',
    ]);
    Brand::query()->create([
        'name' => 'Shell',
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/references/batch', [
        'categories' => ['Aceite Motor', 'Filtros'],
        'brands' => ['Shell', 'Mann Filter'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.categories.created.0.name', 'Filtros')
        ->assertJsonPath('data.categories.existing.0.name', 'Aceite Motor')
        ->assertJsonPath('data.brands.created.0.name', 'Mann Filter')
        ->assertJsonPath('data.brands.existing.0.name', 'Shell')
        ->assertJsonPath('data.summary.created_count', 2)
        ->assertJsonPath('data.summary.existing_count', 2)
        ->assertJsonPath('data.summary.error_count', 0);

    expect(Category::query()->where('name', 'Filtros')->count())->toBe(1)
        ->and(Category::query()->where('name', 'Aceite Motor')->count())->toBe(1)
        ->and(Brand::query()->where('name', 'Mann Filter')->count())->toBe(1)
        ->and(Brand::query()->where('name', 'Shell')->count())->toBe(1);
});

it('reports invalid product import batch references without aborting the whole batch', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/references/batch', [
        'categories' => ['', str_repeat('C', 101), 'Baterias'],
        'brands' => ['', 'ACDelco'],
    ]);

    $response->assertStatus(207)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.categories.created.0.name', 'Baterias')
        ->assertJsonPath('data.brands.created.0.name', 'ACDelco')
        ->assertJsonPath('data.summary.created_count', 2)
        ->assertJsonPath('data.summary.skipped_count', 2)
        ->assertJsonPath('data.summary.error_count', 1);

    expect($response->json('data.categories.errors.0.name'))->toBe(str_repeat('C', 101))
        ->and($response->json('data.categories.errors.0.message'))->toContain('100 caracteres')
        ->and(Category::query()->where('name', 'Baterias')->exists())->toBeTrue()
        ->and(Brand::query()->where('name', 'ACDelco')->exists())->toBeTrue();
});

it('creates product import vehicle candidates in one backend batch', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/vehicle-candidates/batch', [
        'candidates' => [[
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'chassis' => 'P90',
            'engine' => '1NZFE',
            'label' => 'TOYOTA YARIS P90 1NZFE',
            'source' => 'nombre',
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.candidates.0.status', 'created')
        ->assertJsonPath('data.candidates.0.make.name', 'TOYOTA')
        ->assertJsonPath('data.candidates.0.model.name', 'YARIS')
        ->assertJsonPath('data.candidates.0.chassis.code', 'P90')
        ->assertJsonPath('data.candidates.0.engine.engine_code', '1NZFE')
        ->assertJsonPath('data.summary.created_count', 4)
        ->assertJsonPath('data.summary.error_count', 0);

    $model = VehicleModel::query()->where('name', 'YARIS')->firstOrFail();
    $chassis = VehicleChassis::query()->where('code', 'P90')->firstOrFail();
    $engine = VehicleEngine::query()->where('engine_code', '1NZFE')->firstOrFail();

    expect($model->chassis()->whereKey($chassis->id)->exists())->toBeTrue()
        ->and($engine->models()->whereKey($model->id)->exists())->toBeTrue();
});

it('reuses existing product import vehicle candidates without duplicating catalog records', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    ['make' => $make, 'model' => $model] = productImportCreateVehicleScope();

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/vehicle-candidates/batch', [
        'candidates' => [[
            'make_id' => $make->id,
            'model_id' => $model->id,
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'chassis' => 'P90',
            'engine' => '1NZFE',
            'label' => 'TOYOTA YARIS P90 1NZFE',
            'source' => 'nombre',
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.candidates.0.status', 'existing')
        ->assertJsonPath('data.summary.created_count', 0)
        ->assertJsonPath('data.summary.existing_count', 4)
        ->assertJsonPath('data.summary.error_count', 0);

    expect(VehicleMake::query()->where('name', 'TOYOTA')->count())->toBe(1)
        ->and(VehicleModel::query()->where('name', 'YARIS')->count())->toBe(1)
        ->and(VehicleChassis::query()->where('code', 'P90')->count())->toBe(1)
        ->and(VehicleEngine::query()->where('engine_code', '1NZFE')->count())->toBe(1);
});

it('exports edited product import preview rows as an excel file without importing anything', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import/export-preview', [
        'rows' => [
            [
                '__row' => 2,
                '__valid' => true,
                '__action_type' => 'create',
                '__errors' => [],
                'nombre' => 'Aceite 10W30 editado',
                'sku' => 'OIL-EDIT-001',
                'system_id' => 'LS-EDIT-001',
                'brand' => 'Shell',
                'categoria' => 'Aceite Motor',
                'costo' => '5.35',
                'precio' => '6.42',
                'cantidad' => '10',
                'descripcion' => 'Fila corregida en preview',
            ],
            [
                '__row' => 7,
                '__valid' => false,
                '__action_type' => 'invalid',
                '__errors' => ['La categoria no existe.'],
                'nombre' => 'Producto pendiente',
                'sku' => 'OIL-EDIT-ERR',
                'categoria' => 'Fantasma',
            ],
        ],
    ]);

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'edited-preview-export-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($response->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($sheet->getCell('A1')->getValue())->toBe('Fila')
        ->and($sheet->getCell('B1')->getValue())->toBe('Estado')
        ->and($sheet->getCell('C1')->getValue())->toBe('Accion prevista')
        ->and($sheet->getCell('D1')->getValue())->toBe('Errores')
        ->and($sheet->getCell('E1')->getValue())->toBe('nombre')
        ->and($sheet->getCell('M1')->getValue())->toBe('descripcion')
        ->and($sheet->getCell('N1')->getValue())->toBeNull()
        ->and($sheet->getCell('A2')->getValue())->toBe(2)
        ->and($sheet->getCell('B2')->getValue())->toBe('Valida')
        ->and($sheet->getCell('C2')->getValue())->toBe('create')
        ->and($sheet->getCell('E2')->getValue())->toBe('Aceite 10W30 editado')
        ->and($sheet->getCell('M2')->getValue())->toBe('Fila corregida en preview')
        ->and($sheet->getCell('B3')->getValue())->toBe('Con errores')
        ->and($sheet->getCell('D3')->getValue())->toBe('La categoria no existe.')
        ->and(Product::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('rejects edited product import preview export without rows', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/export-preview', [
        'rows' => [],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Debe enviar filas para exportar.');
});

it('downloads the product import template without the image url column', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->get('/api/v1/products/import/template');

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'product-template-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('I1')->getValue())->toBe('descripcion')
        ->and($sheet->getCell('J1')->getValue())->toBeNull()
        ->and($sheet->getCell('I2')->getValue())->toBe('Terminal importado desde Lightspeed');
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
            'action_type' => 'invalid',
            'column' => 'categoria',
            'value' => 'Fantasma',
            'error' => 'La categoria no existe.',
            'suggestion' => 'Aceite Motor',
            'change_preview' => [[
                'field' => 'categoria',
                'label' => 'Categoria',
                'current' => null,
                'imported' => 'Fantasma',
            ]],
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
        ->and($sheet->getCell('F2')->getValue())->toBe('invalid')
        ->and($sheet->getCell('I2')->getValue())->toBeNull()
        ->and($sheet->getCell('J2')->getValue())->toBe('Fantasma')
        ->and($sheet->getCell('H2')->getValue())->toBe('La categoria no existe.');
});

it('exports all failed rows even when the panel errors are limited', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $failedRows = [];

    foreach (range(1, 150) as $index) {
        $failedRows[] = [
            'row' => $index + 1,
            'label' => "Producto {$index}",
            'action_type' => 'invalid',
            'data' => ['nombre' => "Producto {$index}", 'categoria' => 'Fantasma'],
            'error' => "Error {$index}",
        ];
    }

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'errors.xlsx',
        'stored_path' => 'imports/errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'failed_rows' => $failedRows,
        'errors' => array_slice($failedRows, -100),
        'results' => [],
        'summary' => ['latest_errors_limited_to' => 100],
    ]);

    $response = $this->actingAs($admin)->get("/api/v1/products/import/runs/{$importRun->id}/errors.xlsx");

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'full-errors-export-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getHighestDataRow())->toBe(151)
        ->and($sheet->getCell('A2')->getValue())->toBe(2)
        ->and($sheet->getCell('C2')->getValue())->toBe('Producto 1')
        ->and($sheet->getCell('A151')->getValue())->toBe(151)
        ->and($sheet->getCell('C151')->getValue())->toBe('Producto 150')
        ->and($sheet->getCell('H151')->getValue())->toBe('Error 150');
});

it('exports all persisted import run row errors before falling back to limited panel errors', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $importRun = ImportRun::query()->create([
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_VALIDATION,
        'mode' => ImportRun::MODE_VALIDATE_ONLY,
        'original_filename' => 'persisted-errors.xlsx',
        'stored_path' => 'imports/persisted-errors.xlsx',
        'status' => ImportRun::STATUS_COMPLETED,
        'error_count' => 125,
        'errors' => [['row' => 126, 'label' => 'Limitado', 'error' => 'Solo panel']],
        'results' => [],
        'summary' => ['latest_errors_limited_to' => 100],
    ]);

    foreach (range(1, 125) as $index) {
        ImportRunRow::query()->create([
            'import_run_id' => $importRun->id,
            'row_number' => $index + 1,
            'sheet_name' => 'Productos',
            'status' => 'error',
            'action_type' => 'invalid',
            'label' => "Producto {$index}",
            'data' => ['nombre' => "Producto {$index}", 'categoria' => 'Fantasma'],
            'errors' => ["Error persistido {$index}"],
            'warnings' => [],
            'conflicts' => [],
            'change_preview' => [],
        ]);
    }

    $response = $this->actingAs($admin)->get("/api/v1/products/import/runs/{$importRun->id}/errors.xlsx");

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'persisted-errors-export-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getHighestDataRow())->toBe(126)
        ->and($sheet->getCell('B126')->getValue())->toBe('Productos')
        ->and($sheet->getCell('C126')->getValue())->toBe('Producto 125')
        ->and($sheet->getCell('H126')->getValue())->toBe('Error persistido 125');
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

it('cancels pending product import runs immediately and requests cancellation for running runs', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $pendingRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'pending.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $runningRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'running.xlsx',
        'status' => ImportRun::STATUS_RUNNING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$pendingRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_CANCELLED);

    $this->actingAs($admin)
        ->postJson("/api/v1/products/import/runs/{$runningRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_CANCEL_REQUESTED);

    expect($pendingRun->refresh()->finished_at)->not->toBeNull()
        ->and($pendingRun->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($runningRun->refresh()->status)->toBe(ImportRun::STATUS_CANCEL_REQUESTED)
        ->and($runningRun->finished_at)->toBeNull();
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

it('marks orphaned product import runs as terminal when listing history without touching fresh cancellations', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $stalePending = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'pending.xlsx',
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $stalePending->forceFill([
        'created_at' => now()->subHours(8),
        'updated_at' => now()->subHours(8),
    ])->save();
    $freshCancelRequested = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'cancel.xlsx',
        'status' => ImportRun::STATUS_CANCEL_REQUESTED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $staleCancelRequested = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'original_filename' => 'stale-cancel.xlsx',
        'status' => ImportRun::STATUS_CANCEL_REQUESTED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);
    $staleCancelRequested->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->save();

    $this->actingAs($admin)->getJson('/api/v1/products/import/runs')
        ->assertOk();

    expect($stalePending->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($stalePending->canCancel())->toBeFalse()
        ->and($freshCancelRequested->refresh()->status)->toBe(ImportRun::STATUS_CANCEL_REQUESTED)
        ->and($freshCancelRequested->canCancel())->toBeFalse()
        ->and($staleCancelRequested->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($staleCancelRequested->canCancel())->toBeFalse();
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

it('returns spanish validation messages when storing import profiles', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/profiles', [
        'name' => '',
        'mapping' => 'description:nombre',
        'sheet_index' => -1,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'mapping', 'sheet_index'])
        ->assertJsonPath('errors.name.0', 'Debe asignar un nombre al perfil de importacion.')
        ->assertJsonPath('errors.mapping.0', 'El mapeo del perfil debe enviarse como un objeto.')
        ->assertJsonPath('errors.sheet_index.0', 'La hoja del perfil no puede ser negativa.');
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
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite manager', 'Aceites', 'LS-PROFILE-MANAGER'],
        ]),
        'profile_id' => $adminProfile->id,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'force_without_preview' => true,
        'force_confirmation' => 'IMPORTAR SIN PREVIEW',
    ]);

    $managerResponse->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(ImportRun::query()->count())->toBe(0);

    $adminResponse = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => productImportUploadedSpreadsheet([
            ['Description', 'Category', 'System ID'],
            ['Aceite admin', 'Aceites', 'LS-PROFILE-ADMIN'],
        ]),
        'profile_id' => $managerProfile->id,
        'sheet_name' => 'SAMPLE UPLOAD LIGHTSPEED',
        'force_without_preview' => true,
        'force_confirmation' => 'IMPORTAR SIN PREVIEW',
    ]);

    $adminResponse->assertOk()
        ->assertJsonPath('success', true);

    $importRun = ImportRun::query()->firstOrFail();

    expect($importRun->user_id)->toBe($admin->id)
        ->and($importRun->import_profile_id)->toBe($managerProfile->id);

    Queue::assertPushed(ProcessProductImportRun::class);
});

it('blocks new heavy product import runs when the user already has two active runs', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    foreach ([ImportRun::STATUS_PENDING, ImportRun::STATUS_RUNNING] as $status) {
        ImportRun::query()->create([
            'user_id' => $admin->id,
            'entity' => 'products',
            'original_filename' => "active-{$status}.csv",
            'run_type' => ImportRun::TYPE_IMPORT,
            'mode' => ImportRun::MODE_CREATE_UPDATE,
            'status' => $status,
            'results' => [],
            'errors' => [],
            'summary' => [],
        ]);
    }

    $response = $this->actingAs($admin)->postJson('/api/v1/products/import', [
        'file' => productImportUploadedCsv([
            ['Description', 'Category', 'System ID'],
            ['Aceite bloqueado', 'Aceites', 'LS-CONCURRENCY'],
        ]),
        'force_without_preview' => true,
        'force_confirmation' => 'IMPORTAR SIN PREVIEW',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.active_runs_limit', 2);

    expect(ImportRun::query()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('does not block reads or cancellation when the product import concurrency limit is reached', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $pendingRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'pending.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'running.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_RUNNING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)->getJson('/api/v1/products/import/runs')
        ->assertOk();

    $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$pendingRun->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', ImportRun::STATUS_CANCELLED);
});

it('applies a specific throttle to heavy product import endpoints', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    RateLimiter::clear("product-import-heavy|{$admin->id}");

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $this->actingAs($admin)->postJson('/api/v1/products/import/validate-rows', [
            'rows' => [
                ['__row' => $attempt + 1, 'nombre' => "Producto {$attempt}"],
            ],
        ])->assertOk();
    }

    $this->actingAs($admin)->postJson('/api/v1/products/import/validate-rows', [
        'rows' => [
            ['__row' => 99, 'nombre' => 'Producto limitado'],
        ],
    ])->assertStatus(429);
});

it('nulls import run profile references when an import profile is deleted', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $profile = ImportProfile::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'name' => 'Perfil temporal',
        'mapping' => ['description' => 'nombre'],
        'mode' => ImportRun::MODE_CREATE_UPDATE,
    ]);

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'import_profile_id' => $profile->id,
        'original_filename' => 'profile.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_COMPLETED,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $profile->delete();

    expect($importRun->refresh()->import_profile_id)->toBeNull();
});

it('records product import activity for mutation operations', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->postJson('/api/v1/products/import/references/batch', [
        'categories' => ['Categoria auditable'],
        'brands' => ['Marca auditable'],
    ])->assertCreated();

    $importRun = ImportRun::query()->create([
        'user_id' => $admin->id,
        'entity' => 'products',
        'original_filename' => 'cancel.csv',
        'run_type' => ImportRun::TYPE_IMPORT,
        'mode' => ImportRun::MODE_CREATE_UPDATE,
        'status' => ImportRun::STATUS_PENDING,
        'results' => [],
        'errors' => [],
        'summary' => [],
    ]);

    $this->actingAs($admin)->postJson("/api/v1/products/import/runs/{$importRun->id}/cancel")
        ->assertOk();

    expect(DB::table('activity_log')->where('log_name', 'product_import')->where('event', 'references_batch_created')->exists())->toBeTrue()
        ->and(DB::table('activity_log')->where('log_name', 'product_import')->where('event', 'cancelled')->exists())->toBeTrue();
});
