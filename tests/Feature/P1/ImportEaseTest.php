<?php

use App\Exports\BrandImportTemplate;
use App\Imports\BrandImport;
use App\Imports\CategoryImport;
use App\Imports\ProductImport;
use App\Imports\SupplierImport;
use App\Imports\VehicleImport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports category default attributes from simple lists and keeps json legacy support', function () {
    $import = new CategoryImport;

    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Frenos',
            'atributos_default' => 'color:rojo,largo:15cm,posicion:requerido',
        ],
        [
            '__row' => 3,
            'nombre' => 'Filtros',
            'atributos_default' => '[{"name":"medida","required":true}]',
        ],
    ]);

    expect($import->successCount)->toBe(2)
        ->and($import->errorCount)->toBe(0);

    $frenos = Category::where('name', 'Frenos')->firstOrFail();
    $filtros = Category::where('name', 'Filtros')->firstOrFail();

    expect($frenos->default_attributes[0])->toMatchArray([
        'name' => 'color',
        'default_value' => 'rojo',
    ])
        ->and($frenos->default_attributes[1])->toMatchArray([
            'name' => 'largo',
            'default_value' => '15',
            'unit' => 'cm',
        ])
        ->and($frenos->default_attributes[2])->toMatchArray([
            'name' => 'posicion',
            'required' => true,
        ])
        ->and($filtros->default_attributes[0])->toMatchArray([
            'name' => 'medida',
            'required' => true,
        ]);
});

it('rejects duplicate explicit category slugs before hitting database constraints', function () {
    Category::create([
        'name' => 'Categoria con slug',
        'slug' => 'ddd',
    ]);

    Category::create([
        'name' => 'Filtrosaaa',
        'slug' => 'filtrosaaa',
    ]);

    $import = new CategoryImport;
    $import->execute([
        [
            '__row' => 3,
            'nombre' => 'Filtrosaaa',
            'slug' => 'ddd',
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1)
        ->and($import->errors[0]['error'])->toContain("El slug 'ddd' ya existe");

    $this->assertDatabaseHas('categories', [
        'name' => 'Filtrosaaa',
        'slug' => 'filtrosaaa',
    ]);
});

it('imports products with simple direct columns for attributes and extra categories only', function () {
    Category::create([
        'name' => 'Encendido',
        'slug' => 'encendido',
    ]);

    $import = new ProductImport(app(ProductService::class));
    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Bujia import facil',
            'sku' => 'IMP-EASY',
            'categoria' => 'Encendido',
            'categorias_extra' => 'Motor, Servicio',
            'atributos' => 'color:rojo,largo:15cm',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $product = Product::where('sku_base', 'IMP-EASY')->firstOrFail();

    $this->assertDatabaseHas('categories', ['name' => 'Motor']);
    $this->assertDatabaseHas('categories', ['name' => 'Servicio']);
    $this->assertDatabaseHas('product_attributes', [
        'product_id' => $product->id,
        'variant_id' => null,
        'name' => 'largo',
        'value' => '15',
        'unit' => 'cm',
    ]);

    expect($product->variants()->count())->toBe(0);
});

it('rolls back partial work when an import row fails during processing', function () {
    $import = new ProductImport(app(ProductService::class));

    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Producto rollback',
            'sku' => 'ROLLBACK-1',
            'categoria' => 'Categoria Rollback',
            'categorias_extra' => '999999',
        ],
    ]);

    expect($import->successCount)->toBe(0)
        ->and($import->errorCount)->toBe(1);

    $this->assertDatabaseMissing('categories', ['name' => 'Categoria Rollback']);
    $this->assertDatabaseMissing('products', ['sku_base' => 'ROLLBACK-1']);
});

it('imports brands without prefix and links suppliers from comma lists', function () {
    $import = new BrandImport;

    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Marca Facil',
            'estado' => 'activo',
            'proveedores' => 'Proveedor Uno, Proveedor Dos',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and((new BrandImportTemplate)->headings())->not->toContain('prefijo');

    $brand = Brand::where('name', 'Marca Facil')->firstOrFail();

    expect($brand->status)->toBe('active')
        ->and($brand->suppliers()->where('name', 'Proveedor Uno')->exists())->toBeTrue()
        ->and($brand->suppliers()->where('name', 'Proveedor Dos')->exists())->toBeTrue();
});

it('imports supplier brand links from comma lists', function () {
    $import = new SupplierImport;

    $import->execute([
        [
            '__row' => 2,
            'nombre' => 'Proveedor Facil',
            'email' => 'facil@example.test',
            'estado' => 'inactivo',
            'marcas' => 'Bosch, ACDelco',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $supplier = Supplier::where('name', 'Proveedor Facil')->firstOrFail();

    expect($supplier->status)->toBe('inactive')
        ->and($supplier->brands()->where('name', 'Bosch')->exists())->toBeTrue()
        ->and($supplier->brands()->where('name', 'ACDelco')->exists())->toBeTrue();
});

it('imports vehicle engine metadata with closed fuel catalog normalization', function () {
    $import = new VehicleImport;

    $import->execute([
        [
            '__row' => 2,
            'make' => 'TOYOTA',
            'model' => 'YARIS',
            'year' => '2000-2005',
            'generation' => 'P10',
            'code' => 'P10',
            'engine' => '1NZFE',
            'displacement' => '1.5L',
            'fuel_type' => 'gasolina',
            'aspiration' => 'Natural',
        ],
    ]);

    expect($import->successCount)->toBe(1)
        ->and($import->errorCount)->toBe(0);

    $model = VehicleModel::where('name', 'YARIS')->firstOrFail();
    $engine = VehicleEngine::where('engine_code', '1NZFE')->firstOrFail();

    expect($model->generation)->toBe('P10')
        ->and($engine->fuel_type)->toBe('Gasoline')
        ->and($engine->displacement)->toBe('1.5L')
        ->and($engine->aspiration)->toBe('Natural');
});
