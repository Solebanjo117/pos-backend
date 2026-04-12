# Sprint 3: UI/UX y Búsqueda — Plan de Implementación

**Fecha**: 2026-04-09
**Estado**: Pendiente de aprobación
**Estimado**: ~5 horas de implementación

---

## Contexto

Sprint 3 se enfoca en mejoras de UI/UX: simplificar la búsqueda del POS (reemplazar modal con inline), limpiar columnas de descuento/impuesto en tablas, simplificar el popup para clientes, y reorganizar referencias OEM en el modal de producto. Task #22 (Cola de bodega como ventana principal) ya se completó en Sprint 2.

## Tareas

| # | Tarea | Tiempo | Riesgo | Tipo |
|---|-------|--------|--------|------|
| 3.5 | OEM refs debajo de VIN, alternativas colapsables | 20 min | Muy bajo | Frontend |
| 3.4 | Popup cliente simplificado (solo imagen, categoría, ID, marca, precio) | 30 min | Muy bajo | Frontend |
| 3.2/3.3 | Quitar descuento/impuesto inline de tablas | 1 hora | Bajo | Frontend |
| 3.1 | Búsqueda inline en POS (sin popups) | 3 horas | Medio | Frontend |
| ~~3.6~~ | ~~Cola de bodega como ventana principal~~ | — | — | ✅ Hecho en Sprint 2 |

**Orden**: 3.5 → 3.4 → 3.2/3.3 → 3.1 (quick wins primero, cambio más grande al final)

---

## Task 3.5: Reorganizar Referencias OEM (#10/#24)

### Objetivo
Mover OEM refs debajo de Vehículos Compatibles (ancho completo). Hacer Alternativas colapsables.

### Archivo
- `pos-frontend/.../components/Products/ProductDetailModal.vue`

### Cambios
1. Agregar `const showAlternatives = ref(false)` en script
2. Romper el grid lado-a-lado (línea 544) en secciones separadas
3. OEM: card ancho completo directamente después de Vehículos (línea 541)
4. Alternativas: botón toggle colapsable, cerrado por defecto

### Verificaciones
- [ ] OEM References aparecen directamente debajo de Vehículos Compatibles
- [ ] Alternativas colapsadas por defecto, expandibles con botón
- [ ] Sin cambios de layout cuando solo existe OEM o solo Alternativas

---

## Task 3.4: Simplificar Popup Cliente / Blind Mode (#26)

### Objetivo
En blind mode mostrar SOLO: Imagen, Categoría, System ID (8 dígitos), Marca de variante, Precio.

### Archivo
- `pos-frontend/.../components/Products/ProductDetailModal.vue`

### Cambios
1. **System ID**: `#{{ product.id }}` → `#{{ String(product.id).padStart(8, '0') }}` (líneas 282, 369)
2. **Categoría**: Agregar `<p v-if="blindMode && product.category?.name">` después de línea 371
3. **Ocultar en blind mode**:
   - Especificaciones Técnicas (línea 375): `v-if="!blindMode"`
   - Stock disponible (líneas 458-463): `v-if="!blindMode"`
   - Controles de cantidad (líneas 429-433): `v-if="!blindMode"`
   - Botón agregar al carrito (líneas 466-473): `v-if="!blindMode"`
   - Panel inferior completo — Vehículos, OEM, Alternativas (línea 494): `v-if="!blindMode"`

### Verificaciones
- [ ] Blind mode muestra: Imagen, Nombre categoría, ID #00000001, Marca variante, Precio con toggle ITBM
- [ ] Blind mode oculta: SKU, Descripción, Specs, Stock, Qty, Botón carrito, Vehículos, OEM, Alternativas
- [ ] Modo normal sin cambios
- [ ] Toggle ITBM sigue funcionando en blind mode

---

## Task 3.2/3.3: Quitar Descuento/Impuesto Inline (#11/#15d/#19i)

### Objetivo
Eliminar indicadores de descuento/impuesto por línea de las tablas principales. Mantenerlos en secciones expandibles y resúmenes al pie.

### Archivos y Cambios

#### 1. `components/POS/ProductTable.vue`
- **Eliminar** badge de descuento `-X% OFF` (líneas 171-173)
- **Eliminar** badge de exento `Exento` (líneas 174-176)
- **Eliminar** indicador `+X% ITBMS` del precio (líneas 190-193)
- **Mantener** badges de vendedor y nota, sección expandible completa

#### 2. `components/POS/SummarySection.vue`
- **Agregar** prop `discountAmount?: number`
- **Agregar** fila de Descuento (condicional) entre IVA y TOTAL
- **Pasar** prop desde POS Index.vue con total de descuento calculado

#### 3. `pages/SalesOrders/Show.vue`
- **Eliminar** columnas `<th>Descuento</th>` y `<th>Impuestos</th>` (líneas 323-324)
- **Eliminar** celdas `<td>` correspondientes (líneas 346-350)
- **Mantener** resumen al pie con totales agregados

#### 4. `pages/SalesQuotes/Show.vue`
- **Eliminar** columnas `<th>Desc.</th>` y `<th>Impuesto</th>` (líneas 288-289)
- **Eliminar** celdas `<td>` correspondientes (líneas 311-317)
- **Mantener** resumen al pie con totales agregados

### NO se cambian
- `SalesOrders/Edit.vue` — ya no tiene columnas de descuento/impuesto
- `SalesOrders/Create.vue` — formulario admin, mantener controles
- `SalesQuotes/Edit.vue` — ya simplificado

### Verificaciones
- [ ] POS: tabla sin badges de descuento ni indicador ITBMS en fila principal
- [ ] POS: sección expandible aún tiene controles completos de descuento/impuesto
- [ ] POS: Summary muestra Subtotal, Descuento (si > 0), IVA, TOTAL
- [ ] SalesOrders/Show: tabla sin columnas Descuento/Impuestos, resumen intacto
- [ ] SalesQuotes/Show: tabla sin columnas Desc./Impuesto, resumen intacto

---

## Task 3.1: Búsqueda Inline en POS (#15/#16/#21/#30)

### Objetivo
Reemplazar ProductSearchModal (popup) con búsqueda inline. Al escribir, resultados aparecen en dropdown debajo del input. Click en resultado agrega al carrito directamente.

### Nuevo Componente: `components/POS/POSInlineSearch.vue`

**Comportamiento:**
- Input de búsqueda + dropdowns de categoría/marca (migrados de POSControls)
- Al escribir (debounce 300ms, min 2 chars): `GET /products?search=X&per_page=10&warehouse_id=Y`
- Dropdown posicionado absolutamente (z-50) debajo del input
- Cada resultado muestra: nombre, marca/categoría, precio, badge de stock
- Teclado: ArrowUp/Down para navegar, Enter para seleccionar, Escape para cerrar
- Al seleccionar:
  - 0-1 variantes → emit `product-selected` (padre agrega al carrito)
  - 2+ variantes → emit `product-needs-variant` (padre abre VariantSelectModal)
  - Limpiar input, re-focus
- Link "Buscar todos" al pie del dropdown abre ProductSearchModal (fallback)

**Props:**
```typescript
categories?: Category[]
brands?: Brand[]
warehouseId?: number | null
disabled?: boolean
```

**Emits:** `product-selected`, `product-needs-variant`, `open-full-search`

**Patrones a reutilizar:**
- `useDebounceFn` de `@vueuse/core`
- `apiClient.get('/products', { params })` (de `AsyncProductCombobox.vue`)
- Navegación por teclado de `AsyncProductCombobox.vue`
- `formatCurrency` de `@/utils/formatters`

### Cambios en `pages/POS/Index.vue`

1. **Reemplazar** `<POSControls>` (~línea 1492) con `<POSInlineSearch>`
2. **Agregar handlers**:
   - `handleInlineSelect(product)` — reutiliza lógica existente de addToCart
   - `handleInlineVariantNeeded(product)` — reutiliza variantQueue existente
3. **Actualizar** shortcut F1 para focus en nuevo componente
4. **Mantener** ProductSearchModal como fallback avanzado

### NO se eliminan
- `POSControls.vue` — puede usarse en otro contexto
- `ProductSearchModal.vue` — se mantiene como búsqueda avanzada

### Verificaciones
- [ ] POS: escribir en búsqueda muestra dropdown con resultados
- [ ] Click en resultado agrega al carrito (productos de 1 variante)
- [ ] Productos multi-variante abren VariantSelectModal
- [ ] Input se limpia después de selección
- [ ] Navegación por teclado funciona (flechas, enter, escape)
- [ ] F1 enfoca búsqueda inline
- [ ] "Buscar todos" abre ProductSearchModal
- [ ] Dropdowns de categoría/marca filtran resultados
- [ ] Sin errores en consola

---

## Checklist General

### Frontend
- [ ] Sin errores en consola en ninguna página
- [ ] Búsqueda inline POS funciona end-to-end
- [ ] Modal de producto: blind mode muestra info mínima
- [ ] Modal de producto: OEM debajo de vehículos, alternativas colapsables
- [ ] Tabla de carrito: filas limpias sin badges de descuento/impuesto
- [ ] Resumen de carrito: muestra total de descuento cuando aplica
- [ ] SalesOrders/Show: sin columnas inline de descuento/impuesto
- [ ] SalesQuotes/Show: sin columnas inline de descuento/impuesto

### Flujo de integración
1. Abrir POS → escribir nombre → seleccionar del dropdown → se agrega al carrito
2. Abrir POS → buscar producto multi-variante → aparece VariantSelectModal → seleccionar → agrega
3. Ver detalle producto → toggle blind mode → solo Imagen, Categoría, System ID, Marca, Precio
4. Ver detalle producto → OEM refs debajo de vehículos → click toggle alternativas
5. Carrito POS → expandir item → controles de descuento/impuesto siguen funcionando
6. Ver SalesOrder completada → tabla muestra Producto, Cant., Precio, Total → resumen muestra Impuestos, Descuento, Total

### Estrategia de Commits
1. `feat: reorganize OEM refs below vehicles, make alternatives collapsible`
2. `feat: simplify blind mode to show only image, category, ID, brand, price`
3. `refactor: remove inline discount/tax badges from POS and order tables`
4. `feat: replace POS search modal with inline search dropdown`
