# Tracking de 34 Cambios - HermeX POS

**Fecha inicio**: 2026-04-09
**Estado**: Sprint 5 Completado

---

## Sprint 1: Quick Wins y Limpieza SKU ✅ COMPLETADO

- [x] **#7** - Quitar "Sin descripcion" del popup
- [x] **#2** - Quitar marca default y crear producto sin variante
- [x] **#3/#8** - Quitar SKU autogenerado (frontend + backend)
- [x] **#4/#12** - Que no se genere custom SKU diferente
- [x] **#13** - Arreglar error del Enter en multiples formularios
- [x] **#14** - Error busqueda al seleccionar categoria y volver a todas
- [x] **#28/#32** - Quitar barras y poner espacios
- [x] **#29** - Notas solo visibles para vendedores
- [x] **#19** - Inventario en 0, no negativos

## Sprint 2: Rediseño de Variantes y Pricing ✅ COMPLETADO (2026-04-09)

### Tareas Principales
- [x] **#1** - Costo no va en categorias, va en variables (Task 2.3)
- [x] **#6/#14v** - Variante = marca + proveedor + codigo + precio (ya implementado)
- [x] **#5** - Especificaciones tecnicas en editar producto (Task 2.2)
- [x] **#18** - Solo precio y costo, no precio total ni precio base (Task 2.4)
- [x] **#9/#16** - Arreglar errores en formulas (calculo incorrecto) (Task 2.5)
  - [x] Fix nested parentheses parsing
  - [x] Fix division by zero float precision
  - [x] Fix double ITBM application

### Mejoras Adicionales
- [x] Cola de bodega como sidebar principal con badge (Task 2.1)
- [x] Fix filtros múltiples (categorías/marcas)
- [x] Fix console errors (404 warehouse queue, SalesOrder ID 0, DataCloneError)
- [x] Cola de bodega desde cotizaciones (send-to-deposit)
- [x] Remover banner de filtros avanzados en productos
- [x] Validación de ID en SalesOrderController
- [x] Auto-herencia de atributos técnicos a variantes

## Sprint 3: UI/UX y Busqueda ✅ COMPLETADO (2026-04-09)

- [x] **#15/#16/#21/#30** - Crear busqueda standard inline (sin popups)
- [x] **#11/#15d** - Eliminar columna de descuento o hacerla opcional
- [x] **#19i** - Quitar columna impuesto/descuento inline, mostrar abajo
- [x] **#26** - Popup cliente con menos info (imagen/categoria/systemId/variante/precio)
- [x] **#10/#24** - Quitar ref cruzadas, poner abajo de VIN en cuadro aparte
- [x] **#22** - Cola de bodega como ventana principal (ya hecho en Sprint 2)

## Sprint 4: System ID y Vehiculos ✅ COMPLETADO (2026-04-10)

- [x] **#27** - Crear System ID tipo 00000001
- [x] **#31/#33** - System ID diferente por marca
- [x] **#5v** - Motores mas organizados con filtros
- [x] **#9v/#23** - Especificar modelo de auto

## Sprint 5: Inventario y Compras ✅ COMPLETADO (2026-04-10)

- [x] **#24** - Arreglar ordenes de compra, mover a compras (ya estaba bajo Compras en sidebar)
- [x] **#25** - Agregar proveedores en las entradas
- [x] **#3q** - Opcion sin abastecer no se puede cotizar
- [x] **#4l** - Cantidad en OC vs inventario (unidad no lote)
- [x] **#17** - Crear formulas desde admin

## Sprint 6: Features Nuevos

- [ ] **#20** - Articulos miscelaneos en cotizacion y POS
- [ ] **#23d** - Borrar data para empezar de 0
- [ ] **#34** - Importacion desde Excel

---

## Notas de Implementación

### Sprint 2 (2026-04-09)

**Archivos Modificados:**

Backend:
- `app/Models/Category.php` - Removed default_cost from fillable/casts
- `app/Http/Controllers/Api/CategoryController.php` - Removed default_cost validation
- `app/Http/Controllers/Api/ProductController.php` - Support array filters for brand_id/category_id
- `app/Http/Controllers/Api/SalesOrderController.php` - Added ID validation in show()
- `app/Http/Controllers/Api/SalesQuoteController.php` - sendToDeposit() converts to order and sends to warehouse
- `app/Http/Controllers/Api/ProductAttributeController.php` - Auto-inherit product attributes to variants
- `app/Services/PricingFormulaService.php` - Fixed nested parentheses, division by zero, double ITBM
- `tests/Unit/PricingFormulaServiceTest.php` - 12 comprehensive tests (NEW)

Frontend:
- `components/AppSidebar.vue` - Warehouse queue as top-level with badge counter
- `components/Products/VariantManager.vue` - Changed headers to "Costo"/"Precio", removed default_cost prop
- `components/Products/ProductFilters.vue` - Replaced structuredClone with JSON.parse/stringify
- `components/Products/AttributeManager.vue` - Used in product edit page
- `pages/Products/Index.vue` - Removed advanced filter banner (VIN/OEM/specs)
- `pages/Products/Edit.vue` - Added AttributeManager section, removed categoryDefaultCost
- `pages/Products/Create.vue` - Removed categoryDefaultCost refs
- `pages/Categories/Create.vue` - Removed default_cost field, previewCost = ref(100)
- `pages/Categories/Edit.vue` - Removed default_cost field
- `types/category.ts` - Removed default_cost from interfaces

**Tests Passing:** 12/12 pricing formula tests
**Migration:** No database changes (default_cost column preserved for backwards compatibility)

**Verificaciones Manuales Pendientes:** Ver seccion de verificaciones en plan Sprint 2

### Sprint 3 (2026-04-09)

**Archivos Modificados:**

Frontend:
- `components/Products/ProductDetailModal.vue` - OEM refs full-width debajo de vehiculos, alternativas colapsables con toggle, blind mode simplificado (imagen/categoria/ID 8 digitos/marca/precio), oculta specs/stock/qty/carrito/vehiculos en blind mode
- `components/POS/ProductTable.vue` - Eliminados badges de descuento (-X% OFF), exento, y +X% ITBMS de filas principales
- `components/POS/SummarySection.vue` - Agregada prop discountAmount, fila de Descuento condicional (solo si > 0)
- `components/POS/POSInlineSearch.vue` - NUEVO: busqueda inline con dropdown, debounce 300ms, navegacion teclado (flechas/Enter/Escape), filtros categoria/marca
- `pages/POS/Index.vue` - Integrado POSInlineSearch reemplazando POSControls, handlers inline (select/variant-needed/full-search), F1 enfoca busqueda, cartDiscount computed
- `pages/SalesOrders/Show.vue` - Eliminadas columnas Descuento e Impuestos de tabla de items
- `pages/SalesQuotes/Show.vue` - Eliminadas columnas Desc. e Impuesto de tabla de items

**Commits:**
- `116f15e` feat: improve product detail modal UX for client view
- `6cbb99a` refactor: remove inline discount/tax indicators from tables
- `edf00e5` feat: replace POS search modal with inline search dropdown

### Sprint 4 (2026-04-10)

**Archivos Modificados:**

Backend:
- `database/migrations/*` - Agregado system_id a products/variants, prefix a brands, y tablas sequences
- `app/Models/Product.php` - Agregado system_id a fillable
- `app/Models/ProductVariant.php` - Agregado system_id a fillable
- `app/Models/Brand.php` - Prefix autogenerado (3 letras) y secuencia en evento boot
- `app/Http/Controllers/Api/BrandController.php` - Validación de prefijo (max 5 char, único)
- `app/Services/ProductService.php` - Fix de métodos (assignAttributes público, addCompatibility), agregado de generación de IDs atómicos (lockForUpdate)
- `app/Http/Resources/*` - Exposed system_id y prefix en API resources

Frontend:
- `types/product.ts` - Refactor a ProductFilters (soporte de arrays y nuevos campos)
- `types/brand.ts` - Añadido prefix
- `pages/POS/Index.vue` - Fix casteo de `discount_amount`

### Sprint 5 (2026-04-10)

**Archivos Modificados:**

Backend:
- `database/migrations/*` - Agregadas migraciones para `supplier_id` en inventario, `units_per_package` en items de OC, y tabla `pricing_formulas`.
- `app/Models/*` - Modelos `InventoryMovement` (con proveedor), `PurchaseOrderItem` (accessors unidades) y nuevo `PricingFormula`.
- `app/Http/Controllers/Api/*` - Validaciones añadidas en `InventoryMovementController` y `PurchaseOrderController`. Configuración de cotizaciones en `StoreSettingController` y validación en `SalesQuoteController`. Nuevo `PricingFormulaController`.
- `app/Services/*` - Servicio de OC recibe unidades según empaque, lógica del pricing controller añadida.
- `routes/api.php` y `routes/api_pricing_formulas.php` - Exposición de CRUD fórmulas.
- `database/seeders/*` - Seeder de configuración y seeder migratorio de fórmulas estáticas.

Frontend:
- `resources/js/composables/*` - Nuevo `usePricingFormulas`, interfaz para `require_stock_for_quotes` en settings y `units_per_package` en `usePurchaseOrders`.
- `resources/js/components/Products/PricingFormula.vue` - Transformado a uso de API dinámico en vez de presets en duro.
- `resources/js/pages/settings/*` - Store.vue con opción de bloque de cotización y nueva página CRUD `PricingFormulas.vue`.
- `resources/js/pages/InventoryMovements/*` - `CreateInbound.vue` e `Index.vue` soportan asignar/leer proveedor.
- `resources/js/pages/PurchaseOrders/*` - `Create.vue` y `Receive.vue` operan sobre empaques vs unidades.
- `resources/js/components/AppSidebar.vue` y `routes/web.php` - Nuevas rutas de navegación para configuraciones de pricing.

**Commits:**
*(Completado y testeado localmente)*
- `components/Products/ProductDetailModal.vue` - Modificado para usar Layout de Cards para vehículos (Make + Model / Años agrupados). Blind mode muestra `system_id` (del prod o variante + prefijo).
- `composables/useVehicleEngines.ts` - Soporte param `make_id`
- `pages/Vehicles/Engines/Index.vue` - Refactor completo agregando filtros de Make/Model con selects.
- `pages/Brands/Create.vue` & `Edit.vue` - Añadido input prefix autogenerado
