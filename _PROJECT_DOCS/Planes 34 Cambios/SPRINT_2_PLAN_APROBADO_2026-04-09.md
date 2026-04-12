# Plan de Implementacion - 34 Cambios HermeX POS

## Contexto

El sistema HermeX POS (Laravel backend + Vue 3 frontend) necesita 34 ajustes. Reorganizados por **prioridad del usuario** (Alta primero), no por agrupacion tematica.

**Tracking**: `_PROJECT_DOCS/CAMBIOS_34_TRACKING.md`
**Plan detallado**: `_PROJECT_DOCS/PLAN_34_CAMBIOS.md`

---

## Sprint 1: Quick Wins y Limpieza SKU ✅ COMPLETADO

- [x] #7 - Quitar "Sin descripcion" del popup
- [x] #2 - Quitar marca default y crear producto sin variante  
- [x] #3/#8 - Eliminar textos UI de SKU autogenerado (logica se mantiene)
- [x] #4/#12 - No generar custom SKU diferente al agregado
- [x] #13 - Arreglar error del Enter en multiples formularios
- [x] #14 - Error busqueda al seleccionar categoria y volver a todas
- [x] #28/#32 - Quitar barras y poner espacios
- [x] #29 - Notas solo visibles para vendedores
- [x] #19 - Inventario en 0, no negativos

---

## Sprint 2: Pricing, Edicion y Cola de Bodega (ACTUAL)

Todas tareas **Alta** prioridad. Orden de implementacion optimizado por dependencias.

### Tarea 2.1: #22 - Cola de Bodega como ventana principal
**~30 min | Solo Frontend | 1 archivo**

**Archivo**: `pos-frontend/.../components/AppSidebar.vue`

1. **Importar** `useWarehouseQueue` (linea 25-26):
   ```typescript
   import { useWarehouseQueue } from '@/composables/useWarehouseQueue'
   // linea 30:
   const { count: warehouseQueueCount, startPolling: startWarehousePolling } = useWarehouseQueue()
   ```

2. **Agregar polling** en onMounted (despues de linea 39):
   ```typescript
   if (hasRole(['admin', 'manager', 'buyer', 'assistant'])) {
       startWarehousePolling(30000)
   }
   ```

3. **Remover** de Inventario sub-items (lineas 189-193): quitar el objeto `{ title: 'Cola de Bodega', ... }`

4. **Agregar como top-level** despues de Dashboard (linea 155):
   ```typescript
   if (hasRole(['admin', 'manager', 'buyer', 'assistant'])) {
       items.push({
           title: 'Cola de Bodega',
           href: '/warehouses/queue',
           icon: PackageSearch,
           badge: warehouseQueueCount.value > 0 ? String(warehouseQueueCount.value) : undefined,
       })
   }
   ```

**QA**: Sidebar muestra "Cola de Bodega" como item principal con badge de conteo. Ya no aparece dentro de Inventario.

---

### Tarea 2.2: #5 - Especificaciones tecnicas en Editar Producto
**~45 min | Solo Frontend | 1 archivo**

**Archivo**: `pos-frontend/.../pages/Products/Edit.vue`

**Todo el backend ya existe:**
- `AttributeManager.vue` componente completo con CRUD
- `ProductAttributeController.php` con index/store/update/destroy
- `useProductAttributes.ts` composable
- Rutas: `GET /products/{id}/attributes`, `POST/PUT/DELETE /product-attributes/{id}`

**Cambios:**
1. **Importar** `AttributeManager` (junto a otros imports ~linea 11):
   ```typescript
   import AttributeManager from '@/components/Products/AttributeManager.vue'
   ```

2. **Insertar seccion** entre Variantes (~linea 940) y OEM References (~linea 945):
   ```html
   <div class="rounded-lg bg-white p-6 shadow dark:bg-card border-l-4 border-indigo-500">
       <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
           <Wrench class="w-5 h-5 text-indigo-600" /> Especificaciones Tecnicas
       </h2>
       <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
           Agrega o edita especificaciones tecnicas del producto.
       </p>
       <AttributeManager :product-id="props.productId" />
   </div>
   ```

**QA**: En editar producto, la seccion "Especificaciones Tecnicas" aparece. Se pueden agregar/editar/eliminar atributos clave-valor.

---

### Tarea 2.3: #1 - Costo no va en categorias, va en variables
**~1.5 horas | Backend + Frontend | 8 archivos**

**Backend (2 archivos):**

| Archivo | Cambio |
|---------|--------|
| `app/Models/Category.php` | Quitar `'default_cost'` de `$fillable` (L24) y `$casts` (L35) |
| `app/Http/Controllers/Api/CategoryController.php` | Quitar `'default_cost' => 'nullable|numeric|min:0'` de store() (L59) y update() (L96) |

No borrar la columna de la BD — solo dejar de usarla.

**Frontend (6 archivos):**

| Archivo | Cambio |
|---------|--------|
| `pages/Categories/Create.vue` | Quitar `default_cost` del form (L22), quitar input UI (L201-214), `previewCost` pasa a `100` fijo (L29) |
| `pages/Categories/Edit.vue` | Quitar `default_cost` del form (L28), del load (L87), quitar input UI (~L234-251), `previewCost` a `100` |
| `pages/Products/Create.vue` | Quitar ref `categoryDefaultCost` (L108), quitar asignacion en category watcher (L240), quitar prop del `<VariantManager>` |
| `pages/Products/Edit.vue` | Quitar ref `categoryDefaultCost` (~L52), quitar asignacion (~L86), quitar prop del `<VariantManager>` |
| `components/Products/VariantManager.vue` | Quitar prop `categoryDefaultCost` (L26), cambiar `cost: props.categoryDefaultCost ?? 0` a `cost: 0` (L277), quitar bloque `recalculatePriceFromCategory()` condicional (L293-296) |
| `types/category.ts` | Quitar `default_cost` de interfaces Category y CreateCategoryData |

**QA**: Crear categoria → NO aparece campo "Costo por Defecto". Crear producto → costo se ingresa en variante manualmente. Preview de formula en categoria usa $100 como muestra.

---

### Tarea 2.4: #18 - Solo Precio y Costo (sin "Precio Total" ni "Precio Base")
**~1 hora | Solo Frontend | 1 archivo principal**

**Archivo principal**: `pos-frontend/.../components/Products/VariantManager.vue`

1. **Tabla headers** (L471-472): Cambiar "Precio Base" → "Costo", "Precio Total" → "Precio"

2. **Tabla celdas** (L496-506):
   - Celda "Costo": `variant.pricing.cost` (antes mostraba base_price)
   - Celda "Precio": `variant.pricing.base_price` (precio de venta, ya calculado por formula/margen)
   - Quitar calculo inline de `base_price * (1 + tax_rate/100)` de la celda Precio Total

**ProductDetailModal.vue**: Ya muestra base_price como precio principal y cost para sellers — no necesita cambios.

**QA**: Tabla de variantes muestra columnas: Nombre | Atributos | Marca | **Costo** | **Precio** | Stock | Acciones. No aparece "Precio Base" ni "Precio Total".

---

### Tarea 2.5: #9/#16 - Arreglar errores en formulas
**~3 horas | Backend + Frontend | 2 archivos + 1 test nuevo**

#### Bug 1 (CRITICO): Parentesis anidados — `PricingFormulaService.php`

**Archivo**: `pos-backend/app/Services/PricingFormulaService.php`

**Problema** (L171): Regex `[^()]*` no captura parentesis internos.
`ROUND(costo * (1.5 + 0.5))` falla porque `(1.5 + 0.5)` rompe el patron.

**Fix**: Reemplazar `resolveFunctions()` (L158-187) completo con parser stack-based:

```php
private function resolveFunctions(string $expr): string
{
    $functions = ['ROUNDUP', 'ROUNDDOWN', 'ROUND', 'CEIL', 'FLOOR'];
    $maxIterations = 10;
    $iteration = 0;

    while ($iteration++ < $maxIterations) {
        $found = false;
        foreach ($functions as $fn) {
            $fnLower = strtolower($fn);
            $exprLower = strtolower($expr);
            $pos = 0;
            while (($pos = strpos($exprLower, $fnLower, $pos)) !== false) {
                if ($pos > 0 && ctype_alpha($expr[$pos - 1])) { $pos++; continue; }
                $afterFn = $pos + strlen($fn);
                while ($afterFn < strlen($expr) && $expr[$afterFn] === ' ') $afterFn++;
                if ($afterFn >= strlen($expr) || $expr[$afterFn] !== '(') { $pos++; continue; }

                // Stack-based balanced parens
                $depth = 0;
                $end = $afterFn;
                for ($i = $afterFn; $i < strlen($expr); $i++) {
                    if ($expr[$i] === '(') $depth++;
                    if ($expr[$i] === ')') $depth--;
                    if ($depth === 0) { $end = $i; break; }
                }
                if ($depth !== 0) throw new \InvalidArgumentException("Parentesis sin cerrar en {$fn}");

                $innerExpr = substr($expr, $afterFn + 1, $end - $afterFn - 1);
                $innerValue = $this->evaluateArithmetic($innerExpr);
                $result = $this->applyFunction($fn, (float) $innerValue);
                $expr = substr($expr, 0, $pos) . (string) $result . substr($expr, $end + 1);
                $found = true;
                break 2;
            }
        }
        if (!$found) break;
    }
    return $expr;
}
```

#### Bug 2: Division por cero — `PricingFormulaService.php`

**Linea 288**: Cambiar `$right == 0` a `abs($right) < 1e-10`

#### Bug 3: Doble ITBM — `PricingFormulaService.php`

**Problema**: `calculate()` (L121-134) llama `evaluate()` que sustituye ITBM dentro de la formula, y luego llama `applyItbm()` que aplica ITBM otra vez. Si la formula ya usa ITBM, se aplica doble.

**Fix**: En `calculate()`, detectar si la formula referencia ITBM y si si, no aplicar externamente:

```php
public function calculate(string $formula, float $costo, bool $appliesItbm, float $itbmPercent = 7.0): ?float
{
    if (empty(trim($formula))) return null;

    $formulaUsesItbm = (bool) preg_match('/\bITBM\b/i', $formula);

    try {
        $base = $this->evaluate($formula, $costo, $itbmPercent);
    } catch (\Throwable $e) {
        return null;
    }

    // Si la formula ya maneja ITBM, no aplicar de nuevo
    if ($formulaUsesItbm) return round($base, 2);
    return $this->applyItbm($base, $appliesItbm, $itbmPercent);
}
```

#### Tests — Crear `tests/Unit/PricingFormulaServiceTest.php`

```php
test('nested parentheses: ROUND(costo * (1.5 + 0.5))', function () {
    $s = new PricingFormulaService();
    expect($s->evaluate('ROUND(costo * (1.5 + 0.5))', 100))->toBe(200.0);
});
test('no double ITBM when formula uses ITBM', function () {
    $s = new PricingFormulaService();
    expect($s->calculate('costo + costo * ITBM', 100.0, true, 7.0))->toBe(107.0);
});
test('ITBM applied externally when formula does NOT use ITBM', function () {
    $s = new PricingFormulaService();
    expect($s->calculate('costo * 1.5', 100.0, true, 7.0))->toBe(160.50);
});
test('division by zero throws', function () {
    $s = new PricingFormulaService();
    $s->evaluate('costo / 0', 100);
})->throws(\InvalidArgumentException::class);
```

**QA**: `php artisan test tests/Unit/PricingFormulaServiceTest.php` pasa. En app: formula `ROUND(costo * (1.5 + 0.5))` con costo=100 → $200. Formula `costo + costo * ITBM` con costo=100 y applies_itbm=true → $107 (no $114.49).

---

## Sprint 3: Compras, Inventario y Excel Import (Alta)

- [ ] #3 - Requerir stock para cotizar (setting + validacion)
- [ ] #4 - Cantidad en OC vs inventario (unidad, no lote)
- [ ] #24 - Ajustar OC: quitar de inventario, mover a Compras, renombrar
- [ ] #25 - Agregar proveedores en entradas
- [ ] #34 - Importacion desde Excel

## Sprint 4: System ID y Vehiculos (Alta)

- [ ] #27+#31+#33 - System ID unificado por marca (implementar directo con prefijo de marca)
- [ ] #9v/#23 - Especificar modelo de auto prominente

## Sprint 5: UI/UX y Busqueda (Media)

- [ ] #15/#21/#30 - Busqueda inline sin popups
- [ ] #6/#14v - Variante = marca + proveedor + codigo + precio
- [ ] #11/#15d - Descuento opcional
- [ ] #19i - Impuesto/descuento abajo de tabla
- [ ] #26 - Popup cliente minimalista
- [ ] #10/#24r - Ref cruzadas debajo de VIN
- [ ] #5v - Motores con filtros

## Sprint 6: Features Nuevos (Media/Baja)

- [ ] #17 - Formulas desde admin
- [ ] #20 - Articulos miscelaneos
- [ ] #23d - Borrar data para empezar de 0

---

## Verificacion Sprint 2

1. `php artisan test tests/Unit/PricingFormulaServiceTest.php` — todos pasan
2. Sidebar: "Cola de Bodega" como item principal con badge
3. Edit producto: seccion "Especificaciones Tecnicas" funcional
4. Crear categoria: NO aparece "Costo por Defecto"
5. Crear variante: costo empieza en 0, se ingresa manualmente
6. Tabla variantes: columnas "Costo" y "Precio" (no "Precio Base"/"Precio Total")
7. Formula `ROUND(costo * (1.5 + 0.5))` con costo=100 → $200
8. Formula `costo + costo * ITBM` con applies_itbm=true → $107 (no doble)
