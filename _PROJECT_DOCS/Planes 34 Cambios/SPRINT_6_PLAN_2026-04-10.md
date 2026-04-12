# Sprint 6: Features Nuevos — Plan de Implementación

**Fecha**: 2026-04-10
**Tareas**: 3 (#20, #23d, #34)
**Estimación total**: ~29h
**Orden**: 6.1 → 6.2 → 6.3

---

## Task 6.1: Artículos Misceláneos en Cotización y POS (#20) — ~12h, Riesgo ALTO

**Objetivo**: Permitir agregar líneas personalizadas (sin producto del catálogo) en cotizaciones y órdenes de venta.

### Backend

#### Migración — `2026_04_11_000001_add_custom_line_support_to_order_and_quote_items.php`

```php
// sales_order_items
Schema::table('sales_order_items', function (Blueprint $table) {
    $table->boolean('is_custom_line')->default(false)->after('notes');
    $table->string('custom_description')->nullable()->after('is_custom_line');
});
// Hacer product_id nullable
Schema::table('sales_order_items', function (Blueprint $table) {
    $table->foreignId('product_id')->nullable()->change();
});

// sales_quote_items
Schema::table('sales_quote_items', function (Blueprint $table) {
    $table->boolean('is_custom_line')->default(false)->after('notes');
    $table->string('custom_description')->nullable()->after('is_custom_line');
});
Schema::table('sales_quote_items', function (Blueprint $table) {
    $table->foreignId('product_id')->nullable()->change();
});
```

> **Nota**: Requiere `doctrine/dbal` para `->change()`. Verificar que está instalado.

#### Modelos

**`app/Models/SalesOrderItem.php`**:
- Agregar a `$fillable`: `'is_custom_line'`, `'custom_description'`
- Agregar a `$casts`: `'is_custom_line' => 'boolean'`
- Modificar accessor `getProductNameAttribute()`: retornar `custom_description` si `is_custom_line`
- Modificar accessor `getProductSkuAttribute()`: retornar `'MISC'` si `is_custom_line`

**`app/Models/SalesQuoteItem.php`**:
- Mismos cambios que SalesOrderItem

#### Controladores — Validación

**`app/Http/Controllers/Api/SalesOrderController.php`** — `store()`:
- Cambiar validación de items:
```php
'items.*.product_id'          => 'required_without:items.*.is_custom_line|nullable|exists:products,id',
'items.*.is_custom_line'      => 'nullable|boolean',
'items.*.custom_description'  => 'required_if:items.*.is_custom_line,true|nullable|string|max:255',
```

**`app/Http/Controllers/Api/SalesQuoteController.php`** — `store()` y `storeFromPos()`:
- Misma validación condicional que SalesOrderController

#### Servicios — Lógica de inventario

**`app/Services/SalesOrderService.php`** — `create()` (líneas 129-165):
- Envolver reserva de stock en condición:
```php
foreach ($order->items as $item) {
    if ($item->is_custom_line) continue; // ← SKIP inventory for custom lines
    // ... existing stock reservation logic
}
```

**`app/Models/SalesOrder.php`** — `confirm()` (líneas 245-295):
- Envolver creación de InventoryMovement en condición:
```php
foreach ($this->items as $item) {
    if ($item->is_custom_line) continue; // ← SKIP movement for custom lines
    // ... existing movement/deduction logic
}
```

**`app/Services/SalesOrderService.php`** — `cancel()` (si existe lógica de devolución de stock):
- Mismo guard: `if ($item->is_custom_line) continue;`

**`app/Http/Controllers/Api/SalesQuoteController.php`** — `validateStockForQuoteItems()`:
- Agregar guard al inicio del foreach:
```php
foreach ($items as $item) {
    if (!empty($item['is_custom_line'])) continue; // ← SKIP stock check
    // ... existing validation
}
```

### Frontend

#### Tipos — `pos-frontend/resources/js/types/`

**`salesOrder.ts`** (o donde esté SalesOrderItem):
- Agregar: `is_custom_line?: boolean`, `custom_description?: string`

**`salesQuote.ts`**:
- Agregar: `is_custom_line?: boolean`, `custom_description?: string`

#### Composable POS — `pos-frontend/resources/js/composables/usePOS.ts`

**Interfaz `CartItem`** (líneas 9-30):
- Agregar campos opcionales:
```ts
is_custom_line?: boolean
custom_description?: string
```

**Nueva función `addCustomLine()`**:
```ts
function addCustomLine(description: string, price: number, quantity: number = 1) {
    const customItem: CartItem = {
        product_id: 0,        // placeholder, backend acepta null
        product_name: description,
        product_sku: 'MISC',
        variant_id: null,
        quantity,
        unit_price: price,
        discount_percent: 0,
        discount_amount: 0,
        tax_percent: 0,
        tax_amount: 0,
        is_custom_line: true,
        custom_description: description,
        // ... otros campos con defaults
    }
    cart.value.push(customItem)
}
```

**`processSale()`** (líneas 535-545):
- En el mapeo de items, incluir `is_custom_line` y `custom_description`
- Si `is_custom_line`, enviar `product_id: null` (no 0)

#### Componente POS — `pos-frontend/resources/js/components/POS/ProductTable.vue`

- En filas de items custom: mostrar `custom_description` en lugar de product name
- SKU muestra "MISC" (viene del CartItem)
- Permitir edición inline de `custom_description` y `unit_price` para líneas custom

#### Página POS — `pos-frontend/resources/js/pages/POS/Index.vue`

- Agregar botón "Artículo Misceláneo" (o "Línea Personalizada") junto al buscador
- Al hacer clic: mini-modal o inline con campos: Descripción, Precio, Cantidad
- Al confirmar: llama `addCustomLine(desc, price, qty)`

#### Cotizaciones — `pos-frontend/resources/js/pages/SalesQuotes/Create.vue`

- Agregar mismo botón de artículo misceláneo si existe formulario de items

#### Vista de detalle — Show pages

- `SalesOrders/Show.vue` y `SalesQuotes/Show.vue`: renderizar `custom_description` cuando `is_custom_line === true`

### Archivos críticos a modificar

| Archivo | Cambio |
|---------|--------|
| Nueva migración | `is_custom_line`, `custom_description`, `product_id` nullable |
| `SalesOrderItem.php` | fillable, casts, accessors |
| `SalesQuoteItem.php` | fillable, casts, accessors |
| `SalesOrderController.php` | validación condicional |
| `SalesQuoteController.php` | validación condicional + stock check guard |
| `SalesOrderService.php` | skip custom lines en reserva/cancelación |
| `SalesOrder.php` | skip custom lines en confirm() |
| `usePOS.ts` | CartItem interface, addCustomLine(), processSale() |
| `ProductTable.vue` | render custom lines |
| `POS/Index.vue` | botón artículo misceláneo |
| `SalesOrders/Show.vue` | render custom description |
| `SalesQuotes/Show.vue` | render custom description |

---

## Task 6.2: Borrar Data para Empezar de 0 (#23d) — ~8h, Riesgo MEDIO

**Objetivo**: Comando y endpoint para limpiar toda la data transaccional y re-seedear con datos esenciales. Ya existe `EssentialSeeder` con data primordial y `BackupDatabase` command.

### Backend

#### Comando Artisan — `app/Console/Commands/ClearTransactionalData.php` (NUEVO)

```php
class ClearTransactionalData extends Command
{
    protected $signature = 'app:clear-data {--force : Skip confirmation} {--no-backup : Skip backup}';
    protected $description = 'Clear all transactional data and re-seed essentials';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('⚠️ ESTO BORRARÁ TODOS LOS DATOS TRANSACCIONALES. ¿Continuar?')) {
                return 1;
            }
        }

        // 1. Backup (unless --no-backup)
        if (!$this->option('no-backup')) {
            $this->call('db:backup');
            $this->info('Backup creado.');
        }

        // 2. Disable FK checks
        DB::statement('SET CONSTRAINTS ALL DEFERRED'); // PostgreSQL
        // o DB::statement('SET FOREIGN_KEY_CHECKS=0'); // MySQL

        // 3. Truncar tablas transaccionales (orden FK-safe)
        $tables = [
            // Movimientos e inventario
            'inventory_movements',
            'product_inventories',
            'product_batches',
            // Ventas
            'sales_order_items',
            'sales_order_payments',
            'sales_orders',
            // Cotizaciones
            'sales_quote_items',
            'sales_quotes',
            // Compras
            'purchase_order_items',
            'purchase_orders',
            // Warehouse queue
            'warehouse_queue_items',
            'warehouse_queues',
            // Cuentas por pagar/cobrar
            'accounts_payable',
            'accounts_receivable',
            // Secuencias (reiniciar contadores)
            'brand_sequences',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                // O para PostgreSQL: DB::statement("TRUNCATE TABLE {$table} CASCADE");
            }
        }

        // 4. Re-enable FK checks
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // 5. Re-seed esenciales
        $this->call('db:seed', ['--class' => 'EssentialSeeder']);

        // 6. Reset secuencias de auto-increment si PostgreSQL
        // (opcional, depende del driver)

        $this->info('✅ Datos transaccionales borrados. Data esencial re-seeded.');
        return 0;
    }
}
```

> **Nota**: Verificar nombres exactos de tablas con `php artisan db:show` o Schema. La lista de arriba es estimada — revisar el schema real.

#### Endpoint API — `app/Http/Controllers/Api/DataResetController.php` (NUEVO)

```php
class DataResetController extends Controller
{
    public function reset(Request $request)
    {
        // Solo admin
        if ($request->user()->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'password' => 'required|string',
            'confirmation_text' => 'required|in:REINICIAR DATOS',
        ]);

        // Verificar contraseña del usuario actual
        if (!Hash::check($validated['password'], $request->user()->password)) {
            return response()->json(['success' => false, 'message' => 'Contraseña incorrecta'], 422);
        }

        try {
            Artisan::call('app:clear-data', ['--force' => true]);
            return response()->json([
                'success' => true,
                'message' => 'Datos reiniciados correctamente. Se creó un backup previo.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
```

#### Rutas — `routes/api.php` o nuevo `routes/api_data_reset.php`

```php
Route::post('/data/reset', [DataResetController::class, 'reset'])
    ->middleware('role:admin');
```

### Frontend

#### Página Settings — Sección en `pos-frontend/resources/js/pages/settings/Store.vue`

Agregar sección "Zona de Peligro" al final de la página:

```html
<div class="border-2 border-red-300 rounded-lg p-6 mt-8">
    <h3 class="text-lg font-bold text-red-600">Zona de Peligro</h3>
    <p class="text-sm text-gray-600 mt-2">
        Reiniciar todos los datos transaccionales (ventas, compras, inventario, cotizaciones).
        Se preservan: productos, categorías, marcas, proveedores, usuarios, configuraciones.
        Se crea un backup automático antes de borrar.
    </p>

    <button @click="showResetModal = true" class="mt-4 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
        Reiniciar Datos
    </button>
</div>
```

**Modal de confirmación** (doble seguridad):
1. Input: contraseña del usuario
2. Input: escribir "REINICIAR DATOS" textualmente
3. Botón deshabilitado hasta que ambos campos estén llenos
4. Al confirmar: POST `/api/data/reset` con `{ password, confirmation_text: 'REINICIAR DATOS' }`
5. Mostrar resultado (éxito o error)
6. En éxito: recargar página o redirigir a dashboard

### Archivos críticos a modificar

| Archivo | Cambio |
|---------|--------|
| `ClearTransactionalData.php` | NUEVO — comando artisan |
| `DataResetController.php` | NUEVO — endpoint API |
| `routes/api.php` | Registrar ruta de reset |
| `pages/settings/Store.vue` | Sección zona de peligro + modal |

---

## Task 6.3: Importación desde Excel (#34) — ~9h, Riesgo BAJO

**Objetivo**: Permitir importar productos masivamente desde archivo Excel. Ya existe `maatwebsite/excel` instalado (usado para exports).

### Backend

#### Import Class — `app/Imports/ProductImport.php` (NUEVO)

Usar patrón `ToCollection` para validar todo antes de insertar:

```php
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ProductImport implements ToCollection, WithHeadingRow, WithValidation
{
    public array $results = [];
    public array $errors = [];
    public int $successCount = 0;
    public int $errorCount = 0;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                $rowNum = $index + 2; // +2 por header y 0-index

                // Resolver brand_id y category_id por nombre
                $brand = Brand::where('name', $row['marca'])->first();
                $category = Category::where('name', $row['categoria'])->first();

                if (!$brand || !$category) {
                    throw new \Exception("Marca o categoría no encontrada");
                }

                // Crear producto via ProductService (reutiliza lógica existente)
                $product = app(ProductService::class)->createProduct([
                    'name'        => $row['nombre'],
                    'sku_base'    => $row['sku'] ?? null,
                    'description' => $row['descripcion'] ?? null,
                    'brand_id'    => $brand->id,
                    'category_id' => $category->id,
                    'status'      => 'active',
                ]);

                // Si hay datos de variante (costo/precio), crear variante
                if (!empty($row['costo']) || !empty($row['precio'])) {
                    $product->variants()->create([
                        'sku'        => $row['sku_variante'] ?? $product->sku_base,
                        'cost_price' => $row['costo'] ?? 0,
                        'sell_price' => $row['precio'] ?? 0,
                        'supplier_id'=> $this->resolveSupplier($row['proveedor'] ?? null),
                    ]);
                }

                $this->successCount++;
                $this->results[] = ['row' => $rowNum, 'status' => 'ok', 'name' => $row['nombre']];

            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row'     => $rowNum ?? $index + 2,
                    'name'    => $row['nombre'] ?? '???',
                    'error'   => $e->getMessage(),
                ];
            }
        }
    }

    public function rules(): array
    {
        return [
            'nombre'    => 'required|string|max:255',
            'marca'     => 'required|string',
            'categoria' => 'required|string',
        ];
    }

    private function resolveSupplier(?string $name): ?int
    {
        if (!$name) return null;
        return Supplier::where('name', $name)->value('id');
    }
}
```

#### Template — `app/Http/Controllers/Api/ProductImportController.php` (NUEVO)

```php
class ProductImportController extends Controller
{
    /**
     * Descargar plantilla Excel
     */
    public function template()
    {
        return Excel::download(new ProductImportTemplate(), 'plantilla_productos.xlsx');
    }

    /**
     * Preview: lee el archivo y retorna las filas parseadas sin insertar
     */
    public function preview(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $rows = Excel::toCollection(null, $request->file('file'))
            ->first()
            ->map(fn($row) => $row->toArray());

        return response()->json([
            'success' => true,
            'data'    => ['rows' => $rows, 'count' => $rows->count()],
        ]);
    }

    /**
     * Importar productos
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $import = new ProductImport();
        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'data'    => [
                'success_count' => $import->successCount,
                'error_count'   => $import->errorCount,
                'results'       => $import->results,
                'errors'        => $import->errors,
            ],
            'message' => "Importados: {$import->successCount}, Errores: {$import->errorCount}",
        ]);
    }
}
```

#### Template Export — `app/Exports/ProductImportTemplate.php` (NUEVO)

```php
class ProductImportTemplate implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['nombre', 'sku', 'descripcion', 'marca', 'categoria', 'costo', 'precio', 'sku_variante', 'proveedor'];
    }

    public function array(): array
    {
        return [
            ['Producto Ejemplo', 'SKU-001', 'Descripción del producto', 'Genérica', 'General', 100, 150, 'SKU-001-V1', 'Proveedor General'],
        ];
    }
}
```

#### Rutas — `routes/api_product_import.php` (NUEVO)

```php
Route::prefix('products/import')->middleware('role:admin,manager')->group(function () {
    Route::get('/template', [ProductImportController::class, 'template']);
    Route::post('/preview', [ProductImportController::class, 'preview']);
    Route::post('/', [ProductImportController::class, 'import']);
});
```

Registrar en `routes/api.php`.

### Frontend

#### Composable — `resources/js/composables/useProductImport.ts` (NUEVO)

```ts
// downloadTemplate() → GET /products/import/template (download file)
// previewImport(file) → POST /products/import/preview (FormData)
// executeImport(file) → POST /products/import (FormData)
```

#### Página — `resources/js/pages/Products/Import.vue` (NUEVO)

Flujo de la página:
1. **Paso 1**: Descargar plantilla (botón) + zona de drag-and-drop para subir archivo
2. **Paso 2**: Preview — tabla con las filas del Excel, destacar errores de validación
3. **Paso 3**: Confirmar importación → ejecutar → mostrar resultados (exitosos + errores por fila)

Componentes:
- Zona de upload con drag-and-drop
- Tabla de preview con columnas del Excel
- Indicadores de éxito/error por fila
- Resumen final: "X productos importados, Y errores"

#### Ruta web — `pos-frontend/routes/web.php`

```php
Route::get('/products/import', fn() => Inertia::render('Products/Import'))
    ->middleware(['auth', 'role:admin,manager']);
```

#### Botón en listado — `pos-frontend/resources/js/pages/Products/Index.vue`

- Agregar botón "Importar Excel" junto al botón de crear producto
- Link a `/products/import`

#### Sidebar (opcional)

- Sub-item bajo Productos: "Importar" con ícono Upload

### Archivos críticos a modificar

| Archivo | Cambio |
|---------|--------|
| `ProductImport.php` | NUEVO — clase de importación |
| `ProductImportTemplate.php` | NUEVO — plantilla Excel |
| `ProductImportController.php` | NUEVO — endpoints template/preview/import |
| `routes/api_product_import.php` | NUEVO — rutas API |
| `routes/api.php` | Registrar rutas de import |
| `useProductImport.ts` | NUEVO — composable frontend |
| `Products/Import.vue` | NUEVO — página de importación |
| `routes/web.php` | Ruta frontend |
| `Products/Index.vue` | Botón "Importar Excel" |

---

## Resumen

| Task | Descripción | Horas | Riesgo | Archivos nuevos | Archivos modificados |
|------|-------------|-------|--------|-----------------|---------------------|
| 6.1 (#20) | Artículos misceláneos | ~12h | ALTO | 1 migración | ~12 archivos |
| 6.2 (#23d) | Borrar data / reset | ~8h | MEDIO | 2 (command + controller) | ~2 archivos |
| 6.3 (#34) | Importación Excel | ~9h | BAJO | 5 (import, template, controller, composable, page) | ~3 archivos |
| **Total** | | **~29h** | | **8 nuevos** | **~17 modificados** |

---

## Verificación

### Task 6.1 — Artículos Misceláneos
1. `php artisan migrate` — columnas `is_custom_line`, `custom_description` creadas, `product_id` nullable
2. Crear orden de venta con item custom desde POS → guardar → ver en Show page
3. Crear cotización con item custom → guardar → ver en Show page
4. Confirmar orden con mix de items normales + custom → inventario solo se mueve para items normales
5. Cancelar orden con items custom → sin errores de inventario
6. Activar `require_stock_for_quotes` → cotización con item custom → no debe fallar por stock

### Task 6.2 — Borrar Data
1. `php artisan app:clear-data` → pide confirmación, crea backup, limpia tablas, re-seed
2. Verificar que productos, categorías, marcas, usuarios siguen existiendo
3. Verificar que órdenes, cotizaciones, movimientos fueron eliminados
4. POST `/api/data/reset` con contraseña incorrecta → error 422
5. POST `/api/data/reset` con texto incorrecto → error 422
6. POST `/api/data/reset` correcto → datos reiniciados
7. Verificar backup en `storage/app/backups/`

### Task 6.3 — Importación Excel
1. GET `/api/products/import/template` → descarga .xlsx con headers correctos
2. Llenar plantilla con 5 productos válidos + 2 inválidos
3. POST `/api/products/import/preview` → muestra 7 filas parseadas
4. POST `/api/products/import` → 5 exitosos, 2 errores con detalle por fila
5. Verificar productos creados en DB con variantes, brand, category correctos
6. Frontend: flujo completo upload → preview → import → resultados

### Commits sugeridos
1. `feat(sales): add custom line items to orders and quotes (#20)`
2. `feat(admin): add data reset command and endpoint (#23d)`
3. `feat(products): add Excel import functionality (#34)`
