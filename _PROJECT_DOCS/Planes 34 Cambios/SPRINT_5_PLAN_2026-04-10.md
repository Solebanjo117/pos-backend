# Sprint 5: Inventario y Compras — Plan de Implementacion

**Fecha**: 2026-04-10
**Tareas**: 5 (#24, #25, #3q, #4l, #17)
**Estimacion total**: ~19h
**Orden**: 5.1 → 5.2 → 5.3 → 5.4 → 5.5 (quick wins primero, CRUD grande al final)

---

## Task 5.1: Verificar OC bajo Compras (#24) — 0h, Ya completado

`AppSidebar.vue` ya tiene "Compras" como seccion top-level con: Lista de Abastecimiento, Ordenes de Compra, Cuentas por Pagar, Proveedores. No requiere cambios.

**Accion**: Marcar como completado en tracking.

---

## Task 5.2: Agregar proveedor en entradas de inventario (#25) — 3h, Bajo riesgo

**Backend + Frontend** — Agregar `supplier_id` opcional a movimientos de inventario tipo inbound.

### Backend

**Migracion** — `2026_04_11_000001_add_supplier_id_to_inventory_movements_table.php`
```php
Schema::table('inventory_movements', function (Blueprint $table) {
    $table->foreignId('supplier_id')->nullable()->after('user_id')
          ->constrained('suppliers')->onDelete('set null');
    $table->index('supplier_id');
});
```

**`app/Models/InventoryMovement.php`** (linea 13-25 fillable):
- Agregar `'supplier_id'` al array `$fillable`
- Agregar relacion:
```php
public function supplier(): BelongsTo
{
    return $this->belongsTo(Supplier::class);
}
```

**`app/Http/Requests/StoreInboundMovementRequest.php`** (linea 17-23 rules):
- Agregar regla: `'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id']`

**`app/Services/InventoryMovementService.php`**:
- `recordInbound()` (linea ~103, en el `InventoryMovement::create`): agregar `'supplier_id' => $data['supplier_id'] ?? null`
- `getMovements()` (linea 19): agregar `'supplier'` al array de `with()`
- `getMovementById()` (linea 71): agregar `'supplier'` al array de `with()`

**`app/Http/Controllers/Api/InventoryMovementController.php`**:
- `storeBulkInbound()` (linea 89-95): agregar regla `'items.*.supplier_id' => 'nullable|integer|exists:suppliers,id'`

**`app/Http/Resources/InventoryMovementResource.php`** (linea ~46, despues de 'user'):
```php
'supplier' => $this->whenLoaded('supplier', function () {
    return $this->supplier_id ? [
        'id' => $this->supplier->id,
        'name' => $this->supplier->name,
    ] : null;
}),
```

### Frontend

**`pos-frontend/resources/js/pages/InventoryMovements/CreateInbound.vue`**:
1. Importar `useSuppliers` composable (ya existe en `composables/useSuppliers.ts` con `fetchActiveSuppliers()`)
2. Agregar `supplier_id: null` a `headerForm` (linea 37-43)
3. En `onMounted`: cargar `fetchActiveSuppliers()`
4. En template, seccion "Informacion General" (~linea 322): agregar `<select>` de proveedor con opcion "Sin proveedor"
5. En `handleSubmit()` (~linea 236): incluir `supplier_id: headerForm.value.supplier_id || undefined` en cada item

**`pos-frontend/resources/js/pages/InventoryMovements/Index.vue`**:
- Agregar columna "Proveedor" a la tabla (solo mostrar si `movement.supplier?.name`)

---

## Task 5.3: Requerir stock para cotizar (#3q) — 4h, Riesgo medio

**Backend + Frontend** — Nuevo setting que bloquea cotizaciones cuando los productos no tienen stock suficiente.

### Backend

**`database/seeders/DefaultSettingsSeeder.php`** (~linea 30):
- Agregar: `Setting::updateOrCreate(['key' => 'require_stock_for_quotes'], ['value' => false, 'type' => 'boolean', 'group' => 'quotes'])`

**`app/Http/Controllers/Api/StoreSettingController.php`**:
- `index()` (linea 22): grupo `'quotes'` ya esta incluido en el query — no requiere cambio
- `update()` (linea 40-50): agregar `'require_stock_for_quotes'` al array `$allowed`
- Match de `$group` (linea 54-58): agregar caso para `require_stock` → `'quotes'`
- Match de `$type` (linea 61-64): agregar `$key === 'require_stock_for_quotes' => 'boolean'`

**`app/Http/Controllers/Api/SalesQuoteController.php`** — Nuevo metodo privado:
```php
private function validateStockForQuoteItems(array $items, ?int $warehouseId = null): void
{
    if (!\App\Models\Setting::get('require_stock_for_quotes', false)) {
        return;
    }
    $errors = [];
    foreach ($items as $item) {
        $wId = $item['warehouse_id'] ?? $warehouseId;
        $query = \App\Models\ProductInventory::where('warehouse_id', $wId);
        if (!empty($item['variant_id'])) {
            $query->where('variant_id', $item['variant_id']);
        } else {
            $query->where('product_id', $item['product_id'])->whereNull('variant_id');
        }
        $available = $query->value('quantity_available') ?? 0;
        if ($available < ($item['quantity'] ?? 1)) {
            $name = \App\Models\Product::find($item['product_id'])?->name ?? "#{$item['product_id']}";
            $errors[] = "{$name}: disponible {$available}, solicitado {$item['quantity']}";
        }
    }
    if (!empty($errors)) {
        throw new \Exception('Stock insuficiente: ' . implode('; ', $errors));
    }
}
```

Llamar en `store()` (antes de linea 112, antes del `DB::transaction`):
```php
$this->validateStockForQuoteItems($validated['items'], $validated['warehouse_id'] ?? null);
```

Llamar en `storeFromPos()` (antes de linea 271):
```php
$this->validateStockForQuoteItems($validated['items'], $validated['warehouse_id'] ?? null);
```

**Nota**: El catch existente en ambos metodos (lineas 145-147 y 305-307) ya retorna JSON con `$e->getMessage()`, asi que el error llega al frontend automaticamente.

### Frontend

**`pos-frontend/resources/js/composables/useStoreSettings.ts`** (interfaz linea 4-21):
- Agregar a `StoreSettings`: `require_stock_for_quotes: boolean`
- Agregar al default (linea 34): `require_stock_for_quotes: false`

**`pos-frontend/resources/js/pages/settings/Store.vue`** (seccion Cotizaciones, ~linea 391-407):
- Agregar checkbox debajo del dropdown de expiracion:
```html
<label class="flex items-start gap-3 cursor-pointer mt-4">
    <input v-model="settings.require_stock_for_quotes" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 dark:border-border text-primary focus:ring-primary/50" />
    <div>
        <p class="text-sm font-medium text-gray-900 dark:text-white">Requerir stock para cotizar</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">No se podran crear cotizaciones para productos sin stock disponible.</p>
    </div>
</label>
```

---

## Task 5.4: Unidades por paquete en OC (#4l) — 4h, Riesgo medio

**Backend + Frontend** — Campo `units_per_package` en items de OC para distinguir entre paquetes y unidades individuales.

### Backend

**Migracion** — `2026_04_11_000002_add_units_per_package_to_purchase_order_items_table.php`
```php
Schema::table('purchase_order_items', function (Blueprint $table) {
    $table->unsignedInteger('units_per_package')->default(1)->after('quantity_ordered');
});
```

**`app/Models/PurchaseOrderItem.php`** (linea 13-24 fillable):
- Agregar `'units_per_package'` a `$fillable`
- Agregar a `$casts`: `'units_per_package' => 'integer'`
- Agregar a `$appends`: `'total_units_ordered'`, `'total_units_received'`
- Nuevos accessors:
```php
public function getTotalUnitsOrderedAttribute(): int
{
    return $this->quantity_ordered * ($this->units_per_package ?? 1);
}
public function getTotalUnitsReceivedAttribute(): int
{
    return $this->quantity_received * ($this->units_per_package ?? 1);
}
```

**`app/Services/PurchaseOrderService.php`** — `receiveItems()` (lineas 214-360):

Puntos criticos a modificar (multiplicar por `units_per_package`):

1. **Linea 247** (InventoryMovement quantity):
   ```php
   'quantity' => $qtyToReceive * ($item->units_per_package ?? 1),
   ```

2. **Linea 291** (inventory increment):
   ```php
   $inventory->quantity_available += $qtyToReceive * ($item->units_per_package ?? 1);
   ```

3. **Linea 299** (batch quantity):
   ```php
   'quantity' => $qtyToReceive * ($item->units_per_package ?? 1),
   ```

**`app/Services/PurchaseOrderService.php`** — `addItemToDailyPO()` (~lineas 559-655):
- Incluir `units_per_package` en la creacion del PurchaseOrderItem
- Multiplicar quantity en el InventoryMovement::create y ProductInventory update

**`app/Http/Controllers/Api/PurchaseOrderController.php`**:
- `store()` y `update()`: agregar validacion `'items.*.units_per_package' => 'nullable|integer|min:1'`
- `quickPurchase()`: agregar validacion `'items.*.units_per_package' => 'nullable|integer|min:1'`

### Frontend

**`pos-frontend/resources/js/pages/PurchaseOrders/Create.vue`**:
- En `addProduct()`: agregar `units_per_package: 1` al item
- En la tabla de items: agregar columna "Und/Paq" con input numerico (`min="1"`, `class="w-16"`)
- Mostrar columna "Total Und." calculada: `item.quantity * (item.units_per_package || 1)`

**`pos-frontend/resources/js/pages/PurchaseOrders/Receive.vue`**:
- Agregar columna "Und/Paq" mostrando `item.units_per_package` (readonly)
- Agregar columna "Total Unidades" mostrando: `quantity_received * units_per_package`
- Texto informativo cuando `units_per_package > 1`: "Cada paquete = X unidades"

**`pos-frontend/resources/js/pages/PurchaseOrders/Show.vue`**:
- Mostrar `units_per_package` y `total_units_ordered` / `total_units_received` en la tabla de items

---

## Task 5.5: Crear formulas de pricing desde admin (#17) — 8h, Riesgo bajo

**Backend + Frontend** — CRUD de formulas de pricing reemplazando presets hardcodeados.

### Backend

**Migracion** — `2026_04_11_000003_create_pricing_formulas_table.php`
```php
Schema::create('pricing_formulas', function (Blueprint $table) {
    $table->id();
    $table->string('name');              // ej. "Costo x 2"
    $table->string('formula');           // ej. "costo * 2"
    $table->string('description')->nullable();
    $table->boolean('is_builtin')->default(false);
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('sort_order')->default(0);
    $table->timestamps();
});
```

**Modelo** — `app/Models/PricingFormula.php` (NUEVO):
```php
class PricingFormula extends Model
{
    protected $fillable = ['name', 'formula', 'description', 'is_builtin', 'is_active', 'sort_order'];
    protected $casts = ['is_builtin' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeBuiltin($query) { return $query->where('is_builtin', true); }
}
```

**Seeder** — `database/seeders/PricingFormulaSeeder.php` (NUEVO):
- Seed las 7 formulas builtin de `PricingFormulaService::presets()` con `is_builtin = true`
- Usar `updateOrCreate` por `formula` para idempotencia
- Formulas:
  1. Costo x 2 → `costo * 2`
  2. Costo x 1.5 (redondeado) → `ROUND(costo * 1.5)`
  3. Costo x 2 (redondear arriba) → `ROUNDUP(costo * 2)`
  4. Costo x 2 (termina en .88) → `FLOOR(costo * 2) + 0.88`
  5. Costo x 2 (termina en .99) → `FLOOR(costo * 2) + 0.99`
  6. Costo + ITBM → `costo + costo * ITBM`
  7. Costo x 1.5 + ITBM → `ROUND(costo * 1.5 + costo * 1.5 * ITBM)`

**Controlador** — `app/Http/Controllers/Api/PricingFormulaController.php` (NUEVO):
- `index()` — GET /pricing-formulas: todas las activas, ordered by sort_order
- `store(Request)` — POST: crear nueva, validar syntax via `PricingFormulaService::validate()`
- `update(Request, $id)` — PUT: editar (builtin: solo toggle active, no cambiar formula)
- `destroy($id)` — DELETE: eliminar solo si `!is_builtin`

Validacion en store/update:
```php
'name' => 'required|string|max:100',
'formula' => 'required|string|max:255',
'description' => 'nullable|string|max:255',
'is_active' => 'nullable|boolean',
'sort_order' => 'nullable|integer|min:0',
```
+ Verificar formula valida via `PricingFormulaService` (instanciar y llamar `validate()`)

**Rutas** — `routes/api_pricing_formulas.php` (NUEVO):
```php
Route::prefix('pricing-formulas')->middleware('role:admin,manager')->group(function () {
    Route::get('/', [PricingFormulaController::class, 'index']);
    Route::post('/', [PricingFormulaController::class, 'store']);
    Route::put('/{id}', [PricingFormulaController::class, 'update']);
    Route::delete('/{id}', [PricingFormulaController::class, 'destroy']);
});
```

Registrar en `routes/api.php` (~linea 87, despues de `api_store_settings.php`):
```php
require __DIR__ . '/api_pricing_formulas.php';
```

**`app/Services/PricingFormulaService.php`** — `presets()` (linea 148-159):
- Cambiar de array hardcodeado a query de DB:
```php
public static function presets(): array
{
    return \App\Models\PricingFormula::active()
        ->orderBy('sort_order')
        ->orderBy('name')
        ->get()
        ->map(fn($f) => ['label' => $f->name, 'formula' => $f->formula])
        ->all();
}
```
El endpoint existente `GET /pricing-formula/presets` (StoreSettingController::formulaPresets) retornara datos de DB automaticamente sin cambios adicionales.

### Frontend

**Composable** — `resources/js/composables/usePricingFormulas.ts` (NUEVO):
```ts
// fetchFormulas() → GET /pricing-formulas
// createFormula(data) → POST /pricing-formulas
// updateFormula(id, data) → PUT /pricing-formulas/{id}
// deleteFormula(id) → DELETE /pricing-formulas/{id}
```

**Pagina** — `resources/js/pages/settings/PricingFormulas.vue` (NUEVO):
- Tabla con columnas: Nombre, Formula, Descripcion, Tipo (builtin badge), Activa (toggle), Acciones
- Boton "Nueva Formula" que abre modal con: nombre, formula (input libre), descripcion
- Preview en vivo: al escribir formula, hacer POST a `/pricing-formula/preview` con cost=100
- Variables soportadas (`costo`, `base_price`, `ITBM`) y funciones (`ROUND`, `CEIL`, `FLOOR`, `ROUNDUP`, `ROUNDDOWN`) como hint debajo del input
- Editar: modal con los mismos campos (formula readonly si is_builtin)
- Eliminar: solo habilitado para !is_builtin, con confirmacion

**Ruta web** — `pos-frontend/routes/web.php`:
```php
Route::get('/settings/pricing-formulas', fn() => Inertia::render('settings/PricingFormulas'))
    ->middleware(['auth', 'role:admin,manager']);
```

**Sidebar** — `resources/js/components/AppSidebar.vue` (seccion Administracion, ~linea 318-345):
- Agregar sub-item: `{ title: 'Formulas de Precio', href: '/settings/pricing-formulas', icon: Calculator }`

**`resources/js/components/Products/PricingFormula.vue`** (linea 27-35):
- Reemplazar `const PRESETS = [...]` hardcodeado por fetch dinamico:
```ts
const PRESETS = ref<{ label: string; formula: string }[]>([])
onMounted(async () => {
    try {
        const { data } = await apiClient.get('/pricing-formula/presets')
        PRESETS.value = data.data ?? []
    } catch { /* fallback empty */ }
})
```
- En template: cambiar `PRESETS` directo a `PRESETS.value` en el v-for

---

## Resumen

| Task | Descripcion | Horas | Riesgo | Backend | Frontend |
|------|-------------|-------|--------|---------|----------|
| 5.1 (#24) | OC bajo Compras | 0h | - | - | - |
| 5.2 (#25) | Proveedor en entradas | 3h | Bajo | 1 migracion, modelo, service, resource | CreateInbound dropdown, Index columna |
| 5.3 (#3q) | Stock requerido para cotizar | 4h | Medio | 1 setting, validacion en SalesQuoteController | Settings toggle |
| 5.4 (#4l) | Unidades por paquete | 4h | Medio | 1 migracion, modelo, PurchaseOrderService | Create/Receive/Show columnas |
| 5.5 (#17) | Formulas CRUD admin | 8h | Bajo | 1 migracion, modelo, controller, rutas, seeder | Pagina nueva, composable, PricingFormula.vue |
| **Total** | | **19h** | | | |

---

## Verificacion

### Backend
1. `php artisan migrate` — 3 migraciones exitosas
2. `php artisan db:seed --class=DefaultSettingsSeeder` — setting require_stock_for_quotes creado
3. `php artisan db:seed --class=PricingFormulaSeeder` — 7 formulas builtin creadas
4. `php artisan test` — sin regresiones
5. Crear entrada de inventario con proveedor → verificar DB tiene supplier_id
6. Activar require_stock_for_quotes → crear cotizacion sin stock → error 500 con mensaje descriptivo
7. Crear OC con units_per_package=10, recibir 5 paquetes → inventario sube 50
8. CRUD /pricing-formulas — crear, editar, eliminar, no eliminar builtin

### Frontend
1. CreateInbound: dropdown de proveedor funcional
2. Index movimientos: columna Proveedor visible en entradas
3. Settings > Cotizaciones: toggle "Requerir stock" funcional, persiste al guardar
4. PO Create: columna Und/Paq con input editable, columna Total Und. calculada
5. PO Receive: muestra total unidades calculado cuando units_per_package > 1
6. Settings > Formulas de Precio: tabla CRUD funcional
7. PricingFormula.vue en productos/categorias: presets vienen de API, no hardcodeados

### Commits sugeridos
1. `feat(inventory): add supplier tracking to inbound movements (#25)`
2. `feat(quotes): add require_stock_for_quotes setting (#3q)`
3. `feat(purchases): add units_per_package to PO items (#4l)`
4. `feat(pricing): CRUD admin for pricing formulas (#17)`
