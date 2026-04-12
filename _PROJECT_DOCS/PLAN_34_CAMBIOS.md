# Plan de Implementacion - 34 Cambios HermeX POS

## Contexto

El sistema HermeX POS (Laravel backend + Vue 3 frontend) necesita 34 ajustes que van desde correcciones de bugs hasta nuevas funcionalidades. Los cambios abarcan: creacion de productos, pricing, busqueda, inventario, ordenes de compra, vehiculos, y features nuevos como importacion Excel y articulos miscelaneos.

**Tracking de progreso**: `_PROJECT_DOCS/CAMBIOS_34_TRACKING.md`

---

## Sprint 1: Quick Wins y Limpieza SKU ✅ COMPLETADO

### #7 - Quitar "Sin descripcion" del popup
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductDetailModal.vue`: cambiar `{{ product.description \|\| 'Sin descripcion' }}` a `v-if="product.description"` condicional |
| QA | Verificar que productos sin descripcion no muestren texto placeholder |

---

### #2 - Quitar marca default y crear producto sin variante
**Complejidad: Pequena | Frontend + Backend**

| Seccion | Accion |
|---------|--------|
| Frontend | `Create.vue`: eliminar auto-seleccion de `brands.value[0].id` en `onMounted` |
| Frontend | Hacer `brand_id` opcional (null/undefined), agregar opcion "Sin Marca" en selector |
| Frontend | Relajar validacion para permitir submit sin brand_id |
| Backend | `ProductService.php`: no forzar variante al crear si no hay brand_id |
| QA | Crear producto sin marca ni variante -> debe guardarse sin error |

---

### #3 y #8 - Eliminar tema "SKU autogenerado" (solo textos UI)
**Complejidad: Pequena | Solo Frontend**

**NOTA IMPORTANTE**: La logica de auto-generacion de SKU (`fetchSkuPreview`, `generateSkuFromFormat`, watchers) se MANTIENE. Solo se eliminan los textos de advertencia en la UI.

| Seccion | Accion |
|---------|--------|
| Frontend | `Create.vue`: eliminar texto "Se generara: ..." preview paragraph debajo del input SKU |
| Frontend | Cambiar placeholder de "Autogenerar al guardar" a algo neutro |
| Frontend | Mantener `fetchSkuPreview()`, `skuPreview`, `skuBaseManuallyEdited` y watchers intactos |
| Frontend | El SKU se auto-genera al seleccionar categoria, pero el usuario puede editar manualmente |
| Backend | `ProductService.php`: si usuario provee SKU, usar ese; si no, auto-generar desde formato |
| QA | Al crear producto y seleccionar categoria, el SKU se llena automaticamente. El usuario puede sobreescribirlo. No deben aparecer mensajes de advertencia sobre auto-generacion |

---

### #4 y #12 - Que no se genere custom SKU diferente, usar el mismo que se agrego
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `Create.vue`: eliminar `autoVariantSku` computed que agrega sufijo de marca (ej. "-BOX") |
| Frontend | Eliminar watcher que auto-sincroniza `sku_base` a `upc` |
| Frontend | `VariantManager.vue`: eliminar auto-sync de `form.sku` a `form.upc` |
| Frontend | El SKU que ingrese el usuario debe quedarse EXACTO, sin modificaciones de sufijo |
| QA | Ingresar SKU "ABC-123" -> variante debe mostrar "ABC-123", no "ABC-123-BOX" |

---

### #13 - Arreglar error del Enter (MULTIPLES FORMULARIOS)
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | Agregar handler `@keydown.enter` en cada `<form>` que previene submit desde inputs |
| Frontend | Auditar TODOS los formularios del sistema |
| Frontend | Pattern: `@keydown.enter="(e: KeyboardEvent) => { if ((e.target as HTMLElement)?.tagName === 'INPUT') e.preventDefault() }"` |
| QA | En cada formulario, presionar Enter en cualquier campo -> NO debe enviar el form |

---

### #14 - Error de busqueda cuando se selecciona categoria y vuelve a todas
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductFilters.vue`: cambiar shallow copy `{ ...v }` a `structuredClone(v)` para deep copy de arrays |
| Frontend | Verificar que al volver a "Todas", `category_ids` se resetee a `[]` |
| QA | Seleccionar categoria A -> buscar -> volver a "Todas" -> resultados NO deben filtrar por A |

---

### #28 y #32 - Quitar barras y poner espacios
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | Crear helper `displayFormat(str)` en `utils/formatters.ts` que reemplace hyphens con espacios |
| Frontend | Aplicar en ProductDetailModal, ProductSearchModal, tablas de productos |
| QA | SKU "FIL-001-BOX" debe mostrarse como "FIL 001 BOX" en displays al cliente |

---

### #29 - Notas solo visibles para vendedores
**Complejidad: Pequena | Ambos**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductDetailModal.vue`: envolver notas con `v-if="!blindMode"` |
| Frontend | `internal_notes` en SalesOrder/SalesQuote: validar que solo roles seller/admin las vean |
| QA | Cashier/cliente NO deben ver notas internas en ninguna vista |

---

### #19 - Cantidad de inventario en 0, no negativos
**Complejidad: Pequena | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | CHECK constraint `quantity_available >= 0` ya existe |
| Backend | Setting `allow_negative_inventory` -> default `false` |
| Backend | Auditar `InventoryMovementService.php` para bloquear movimientos que lleven a negativo |
| Frontend | Agregar `min="0"` a TODOS los inputs de cantidad de inventario |
| QA | Intentar poner inventario en -1 -> debe rechazar en frontend y backend |

---

## Sprint 2: Rediseno de Variantes y Pricing ✅ COMPLETADO

### #1 - Costo no va en categorias, va en variables/producto
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Deprecar `default_cost` de tabla `categories` (dejar columna pero sin uso) |
| Backend | `Category.php`: quitar `default_cost` de fillable/casts |
| Backend | `CategoryController.php`: dejar de aceptar/retornar `default_cost` |
| Frontend | `Create.vue` (productos): quitar `categoryDefaultCost` ref |
| Frontend | `VariantManager.vue`: quitar auto-fill de cost desde categoria |
| Frontend | `Categories/Create.vue` y `Edit.vue`: quitar campo default_cost del form |
| QA | Crear categoria -> NO debe pedir costo. Crear producto -> costo debe estar en variables |

---

### #6 y #14(variante) - Variante = marca + proveedor + codigo + precio
**Complejidad: Grande | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nueva migracion: agregar `supplier_id` FK nullable a `product_variants` |
| Backend | `ProductVariant.php`: agregar `supplier_id` a fillable, relacion `supplier()` BelongsTo |
| Backend | `ProductService.php`: actualizar logica de creacion de variantes para aceptar supplier_id |
| Frontend | `VariantManager.vue`: agregar dropdown de proveedor (SearchableSelect cargando `/suppliers/all`) |
| Frontend | Renombrar "Custom SKU" a "Codigo" |
| Frontend | Quitar campos status dropdown e is_default del form de variante |
| Frontend | Agregar campo precio prominente en el form de variante |
| Frontend | Columnas de tabla de variantes: Marca, Proveedor, Codigo, Precio |
| QA | Crear variante con marca+proveedor+codigo+precio -> debe guardarse correctamente |

---

### #5 - Especificaciones tecnicas en editar producto (no solo en variante)
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `Products/Edit.vue`: agregar seccion collapsible "Especificaciones Tecnicas" |
| Frontend | Cargar/guardar atributos via API `/products/{id}/attributes` (endpoints ya existen) |
| Frontend | Campos dinamicos clave-valor reutilizando patron de `VariantManager.vue` |
| QA | Editar producto existente -> poder agregar/modificar/eliminar especificaciones |

---

### #18 - Solo precio y costo, no precio total ni precio base
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| Frontend | `VariantManager.vue` pricing: ocultar MSRP, tax_type. Solo mostrar Cost y Price |
| Frontend | `ProductDetailModal.vue`: quitar "Precio Total", mostrar solo "Precio" y "Costo" en modo seller |
| Frontend | `Create.vue`: simplificar `form.pricing` a solo `cost` y `base_price` |
| Backend | No cambiar schema. Dejar columnas msrp/tax_rate pero no exponerlas al usuario |
| QA | En creacion y edicion solo deben verse 2 campos de precio: Costo y Precio |

---

### #9 y #16 - Arreglar errores en formulas (CALCULO INCORRECTO)
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| Backend | `PricingFormulaService.php`: BUG CRITICO - patron `[^()]*` no maneja parentesis anidados |
| Backend | Fix: implementar parser recursivo con stack-based matching para parentesis |
| Backend | Fix: cambiar `==` a `===` para comparacion de division por cero |
| Frontend | `PricingFormula.vue`: watchers competidores causan doble conteo de ITBM |
| Frontend | Fix: consolidar watchers, detectar si formula ya incluye ITBM para no aplicar doble |
| QA | Formula `costo * 2` con costo=100 -> debe dar 200. `ROUND(costo * (1.5 + 0.5))` con costo=100 -> debe dar 200 |

---

## Sprint 3: UI/UX y Busqueda ✅ COMPLETADO (2026-04-09)

### #15, #16, #21, #30 - Crear busqueda standard inline (sin popups)
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | Crear nuevo componente `ProductInlineSearch.vue`: autocomplete/combobox con dropdown |
| Frontend | Reemplazar `ProductSearchModal.vue` (modal) en POS y cotizaciones |
| Frontend | `POS/Index.vue`: usar busqueda inline en vez de modal |
| Frontend | Asegurar consistencia: mismo search en POS, cotizaciones y pagina de productos |
| QA | Buscar producto en POS -> resultados deben aparecer debajo del campo, sin abrir modal |

**Nota**: "El search de Joshua" aun no existe, se crea desde cero.

---

### #11 y #15(descuento) - Eliminar columna de descuento o hacerla opcional
**Complejidad: Pequena | Frontend + Backend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductTable.vue`: ocultar columnas de descuento por default |
| Frontend | Agregar toggle/setting `show_discount_column` en configuracion |
| Frontend | `SalesOrders/Show.vue`: ocultar columnas si todos los valores son 0 |
| QA | Por default no debe verse la columna de descuento. Al activar toggle, debe aparecer |

---

### #19(impuestos) - Quitar columna de impuesto y descuento inline, que salga abajo
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductTable.vue`: quitar columnas inline de tax_percent y discount_percent |
| Frontend | Agregar seccion resumen DEBAJO de la tabla: Subtotal, Descuento total, Impuesto total, TOTAL |
| Frontend | Mismo approach para `SalesQuotes/Editor.vue` |
| QA | La tabla: Producto, Cantidad, Precio, Total por linea. Impuestos/descuentos en resumen abajo |

---

### #26 - Popup del cliente con menos info posible
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductDetailModal.vue` en `blindMode` mostrar SOLO: Imagen, Categoria, System ID, Variante, Precio |
| Frontend | Ocultar: OEM refs, alternativas, specs tecnicas, costo, stock, controles de carrito |
| QA | En modo cliente, popup debe ser minimalista con solo los 5 datos mencionados |

---

### #10 y #24 - Quitar ref cruzadas y poner abajo de VIN en cuadro aparte
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductDetailModal.vue`: mover seccion OEM references debajo de seccion VIN/compatibilidad |
| Frontend | Envolver en card/cuadro separado visualmente |
| QA | Las referencias cruzadas deben aparecer DEBAJO del VIN, en su propio recuadro |

---

### #22 - Cola de bodega como ventana principal (no submenu)
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `AppSidebar.vue`: mover "Cola de Bodega" de submenu Inventario a item de nivel superior |
| QA | "Cola de Bodega" debe aparecer como opcion principal en sidebar |

---

## Sprint 4: System ID y Vehiculos ✅ COMPLETADO (2026-04-10)

### #27 - Crear System ID tipo 00000001
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nueva migracion: agregar columna `system_id` (VARCHAR, unique) a `products` |
| Backend | `Product.php`: agregar a fillable, accessor que zero-pad a 8 digitos |
| Backend | `ProductService.php`: auto-generar `system_id` al crear producto (max+1, pad left) |
| Frontend | `ProductDetailModal.vue`: reemplazar `#{{ product.id }}` con `product.system_id` |
| QA | Primer producto: "00000001", segundo: "00000002", etc |

---

### #31 y #33 - System ID diferente por marca
**Complejidad: Grande | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nueva migracion: tabla `brand_sequences` (brand_id FK, next_value INT) |
| BD | Agregar `system_id` a `product_variants` |
| Backend | `ProductService.php`: al crear variante, obtener next_value de la marca e incrementar |
| Backend | Formato: `{brand_prefix}{padded_number}` ej. "BOX00000001" |
| Frontend | Mostrar system_id por marca en todos los displays de variante |
| QA | Crear variante Bosch -> "BOX00000001". Crear variante Denso -> "DEN00000001" |

---

### #5(vehiculos) - Motores mas organizados con filtros
**Complejidad: Media | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `Vehicles/Engines/Index.vue`: reemplazar tree view con tabla/lista filtrable |
| Frontend | Agregar sidebar de filtros: dropdown marca, busqueda texto |
| QA | Filtrar motores por marca -> solo motores de esa marca |

---

### #9(vehiculos) y #23 - Especificar que modelo de auto es
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `ProductDetailModal.vue` tabla compatibilidad: hacer columna modelo mas prominente |
| Frontend | Agregar rango de anos visible ("2018-2023") |
| QA | Compatibilidad debe mostrar: "Toyota Corolla 2018-2023" |

---

## Sprint 5: Inventario y Compras ✅ COMPLETADO (2026-04-10)

### #24 - Arreglar ordenes de compra
**Complejidad: Pequena | Solo Frontend**

| Seccion | Accion |
|---------|--------|
| Frontend | `AppSidebar.vue`: verificar que OC estan bajo "Compras" |
| Frontend | Asegurar terminologia consistente en todo el sistema |
| QA | Menu "Compras" debe contener "Ordenes de Compra" |

---

### #25 - Agregar proveedores en las entradas
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nueva migracion: agregar `supplier_id` FK nullable a `inventory_movements` |
| Backend | `InventoryMovement.php`: agregar `supplier_id` a fillable, relacion `supplier()` |
| Frontend | `InventoryMovements/CreateInbound.vue`: agregar dropdown de proveedor |
| QA | Crear entrada de inventario -> debe poder seleccionar proveedor |

---

### #3 - Opcion sin abastecer no se puede cotizar
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nuevo setting: `require_stock_for_quotes` (boolean, default false) |
| Backend | `SalesQuoteController.php`: si setting activo, validar stock > 0 |
| Frontend | `settings/Store.vue`: agregar toggle "Requerir stock para cotizar" |
| QA | Con setting activo: intentar cotizar producto sin stock -> debe mostrar error |

---

### #4(lote) - Cantidad en OC vs inventario (unidad, no lote)
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| Backend | Investigar flujo: PO `quantity_ordered` -> recepcion -> inventory |
| BD | Si no hay campo `units_per_package`, agregar migracion |
| Frontend | Mostrar claramente "Cantidad (unidades)" en PO y en inventario |
| QA | Crear OC con 5 unidades, lote de 10 -> debe mostrar "5" no "50" |

---

### #17 - Crear formulas desde admin
**Complejidad: Grande | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Nueva migracion: tabla `pricing_formulas` (id, name, formula, description, is_active) |
| Backend | Nuevo modelo `PricingFormula`, nuevo controller `PricingFormulaController` (CRUD) |
| Frontend | Nueva pagina: `pages/Settings/PricingFormulas.vue` con editor de expresiones |
| Frontend | `PricingFormula.vue`: reemplazar PRESETS hardcodeados con formulas de API |
| Seguridad | Limitar funciones permitidas a aritmetica basica (no eval arbitrario) |
| QA | Crear formula "costo * 1.8" desde admin -> debe aparecer como opcion en productos |

---

## Sprint 6: Features Nuevos (Semana 11-12) ← SIGUIENTE

### #20 - Articulos miscelaneos en cotizacion y POS
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| BD | Agregar `is_custom_line` y `custom_description` a `sales_order_items` y `sales_quote_items` |
| Backend | `SalesOrderService.php`: saltar deduccion de inventario si `is_custom_line = true` |
| Frontend | `ProductTable.vue`: boton "Agregar linea personalizada" |
| Frontend | Fila editable: descripcion (texto libre) + precio + cantidad, sin vincular a producto |
| QA | Agregar "Servicio de instalacion - $50" como linea miscelanea -> no toca inventario |

---

### #23 - Borrar la data para empezar de 0
**Complejidad: Media | Ambos**

| Seccion | Accion |
|---------|--------|
| Backend | Nuevo comando Artisan: `php artisan app:clear-data` que trunca tablas en orden FK |
| Backend | Nuevo endpoint admin-only con DOBLE confirmacion (password + texto "CONFIRMAR") |
| Backend | Crear backup automatico antes de borrar |
| Frontend | Boton "Limpiar todos los datos" con dialogo de doble confirmacion |
| QA | Borrar datos -> tablas vacias excepto configuracion y usuarios |

---

### #34 - Importacion desde Excel
**Complejidad: Grande | Ambos**

| Seccion | Accion |
|---------|--------|
| Backend | Usar package `maatwebsite/excel` (ya instalado) |
| Backend | Nuevo controller `ProductImportController` + service `ProductImportService` |
| Backend | Nuevas rutas: `POST /products/import`, `GET /products/import/template` |
| Frontend | Nueva pagina `pages/Products/Import.vue`: upload, preview/mapeo, barra de progreso |
| Frontend | `Products/Index.vue`: boton "Importar desde Excel" |
| QA | Subir Excel con 10 productos -> crearse correctamente con variantes y precios |

**Template Excel**: SKU | Nombre | Categoria | Marca | Proveedor | Codigo | Costo | Precio | Descripcion

---

## Archivos Criticos

| Area | Archivo | Cambios |
|------|---------|---------|
| Crear producto | `pos-frontend/.../pages/Products/Create.vue` | #1,#2,#3,#4,#8,#11 |
| Editar producto | `pos-frontend/.../pages/Products/Edit.vue` | #5 |
| Variantes | `pos-frontend/.../components/Products/VariantManager.vue` | #1,#4,#5,#6,#8 |
| Popup producto | `pos-frontend/.../components/Products/ProductDetailModal.vue` | #7,#10,#24,#26,#28,#29 |
| Sidebar | `pos-frontend/.../components/AppSidebar.vue` | #22,#24 |
| Filtros | `pos-frontend/.../components/Products/ProductFilters.vue` | #14 |
| POS tabla | `pos-frontend/.../components/POS/ProductTable.vue` | #11,#19,#20 |
| Busqueda POS | `pos-frontend/.../components/POS/ProductSearchModal.vue` | #15,#16,#21,#30 |
| Formulas FE | `pos-frontend/.../components/Products/PricingFormula.vue` | #9,#17 |
| Formulas BE | `pos-backend/app/Services/PricingFormulaService.php` | #9,#16 |
| ProductVariant | `pos-backend/app/Models/ProductVariant.php` | #1,#6,#27,#31 |
| Product | `pos-backend/app/Models/Product.php` | #27 |
| Category | `pos-backend/app/Models/Category.php` | #1 |
| ProductService | `pos-backend/app/Services/ProductService.php` | #3,#6,#27,#31 |
| SalesQuoteCtrl | `pos-backend/.../Controllers/Api/SalesQuoteController.php` | #3,#20 |
| InventoryMovement | `pos-backend/app/Models/InventoryMovement.php` | #25 |
| Categories FE | `pos-frontend/.../pages/Categories/Create.vue` | #1 |
| Settings FE | `pos-frontend/.../pages/Settings/` | #17,#19,#23 |

## Verificacion

Para cada sprint:
1. `php artisan test` en backend despues de cada cambio
2. Verificar visualmente en frontend (localhost:3000)
3. Flujo completo: crear producto > agregar inventario > cotizar > vender
4. Verificar formulas: `costo * 2`, `ROUND(costo * 1.5)`, `costo + costo * ITBM`
5. Busqueda con filtros de categoria: seleccionar, buscar, volver a "todas"
6. Permisos por rol: admin, seller, cashier
7. Crear producto sin marca/sin variante/con SKU auto-generado
8. Articulos miscelaneos no afectan inventario
9. System IDs se generan correctamente por marca
10. Import Excel crea productos validos
