## ERRORES CRÍTICOS (bloquean uso)
- [seeders] `php artisan db:seed` falla con `SQLSTATE[23505] ... vehicle_makes_name_unique` por llave duplicada `Ford`; `VehicleSeeder` usa `VehicleMake::create()` sin protección y `DatabaseSeeder` invoca `VehicleSeeder` dos veces (array + llamada individual) [pos-backend/database/seeders/DatabaseSeeder.php, pos-backend/database/seeders/VehicleSeeder.php]
- [seguridad/rutas web] Rutas de vistas sensibles siguen accesibles sin middleware auth global en web (evidencia previa de acceso 200 sin sesión en `/purchase-orders`, `/suppliers`, `/accounts-payable`) [pos-frontend/routes/web.php]

## ERRORES MENORES (no bloquean pero molestan)
- [purchase orders/create] Warning en consola: `Failed to resolve component: Package` al abrir crear orden de compra [http://pos-frontend.test/purchase-orders/create]
- [customers/show] Warning en consola: `Invalid prop: type check failed for prop "customerId". Expected Number ... got String "1"` [http://pos-frontend.test/customers/1]
- [backend/logs] Múltiples `slow_request` recientes (ej. `GET api/v1/sales-orders/1/ticket` ~13.3s; `POST api/v1/products/13/images` ~3.7s) [pos-backend/storage/logs/laravel.log]
- [frontend/logs] Errores repetidos de consola/artisan: `The "--columns" option does not exist` [pos-frontend/storage/logs/laravel.log]
- [frontend/logs] Error de Tinker en logs: `PHP Parse error: Syntax error, unexpected '=' on line 1` [pos-frontend/storage/logs/laravel.log]

## SEGUNDA PASADA (REPRODUCCIÓN PRODUCTOS VS POS)
- Fecha/hora de verificación: 2026-03-18 (misma sesión autenticada, una sola pestaña, usuario `admin`).
- Paso 1: abrir `/products` con almacén global seleccionado (`Almacén Principal CDMX`) → **Resultado**: listado visible con 5 productos.
- Paso 2: buscar `Alternador` y pulsar `Filtrar` → **Resultado**: 1 coincidencia (`ALT-12V-90A`, “Alternador 12V 90A”), acciones `Ver Detalle/Editar/Archivar` visibles.
- Paso 3: cambiar almacén global a `Almacén Guadalajara` → **Resultado**: los mismos 5 productos permanecen visibles, con stock en 0 en esa sucursal.
- Paso 4: abrir `/pos`, buscar `Alternador` y agregar al carrito → **Resultado**: producto agregado correctamente; carrito y totales operativos.
- Paso 5: volver a `/products` → **Resultado**: listado sigue visible (no aparece “No hay productos”).
- Conclusión de segunda pasada: la inconsistencia “POS con productos pero Productos vacío” **no fue reproducible** en esta ronda; se clasifica como intermitente/no determinística hasta contar con evidencia adicional de condiciones exactas (filtros persistidos, contexto de almacén/sesión o estado de caché).

## TERCERA PASADA (EVIDENCIA TÉCNICA FORENSE)
- Contexto capturado en caliente sobre `/products`:
	- `selectedWarehouse`: `Almacén Guadalajara`
	- `selectedWarehouseValue`: `28`
	- `tableRows`: `5`
	- `hasNoProductsText`: `false`
	- `cartText`: `Carrito 1`
- Claves de persistencia detectadas en `localStorage` (estado potencialmente influyente):
	- `pos_sessions_user_0`
	- `pos_active_session_user_0`
	- `pos_open_session_user_30`
	- `pos_current_warehouse_id`
- Request real observado del listado de productos (resource timing del navegador):
	- `GET /api/v1/products?search=&warehouse_id=28&has_stock=false&page=1&per_page=20`
	- Se observó en múltiples disparos sucesivos y devolvió render con filas visibles.
- Hallazgo: en esta captura el frontend sí respeta `warehouse_id=28` y muestra catálogo; no se confirmó un desacople permanente entre fuente de datos de POS y módulo Productos.
- Hipótesis técnica actualizada: el comportamiento reportado parece más compatible con intermitencia por estado persistido (filtros/contexto de almacén/sesión) que con ausencia estructural de datos en Products.

## FLUJOS QUE FUNCIONAN CORRECTAMENTE
- [login] ✅ Formulario carga, login con usuario `admin` y contraseña `password` funciona, redirige a `/dashboard`.
- [dashboard] ✅ Carga sin spinner infinito; métricas visibles; sin errores bloqueantes visibles.
- [POS] ✅ Apertura de caja funciona; búsqueda de productos funciona; agregar producto al carrito funciona; precio/impuesto calculan correctamente; botón Cotizar existe y guarda cotización; botones de checkout se habilitan/deshabilitan según contexto (caja abierta + carrito con ítems).
- [cotizaciones] ✅ Lista carga; cotización creada desde POS aparece; transición de estado funciona (`→ Depósito` cambió a `En Depósito`); detalle abre correctamente.
- [inventario] ✅ Vista carga y abre formulario de Nueva Entrada.
- [órdenes de compra] ✅ Lista carga; crear nueva OC abre formulario; selector de proveedor aparece con opciones en filtros/listado.
- [clientes] ✅ Lista carga; crear cliente abre formulario; detalle de cliente abre y muestra sección de movimientos de cuenta.
- [reportes] ✅ Reportes cargan sin 500; en financiero los filtros de fecha Desde/Hasta están disponibles.
- [configuración de tienda] ✅ Store carga con todos los bloques; sección Fiscal muestra `store_tax_rate`; sección Módulos muestra toggle de work orders; Guardar Configuración funciona (mensaje de éxito visible).
- [backend tests] ✅ `31 passed / 0 failed` en `php artisan test --stop-on-failure`.
- [migraciones] ✅ No hay migraciones pendientes (`Pending: 0`).
- [build frontend] ✅ `npm run build` pasa (`BUILD_EXIT_CODE=0`).

## FLUJOS CON PROBLEMAS VISUALES
- [productos] En segunda pasada sí hubo filas visibles y se validó filtro por texto (`Alternador`) + acciones por fila (`Ver Detalle/Editar/Archivar`). Hallazgo anterior de “lista vacía” quedó como no reproducible en esta ejecución.
- [cotizaciones] En detalle no se visualiza campo de notas en la pantalla auditada.
- [vehículos] Módulo carga pero muestra “No hay marcas de vehículos registradas”, por lo que no se pudo validar cascada marca→modelo→motor.
- [work orders] Lista carga pero botón “Nueva orden de trabajo” aparece deshabilitado en el estado auditado.

## ERRORES TÉCNICOS
- Tests: 31 passed / 0 failed
- Migraciones pendientes: 0
- TS errors: 17
- Build: ✅

## PRIORIDAD DE CORRECCIÓN SUGERIDA
1. Corregir idempotencia de seeders (eliminar doble invocación en DatabaseSeeder y reemplazar create por firstOrCreate/updateOrCreate en VehicleSeeder).
2. Aplicar protección auth/role consistente a rutas web sensibles en pos-frontend/routes/web.php.
3. Corregir warnings de consola en PurchaseOrders/Create y Customers/Show para limpiar deuda de UI.
4. Resolver 17 errores de TypeScript empezando por Products/Create y SalesOrders/Create/Edit.
5. Instrumentar trazas para intermitencias de listado en Productos (payload de filtros, warehouse context activo y estado de caché) para confirmar/descartar bug residual.
