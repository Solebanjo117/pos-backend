# Plan: Reestructurar Sidebar de Navegacion

## Context

El sidebar actual tiene 10 grupos/items con muchas opciones que se quieren simplificar y reorganizar en 7 secciones. El objetivo es una navegacion mas limpia, agrupando las "colas" en una seccion dedicada, moviendo Categorias/Marcas/Proveedores dentro de Inventario, y renombrando Administracion a Settings.

Se necesitan **3 paginas nuevas** y **3 endpoints backend nuevos** para soportar la nueva estructura.

---

## Estructura Final del Sidebar

```
> COLA (grupo colapsable)
    - Cola Caja         -> /pos/cashier           [EXISTE]
    - Cola Bodega       -> /warehouses/queue       [EXISTE]
    - Cola Entrega      -> /queues/delivery        [NUEVA]
    - Cola Pedidos      -> /queues/purchase-orders [NUEVA]

> VENTAS (grupo colapsable)
    - Caja              -> /pos                    [EXISTE]
    - Cotizaciones      -> /sales-quotes           [EXISTE]

> INVENTARIO (grupo colapsable)
    - Productos         -> /products               [EXISTE]
    - Pedidos           -> /purchase-orders         [EXISTE]
    - Devoluciones      -> /returns                 [NUEVA]
    - Categorias        -> /categories              [EXISTE]
    - Marcas            -> /brands                  [EXISTE]
    - Proveedores       -> /suppliers               [EXISTE]

> VEHICULOS (grupo colapsable)
    - Carros            -> /vehicles               [EXISTE]
    - Motores           -> /vehicles/engines        [EXISTE]

> CLIENTES             -> /customers               [EXISTE, standalone]

> REPORTES (grupo colapsable, mantener sub-items actuales)
    - Niveles de Stock          -> /reports/stock-levels
    - Historico de Movimientos  -> /reports/movement-history
    - Finanzas y Rentabilidad   -> /reports/financial
    - Cierre de Caja (Z-Report) -> /reports/z-report

> SETTINGS (grupo colapsable, renombrar de "Administracion")
    - Usuarios              -> /users
    - Backups               -> /settings/backups
    - Bitacora              -> /settings/activity-logs
    - Config. de Tienda     -> /settings/store
    - Formulas de Precio    -> /settings/pricing-formulas
```

**Eliminados del sidebar** (paginas siguen existiendo por URL directo):
Dashboard, Ordenes de Venta, Cierres de Caja, Ordenes de Trabajo, Compras (grupo), Ver Inventario, Movimientos, Transferencias, Almacenes, Stock por Almacen, Mermas, Vehiculos > Chasis, Clientes > Cuentas por Cobrar, Catalogo (grupo)

---

## Fase 1: Backend - Nuevos Endpoints

### 1.1 Endpoint "Cola Entrega" - pendingDelivery

**Archivo**: `app/Http/Controllers/Api/SalesOrderController.php`
- Agregar metodo `pendingDelivery()` que retorne ordenes con `prepared_at IS NOT NULL` y `status = 'confirmed'` (preparadas pero no completadas/entregadas)
- Ordenar por `prepared_at ASC`
- Incluir relaciones: customer, items.product

**Ruta**: `routes/api_sales_orders.php`
- `GET /sales-orders/pending-delivery` (antes de las rutas con `{id}`)

### 1.2 Endpoint "Cola Pedidos" - pendingReceipt

**Archivo**: `app/Http/Controllers/Api/PurchaseOrderController.php`
- Agregar metodo `pendingReceipt()` que retorne POs con `status IN ('sent', 'partial')`
- Ordenar por `expected_date ASC`
- Incluir relaciones: supplier, items.product

**Ruta**: `routes/api_purchase_orders.php`
- `GET /purchase-orders/pending-receipt` (antes de las rutas con `{id}`)

### 1.3 Endpoint "Devoluciones Index" - listAll

**Archivo**: `app/Http/Controllers/Api/SalesReturnController.php`
- Agregar metodo `listAll()` que retorne todos los SalesReturn paginados con filtro por status
- Incluir relaciones: salesOrder, customer, user, items.product

**Ruta**: `routes/api_sales_orders.php` o nuevo `routes/api_sales_returns.php`
- `GET /sales-returns` (nueva ruta global, no anidada bajo ordenes)

---

## Fase 2: Frontend - Nuevas Paginas

### 2.1 Cola Entrega: `resources/js/pages/Queues/DeliveryQueue.vue`

- Modelar segun `resources/js/pages/Warehouse/Queue.vue` (mismo patron de tarjetas con polling)
- Muestra ordenes preparadas pendientes de entrega
- Boton "Marcar como Entregado" que llame al complete() de la orden
- Polling cada 15-30 segundos

### 2.2 Cola Pedidos: `resources/js/pages/Queues/PurchaseOrderQueue.vue`

- Modelar segun `resources/js/pages/Warehouse/Queue.vue`
- Muestra POs pendientes de recibir del proveedor
- Link a la pagina de recepcion `/purchase-orders/{id}/receive`
- Polling cada 30 segundos

### 2.3 Devoluciones: `resources/js/pages/Returns/Index.vue`

- Modelar segun `resources/js/pages/SalesOrders/Index.vue` (patron de tabla)
- Tabla con: numero devolucion, numero orden, cliente, monto, status (badge), fecha
- Filtros por status (pending/approved/rejected)
- Acciones: ver orden, aprobar/rechazar pendientes

### 2.4 Composables para badges de cola

- `resources/js/composables/useDeliveryQueue.ts` - modelar segun `useWarehouseQueue.ts` existente
- `resources/js/composables/usePurchaseOrderQueue.ts` - mismo patron

### 2.5 Rutas Inertia (frontend)

En `pos-frontend/routes/web.php` agregar:
- `GET /queues/delivery` -> render `Queues/DeliveryQueue`
- `GET /queues/purchase-orders` -> render `Queues/PurchaseOrderQueue`
- `GET /returns` -> render `Returns/Index` (ANTES de `/returns/{orderId}` existente)

Luego ejecutar generacion de rutas TypeScript (Wayfinder) si aplica.

---

## Fase 3: Reestructurar Sidebar

### Archivo principal: `pos-frontend/resources/js/components/AppSidebar.vue`

1. **Actualizar imports de iconos** - agregar: `ListOrdered` (Cola), `RotateCcw` (Devoluciones), `ClipboardCheck` (Cola Pedidos)
2. **Importar nuevos composables** para badges de Cola Entrega y Cola Pedidos
3. **Reescribir `mainNavItems` computed** con la estructura de 7 secciones definida arriba
4. **Mantener filtrado por roles** existente para cada seccion/sub-item
5. **Eliminar** funcion `filterWorkOrders` (ya no se usa)
6. **Cambiar link del logo** de `dashboard()` a `/pos`
7. **Iniciar polling** de nuevos composables en `onMounted`

### NavMain.vue - Sin cambios necesarios

El componente ya soporta grupos colapsables, items standalone, badges y deteccion de ruta activa.

---

## Fase 4: Verificacion

1. Verificar que cada link del sidebar navega a la pagina correcta
2. Verificar que los badges de Cola Caja, Cola Bodega, Cola Entrega y Cola Pedidos se muestran
3. Verificar las 3 paginas nuevas cargan datos correctamente
4. Verificar que paginas eliminadas del sidebar (Dashboard, Sales Orders, etc.) siguen accesibles por URL directo
5. Verificar que los grupos colapsables se expanden al estar en una ruta hija
6. Probar con diferentes roles si es posible (admin vs manager vs cashier)

---

## Archivos Criticos a Modificar

| Archivo | Cambio |
|---------|--------|
| `pos-frontend/resources/js/components/AppSidebar.vue` | Reescribir mainNavItems |
| `pos-backend/app/Http/Controllers/Api/SalesOrderController.php` | +pendingDelivery() |
| `pos-backend/app/Http/Controllers/Api/PurchaseOrderController.php` | +pendingReceipt() |
| `pos-backend/app/Http/Controllers/Api/SalesReturnController.php` | +listAll() |
| `pos-backend/routes/api_sales_orders.php` | +ruta pending-delivery |
| `pos-backend/routes/api_purchase_orders.php` | +ruta pending-receipt |
| `pos-frontend/routes/web.php` | +3 rutas Inertia |

## Archivos Nuevos a Crear

| Archivo | Descripcion |
|---------|-------------|
| `pos-frontend/resources/js/pages/Queues/DeliveryQueue.vue` | Pagina Cola Entrega |
| `pos-frontend/resources/js/pages/Queues/PurchaseOrderQueue.vue` | Pagina Cola Pedidos |
| `pos-frontend/resources/js/pages/Returns/Index.vue` | Pagina Devoluciones |
| `pos-frontend/resources/js/composables/useDeliveryQueue.ts` | Composable polling entrega |
| `pos-frontend/resources/js/composables/usePurchaseOrderQueue.ts` | Composable polling pedidos |
| `pos-backend/routes/api_sales_returns.php` (opcional) | Rutas de devoluciones globales |

## Archivos de Referencia (patron a seguir)

| Archivo | Para modelar |
|---------|-------------|
| `pos-frontend/resources/js/pages/Warehouse/Queue.vue` | Paginas de Cola |
| `pos-frontend/resources/js/composables/useWarehouseQueue.ts` | Composables de polling |
| `pos-frontend/resources/js/pages/SalesOrders/Index.vue` | Pagina Devoluciones (tabla) |

---

## Fase 5: Cambios en Pagina de Categorias (Create/Edit)

### Terminologia
- **Costo** = precio de compra (lo que se paga al proveedor)
- **Venta** = precio de venta (lo que paga el cliente)

### 5.1 AGREGAR: Campo "Precio Minimo Sugerido"

Nuevo campo `min_suggested_price` en categorias. Es un numero redondo simple (decimal), NO una formula.
Sirve como piso de referencia para el precio de venta de productos en esta categoria.

**Backend:**
- Migracion: agregar columna `min_suggested_price` decimal(12,2) nullable a `categories`
- Model `Category.php`: agregar a `$fillable`
- Controller `CategoryController.php`: agregar validacion en store/update (`nullable|numeric|min:0`)

**Frontend (Create.vue y Edit.vue):**
- Agregar input numerico con label "Precio Minimo Sugerido" y prefijo "$"
- Ubicar en la seccion "Configuracion de Precios", debajo de la formula
- Helper text: "Precio minimo de venta sugerido para productos de esta categoria"

### 5.2 ELIMINAR: "Costo por Defecto"

El campo `default_cost` en la tabla categories no aporta valor claro. Eliminar:

**Frontend (Create.vue y Edit.vue):**
- Eliminar el input de "Costo por Defecto" del formulario
- Eliminar la variable `previewCost` que usaba este valor para la preview

**Backend:**
- Quitar `default_cost` de `$fillable` en Category model (dejar la columna en DB por ahora, no requiere migracion destructiva)
- Quitar validacion de `default_cost` del CategoryController store/update

### 5.3 ELIMINAR: "Precio Total" (preview de formula)

La seccion de preview que muestra el precio calculado con un costo de ejemplo ya no va.

**Frontend (Create.vue y Edit.vue):**
- Eliminar toda la seccion de "Precio Total" (el bloque que muestra resultado, "calculando...", y "Formula invalida")
- Eliminar la funcion `runPreview()` y su watcher debounced
- Eliminar las refs `previewPrice`, `previewLoading`, `previewCost`
- Eliminar la llamada API a `/categories/preview-price`

**Backend:**
- Opcionalmente quitar el endpoint `POST /categories/preview-price` del CategoryController y su ruta (puede dejarse si se usa en otro lugar)

### 5.4 MANTENER: Formula de precio + ITBM

Estos campos se quedan tal cual:
- Input "Formula de precio" (text, con placeholder "ej: costo * 1.3 + 5")
- Checkbox "Aplicar ITBM" + input "Porcentaje ITBM"

### Archivos adicionales a modificar (Categorias)

| Archivo | Cambio |
|---------|--------|
| `pos-frontend/resources/js/pages/Categories/Create.vue` | +min_suggested_price, -default_cost, -preview |
| `pos-frontend/resources/js/pages/Categories/Edit.vue` | +min_suggested_price, -default_cost, -preview |
| `pos-frontend/resources/js/types/category.ts` | +min_suggested_price al tipo |
| `pos-backend/app/Models/Category.php` | +min_suggested_price a $fillable, -default_cost |
| `pos-backend/app/Http/Controllers/Api/CategoryController.php` | +validacion min_suggested_price, -default_cost |
| `pos-backend/database/migrations/nueva` | Agregar columna min_suggested_price |

---

## Fase 6: Asociar Proveedores a Marcas

Permitir seleccionar que proveedores venden una marca determinada. Relacion many-to-many.

### 6.1 Backend: Pivot table y relaciones

**Migracion nueva**: crear tabla `brand_supplier`
- `brand_id` (FK a brands)
- `supplier_id` (FK a suppliers)
- timestamps
- Indice unico compuesto [brand_id, supplier_id]

**Model `Brand.php`**: agregar relacion
- `suppliers()` -> belongsToMany(Supplier::class)

**Model `Supplier.php`**: agregar relacion inversa
- `brands()` -> belongsToMany(Brand::class)

**Controller `BrandController.php`**: modificar store/update
- Aceptar campo `supplier_ids` (array de IDs de proveedores)
- Validacion: `supplier_ids` nullable array, cada ID existe en suppliers
- Usar `$brand->suppliers()->sync($validated['supplier_ids'] ?? [])` en store y update
- En show/index: cargar relacion `suppliers` para que el frontend tenga los datos

### 6.2 Frontend: Multi-select en Create/Edit de Marcas

**Archivos**: `pos-frontend/resources/js/pages/Brands/Create.vue` y `Edit.vue`

- Agregar campo multi-select "Proveedores" debajo de los campos actuales
- Cargar lista de proveedores activos via API (`GET /suppliers?status=active`)
- Enviar `supplier_ids: number[]` al guardar
- En Edit: pre-seleccionar los proveedores ya asociados

**Tipo**: `pos-frontend/resources/js/types/brand.ts`
- Agregar `suppliers?: Supplier[]` al tipo Brand
- Agregar `supplier_ids?: number[]` a CreateBrandData/UpdateBrandData

### Archivos adicionales a modificar (Marcas-Proveedores)

| Archivo | Cambio |
|---------|--------|
| `pos-backend/app/Models/Brand.php` | +relacion suppliers() |
| `pos-backend/app/Models/Supplier.php` | +relacion brands() |
| `pos-backend/app/Http/Controllers/Api/BrandController.php` | +supplier_ids en store/update, cargar suppliers |
| `pos-frontend/resources/js/pages/Brands/Create.vue` | +multi-select proveedores |
| `pos-frontend/resources/js/pages/Brands/Edit.vue` | +multi-select proveedores pre-cargado |
| `pos-frontend/resources/js/types/brand.ts` | +suppliers, +supplier_ids |
| `pos-backend/database/migrations/nueva` | Crear tabla pivot brand_supplier |

---

## Fase 7: Cambios en Lista de Categorias (Index)

**Archivo principal**: `pos-frontend/resources/js/pages/Categories/Index.vue`

### 7.1 Reemplazar columna "Descripcion" por tags de atributos

- Eliminar la columna "Descripcion"
- Agregar columna "Atributos" que muestre los `default_attributes` de cada categoria como tags/badges
- Cada atributo se muestra como un chip/badge pequeno (ej: `ROSCA RACK`, `ROSCA NUT`, `LH/RH`)
- Si la categoria no tiene atributos, mostrar "—"

### 7.2 Nombre de categoria clickeable para editar

- Hacer que el nombre de la categoria sea un link que navegue a la pagina de edicion (`/categories/{id}/edit`)
- Eliminar el boton de "Editar" separado
- Mantener solo el boton de "Eliminar" a la derecha

### 7.3 Reemplazar slug por "path" (PENDIENTE)

- Actualmente debajo del nombre se muestra el slug (ej: "terminal-externo")
- El cliente quiere reemplazarlo por un "path" pero no ha aclarado que significa exactamente
- **Pendiente de definicion del cliente** - posibles interpretaciones:
  - Ruta jerarquica de categorias padre (ej: "Repuestos > Electricos > Terminal Externo")
  - Path estilo URL con slugs (ej: "repuestos/electricos/terminal-externo")
- Se implementara cuando el cliente aclare

### Archivos a modificar (Lista Categorias)

| Archivo | Cambio |
|---------|--------|
| `pos-frontend/resources/js/pages/Categories/Index.vue` | -col descripcion, +col atributos tags, nombre clickeable, -boton editar |
| `pos-backend/app/Http/Controllers/Api/CategoryController.php` | Asegurar que index retorne default_attributes |

---

## Fase 8: Cambios en Vehiculos

### 8.1 Renombrar "Generacion" a "Chasis" en formulario de modelos

El campo `generation` en VehicleModel se usa para indicar el chasis (ej: E170, W204, P10).
Renombrar el label en la UI de "Generación" a "Chasis". El campo en DB/API puede quedarse como `generation` para evitar migracion, solo cambia el texto visible.

**Archivos a modificar:**

| Archivo | Lineas | Cambio |
|---------|--------|--------|
| `pos-frontend/resources/js/pages/Vehicles/Index.vue` | 567 | Label "Generación" -> "Chasis" |
| `pos-frontend/resources/js/pages/Vehicles/Index.vue` | 568 | Placeholder "Ej: E170, W204" -> "Ej: P10, E170, W204" |
| `pos-frontend/resources/js/pages/Vehicles/Index.vue` | 481 | Badge display (sin cambio funcional, solo contexto) |
| `pos-frontend/resources/js/pages/Products/Create.vue` | ~347,365,462 | Display text donde diga "generacion" cambiar a "chasis" si es visible al usuario |
| `pos-frontend/resources/js/pages/Products/Edit.vue` | similar | Mismo cambio |

### 8.2 Fix bug: Asignar motor a un modelo de vehiculo

**Problema identificado**: Al asignar un motor existente a un modelo via `assignEngineToModel()`, el cache `allEngines` no se invalida. El motor recien asignado sigue apareciendo como disponible en el dropdown para otros modelos.

**Archivo principal**: `pos-frontend/resources/js/pages/Vehicles/Index.vue` (lineas 259-274)

**Causa raiz**: 
- `assignEngineToModel()` llama `updateEngine(id, { model_id })` que actualiza el array `engines` del composable
- Pero `allEngines` (la lista global de motores sin modelo) no se recarga
- El filtro en linea 119 usa `enginesByModel` para excluir los ya asignados, pero depende de datos desactualizados

**Fix necesario**:
1. Despues de `assignEngineToModel()` exitoso, re-llamar `loadAllEngines()` para refrescar la lista de motores disponibles
2. Alternativamente, remover el motor asignado del array `allEngines` localmente sin hacer otra llamada API

**Archivos a modificar:**

| Archivo | Cambio |
|---------|--------|
| `pos-frontend/resources/js/pages/Vehicles/Index.vue` | Invalidar cache allEngines tras asignar motor (linea ~270) |
| `pos-frontend/resources/js/composables/useVehicleEngines.ts` | Verificar que updateEngine actualice correctamente ambos arrays |

### 8.3 Mover VINs de Engine a Model

Actualmente `vehicle_vins` tiene FK `engine_id`. Los VINs deben pertenecer al **modelo** de vehiculo, no al motor. Un modelo puede tener multiples VINs.

**Backend - Migracion:**
- Agregar columna `model_id` (FK a vehicle_models) en `vehicle_vins`
- Poblar `model_id` a partir del engine -> model relationship existente
- Eliminar columna `engine_id` (o hacerla nullable y deprecada)
- Cambiar constraint unique de VIN si es necesario

**Backend - Model `VehicleVin.php`:**
- Cambiar relacion de `belongsTo(VehicleEngine)` a `belongsTo(VehicleModel)`
- Actualizar fillable: `engine_id` -> `model_id`

**Backend - Controller `VehicleVinController.php`:**
- Cambiar rutas de `/vehicle-engines/{engineId}/vins` a `/vehicle-models/{modelId}/vins`
- Ajustar metodos index/store/destroy para recibir `$modelId` en vez de `$engineId`

**Backend - Rutas `api_vehicles.php`:**
- Mover rutas VIN bajo vehicle-models en vez de vehicle-engines

**Frontend - Composable `useVehicleVins.ts`:**
- Cambiar `engine_id` por `model_id` en tipo VehicleVin
- Cambiar URLs API de `/vehicle-engines/{id}/vins` a `/vehicle-models/{id}/vins`

**Frontend - `Vehicles/Index.vue`:**
- Mover la seccion "VINs del Vehiculo" del modal de editar **motor** al modal de editar **modelo**
- Cuando se edita un modelo, mostrar la lista de VINs y permitir agregar/eliminar
- Variables `_vinEngineId` -> `_vinModelId`, ajustar loadVins para recibir modelId

### 8.4 Quitar VINs del formulario de Productos

En productos, solo se selecciona el vehiculo (modelo), ya no se ingresan VINs manualmente.

**Frontend - `Products/Create.vue`:**
- Eliminar seccion "VINs" del formulario (lineas ~879-900)
- Eliminar variables: `vinInput`, `addedVins`, `addVin()`
- No enviar `vins` en el payload de creacion
- Mantener selector de vehiculo (modelo)

**Frontend - `Products/Edit.vue`:**
- Eliminar seccion "VINs Vinculados" (lineas ~674-709)
- Eliminar funciones: `fetchProductVins()`, `handleAddVin()`, `handleRemoveVin()`
- Eliminar variables: `vins`, `newVin`, `vinError`, `addingVin`
- Mantener selector de vehiculo (modelo)

**Nota**: La tabla `product_vins` y el backend de ProductVins pueden mantenerse para compatibilidad de datos existentes, pero la UI de productos ya no los gestiona.

### Archivos a modificar (VINs)

| Archivo | Cambio |
|---------|--------|
| `pos-backend/app/Models/VehicleVin.php` | FK engine_id -> model_id |
| `pos-backend/app/Http/Controllers/Api/VehicleVinController.php` | Rutas bajo model en vez de engine |
| `pos-backend/routes/api_vehicles.php` | Mover rutas VIN a vehicle-models |
| `pos-backend/database/migrations/nueva` | Migracion engine_id -> model_id |
| `pos-frontend/resources/js/composables/useVehicleVins.ts` | API URLs y tipo model_id |
| `pos-frontend/resources/js/pages/Vehicles/Index.vue` | VINs en modal de modelo, no de motor |
| `pos-frontend/resources/js/pages/Products/Create.vue` | Eliminar seccion VINs |
| `pos-frontend/resources/js/pages/Products/Edit.vue` | Eliminar seccion VINs Vinculados |

---

## Fase 9: Boton Duplicar Producto

Caso de uso: tengo un "Terminal Derecho" con toda su info completa. Quiero duplicarlo para crear el "Terminal Izquierdo" cambiando solo el atributo de lado y los codigos. Todo lo demas (categoria, compatibilidad vehicular, precios, proveedor, etc.) se mantiene igual.

### 9.1 Backend: Endpoint de duplicacion

**Archivo**: `app/Http/Controllers/Api/ProductController.php`
- Nuevo metodo `duplicate(Product $product): JsonResponse`
- Carga el producto con todas sus relaciones: variants (con pricing, attributes, inventory warehouses), compatibility, oem_references, suppliers
- Crea un nuevo producto copiando todos los campos excepto:
  - `sku_base` -> generar nuevo SKU automatico (usar logica existente de nextSku)
  - `name` -> copiar pero agregar sufijo " (Copia)" para diferenciarlo inicialmente
- Duplica relaciones:
  - Variantes con su pricing y atributos (nuevo SKU por variante)
  - Compatibilidad vehicular (mismos vehiculos)
  - Referencias OEM
  - Proveedores
- NO duplica: inventario (empieza en 0), VINs de producto
- Retorna el producto nuevo creado

**Ruta**: `routes/api_products.php`
- `POST /products/{product}/duplicate` (antes de rutas con parametros dinamicos)

### 9.2 Frontend: Boton en pagina Show y redireccion

**Archivo**: `pos-frontend/resources/js/pages/Products/Show.vue`
- Agregar boton "Duplicar" junto al boton "Editar" (linea ~66-73)
- Icono: `Copy` de lucide-vue-next
- Al hacer click: llamar API `POST /products/{id}/duplicate`
- Al recibir respuesta exitosa: redirigir a `/products/{newId}/edit` para que el usuario ajuste los campos que necesite (atributos, codigos, nombre)
- Mostrar toast de exito: "Producto duplicado. Ajusta los campos necesarios."

### Archivos a modificar (Duplicar Producto)

| Archivo | Cambio |
|---------|--------|
| `pos-backend/app/Http/Controllers/Api/ProductController.php` | +metodo duplicate() |
| `pos-backend/app/Services/ProductService.php` | +metodo duplicateProduct() con logica de clonacion |
| `pos-backend/routes/api_products.php` | +ruta POST /products/{product}/duplicate |
| `pos-frontend/resources/js/pages/Products/Show.vue` | +boton Duplicar junto a Editar |
