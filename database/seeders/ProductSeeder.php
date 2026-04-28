<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\OemReference;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBatch;
use App\Models\ProductInventory;
use App\Models\ProductLine;
use App\Models\ProductMedia;
use App\Models\ProductPricing;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\ProductVehicleCompatibility;
use App\Models\ProductVin;
use App\Models\Supplier;
use App\Models\VehicleEngine;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->command?->info('Creando catalogo demo de productos completo...');

            $this->ensureBaseCatalog();

            $brands = Brand::query()->whereIn('name', [
                'ACDelco',
                'Bosch',
                'Brembo',
                'Denso',
                'Mann-Filter',
                'Monroe',
                'NGK',
                'Valeo',
            ])->get()->keyBy('name');

            $suppliers = Supplier::query()->whereIn('name', [
                'AutoPartes Central',
                'Distribuidora Norte',
                'Proveedor General',
            ])->get()->keyBy('name');

            $warehouses = Warehouse::query()->orderBy('id')->take(2)->get();
            $mainWarehouse = $warehouses->first();
            $secondaryWarehouse = $warehouses->get(1, $mainWarehouse);

            $categories = Category::query()->whereIn('slug', [
                'alternadores-arrancadores',
                'baterias',
                'bujias',
                'filtros-aceite',
                'pastillas-freno',
                'discos-freno',
                'amortiguadores',
                'bobinas-encendido',
            ])->get()->keyBy('slug');

            $lines = $this->productLines($brands);
            $engines = VehicleEngine::query()->with('model.make')->get();

            $createdProducts = collect($this->products($brands, $categories, $lines, $engines))
                ->mapWithKeys(function (array $definition) use ($suppliers, $warehouses, $mainWarehouse, $secondaryWarehouse) {
                    $product = $this->seedProduct($definition, $suppliers, $warehouses, $mainWarehouse, $secondaryWarehouse);

                    return [$product->sku_base => $product];
                });

            $this->seedAlternatives($createdProducts);

            $this->command?->info("Catalogo demo creado: {$createdProducts->count()} productos con variantes, precios, stock y metadatos.");
        });
    }

    private function ensureBaseCatalog(): void
    {
        $this->call([
            BrandSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            CategorySeeder::class,
            VehicleSeeder::class,
        ]);

        $this->ensureProductCategories();
        $this->ensureProductAttributes();
        $this->ensureWarehouses();

        Supplier::query()->firstOrCreate(
            ['name' => 'AutoPartes Central'],
            [
                'contact_name' => 'Compras AutoPartes',
                'email' => 'compras@autopartes-central.test',
                'phone' => '+507 300-1000',
                'city' => 'Panama',
                'country' => 'Panama',
                'payment_terms' => 'Net 30',
                'lead_time_days' => 5,
                'status' => 'active',
            ]
        );

        Supplier::query()->firstOrCreate(
            ['name' => 'Distribuidora Norte'],
            [
                'contact_name' => 'Ventas Norte',
                'email' => 'ventas@distribuidora-norte.test',
                'phone' => '+507 300-2000',
                'city' => 'Colon',
                'country' => 'Panama',
                'payment_terms' => 'Contado',
                'lead_time_days' => 8,
                'status' => 'active',
            ]
        );
    }

    private function ensureProductCategories(): void
    {
        $roots = [
            'sistema-electrico' => ['name' => 'Sistema Electrico', 'priority' => 1],
            'motor' => ['name' => 'Motor', 'priority' => 2],
            'frenos' => ['name' => 'Frenos', 'priority' => 3],
            'suspension-direccion' => ['name' => 'Suspension y Direccion', 'priority' => 4],
        ];

        $rootModels = [];

        foreach ($roots as $slug => $data) {
            $rootModels[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'description' => $data['name'],
                    'priority' => $data['priority'],
                ]
            );
        }

        $children = [
            'alternadores-arrancadores' => ['name' => 'Alternadores y Arrancadores', 'parent' => 'sistema-electrico', 'priority' => 1],
            'baterias' => ['name' => 'Baterias', 'parent' => 'sistema-electrico', 'priority' => 2],
            'bujias' => ['name' => 'Bujias', 'parent' => 'motor', 'priority' => 1],
            'bobinas-encendido' => ['name' => 'Bobinas de Encendido', 'parent' => 'motor', 'priority' => 2],
            'filtros-aceite' => ['name' => 'Filtros de Aceite', 'parent' => 'motor', 'priority' => 3],
            'pastillas-freno' => ['name' => 'Pastillas de Freno', 'parent' => 'frenos', 'priority' => 1],
            'discos-freno' => ['name' => 'Discos de Freno', 'parent' => 'frenos', 'priority' => 2],
            'amortiguadores' => ['name' => 'Amortiguadores', 'parent' => 'suspension-direccion', 'priority' => 1],
        ];

        foreach ($children as $slug => $data) {
            Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'parent_id' => $rootModels[$data['parent']]->id,
                    'description' => $data['name'],
                    'priority' => $data['priority'],
                ]
            );
        }
    }

    private function ensureProductAttributes(): void
    {
        foreach ([
            ['name' => 'Voltaje', 'data_type' => 'decimal', 'unit' => 'V'],
            ['name' => 'Amperaje', 'data_type' => 'decimal', 'unit' => 'A'],
            ['name' => 'Capacidad', 'data_type' => 'decimal', 'unit' => 'Ah'],
            ['name' => 'Material', 'data_type' => 'string', 'unit' => null],
            ['name' => 'Tipo de conector', 'data_type' => 'string', 'unit' => null],
            ['name' => 'Certificacion', 'data_type' => 'string', 'unit' => null],
            ['name' => 'Diametro', 'data_type' => 'decimal', 'unit' => 'mm'],
            ['name' => 'Largo extendido', 'data_type' => 'decimal', 'unit' => 'mm'],
        ] as $definition) {
            AttributeDefinition::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'data_type' => $definition['data_type'],
                    'unit' => $definition['unit'],
                    'applicable_categories' => null,
                    'is_required' => false,
                ]
            );
        }
    }

    private function ensureWarehouses(): void
    {
        Warehouse::query()->updateOrCreate(
            ['code' => 'WH-MAIN-01'],
            [
                'name' => 'Almacen Principal',
                'location' => 'Panama',
                'capacity' => 1000,
                'status' => 'active',
                'notes' => 'Almacen principal para demo',
            ]
        );

        Warehouse::query()->updateOrCreate(
            ['code' => 'WH-SEC-01'],
            [
                'name' => 'Almacen Secundario',
                'location' => 'Colon',
                'capacity' => 500,
                'status' => 'active',
                'notes' => 'Almacen secundario para demo',
            ]
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Brand>  $brands
     * @return array<string, ProductLine>
     */
    private function productLines($brands): array
    {
        return [
            'bosch-premium' => ProductLine::query()->updateOrCreate(
                ['brand_id' => $brands['Bosch']->id, 'name' => 'Premium'],
                ['description' => 'Linea premium para repuestos de alta rotacion', 'priority' => 1]
            ),
            'bosch-standard' => ProductLine::query()->updateOrCreate(
                ['brand_id' => $brands['Bosch']->id, 'name' => 'Standard'],
                ['description' => 'Linea estandar para mantenimiento general', 'priority' => 2]
            ),
            'brembo-oe' => ProductLine::query()->updateOrCreate(
                ['brand_id' => $brands['Brembo']->id, 'name' => 'OE Replacement'],
                ['description' => 'Reemplazo equivalente a equipo original', 'priority' => 1]
            ),
            'monroe-oespectrum' => ProductLine::query()->updateOrCreate(
                ['brand_id' => $brands['Monroe']->id, 'name' => 'OESpectrum'],
                ['description' => 'Amortiguacion para confort y control', 'priority' => 1]
            ),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Brand>  $brands
     * @param  \Illuminate\Support\Collection<string, Category>  $categories
     * @param  array<string, ProductLine>  $lines
     * @param  \Illuminate\Database\Eloquent\Collection<int, VehicleEngine>  $engines
     * @return array<int, array<string, mixed>>
     */
    private function products($brands, $categories, array $lines, $engines): array
    {
        return [
            [
                'sku_base' => 'ALT-12V-90A',
                'alternate_code' => 'ALT90A-12V',
                'name' => 'Alternador 12V 90A',
                'description' => 'Alternador para camionetas y utilitarios con polea multicanal y regulador integrado.',
                'category' => $categories['alternadores-arrancadores'],
                'extra_categories' => [$categories['baterias']],
                'line' => $lines['bosch-premium'],
                'batch_tracking' => true,
                'is_universal' => false,
                'attributes' => [
                    ['name' => 'Voltaje', 'value' => '12', 'unit' => 'V'],
                    ['name' => 'Amperaje', 'value' => '90', 'unit' => 'A'],
                    ['name' => 'Tipo de conector', 'value' => '2 pines', 'unit' => null],
                    ['name' => 'Polea', 'value' => '6 canales', 'unit' => null],
                ],
                'variants' => [
                    [
                        'brand' => $brands['Bosch'],
                        'sku' => 'ALT-12V-90A-BOS',
                        'upc' => '750100100001',
                        'default' => true,
                        'price' => ['cost_base' => 2100, 'cost_miscellaneous' => 120, 'base_price' => 3325, 'msrp' => 3890, 'max_discount' => 12],
                        'attributes' => [
                            ['name' => 'Certificacion', 'value' => 'ISO/TS 16949', 'unit' => null],
                        ],
                        'inventory' => [['warehouse' => 0, 'available' => 14, 'reserved' => 2, 'reorder' => 4, 'quantity' => 12, 'location' => 'A1-03']],
                    ],
                    [
                        'brand' => $brands['Valeo'],
                        'sku' => 'ALT-12V-90A-VAL',
                        'upc' => '750100100002',
                        'default' => false,
                        'price' => ['cost_base' => 1980, 'cost_miscellaneous' => 95, 'base_price' => 3150, 'msrp' => 3650, 'max_discount' => 10],
                        'inventory' => [['warehouse' => 1, 'available' => 7, 'reserved' => 1, 'reorder' => 3, 'quantity' => 8, 'location' => 'E2-11']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => 'F7DU-10300-AA', 'manufacturer' => 'Ford'],
                    ['oem_code' => '10464468', 'manufacturer' => 'GM'],
                ],
                'compatibility' => [
                    ['engine' => $this->engine($engines, 'F-150', '3.5L V6 EcoBoost'), 'notes' => 'Requiere polea 6 canales'],
                    ['engine' => $this->engine($engines, 'Silverado 1500', '5.3L V8'), 'notes' => 'Validar conector antes de instalar'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/alt-12v-90a.jpg', 'name' => 'alt-12v-90a.jpg', 'order' => 1],
                    ['category' => 'technical_sheet', 'file' => 'products/demo/alt-12v-90a-ficha.pdf', 'name' => 'alt-12v-90a-ficha.pdf', 'order' => 2],
                ],
                'batches' => [
                    ['batch_number' => 'ALT-2026-01', 'cost' => 2220, 'notes' => 'Primer lote demo'],
                ],
                'vins' => [
                    ['vin' => '1FTFW1EG5JFA00001', 'notes' => 'F-150 demo'],
                ],
            ],
            [
                'sku_base' => 'BRK-PAD-F-CER',
                'alternate_code' => 'PAD-FR-CER',
                'name' => 'Pastillas de freno delanteras ceramicas',
                'description' => 'Juego de pastillas delanteras de bajo ruido con compuesto ceramico.',
                'category' => $categories['pastillas-freno'],
                'extra_categories' => [$categories['discos-freno']],
                'line' => $lines['brembo-oe'],
                'batch_tracking' => false,
                'is_universal' => false,
                'attributes' => [
                    ['name' => 'Material', 'value' => 'Ceramica', 'unit' => null],
                    ['name' => 'Posicion', 'value' => 'Delantera', 'unit' => null],
                    ['name' => 'Incluye sensor', 'value' => 'Si', 'unit' => null],
                ],
                'variants' => [
                    [
                        'brand' => $brands['Brembo'],
                        'sku' => 'BRK-PAD-F-CER-BRE',
                        'upc' => '750100200001',
                        'default' => true,
                        'price' => ['cost_base' => 780, 'cost_miscellaneous' => 25, 'base_price' => 1295, 'msrp' => 1495, 'max_discount' => 8],
                        'attributes' => [['name' => 'Formula', 'value' => 'Low dust', 'unit' => null]],
                        'inventory' => [
                            ['warehouse' => 0, 'available' => 32, 'reserved' => 4, 'reorder' => 10, 'quantity' => 24, 'location' => 'B3-02'],
                            ['warehouse' => 1, 'available' => 18, 'reserved' => 1, 'reorder' => 8, 'quantity' => 18, 'location' => 'F1-05'],
                        ],
                    ],
                    [
                        'brand' => $brands['ACDelco'],
                        'sku' => 'BRK-PAD-F-CER-ACD',
                        'upc' => '750100200002',
                        'default' => false,
                        'price' => ['cost_base' => 690, 'cost_miscellaneous' => 20, 'base_price' => 1160, 'msrp' => 1350, 'max_discount' => 10],
                        'inventory' => [['warehouse' => 0, 'available' => 22, 'reserved' => 3, 'reorder' => 10, 'quantity' => 24, 'location' => 'B3-04']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => '04465-02410', 'manufacturer' => 'Toyota'],
                    ['oem_code' => 'D1060-3TA0A', 'manufacturer' => 'Nissan'],
                ],
                'compatibility' => [
                    ['engine' => $this->engine($engines, 'Corolla', '1.8L I4'), 'notes' => 'Delanteras'],
                    ['engine' => $this->engine($engines, 'Sentra', '1.8L I4'), 'notes' => 'Delanteras'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/brk-pad-f-cer.jpg', 'name' => 'brk-pad-f-cer.jpg', 'order' => 1],
                ],
            ],
            [
                'sku_base' => 'SPK-IR-4PK',
                'alternate_code' => 'IRIDIUM-4',
                'name' => 'Bujias iridium set de 4',
                'description' => 'Set de cuatro bujias de iridio para mayor estabilidad de chispa y vida util.',
                'category' => $categories['bujias'],
                'extra_categories' => [$categories['bobinas-encendido']],
                'line' => $lines['bosch-standard'],
                'batch_tracking' => true,
                'is_universal' => false,
                'attributes' => [
                    ['name' => 'Material', 'value' => 'Iridio', 'unit' => null],
                    ['name' => 'Cantidad por empaque', 'value' => '4', 'unit' => 'pz'],
                    ['name' => 'Rosca', 'value' => 'M14x1.25', 'unit' => null],
                ],
                'variants' => [
                    [
                        'brand' => $brands['NGK'],
                        'sku' => 'SPK-IR-4PK-NGK',
                        'upc' => '750100300001',
                        'default' => true,
                        'price' => ['cost_base' => 505, 'cost_miscellaneous' => 18, 'base_price' => 850, 'msrp' => 990, 'max_discount' => 5],
                        'inventory' => [['warehouse' => 0, 'available' => 80, 'reserved' => 6, 'reorder' => 25, 'quantity' => 60, 'location' => 'C1-01']],
                    ],
                    [
                        'brand' => $brands['Denso'],
                        'sku' => 'SPK-IR-4PK-DEN',
                        'upc' => '750100300002',
                        'default' => false,
                        'price' => ['cost_base' => 530, 'cost_miscellaneous' => 18, 'base_price' => 895, 'msrp' => 1040, 'max_discount' => 5],
                        'inventory' => [['warehouse' => 1, 'available' => 45, 'reserved' => 2, 'reorder' => 20, 'quantity' => 50, 'location' => 'G2-07']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => 'LZFR5CIX-11', 'manufacturer' => 'NGK'],
                    ['oem_code' => 'FK20HR11', 'manufacturer' => 'Denso'],
                ],
                'compatibility' => [
                    ['engine' => $this->engine($engines, 'Civic', '1.5L Turbo I4'), 'notes' => 'Calibracion 0.8 mm'],
                    ['engine' => $this->engine($engines, 'Camry', '2.5L I4'), 'notes' => 'Set completo'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/spk-ir-4pk.jpg', 'name' => 'spk-ir-4pk.jpg', 'order' => 1],
                ],
                'batches' => [
                    ['batch_number' => 'SPK-2026-02', 'cost' => 523, 'notes' => 'Empaque de 4 piezas'],
                ],
            ],
            [
                'sku_base' => 'FIL-OIL-SYN',
                'alternate_code' => 'OIL-FLTR-SYN',
                'name' => 'Filtro de aceite sintetico',
                'description' => 'Filtro de aceite de alta eficiencia para intervalos extendidos.',
                'category' => $categories['filtros-aceite'],
                'extra_categories' => [],
                'line' => null,
                'batch_tracking' => false,
                'is_universal' => true,
                'attributes' => [
                    ['name' => 'Material', 'value' => 'Celulosa sintetica', 'unit' => null],
                    ['name' => 'Diametro', 'value' => '76', 'unit' => 'mm'],
                    ['name' => 'Rosca', 'value' => '3/4-16 UNF', 'unit' => null],
                ],
                'variants' => [
                    [
                        'brand' => $brands['Mann-Filter'],
                        'sku' => 'FIL-OIL-SYN-MAN',
                        'upc' => '750100400001',
                        'default' => true,
                        'price' => ['cost_base' => 92, 'cost_miscellaneous' => 4, 'base_price' => 180, 'msrp' => 220, 'max_discount' => 12],
                        'inventory' => [
                            ['warehouse' => 0, 'available' => 140, 'reserved' => 12, 'reorder' => 40, 'quantity' => 100, 'location' => 'D1-10'],
                            ['warehouse' => 1, 'available' => 70, 'reserved' => 5, 'reorder' => 30, 'quantity' => 80, 'location' => 'H3-01'],
                        ],
                    ],
                    [
                        'brand' => $brands['Bosch'],
                        'sku' => 'FIL-OIL-SYN-BOS',
                        'upc' => '750100400002',
                        'default' => false,
                        'price' => ['cost_base' => 105, 'cost_miscellaneous' => 4, 'base_price' => 195, 'msrp' => 235, 'max_discount' => 12],
                        'inventory' => [['warehouse' => 0, 'available' => 90, 'reserved' => 8, 'reorder' => 35, 'quantity' => 100, 'location' => 'D1-12']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => '90915-YZZF2', 'manufacturer' => 'Toyota'],
                    ['oem_code' => 'FL-500S', 'manufacturer' => 'Ford'],
                ],
                'compatibility' => [
                    ['engine' => null, 'notes' => 'Producto universal; confirmar rosca y diametro'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/fil-oil-syn.jpg', 'name' => 'fil-oil-syn.jpg', 'order' => 1],
                    ['category' => 'diagram', 'file' => 'products/demo/fil-oil-syn-diagrama.jpg', 'name' => 'fil-oil-syn-diagrama.jpg', 'order' => 2],
                ],
            ],
            [
                'sku_base' => 'BAT-AGM-75AH',
                'alternate_code' => 'AGM75-12V',
                'name' => 'Bateria AGM 12V 75Ah',
                'description' => 'Bateria AGM libre de mantenimiento para vehiculos con demanda electrica alta.',
                'category' => $categories['baterias'],
                'extra_categories' => [$categories['alternadores-arrancadores']],
                'line' => $lines['bosch-premium'],
                'batch_tracking' => true,
                'is_universal' => true,
                'attributes' => [
                    ['name' => 'Voltaje', 'value' => '12', 'unit' => 'V'],
                    ['name' => 'Capacidad', 'value' => '75', 'unit' => 'Ah'],
                    ['name' => 'CCA', 'value' => '720', 'unit' => 'A'],
                    ['name' => 'Tecnologia', 'value' => 'AGM', 'unit' => null],
                ],
                'variants' => [
                    [
                        'brand' => $brands['Bosch'],
                        'sku' => 'BAT-AGM-75AH-BOS',
                        'upc' => '750100500001',
                        'default' => true,
                        'price' => ['cost_base' => 1680, 'cost_miscellaneous' => 65, 'base_price' => 2795, 'msrp' => 3350, 'max_discount' => 8],
                        'inventory' => [['warehouse' => 0, 'available' => 20, 'reserved' => 2, 'reorder' => 6, 'quantity' => 15, 'location' => 'A4-01']],
                    ],
                    [
                        'brand' => $brands['ACDelco'],
                        'sku' => 'BAT-AGM-75AH-ACD',
                        'upc' => '750100500002',
                        'default' => false,
                        'price' => ['cost_base' => 1580, 'cost_miscellaneous' => 60, 'base_price' => 2630, 'msrp' => 3150, 'max_discount' => 8],
                        'inventory' => [['warehouse' => 1, 'available' => 12, 'reserved' => 1, 'reorder' => 5, 'quantity' => 12, 'location' => 'E1-01']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => 'LN3 AGM', 'manufacturer' => 'DIN'],
                    ['oem_code' => '48AGM', 'manufacturer' => 'BCI'],
                ],
                'compatibility' => [
                    ['engine' => null, 'notes' => 'Universal por dimensiones y polaridad'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/bat-agm-75ah.jpg', 'name' => 'bat-agm-75ah.jpg', 'order' => 1],
                ],
                'batches' => [
                    ['batch_number' => 'BAT-2026-01', 'cost' => 1745, 'notes' => 'Lote con garantia de 18 meses'],
                ],
            ],
            [
                'sku_base' => 'SHK-GAS-FRT',
                'alternate_code' => 'AMORT-GAS-F',
                'name' => 'Amortiguador delantero a gas',
                'description' => 'Amortiguador delantero presurizado para mejor control en carretera.',
                'category' => $categories['amortiguadores'],
                'extra_categories' => [],
                'line' => $lines['monroe-oespectrum'],
                'batch_tracking' => false,
                'is_universal' => false,
                'attributes' => [
                    ['name' => 'Posicion', 'value' => 'Delantera', 'unit' => null],
                    ['name' => 'Tecnologia', 'value' => 'Gas presurizado', 'unit' => null],
                    ['name' => 'Largo extendido', 'value' => '520', 'unit' => 'mm'],
                ],
                'variants' => [
                    [
                        'brand' => $brands['Monroe'],
                        'sku' => 'SHK-GAS-FRT-MON',
                        'upc' => '750100600001',
                        'default' => true,
                        'price' => ['cost_base' => 640, 'cost_miscellaneous' => 30, 'base_price' => 1085, 'msrp' => 1280, 'max_discount' => 10],
                        'inventory' => [['warehouse' => 0, 'available' => 28, 'reserved' => 3, 'reorder' => 8, 'quantity' => 20, 'location' => 'S2-08']],
                    ],
                    [
                        'brand' => $brands['ACDelco'],
                        'sku' => 'SHK-GAS-FRT-ACD',
                        'upc' => '750100600002',
                        'default' => false,
                        'price' => ['cost_base' => 610, 'cost_miscellaneous' => 28, 'base_price' => 1025, 'msrp' => 1200, 'max_discount' => 10],
                        'inventory' => [['warehouse' => 1, 'available' => 14, 'reserved' => 1, 'reorder' => 6, 'quantity' => 12, 'location' => 'S1-03']],
                    ],
                ],
                'oem' => [
                    ['oem_code' => '48510-02A10', 'manufacturer' => 'Toyota'],
                    ['oem_code' => 'E9DZ-18124-A', 'manufacturer' => 'Ford'],
                ],
                'compatibility' => [
                    ['engine' => $this->engine($engines, 'Corolla', '1.8L I4'), 'notes' => 'Eje delantero'],
                    ['engine' => $this->engine($engines, 'Fusion', '2.5L I4'), 'notes' => 'Eje delantero'],
                ],
                'media' => [
                    ['category' => 'photo', 'file' => 'products/demo/shk-gas-frt.jpg', 'name' => 'shk-gas-frt.jpg', 'order' => 1],
                ],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Supplier>  $suppliers
     * @param  \Illuminate\Database\Eloquent\Collection<int, Warehouse>  $warehouses
     */
    private function seedProduct(array $definition, $suppliers, $warehouses, Warehouse $mainWarehouse, Warehouse $secondaryWarehouse): Product
    {
        $product = Product::query()->updateOrCreate(
            ['sku_base' => $definition['sku_base']],
            [
                'alternate_code' => $definition['alternate_code'],
                'category_id' => $definition['category']?->id,
                'product_line_id' => $definition['line']?->id,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => 'active',
                'batch_tracking' => $definition['batch_tracking'],
                'is_universal' => $definition['is_universal'],
                'image_url' => collect($definition['media'] ?? [])->first()['file'] ?? null,
            ]
        );

        $product->extraCategories()->sync(
            collect($definition['extra_categories'] ?? [])->filter()->pluck('id')->all()
        );

        $this->replaceProductAttributes($product, $definition['attributes'] ?? []);
        $this->replaceOemReferences($product, $definition['oem'] ?? []);
        $this->replaceMedia($product, $definition['media'] ?? []);
        $this->replaceCompatibility($product, $definition['compatibility'] ?? []);
        $this->replaceBatches($product, $definition['batches'] ?? [], $suppliers->first());
        $this->replaceVins($product, $definition['vins'] ?? []);

        foreach ($definition['variants'] as $variantDefinition) {
            $variant = $this->seedVariant($product, $variantDefinition, $warehouses, $mainWarehouse, $secondaryWarehouse);
            $this->seedProductSupplier($product, $suppliers, $variantDefinition['price']['cost_base']);
        }

        return $product;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Warehouse>  $warehouses
     */
    private function seedVariant(Product $product, array $definition, $warehouses, Warehouse $mainWarehouse, Warehouse $secondaryWarehouse): ProductVariant
    {
        if ($definition['default']) {
            ProductVariant::query()
                ->where('product_id', $product->id)
                ->update(['is_default' => false]);
        }

        $variant = ProductVariant::query()->updateOrCreate(
            ['product_id' => $product->id, 'brand_id' => $definition['brand']->id],
            [
                'sku' => $definition['sku'],
                'upc' => $definition['upc'],
                'status' => 'active',
                'is_default' => $definition['default'],
            ]
        );

        ProductPricing::query()->updateOrCreate(
            ['product_id' => $product->id, 'variant_id' => $variant->id],
            [
                'cost_base' => $definition['price']['cost_base'],
                'cost_miscellaneous' => $definition['price']['cost_miscellaneous'],
                'cost' => $definition['price']['cost_base'] + $definition['price']['cost_miscellaneous'],
                'cost_tax_rate' => 7,
                'sale_tax_rate' => 7,
                'tax_rate' => 7,
                'tax_type' => 'standard',
                'base_price' => $definition['price']['base_price'],
                'msrp' => $definition['price']['msrp'],
                'margin_percentage' => round((($definition['price']['base_price'] - ($definition['price']['cost_base'] + $definition['price']['cost_miscellaneous'])) / $definition['price']['base_price']) * 100, 2),
                'max_discount_percentage' => $definition['price']['max_discount'],
                'pricing_formula' => 'costo * 1.45',
            ]
        );

        ProductAttribute::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->delete();

        foreach ($definition['attributes'] ?? [] as $attribute) {
            $variant->attributes()->create([
                'product_id' => $product->id,
                'name' => $attribute['name'],
                'value' => $attribute['value'],
                'unit' => $attribute['unit'],
            ]);
        }

        foreach ($definition['inventory'] as $inventory) {
            $warehouse = $warehouses->get($inventory['warehouse'])
                ?? ($inventory['warehouse'] === 1 ? $secondaryWarehouse : $mainWarehouse);

            ProductInventory::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'quantity_available' => $inventory['available'],
                    'quantity_reserved' => $inventory['reserved'],
                    'reorder_point' => $inventory['reorder'],
                    'reorder_quantity' => $inventory['quantity'],
                    'location_in_warehouse' => $inventory['location'],
                    'last_restocked_at' => now()->subDays(10 + $inventory['warehouse']),
                ]
            );
        }

        return $variant;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Supplier>  $suppliers
     */
    private function seedProductSupplier(Product $product, $suppliers, float $cost): void
    {
        $preferredSupplier = $suppliers['AutoPartes Central'] ?? $suppliers->first();
        $backupSupplier = $suppliers['Distribuidora Norte'] ?? $suppliers->last();

        ProductSupplier::query()->updateOrCreate(
            ['product_id' => $product->id, 'supplier_id' => $preferredSupplier->id],
            [
                'supplier_cost' => $cost,
                'lead_time_days' => 5,
                'minimum_order_quantity' => 4,
                'is_preferred' => true,
            ]
        );

        if ($backupSupplier && $backupSupplier->id !== $preferredSupplier->id) {
            ProductSupplier::query()->updateOrCreate(
                ['product_id' => $product->id, 'supplier_id' => $backupSupplier->id],
                [
                    'supplier_cost' => round($cost * 1.04, 2),
                    'lead_time_days' => 8,
                    'minimum_order_quantity' => 2,
                    'is_preferred' => false,
                ]
            );
        }
    }

    private function replaceProductAttributes(Product $product, array $attributes): void
    {
        ProductAttribute::query()
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->delete();

        foreach ($attributes as $attribute) {
            $product->attributes()->create([
                'name' => $attribute['name'],
                'value' => $attribute['value'],
                'unit' => $attribute['unit'],
            ]);
        }
    }

    private function replaceOemReferences(Product $product, array $references): void
    {
        OemReference::query()->where('product_id', $product->id)->delete();

        foreach ($references as $reference) {
            $product->oemReferences()->create($reference);
        }
    }

    private function replaceMedia(Product $product, array $mediaItems): void
    {
        ProductMedia::query()->where('product_id', $product->id)->delete();

        foreach ($mediaItems as $media) {
            $product->media()->create([
                'media_type' => Str::endsWith($media['file'], '.pdf') ? 'pdf' : 'image',
                'category' => $media['category'],
                'file_path' => $media['file'],
                'original_filename' => $media['name'],
                'file_size' => 128000,
                'display_order' => $media['order'],
            ]);
        }
    }

    private function replaceCompatibility(Product $product, array $records): void
    {
        ProductVehicleCompatibility::query()->where('product_id', $product->id)->delete();

        foreach ($records as $record) {
            ProductVehicleCompatibility::query()->create([
                'product_id' => $product->id,
                'engine_id' => $record['engine']?->id,
                'chassis_id' => null,
                'notes' => $record['notes'],
                'all_engines' => $record['engine'] === null,
                'all_chassis' => false,
            ]);
        }
    }

    private function replaceBatches(Product $product, array $batches, ?Supplier $supplier): void
    {
        ProductBatch::withTrashed()->where('product_id', $product->id)->forceDelete();

        foreach ($batches as $batch) {
            ProductBatch::query()->create([
                'product_id' => $product->id,
                'batch_number' => $batch['batch_number'],
                'manufacturing_date' => now()->subMonths(2)->toDateString(),
                'expiry_date' => now()->addYears(2)->toDateString(),
                'cost' => $batch['cost'],
                'supplier_id' => $supplier?->id,
                'notes' => $batch['notes'],
            ]);
        }
    }

    private function replaceVins(Product $product, array $vins): void
    {
        ProductVin::query()->where('product_id', $product->id)->delete();

        foreach ($vins as $vin) {
            ProductVin::query()->create([
                'product_id' => $product->id,
                'vin' => $vin['vin'],
                'notes' => $vin['notes'],
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Product>  $products
     */
    private function seedAlternatives($products): void
    {
        $pairs = [
            ['BRK-PAD-F-CER', 'SHK-GAS-FRT', 'compatible'],
            ['FIL-OIL-SYN', 'SPK-IR-4PK', 'compatible'],
            ['ALT-12V-90A', 'BAT-AGM-75AH', 'upgrade'],
        ];

        foreach ($pairs as [$primarySku, $alternativeSku, $type]) {
            $primary = $products[$primarySku] ?? null;
            $alternative = $products[$alternativeSku] ?? null;

            if (! $primary || ! $alternative) {
                continue;
            }

            DB::table('alternative_products')->updateOrInsert(
                [
                    'primary_product_id' => $primary->id,
                    'alternative_product_id' => $alternative->id,
                ],
                [
                    'relationship_type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, VehicleEngine>  $engines
     */
    private function engine($engines, string $model, string $code): ?VehicleEngine
    {
        return $engines->first(function (VehicleEngine $engine) use ($model, $code) {
            return $engine->engine_code === $code
                && $engine->model?->name === $model;
        });
    }
}
