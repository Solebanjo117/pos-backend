# Unificación de Formularios Create/Edit de Productos

## Contexto

`Create.vue` (1735 líneas) y `Edit.vue` (1229 líneas) son templates completamente independientes con ~600 líneas de lógica y markup duplicados. Esto genera bugs divergentes, mantenimiento doble, e inconsistencias funcionales.

---

## 1. Orden Actual de Secciones (MANTENER EXACTO)

### Create.vue — Secciones del Template (en orden)

| # | Sección | Líneas | Descripción |
|---|---------|--------|-------------|
| 0 | Header + Botones (Cancelar/Crear) | 1092–1108 | Arriba del form |
| 0.5 | Duplicate SKU Warning | 1112–1133 | Banner amarillo si `duplicates.length > 0` |
| 1 | **Descripción** | 1137–1168 | `form.name` (con auto-preview) + Categoría (SearchableSelect + botón crear) |
| 2 | **Código y Estado** | 1170–1192 | `form.sku_base` (mono, opcional) + `form.status` (select) |
| 3 | **Códigos Alternos** | 1194–1247 | Input código + SearchableSelect marca + tabla de `addedRefs[]` |
| 4 | **Compatibilidad Vehicular** | 1249–1431 | Colapsable. Universal toggle + tabs Motores/Chasis + tabla combinada |
| 5 | **Atributos** | 1433–1490 | Draft array `variantAttributes[]` con inputs inline |
| 6 | **Upsales** | 1492–1546 | AsyncProductCombobox + tipo relación + tabla `pendingUpsales[]` |
| 7 | **Notas** | 1548–1554 | `form.description` (textarea) |
| 8 | **Imagen del Producto** | 1556–1583 | `pendingImages[]` con preview local |
| 9 | **Variantes** | 1585–1612 | `VariantManager` en draft mode (`@draft-variants-changed`) |
| — | **Inline Modal** | 1617–1731 | Categoría, Marca artículo, Make, Model, Engine, Chassis |

### Edit.vue — Secciones del Template (en orden)

| # | Sección | Líneas | Descripción |
|---|---------|--------|-------------|
| 0 | Header (Editar + Duplicar) | 672–683 | Botón duplicar arriba |
| — | Loading + Success/Error | 686–700 | |
| 1 | **Producto Base** (form submit) | 707–753 | Código + Estado + Descripción (`form.name`) + botones Guardar/Cancelar |
| 2 | **Códigos Alternos** | 758–760 | `<OemReferenceManager :product-id>` (componente independiente) |
| 4 | **Compatibilidad Vehicular** | 764–995 | Siempre visible. Universal toggle + tabs + tabla con API inline |
| 5 | **Variantes** | 998–1011 | `<VariantManager :product-id>` en live mode |
| 5.5 | **Atributos** | 1016–1025 | `<AttributeManager :product-id>` (componente independiente) |
| 6 | **Upsales** | 1028–1032 | `<AlternativeManager :product-id>` (componente independiente) |
| 7 | **Notas** | 1037–1046 | `form.description` (textarea) |
| 8 | **Imagen del Producto** | 1050–1104 | Gallery con carousel, upload/delete via API |
| — | **Inline Modal** | 1110–1227 | Make, Model, Engine, Chassis (NO categoría, NO marca artículo) |

> [!IMPORTANT]
> **Diferencias de orden:** Create tiene "Descripción" y "Código y Estado" como secciones separadas (1 y 2). Edit las combina en "Producto Base" (1). Create tiene Upsales como sección 6 con UI propia; Edit usa `<AlternativeManager>`.

---

## 2. Flujo de Datos Completo

### Create.vue — Inicialización (`onMounted`)

```
1. fetchStoreSettings()           → storeSettings (para rounding increment)
2. GET /categories                → categories[]
3. GET /brands/all                → brands[]
4. GET /warehouses                → auto-set form.initial_inventory[0].warehouse_id
5. fetchMakes()                   → vehicleMakes[]
6. fetchVehicleChassisCatalog()   → vehicleChassisCatalog[]
```

### Create.vue — Watchers Activos

| Watcher | Trigger | Efecto |
|---------|---------|--------|
| `form.sku_base` | Debounced 500ms | `GET /products/check-duplicate?sku=...` → `duplicates[]` |
| `(form).category_id` | Cambio categoría | `GET /categories/{id}` → merge atributos default + set fórmula/ITBM/margin |
| `form.pricing.cost` | Cambio costo | Auto-calcula `base_price` usando fórmula o margen de categoría |
| `form.pricing.base_price` | Cambio precio | Reset `custom_total` |
| `namePreview` | Computed reactivo | Auto-set `form.name` si no fue editado manualmente |
| `selectedMakeId` | Cambio marca | `GET /vehicle-makes/{id}/models` → vehicleModels[] |
| `selectedModelId` | Cambio modelo | `GET /vehicle-models/{id}/engines` + `GET /vehicle-models/{id}/chassis` |

### Create.vue — Payload del Submit (`handleSubmit`)

```typescript
// Paso 1: Crear producto principal
POST /api/v1/products
{
    sku_base, name, description, status,
    category_id, product_line_id, engine_id,
    alternate_code, pricing_formula,
    brand_id,                              // Default variant brand
    sku: form.sku || form.sku_base || '',  // Default variant SKU
    upc,
    pricing: {
        base_price, msrp, cost,
        tax_rate: normalizedSaleTaxRate,
        sale_tax_rate: normalizedSaleTaxRate,
        cost_tax_rate: normalizedCostTaxRate,
    },
    initial_inventory: [{ warehouse_id, stock_available, reorder_point, reorder_quantity }],
    attributes: variantAttributes.filter(a => a.name && a.value),
    vehicle_compatibility: addedEngines.map(e => e.engine_id),  // number[]
    vehicle_chassis: addedChassis.map(c => c.chassis_id),        // number[]
    all_engines: boolean,
    all_chassis: boolean,
    is_universal: boolean,
}
// Respuesta: { data: { id, ... } }

// Paso 2: OEM References (secuencial, post-create)
for (ref of addedRefs) {
    POST /api/v1/products/{productId}/oem-references
    { oem_code, manufacturer }
}

// Paso 3: Draft variants (secuencial, post-create)
for (variant of pendingVariants) {
    POST /api/v1/products/{productId}/variants
    { brand_id, sku, upc, status, pricing, initial_inventory }
}

// Paso 4: Upsales (secuencial, post-create)
for (upsale of pendingUpsales) {
    POST /api/v1/products/{productId}/alternatives
    { alternative_product_id, relationship_type }
}

// Paso 5: Imágenes (batch, post-create)
POST /api/v1/products/{productId}/images
FormData { images[]: File[] }

// Paso 6: Redirect
setTimeout(() => window.location.href = '/products', 1000)
```

### Edit.vue — Inicialización (`onMounted`)

```
1. fetchProduct(productId)        → currentProduct, populate form
2. GET /categories/{categoryId}   → categoryFormula, categoryItbm, categoryMargin (si tiene categoría)
3. fetchImages()                  → productImages[]
4. fetchCompatibleEngines()       → compatibleEngines[], compatibleChassis[], allEnginesFlag, allChassisFlag
5. fetchVehicleMakes()            → vehicleMakes[]
6. fetchVehicleChassisCatalog()   → vehicleChassisCatalog[]
```

### Edit.vue — Payload del Submit (`handleSubmit`)

```typescript
// Solo info base del producto:
PUT /api/v1/products/{productId}
{ sku_base, upc, alternate_code, name, description, status, is_universal, batch_tracking }
// Respuesta: { data: { ... } }

// TODO: No hay redirect — usuario permanece en la página
```

### Edit.vue — Operaciones Inline (NO en submit)

| Operación | Endpoint | Cuándo |
|-----------|----------|--------|
| Agregar motor compatible | `POST /product-compatibility { product_id, engine_id }` | Al click "Agregar" |
| Agregar chasis compatible | `POST /product-compatibility { product_id, chassis_id }` | Al click "Agregar" |
| Marcar all_engines | `POST /product-compatibility { product_id, all_engines: true }` | Al toggle checkbox |
| Marcar all_chassis | `POST /product-compatibility { product_id, all_chassis: true }` | Al toggle checkbox |
| Eliminar compatibilidad | `DELETE /product-compatibility/{compatId}` | Al click eliminar |
| Toggle universal | `PUT /products/{productId} { is_universal }` | Al toggle checkbox |
| Upload imagen | `POST /products/{productId}/images` FormData | Inmediato |
| Delete imagen | `DELETE /products/{productId}/images/{imageId}` | Inmediato |
| Duplicar producto | `POST /products/{productId}/duplicate` | Al click "Duplicar" → redirect |
| OEM refs | Via `<OemReferenceManager>` component | Inline save |
| Atributos | Via `<AttributeManager>` component | Inline save |
| Upsales | Via `<AlternativeManager>` component | Inline save |
| Variantes | Via `<VariantManager :product-id>` | Inline save |

---

## 3. Diferencias Clave de Comportamiento

| Aspecto | Create | Edit |
|---------|--------|------|
| **Persistencia** | TODO draft hasta submit final | Cada sección se guarda independientemente |
| **Vehicle compat** | Array en memoria → enviado con payload | POST/DELETE directos a API |
| **Atributos** | Array draft `variantAttributes[]` | `<AttributeManager>` con API |
| **OEM refs** | Array draft `addedRefs[]` → POST post-create | `<OemReferenceManager>` con API |
| **Upsales** | Array draft `pendingUpsales[]` → POST post-create | `<AlternativeManager>` con API |
| **Imágenes** | File objects → upload post-create | Gallery con upload/delete inmediato |
| **Variantes** | Draft mode (`@draft-variants-changed`) | Live mode (`:product-id`) |
| **Auto-nombre** | Computed `namePreview` basado en categoría + compat | No existe |
| **Pricing inline** | Sección completa con cost/tax/base/fórmula | No existe (se maneja en VariantManager) |
| **Duplicate check** | Watcher en `sku_base` | No existe |
| **Inline modals** | 6 tipos (category, brand, + 4 vehicle) | 4 tipos (solo vehicle) |
| **Post-submit** | Redirect a `/products` | Permanece en la página |

---

## 4. Propuesta de Arquitectura

### Estrategia: Extraer componentes compartidos

En lugar de crear un monolito `ProductForm.vue`, la estrategia es extraer las **secciones duplicadas** a componentes reutilizables que ambas páginas importen. Las páginas wrapper (`Create.vue` y `Edit.vue`) se encargan de la orquestación (draft vs inline).

```
components/Products/
├── VehicleCompatSection.vue      [NEW] — Toda la UI de compatibilidad vehicular
├── VehicleInlineModals.vue       [NEW] — Modals de Make/Model/Engine/Chassis
├── ProductImagesSection.vue      [NEW] — Gallery (edit) + pending (create) via prop mode
├── ProductNotesSection.vue       [NEW] — Textarea de notas (trivial, DRY)
├── ProductBaseInfoSection.vue    [NEW] — SKU, nombre, estado, categoría
├── VariantManager.vue            [EXISTS] — Ya compartido
├── OemReferenceManager.vue       [EXISTS] — Solo Edit
├── AlternativeManager.vue        [EXISTS] — Solo Edit
├── AttributeManager.vue          [EXISTS] — Solo Edit
└── PricingFormula.vue            [EXISTS] — Solo Create
```

### ¿Qué NO se unifica?

- **`handleSubmit`** — Completamente diferente entre Create (payload monolítico + POST secuenciales) y Edit (PUT simple). Cada página mantiene su propia función.
- **Watchers de auto-nombre** — Exclusivo de Create (namePreview, category watch, sku duplicate check).
- **Pricing section inline** — Exclusivo de Create (Edit lo maneja desde VariantManager).
- **La sección "Descripción + Categoría"** de Create vs "Producto Base" de Edit tienen layouts distintos; se unifican SOLO si el componente soporta ambos via props.

---

## 5. Fases de Implementación

### Fase 1: `VehicleCompatSection.vue` (~200 líneas duplicadas → 1 componente)

**El componente más impactante.** La UI de tabs Motores/Chasis, los selects de Make/Model/Engine/Chassis, y la tabla combinada son prácticamente idénticos.

#### Props del componente:
```typescript
interface Props {
    mode: 'draft' | 'live'               // draft=Create, live=Edit
    productId?: number                    // Solo en live mode
    // Draft mode data (v-model)
    modelValue?: {
        engines: EngineCompatibilityEntry[]
        chassis: ChassisCompatibilityEntry[]
        allEngines: boolean
        allChassis: boolean
        isUniversal: boolean
    }
}
```

#### Comportamiento por modo:
- **`draft`**: Los arrays `engines[]` y `chassis[]` se emiten via `@update:modelValue`. No hace API calls.
- **`live`**: Hace `POST /product-compatibility` y `DELETE /product-compatibility/{id}` directamente. Carga datos con `GET /products/{productId}/compatibility`.

#### Archivos a modificar:
- [NEW] `components/Products/VehicleCompatSection.vue` — ~350 líneas
- [MODIFY] `pages/Products/Create.vue` — Reemplazar líneas 1249–1431 por `<VehicleCompatSection mode="draft">`
- [MODIFY] `pages/Products/Edit.vue` — Reemplazar líneas 764–995 por `<VehicleCompatSection mode="live">`

---

### Fase 2: `VehicleInlineModals.vue` (~120 líneas duplicadas → 1 componente)

Los 4 modals de creación rápida (Make, Model, Engine, Chassis) son casi idénticos entre Create y Edit.

#### Props:
```typescript
interface Props {
    currentModal: 'vehicleMake' | 'vehicleModel' | 'vehicleEngine' | 'vehicleChassis' | null
    selectedMakeId: number | null
    selectedModelId: number | null
    vehicleChassisCatalog: VehicleChassisCatalogItem[]
}
```

#### Emits:
```typescript
'close'
'make-created': (make) => void
'model-created': (model) => void
'engine-created': (engine) => void
'chassis-created': (chassis) => void
```

#### Archivos a modificar:
- [NEW] `components/Products/VehicleInlineModals.vue` — ~200 líneas
- [MODIFY] `pages/Products/Create.vue` — Reemplazar líneas 1654–1722 por el componente (mantener modals de category/brand separados que son solo de Create)
- [MODIFY] `pages/Products/Edit.vue` — Reemplazar líneas 1110–1212 por el componente

---

### Fase 3: `ProductImagesSection.vue`

#### Props:
```typescript
interface Props {
    mode: 'pending' | 'gallery'       // pending=Create, gallery=Edit
    productId?: number                 // Solo en gallery mode
    pendingImages?: PendingImage[]     // Solo en pending mode
}
```

#### Archivos a modificar:
- [NEW] `components/Products/ProductImagesSection.vue` — ~150 líneas
- [MODIFY] `pages/Products/Create.vue` — Reemplazar líneas 1556–1583
- [MODIFY] `pages/Products/Edit.vue` — Reemplazar líneas 1050–1104

---

### Fase 4: Limpieza y reducción

Después de extraer los 3 componentes principales:
- **Create.vue**: De ~1735 líneas a ~1100 líneas (~36% reducción)
- **Edit.vue**: De ~1229 líneas a ~650 líneas (~47% reducción)

Las secciones restantes (Descripción, Código, Pricing, Atributos draft, OEM refs draft, Upsales draft) son exclusivas de Create y NO se extraen porque solo se usan ahí.

---

## 6. Detalle de datos que NO deben cambiar

### Create: Payload enviado al backend
El payload de `handleSubmit` debe seguir enviando exactamente:
- `vehicle_compatibility: number[]` (array de engine_ids)
- `vehicle_chassis: number[]` (array de chassis_ids)
- `all_engines: boolean`
- `all_chassis: boolean`
- `is_universal: boolean`
- `attributes: { name, value, unit }[]`
- Post-create: OEM refs, variants, upsales, images (secuencialmente)

### Edit: Operaciones inline
- Compatibilidad sigue usando `POST/DELETE /product-compatibility`
- El submit de Edit sigue enviando solo campos base via `PUT /products/{id}`
- Cada sub-componente (`OemReferenceManager`, `AttributeManager`, etc.) sigue gestionando su propia persistencia

---

## User Review Required

> [!IMPORTANT]
> **Fase 1 es la de mayor impacto y riesgo.** `VehicleCompatSection` toca la lógica más duplicada y compleja. Recomiendo ejecutarla primero y verificar completamente antes de continuar con Fases 2 y 3.

> [!WARNING]
> **No se toca el `handleSubmit` de Create.** La secuencia de POST es crítica (producto → refs → variants → upsales → images). Cualquier error rompe la creación completa.

## Open Questions

1. **¿Quieres que Edit herede features de Create?** Ejemplo: auto-nombre, duplicate check, pricing formula inline. Si sí, eso sería una **Fase 5** separada que agrega funcionalidad nueva a Edit.

2. **¿OEM refs y Upsales en Create deben migrar a componentes también?** Actualmente Create usa arrays draft propios. Podríamos hacer que `OemReferenceManager` y `AlternativeManager` soporten un `mode="draft"` igual que el `VehicleCompatSection`. Eso sería una **Fase 6**.

3. **¿Prioridad de ejecución?** ¿Fase 1 ahora y las demás después? ¿O todo de una vez?

---

## Verification Plan

### Automated Tests
```bash
php artisan test tests/Feature/P1/ --stop-on-failure   # Product create/update/duplicate
npm run build                                           # TypeScript compila sin errores
```

### Manual Verification por Fase

#### Fase 1 (VehicleCompatSection):
- [ ] Create: Agregar motores/chasis a draft → verificar que el payload de submit los incluye
- [ ] Create: Toggle universal → verificar que limpia engines/chassis
- [ ] Create: Tab motores/chasis funciona correctamente
- [ ] Edit: Agregar motor → verificar POST a `/product-compatibility`
- [ ] Edit: Eliminar motor → verificar DELETE
- [ ] Edit: Toggle all_engines checkbox → verificar POST/DELETE
- [ ] Edit: Tabla de compatibilidad carga datos existentes

#### Fase 2 (VehicleInlineModals):
- [ ] Create: Crear marca → se selecciona automáticamente
- [ ] Create: Crear modelo → se selecciona automáticamente
- [ ] Create: Crear motor → se agrega a la selección múltiple
- [ ] Create: Crear chasis desde modal de modelo → flujo de retorno funciona
- [ ] Edit: Mismas verificaciones que Create

#### Fase 3 (ProductImagesSection):
- [ ] Create: Seleccionar imágenes → preview visible → se suben post-create
- [ ] Edit: Gallery con carousel, upload, delete funcional
