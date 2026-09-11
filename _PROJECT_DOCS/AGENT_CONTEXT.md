## Documentación relacionada
- Patrones del proyecto: _PROJECT_DOCS/PATTERNS.md
- Bugs resueltos y reglas derivadas: _PROJECT_DOCS/LESSONS_LEARNED.md

## Actualización de contexto — 2026-09-06

### Alcance y estado Git auditado

- Se revisaron los cambios incorporados después de los merges del 21-ago-2026 hasta los `master` remotos actuales: backend `9e2691aa` (PR #22) y frontend `dcd90afc` (PR #25). `git ls-remote` confirmó ambos SHA en GitHub.
- El rango sustantivo auditado fue backend `e57cad86`, `d0b7034e`, `480abfee`, `d2de0b52` y `74c46135`; frontend `16e8abe4`, `d0ea89f1`, `3d80ddaa`, `7865161b` y `c2b92574`.
- Ambos repositorios estaban limpios antes de la auditoría y las verificaciones no generaron cambios funcionales. Los únicos cambios resultantes son esta actualización de contexto y la corrección equivalente de `pos-frontend/AGENTS.md`; no se ejecutaron migraciones.
- Los mensajes genéricos `Primer commit` de varios commits sustantivos no cumplen la convención de Conventional Commits y dificultan la trazabilidad del historial.

### Backend: capacidades incorporadas

- Se reforzó la idempotencia de aperturas/cierres y movimientos de Caja, cotizaciones, ventas, pagos CxC, compras rápidas, devoluciones y recepciones de órdenes de compra. Las claves se persisten con índices únicos; los flujos más recientes también guardan hashes para distinguir reintentos válidos de reutilizaciones incompatibles.
- La base local tiene aplicadas en batch 4 las nueve migraciones del rango: sesión abierta única, idempotencia de capital/caja/cotizaciones/ventas/pagos/OC/devoluciones, cuenta financiera de devolución y `purchase_order_receipts`.
- El cobro de una cotización preparada reutiliza la orden vinculada, valida que sus líneas sigan coincidiendo con la cotización, exige una sesión de Caja de la sucursal y evita crear una segunda venta.
- Las devoluciones ahora preservan una clave idempotente, validan cantidades nuevamente al aprobar, restauran FIFO, generan nota de crédito y separan reducción de CxC, saldo a favor y reembolso monetario. Los reembolsos bancarios conservan una cuenta financiera específica.
- La recepción de OC bloquea la orden y sus líneas, registra el intento en `purchase_order_receipts` y permite recuperar un reintento idéntico sin duplicar inventario.
- La trazabilidad y los detalles operativos se ampliaron para sesiones POS, tickets, ventas, devoluciones y cola de Caja.

### Frontend: capacidades incorporadas

- Existe una suite Vitest real para componentes, composables, páginas y utilidades; el contexto anterior que indicaba “backend only” quedó obsoleto.
- `createIdempotencyKey()` y firmas estables de payload conservan la misma clave en reintentos equivalentes y generan otra cuando cambia la operación. Este patrón se usa en POS, movimientos/cierres de Caja, pagos, devoluciones y recepción de OC.
- La Cola de Caja presenta las ventas en un flujo operativo propio, abre la cotización como pestaña POS, distingue pedido preparado/pendiente, muestra resumen de líneas y conserva gastos y devoluciones como liquidaciones separadas.
- Se añadieron salvaguardas y pruebas de estructura/payload para modales financieros, clientes rápidos, variantes, sesión, tickets, devoluciones, trazabilidad y recepción de OC.
- `package.json` declara actualmente Vite `^8.0.16`, `laravel-vite-plugin` `^3.1.0`, Vitest `^3.2.4`, Vue Test Utils `^2.4.6` y jsdom `^26.1.0`. No asumir que el `node_modules` local coincide con esas versiones.

### Hallazgos abiertos de la auditoría

1. **P1 — colisión de idempotencia en cotizaciones.** `SalesQuoteController::replayQuoteForKey()` busca solo por UUID y devuelve la cotización existente con HTTP 201 sin comparar el payload. Reutilizar la misma clave con cliente, líneas o importes distintos recupera silenciosamente la primera cotización. Las pruebas actuales cubren repetición idéntica, pero no el conflicto de contenido. Se necesita `request_hash`, respuesta 409 y cobertura para `store` y `storeFromPos`.
2. **P1 — recepción de OC sin aislamiento por sucursal.** `ReceivePurchaseOrderRequest` confirma existencia por ID y `PurchaseOrderService::receiveItems()` vuelve a cargar por ID, pero ninguno compara `purchase_orders.warehouse_id` con el `warehouse_id` efectivo que inyecta `warehouse_context`. Un usuario autorizado en una sucursal puede intentar recibir una OC de otra si conoce su ID. La refactorización idempotente conserva este hueco y no hay prueba cross-warehouse para recepción.
3. **P1 de despliegue — índice de sesión abierta.** `2026_08_22_000001_enforce_single_open_pos_session` crea directamente un índice único parcial. La base local lo aplicó correctamente, pero otro entorno con sesiones abiertas duplicadas por usuario/sucursal fallará al migrar. Antes de desplegar se debe ejecutar una consulta de preflight y resolver duplicados de forma auditable; no aplicar `migrate` a ciegas.
4. **P2 — contrato de referencias de pago solo en UI.** La Cola de Caja exige visualmente referencia cuando el método la requiere, pero `CashierQueueController` y `SalesReturnService` aceptan `reference_number` nulo. Un cliente API directo puede liquidar un reembolso configurado con `requires_reference=true` sin referencia. La validación debe ser del servidor y reutilizable por gastos/devoluciones.
5. **P2 — arquitectura todavía inconsistente.** Aunque los nuevos flujos usan servicios y algunas Form Requests, controladores grandes (`SalesQuoteController`, `SalesOrderController`, `CashierQueueController` y partes de `PurchaseOrderController`) mantienen validación inline, transacciones y lógica de negocio. No considerar completada la convención Form Request → Controller → Service.
6. **P2 — instalación frontend desalineada.** El build local pasó con Vite 7.3.3, pero ese paquete y `laravel-vite-plugin` 2.1.0 están fuera de los rangos declarados; faltan Vitest, Vue Test Utils y jsdom. Por eso `npm run test:unit` no inicia y `vue-tsc --noEmit` falla por módulos de prueba ausentes. Ejecutar una instalación reproducible desde `package-lock.json` antes de interpretar esos fallos como defectos fuente.

### Evidencia de verificación y límites

- Backend focal: 108 pruebas aprobadas, 772 aserciones, sobre idempotencia, Caja, cotizaciones preparadas, pagos, devoluciones, trazabilidad y recepción de OC.
- Backend local: `artisan migrate:status` confirmó todas las migraciones recientes como `Ran`; no se ejecutó `migrate`, seed, rollback ni escritura manual de datos.
- Frontend: `npm run build` completó 4,451 módulos y produjo el bundle. `git diff --check` quedó limpio en ambos repositorios.
- Frontend unit/type-check: no son evidencia válida en esta estación hasta reparar `node_modules`; Vitest no está instalado y los errores de TypeScript observados son consecuencia de módulos/tipos de prueba ausentes.
- Pendiente: pruebas de concurrencia reales en PostgreSQL, instalación limpia de dependencias frontend, suite Vitest, `vue-tsc`, validación visual en navegador y recorridos físicos de Caja/impresión. El backend focal usa SQLite `:memory:` y no prueba semántica de bloqueo PostgreSQL.

### 1. Stack técnico confirmado

- Backend (composer.json): PHP ^8.2, laravel/framework ^12.0, laravel/tinker ^2.10.1, barryvdh/laravel-dompdf, maatwebsite/excel, picqer/php-barcode-generator, spatie/laravel-activitylog.
- Backend dev (composer.json): fakerphp/faker ^1.23, laravel/boost ^1.0, laravel/dusk, laravel/pail ^1.2.2, laravel/pint ^1.24, laravel/sail ^1.41, mockery/mockery ^1.6, nunomaduro/collision ^8.6, pestphp/pest ^4.3, pestphp/pest-plugin-laravel ^4.0.
- Frontend/runtime (package.json): Vue ^3.5.13, @inertiajs/vue3 ^2.3.7, axios ^1.13.5, @vueuse/core ^12.8.2, chart.js ^4.5.1, vue-chartjs ^5.3.3, class-variance-authority ^0.7.1, clsx ^2.1.1, date-fns ^4.1.0, jsbarcode ^3.12.3, laravel-vite-plugin ^3.1.0, lucide-vue-next ^0.468.0, reka-ui 2.8.2, tailwind-merge ^3.2.0, tailwindcss ^4.1.1, tw-animate-css ^1.2.5, vue-input-otp ^0.3.2, vuedraggable ^4.1.0.
- Frontend/dev (package.json): TypeScript ^5.2.2, vite ^8.0.16, Vitest ^3.2.4, Vue Test Utils ^2.4.6, jsdom ^26.1.0, vue-tsc ^2.2.4, @vitejs/plugin-vue ^6.0.0, @tailwindcss/vite ^4.1.11, @eslint/js ^9.19.0, @vue/eslint-config-typescript ^14.3.0, eslint ^9.17.0, eslint-config-prettier ^10.0.1, eslint-import-resolver-typescript ^4.4.4, eslint-plugin-import ^2.32.0, eslint-plugin-vue ^9.32.0, prettier ^3.4.2, prettier-plugin-tailwindcss ^0.6.11, concurrently ^9.0.1, @laravel/vite-plugin-wayfinder ^0.1.3, @types/node ^22.13.5.

### 2. Mapa de archivos críticos

pos-backend/routes/api.php → entrada única de la API v1 con middleware web, auth y throttle:120,1 que carga todos los módulos API.
pos-backend/routes/api_accounts_payable.php → define la ruta de cuentas por pagar para roles admin, manager y buyer.
pos-backend/routes/api_activity_logs.php → define el listado de activity logs restringido a admin.
pos-backend/routes/api_backup.php → define listado, disparo y descarga de respaldos para admin.
pos-backend/routes/api_brands.php → define lectura y escritura de marcas con permisos separados por rol.
pos-backend/routes/api_categories.php → define CRUD de categorías con lectura amplia y escritura para admin y manager.
pos-backend/routes/api_customers.php → define CRUD, búsqueda, órdenes, transacciones y ajuste de saldo de clientes.
pos-backend/routes/api_dashboard.php → define endpoints del dashboard con métricas y contexto obligatorio de sucursal.
pos-backend/routes/api_inventory.php → define inventario, lotes, merma y actualización por producto y sucursal.
pos-backend/routes/api_inventory_movements.php → define entradas, salidas, transferencias y consultas de movimientos de inventario.
pos-backend/routes/api_inventory_transfers.php → define el ciclo de vida de transferencias entre sucursales.
pos-backend/routes/api_pos_sessions.php → define sesión POS actual, apertura, cierre y movimientos de caja.
pos-backend/routes/api_products.php → define CRUD de productos y endpoints de imágenes, atributos, compatibilidad, variantes, OEM, VIN y alternativas.
pos-backend/routes/api_purchase_orders.php → define CRUD de órdenes de compra, recepción, cancelación, PDF, envío y pagos.
pos-backend/routes/api_reports.php → define reportes de stock, movimientos, financiero y Z report con contexto de sucursal.
pos-backend/routes/api_sales_orders.php → define POS settings, CRUD de ventas, transiciones de estado, items, pagos y devoluciones.
pos-backend/routes/api_sales_quotes.php → define CRUD de cotizaciones, conversión desde POS y documentos imprimibles.
pos-backend/routes/api_smart_reorder.php → define low stock, estadísticas y creación masiva de reabasto.
pos-backend/routes/api_store_settings.php → define lectura y actualización de store settings, presets y logo.
pos-backend/routes/api_suppliers.php → define CRUD de proveedores y consulta de órdenes de compra relacionadas.
pos-backend/routes/api_tickets.php → define ticket, nota y proforma imprimibles para órdenes de venta.
pos-backend/routes/api_users.php → define listado y CRUD de usuarios solo para admin.
pos-backend/routes/api_vehicles.php → Gestiona el catálogo vehicular (Marcas VehicleMake, Modelos VehicleModel, Motores VehicleEngine, Chasis VehicleChassis).
pos-backend/routes/api_warehouses.php → define CRUD de sucursales y consulta de inventario por sucursal.
pos-backend/routes/api_work_orders.php → define 6 rutas CRUD + transición de estado para work orders (todos los roles autenticados).
pos-backend/routes/console.php → archivo de rutas de consola de Laravel.
pos-backend/routes/web.php → archivo de rutas web con la ruta raíz.
pos-backend/bootstrap/app.php → registra rutas web/api/console, aliases de middleware y exclusión CSRF para api/*.
pos-backend/config/cors.php → CORS configurado para https://hermexpos.app con supports_credentials=true y flujo preflight manejado por middleware de Laravel.
pos-backend/bootstrap/app.php → HandleCors agregado al grupo api para aplicar CORS en todas las rutas API.
pos-backend/.env → SESSION_DOMAIN=.hermexpos.app, SESSION_SECURE_COOKIE=true y SANCTUM_STATEFUL_DOMAINS configurado para dominio compartido.
/etc/nginx/sites-available/pos-backend → HTTPS activo vía Certbot, sin interceptor OPTIONS; CORS queda manejado por Laravel.
pos-backend/app/Http/Middleware/CheckRole.php → middleware de autorización por rol que también rechaza usuarios inactivos.
pos-backend/app/Http/Middleware/EnforceWarehouseContext.php → middleware que fija warehouse_id efectivo y bloquea usuarios sin sucursal asignada.
pos-backend/app/Http/Middleware/RequestTimingMiddleware.php → registra duración de request, agrega header X-Response-Time y loguea slow requests >500ms.
pos-backend/app/Http/Controllers/Api/ → directorio con 35 controladores API para ventas, inventario, compras, reportes, usuarios, vehículos y configuración.
pos-backend/app/Http/Requests/AssignAttributesRequest.php → valida asignación de atributos por definición y valores numéricos o de texto.
pos-backend/app/Http/Requests/StoreInboundMovementRequest.php → valida altas de movimiento inbound de inventario.
pos-backend/app/Http/Requests/StoreOutboundMovementRequest.php → valida altas de movimiento outbound de inventario.
pos-backend/app/Http/Requests/StoreProductRequest.php → valida creación de producto, pricing, inventario, compatibilidad mutua (motores, chasis y banderas universales), VIN y OEM.
pos-backend/app/Http/Requests/StoreTransferMovementRequest.php → valida transferencias de inventario entre sucursales distintas.
pos-backend/app/Http/Requests/StoreWarehouseRequest.php → valida creación de sucursales con código único y estado permitido.
pos-backend/app/Http/Requests/StoreSalesOrderRequest.php → valida creación de sales orders (cliente, pagos, ítems, precios, impuestos y descuentos por línea).
pos-backend/app/Http/Requests/PosCheckoutRequest.php → valida payload de checkout/venta POS y quedó inyectado en App\Http\Controllers\Api\SalesOrderController@store.
pos-backend/app/Http/Requests/StoreUserRequest.php → valida creación de usuarios (identidad, rol, credenciales y sucursales permitidas).
pos-backend/app/Http/Requests/StoreInventoryRequest.php → valida registro de ajuste de inventario por merma (variant_id, warehouse_id, quantity, reason, notes).
pos-backend/app/Http/Requests/StoreSupplierRequest.php → valida creación de proveedores (datos de contacto, términos y estado).
pos-backend/app/Http/Requests/UpdateProductRequest.php → valida actualización parcial de producto y pricing.
pos-backend/app/Http/Requests/UpdateWarehouseRequest.php → valida actualización parcial de sucursal con unicidad de código ignorando el registro actual.
pos-backend/app/Models/ → directorio de modelos Eloquent para productos, inventario, compras, ventas, vehículos, usuarios y sucursales.
pos-backend/app/Models/Customer.php → modelo Customer con store_credit (saldo a favor) y has_store_credit como atributos computados via $appends.
pos-backend/app/Models/SalesOrder.php → modelo SalesOrder; método addPayment soporta store_credit como método de pago que incrementa el balance del cliente; método confirm() ahora calcula amount_to_bill = total - store_credit_used para no duplicar cargos cuando se usa saldo a favor.
pos-backend/app/Models/CustomerTransaction.php → modelo CustomerTransaction con tipo store_credit_used para registrar uso de saldo a favor.
pos-backend/tests/Feature/ → directorio de feature tests backend con ejemplo base y suite P0.
pos-backend/tests/Browser/ExampleTest.php → prueba browser de ejemplo.
pos-backend/tests/Browser/LoginTest.php → prueba browser del login.
pos-backend/tests/Feature/ExampleTest.php → prueba feature de ejemplo del backend.
pos-backend/tests/Feature/P0/InventoryTransferFlowTest.php → prueba P0 del flujo de transferencias de inventario.
pos-backend/tests/Feature/P0/RoleMiddlewareTest.php → prueba P0 del middleware de roles.
pos-backend/tests/Feature/P0/WarehouseEnforcementTest.php → prueba P0 del enforcement de contexto de sucursal.
pos-backend/tests/Feature/P1/SalesOrderFlowTest.php → prueba P1 de validación de creación de sales-orders con payload inválido.
pos-backend/tests/Feature/P1/ProductSearchTest.php → prueba P1 de listado de productos y búsqueda (con skip en sqlite por ILIKE).
pos-backend/tests/Feature/P1/UserRoleAccessTest.php → prueba P1 de acceso por rol en endpoints de usuarios.
pos-backend/tests/Feature/P1/RateLimitTest.php → prueba P1 del throttle API (120 req/min).
pos-backend/tests/Feature/P1/FormValidationTest.php → prueba P1 de validación de creación de usuarios.
pos-backend/tests/Feature/P1/PosSessionFlowTest.php → prueba P1 de endpoint de sesión POS actual (200/404, no 500).
pos-backend/tests/Feature/P1/InventoryMovementTest.php → prueba P1 de listado de movimientos de inventario.
pos-backend/tests/Feature/P1/WarehouseIsolationTest.php → prueba P1 de aislamiento de stock por sucursal.
pos-backend/tests/Feature/P1/PurchaseOrderFlowTest.php → prueba P1 de rechazo de OC con payload vacío.
pos-backend/tests/Feature/P1/SuppliersTest.php → prueba P1 de validación de proveedor sin nombre.
pos-backend/database/seeders/DefaultSettingsSeeder.php → define defaults de configuración para store_tax_rate, modules_work_orders_enabled y price_rounding_increment (1.00).
pos-backend/database/migrations/2026_03_23_015848_add_store_credit_to_sales_order_payments_method_enum.php → migración que agrega 'store_credit' al enum de métodos de pago en sales_order_payments (compatible con SQLite y MySQL/PostgreSQL).
pos-backend/database/migrations/2026_03_23_025900_add_store_credit_used_to_customer_transactions_type_enum.php → migración que agrega 'store_credit_used' al enum de tipos de transacción en customer_transactions (compatible con SQLite y MySQL/PostgreSQL).
pos-backend/tests/Unit/ExampleTest.php → prueba unitaria de ejemplo del backend.
pos-backend/app/Models/WorkOrder.php → modelo WorkOrder con estados, transiciones, folio auto-generado y relaciones a customer, warehouse, creator, assignee e items.
pos-backend/app/Models/WorkOrderItem.php → modelo WorkOrderItem con relaciones a WorkOrder, Product, ProductVariant y Warehouse.
pos-backend/database/migrations/2026_03_17_000001_create_work_orders_table.php → crea tablas work_orders y work_order_items con FK a customers, warehouses, users, products y product_variants.
pos-backend/app/Http/Requests/StoreWorkOrderRequest.php → valida creación de work orders (warehouse, customer opcional, assigned_to, items con product, variant, warehouse, quantity y unit_price).
pos-backend/app/Http/Controllers/Api/WorkOrderController.php → controlador CRUD + status transition para work orders; descuenta inventario en store y lo restaura en cancel/delete.
pos-backend/routes/api_work_orders.php → 6 rutas: GET/POST /work-orders, GET/PUT/DELETE /work-orders/{id}, POST /work-orders/{id}/transition.
pos-backend/tests/Feature/P2/WorkOrderTest.php → 9 tests P2: creación mínima, folio auto-generado, decremento de inventario, validaciones, listado paginado, transición de estado, transición inválida y restauración de stock al cancelar.
pos-backend/tests/Feature/P2/CustomerBalanceTest.php → 4 tests P2: venta a crédito puro genera 1 transacción 'sale', venta pagada completamente no genera cargo al balance, pago parcial carga solo monto pendiente, venta con store_credit calcula balance correctamente sin duplicar cargos.
pos-frontend/package.json → define scripts de build/dev/lint/format y dependencias reales del frontend Vue/Inertia.
pos-frontend/config/fortify.php → configuración de Fortify incluyendo limiters.login para rate limiting del login.
pos-frontend/resources/js/app.ts → bootstrap de la app Inertia/Vue y carga inicial del tema.
pos-frontend/resources/js/services/api/client.ts → cliente Axios base para /api/v1 con CSRF, cookies y manejo global de errores HTTP.
pos-frontend/resources/js/composables/useAppearance.ts → composable de tema que usa matchMedia y localStorage para apariencia y persistencia.
pos-frontend/resources/js/composables/useAuth.ts → composable relacionado con autenticación del frontend.
pos-frontend/resources/js/composables/useBrands.ts → composable para operaciones de marcas.
pos-frontend/resources/js/composables/useCategories.ts → composable para operaciones de categorías.
pos-frontend/resources/js/composables/useCurrentUrl.ts → composable para obtener y exponer la URL actual.
pos-frontend/resources/js/composables/useCustomers.ts → composable para operaciones de clientes.
pos-frontend/resources/js/composables/useDashboard.ts → composable para datos del dashboard.
pos-frontend/resources/js/composables/useInitials.ts → helper/composable para obtener iniciales de nombre.
pos-frontend/resources/js/composables/useInventory.ts → composable para operaciones de inventario.
pos-frontend/resources/js/composables/useInventoryMovements.ts → composable para movimientos de inventario.
pos-frontend/resources/js/composables/useCashierQueue.ts → composable para polling en tiempo real y contador para la bandeja Cola de Caja.
pos-frontend/resources/js/composables/usePOS.ts → composable POS con persistencia de sesiones y carrito en localStorage; ahora incluye available_stock en cada línea de carrito para validar checkout por stock.
pos-frontend/resources/js/components/POS/ProductTable.vue → tabla del carrito POS con filas expandibles; incluye QuickPurchaseModal para reabasto rápido cuando available_stock <= 0; emite reorder-success para actualizar stock en padre.
pos-frontend/resources/js/composables/usePosSession.ts → composable para sesión activa de POS; ahora sincroniza/limpia estado en localStorage según la sesión real devuelta por backend.
pos-frontend/resources/js/composables/usePosSettings.ts → composable de configuración POS con fallback de sucursal en localStorage.
pos-frontend/resources/js/composables/useProductAttributes.ts → composable que consulta y muta atributos de producto vía apiClient.
pos-frontend/resources/js/composables/useProductFitment.ts → composable que consulta y muta compatibilidad vehicular de productos vía apiClient.
pos-frontend/resources/js/composables/useProducts.ts → composable para operaciones de productos.
pos-frontend/resources/js/composables/usePurchaseOrders.ts → composable para órdenes de compra.
pos-frontend/resources/js/composables/useReports.ts → composable para reportes.
pos-frontend/resources/js/composables/useSalesOrders.ts → composable para órdenes de venta.
pos-frontend/resources/js/composables/useStoreSettings.ts → composable que lee y actualiza store settings y logo vía apiClient.
pos-frontend/resources/js/components/AppSidebar.vue → navegación principal; filtra secciones por rol (POS: admin/manager/cashier; Compras/Inventario: admin/manager/buyer; Reportes/Catálogo: admin/manager; Cotizaciones/Productos: seller incluido; Administración: solo admin). Filtra work orders cuando modules_work_orders_enabled es false.
pos-frontend/resources/js/components/NavMain.vue → componente base de navegación recursivo, adaptado para soportar badges numéricos opcionales.
pos-frontend/resources/js/types/navigation.ts → definiciones TS de items del menú; incluye propiedad `badge`.
pos-frontend/resources/js/composables/useSuppliers.ts → composable para proveedores.
pos-frontend/resources/js/composables/useToast.ts → composable de toasts en memoria para success, error, warning e info.
pos-frontend/resources/js/composables/useTwoFactorAuth.ts → composable para flujos de two-factor authentication.
pos-frontend/resources/js/composables/useUsers.ts → composable que obtiene y maneja usuarios vía apiClient.
pos-frontend/resources/js/composables/useVehicleContext.ts → composable para contexto vehicular en la UI.
pos-frontend/resources/js/composables/useVehicleEngines.ts → composable CRUD de motores de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleChassis.ts → composable CRUD de chasis de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleMakes.ts → composable CRUD de marcas de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleModels.ts → composable CRUD de modelos de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleVins.ts → composable CRUD de VIN por motor vía apiClient.
pos-frontend/resources/js/composables/useWarehouses.ts → composable para operaciones de sucursales.
pos-frontend/resources/js/composables/useWorkOrders.ts → composable para CRUD de work orders y transición de estado vía apiClient.
pos-frontend/resources/js/pages/ → árbol de páginas Inertia/Vue para auth, dashboard, POS, inventario, compras, ventas, reportes, vehículos, settings y catálogos.
pos-frontend/resources/js/pages/WorkOrders/Index.vue → listado de work orders con tabla Folio/Vehículo/Estado/Sucursal/Fecha, filtro por estado y búsqueda por texto.
pos-frontend/resources/js/pages/POS/Index.vue → página principal POS; soporta props quoteMode/quoteId para modo cotización (sin checkout), returnMode/orderId para modo devolución. Valida checkout por stock, consulta sesión activa. Permite usar saldo a favor (store_credit) en pagos con sección dedicada que muestra límite, disponible, balance y días de crédito. Input 'cobrar a crédito' con validación de máximo (mínimo de disponible y pendiente).
pos-frontend/resources/js/pages/POS/CashierQueue.vue → interfaz dedicada al cajero con grid de cotizaciones pendientes y polling en vivo de los tiempos transcurridos.
pos-frontend/resources/js/pages/SalesQuotes/Editor.vue → componente shell que renderiza la pantalla del POS en modo edición de cotizaciones (`quoteMode=true`).
pos-frontend/resources/js/pages/SalesOrders/ReturnEditor.vue → componente shell que renderiza POS en modo devolución (`returnMode=true`) cargando items de la orden como cantidades negativas.
pos-frontend/resources/js/components/POS/ActionButtons.vue → botones de acción del POS; "Cobrar" unificado que abre modal de pago (reemplazo de 4 botones separados).
pos-frontend/resources/js/pages/Customers/Show.vue → detalle del cliente con sección "Saldo a Favor" cuando el cliente tiene current_balance negativo (store_credit).
pos-frontend/resources/js/pages/Settings/Store.vue → UI de configuración de tienda; ahora incluye sección Fiscal (store_tax_rate) y sección Módulos (modules_work_orders_enabled).
pos-frontend/resources/js/components/ → árbol de componentes Vue para shell de aplicación, POS, productos, dashboard, vehículos y librería ui.
pos-frontend/tests/Feature/Auth/AuthenticationTest.php → prueba feature de autenticación.
pos-frontend/tests/Feature/Auth/EmailVerificationTest.php → prueba feature de verificación de email.
pos-frontend/tests/Feature/Auth/PasswordConfirmationTest.php → prueba feature de confirmación de password.
pos-frontend/tests/Feature/Auth/PasswordResetTest.php → prueba feature de reset de password.
pos-frontend/tests/Feature/Auth/RegistrationTest.php → prueba feature de registro.
pos-frontend/tests/Feature/Auth/TwoFactorChallengeTest.php → prueba feature del challenge de two-factor.
pos-frontend/tests/Feature/Auth/VerificationNotificationTest.php → prueba feature del envío de notificación de verificación.
pos-frontend/tests/Feature/DashboardTest.php → prueba feature del dashboard.
pos-frontend/tests/Feature/ExampleTest.php → prueba feature de ejemplo del frontend.
pos-frontend/tests/Feature/Settings/PasswordUpdateTest.php → prueba feature de actualización de password en settings.
pos-frontend/tests/Feature/Settings/ProfileUpdateTest.php → prueba feature de actualización de perfil en settings.
pos-frontend/tests/Feature/Settings/TwoFactorAuthenticationTest.php → prueba feature de two-factor en settings.
pos-frontend/tests/Unit/ExampleTest.php → prueba unitaria de ejemplo del frontend.

### 3. Reglas de no romper

  1. Leer el archivo completo antes de editarlo. Sin excepciones.
  2. Cambio mínimo: modificar solo los archivos necesarios para la tarea.
  3. No eliminar campos, endpoints, relaciones ni funciones existentes.
  4. Si el código real difiere del esperado en el prompt: detenerse y reportar.
  5. Nunca commitear trabajo incompleto.
  6. Toda nueva funcionalidad que afecta flujos existentes usa feature flag.

### 4. Comandos de verificación base

  # Backend
  php artisan test tests/Feature/P0 --stop-on-failure
  php artisan route:list --columns=method,uri,middleware | grep api/v1
  php artisan migrate:status

  # Frontend
  cd pos-frontend && npm run type-check && npm run build

### 5. Estado actual por área

Autenticación: sesión por cookie funcionando en producción con dominio .hermexpos.app compartido entre hermexpos.app y api.hermexpos.app.
Rate limiting: routes/api.php aplica `throttle:120,1` al grupo `v1`.
Form Requests: existen 8 Form Requests en pos-backend/app/Http/Requests/ frente a 35 controladores en pos-backend/app/Http/Controllers/Api/.
Tests: pos-backend/tests/Feature/ tiene 15 archivos (P0+P1+P2+example); última corrida completa backend (`php artisan test --stop-on-failure`): 31 passed (61 assertions). En pos-frontend la última corrida (`php artisan test --stop-on-failure`) falló en Auth/AuthenticationTest por `no such table: activity_log` (SQLite de tests), no por rutas web.
Cache/Queue: config/cache.php usa `redis` como store (CACHE_STORE=redis en .env); config/queue.php usa `redis` como conexión (QUEUE_CONNECTION=redis en .env) con phpredis client en 127.0.0.1:6379 (migración a Redis aplicada ✓).
Store settings: store_tax_rate y modules_work_orders_enabled expuestos como settings configurables (con defaults vía seeder).
Frontend offline: no encontré service worker, IndexedDB ni PWA en package.json, resources/js/app.ts, resources/js/services/api/client.ts ni en el barrido de resources/js/composables/.
Roles: admin, manager, seller, cashier, buyer, assistant — seller formalizado en UI (label "Vendedor", orden correcto en selector, badge verde en listo de usuarios, validación backend ya incluida en StoreUserRequest).
Quote Editor: /quotes/create y /quotes/{id}/edit renderizan SalesQuotes/Editor.vue que importa POS/Index.vue con quoteMode=true (sin checkout/caja). SalesQuotes/Index.vue redirige a estas rutas en vez de /pos.
Sidebar por rol: seller ve Cotizaciones, Productos (lectura), Clientes, Dashboard, Vehículos. Cashier ve POS, Cierres de Caja, Cotizaciones (lectura), Dashboard, Clientes. Admin/Manager ven todo.
API role middleware: api_pos_sessions.php (open/close/movements: admin,manager,cashier). api_sales_quotes.php (store/update/sendTo: admin,manager,seller; convert/collect: admin,manager,cashier; destroy: admin,manager).
Web route protection: /pos requiere role:admin,manager,cashier; /quotes/* requiere role:admin,manager,seller.

### 6. Fases pendientes

  ✅ Prerequisito: dominio hermexpos.app configurado, HTTPS activo en los tres subdominios, CORS resuelto en producción.

  FASE 1 — Seguridad crítica (P0, bloqueante)
    [x] Módulo 1.1: Rate limiting en API (throttle:120,1 en routes/api.php) 
        y login (rate limiter Fortify en provider correspondiente)
    [x] Módulo 1.2: Bugs críticos de UX en POS (a, b, c)
    [x] Módulo 1.3: Form Requests en 5 controladores
    [x] Fase 1 completa (P0 verde)
  
  FASE 2 — Tests y observabilidad (P1, paralela con F1)
    [x] Módulo 2.1: 10 tests P1
    [x] Módulo 2.2: Logging estructurado + RequestTimingMiddleware
    [x] Módulo 2.3: Migrar cache/queue a Redis (CACHE_STORE=redis, QUEUE_CONNECTION=redis, phpredis en 127.0.0.1:6379)
  
  FASE 3 — Configuraciones de tienda y hardcodeados
    [x] Módulo 3.1: Auditoría de valores hardcodeados
    [x] Módulo 3.2: Exponer valores hardcodeados como store settings
    [x] Módulo 3.3: UI de settings (Fiscal + Módulos)
    [x] Fase 3 completa

  FASE 4 — Work orders [requiere F1 completa y modules_work_orders_enabled = true]
    [x] Módulo 4.1: Migración work_orders + work_order_items y modelos WorkOrder / WorkOrderItem
    [x] Módulo 4.2: WorkOrderController (6 rutas), StoreWorkOrderRequest, api_work_orders.php
    [x] Módulo 4.3: 9 tests P2 (todos verdes excepto 1 skip pre-existente de SQLite)
    [x] Módulo 4.4: useWorkOrders.ts, WorkOrders/Index.vue, AppSidebar link, Inertia route
    [x] Fase 4 completa (P0+P1+P2 verde, TS errors 15 → 15, build ✓)
  FASE 5 — PWA/offline [requiere F2 completa]
  FASE 6 — Stripe/Square [requiere F1 completa]
    ✅ Correcciones críticas (seeders, auth rutas, TS errors)
  FASE 7 — Sanctum tokens [requiere F6]
  FASE 8 — QuickBooks/Xero [requiere todo estable]

### Prioridad acordada (Reunión 2)
1. R22 — Fórmula precio por categoría
2. R29 — Lista de abastecimiento
3. R23/R24 — Costo+ITBM y precio venta en OC
4. R28 — Stock mínimo cero
5. R34+R31 — Flechitas inputs + botón Guardar POS
6. R32/R33 — Cola de caja mejoras
7. B01/B02/B03 — Bugs pequeños

---

## Auditoría de requerimientos cliente — 17 de marzo de 2026 (rev.2)

### R01 — Formulario crear vs editar producto son inconsistentes
  R01 | 🚧 N/A — Descartado por decisión de diseño | Create.vue y Edit.vue operan bajo paradigmas fundamentalmente diferentes.
  - Create: recolecta todo en arrays locales y envía un único payload al guardar.
  - Edit: CRUD inmediato por API para compatibilidad, VINs, imágenes, OEM refs y atributos.
  Extraer un componente compartido (VehicleCompatibilitySection) duplicaría la complejidad en lugar de reducirla, ya que la estructura de datos, el flujo de guardado y las interacciones de UI son distintas por diseño. Ambos formularios funcionan correctamente de manera independiente.

  Archivos: pos-frontend/resources/js/pages/Products/Create.vue, pos-frontend/resources/js/pages/Products/Edit.vue

### R02 — Datos técnicos fusionados con información principal
  R02 | ✅ Implementado | Create.vue muestra atributos técnicos fusionados inline en sección "Datos del Producto". Edit.vue ahora tiene la sección "Especificaciones Técnicas" visible con `AttributeManager` renderizado directamente en el template, permitiendo ver, agregar, editar y eliminar atributos.
  Fix previo (2026-03-21): VariantManager.vue hereda atributos de la última variante existente con deep copy.
  Fix final (2026-03-21): AttributeManager añadido al template de Edit.vue con `Wrench` y `Globe` icons importados.

  Archivos: pos-frontend/resources/js/pages/Products/Create.vue, pos-frontend/resources/js/pages/Products/Edit.vue, pos-frontend/resources/js/components/Products/AttributeManager.vue

### R03 — Precio e inventario dentro de cada variante
  R03 | ✅ Implementado | ProductVariant.php (líneas 50-64) tiene pricing(): HasOne y inventory(): HasMany por variante. VariantManager.vue permite definir precio e inventario inicial por variante individualmente. Arquitectura dual: Product O Variant pueden tener pricing/inventory.

  Archivos: pos-backend/app/Models/ProductVariant.php, pos-backend/app/Models/ProductPricing.php, pos-backend/app/Models/ProductInventory.php, pos-frontend/resources/js/components/Products/VariantManager.vue

### R04 — Código de producto no es el key único del sistema
  R04 | ✅ Implementado | SKU es 'nullable|string' sin unique constraint. Migraciones 2026_03_11 y 2026_03_12 eliminaron constraints de SKU y UPC. Key único es ID autogenerado (BIGINT autoincrement).

  Archivos: pos-backend/app/Http/Requests/StoreProductRequest.php:31-32, pos-backend/database/migrations/2026_03_12_011851_drop_sku_unique_from_product_variants_table.php

### R05 — Precio de venta editable en POS según rol
  R05 | ✅ Implementado | Frontend valida rol en ProductTable.vue:82-85 (solo admin/manager ven campo editable; otros ven candado). Backend valida rol en SalesOrderController y SalesQuoteController antes de aceptar precio override; usuarios sin permiso reciben precio del sistema desde ProductPricing.base_price.

  Archivos: pos-frontend/resources/js/components/POS/ProductTable.vue:82-85, pos-backend/app/Http/Controllers/Api/SalesOrderController.php:171-191, pos-backend/app/Http/Controllers/Api/SalesQuoteController.php:90-106

### R06 — Flujo siempre comienza con cotización
  R06 | 🚫 N/A | Descartado por decisión de diseño. El POS permite tanto venta directa de mostrador como ventas cerradas a partir de cotizaciones, brindando flexibilidad para la coexistencia de roles (vendedores vs cajeros expedidores).
  Qué falta: Nada. Se decidió intencionalmente mantener la flexibilidad híbrida actual.

  Archivos: pos-backend/routes/api_sales_orders.php, pos-frontend/resources/js/composables/usePOS.ts

### R07 — Estados del flujo de cotización
  R07 | ✅ Implementado | SalesQuote.php:16 define 9 estados: draft, sent, sent_to_deposit, sent_to_cashier, accepted, collected, rejected, expired, converted. Controller tiene métodos sendToDeposit():248, sendToCashier():267, markCollected():286, convert():322.

  Archivos: pos-backend/app/Models/SalesQuote.php:16, pos-backend/app/Http/Controllers/Api/SalesQuoteController.php:248-322

### R08 — Expiración de cotización configurable desde settings
  R08 | ✅ Implementado | quote_expiration_days en StoreSettingController.php:45 ($allowed). useStoreSettings.ts:16 documenta: 0=disabled, 15/30/60/90 días. Endpoint: PUT /api/v1/store-settings.

  Archivos: pos-backend/app/Http/Controllers/Api/StoreSettingController.php:45, pos-frontend/resources/js/composables/useStoreSettings.ts:16-33

### R09 — Ver especificaciones del producto desde POS/cotización
  R09 | ⚠️ Parcial | ProductDetailModal accesible desde CARRITO via clic derecho/long-press (ProductTable.vue:49-58). Desde BÚSQUEDA (ProductSearchModal.vue) solo hay badges resumidos de key_attributes (líneas 277-290), sin opción de ver detalle completo antes de agregar.
  Qué falta: botón "Ver detalle" en ProductSearchModal antes de agregar al carrito.

  Archivos: pos-frontend/resources/js/components/POS/ProductTable.vue:49-58, pos-frontend/resources/js/components/POS/ProductSearchModal.vue:277-290

### R10 — Filtro de vehículos: marca → modelo → motor + búsqueda directa por motor
  R10 | ⚠️ Parcial | VehicleSelector.vue implementa cascada estricta 4 niveles: Marca→Modelo→Serie→Motor (líneas 117-144 selectores deshabilitados en cascada). Backend VehicleEngineController:15-54 SÍ soporta búsqueda directa por engine_code/displacement/fuel_type pero el frontend NO lo expone.
  Qué falta: campo de búsqueda libre de motor en VehicleSelector que use endpoint /vehicle-engines?search=.

  Archivos: pos-frontend/resources/js/components/VehicleSelector.vue:117-144, pos-backend/app/Http/Controllers/Api/VehicleEngineController.php:15-54

### R11 — Años con dos dígitos en compatibilidad vehicular
  R11 | ❌ Ausente | Migración usa tipo MySQL YEAR (4 dígitos: 1901-2155). VehicleModel.php castea como integer. getYearRangeAttribute() retorna "2020-2024" sin transformación.
  Qué falta: función de formateo para mostrar años abreviados (94, 00, 08) en UI.

  Archivos: pos-backend/database/migrations/2026_02_11_000015_create_vehicle_models_table.php, pos-backend/app/Models/VehicleModel.php

### R12 — Thumbnail del producto en listados y búsqueda POS
  R12 | ✅ Implementado | ProductCard.vue muestra image_url con fallback SVG (lista:63-70, grid:143-152). ProductSearchModal.vue:240-253 muestra thumbnail 40x40 en tabla de resultados.

  Archivos: pos-frontend/resources/js/components/Products/ProductCard.vue:63-152, pos-frontend/resources/js/components/POS/ProductSearchModal.vue:240-253

### R13 — Nota interna separada de nota para el cliente
  R13 | ✅ Implementado | SalesQuote.php y SalesOrder.php tienen 'internal_notes' en fillable. Migración 2026_03_17_120000 agrega columna a ambas tablas. Controllers (store/update/storeFromPos) validan y guardan internal_notes. Frontend SalesQuotes/Create.vue y Edit.vue tienen campo "Nota Interna" con aviso de no aparecer en documentos.

  Archivos: pos-backend/app/Models/SalesQuote.php:33, pos-backend/app/Models/SalesOrder.php:31, pos-backend/database/migrations/2026_03_17_120000_add_internal_notes_to_sales_quotes_and_orders.php, pos-frontend/resources/js/pages/SalesQuotes/Create.vue:361-372, pos-frontend/resources/js/pages/SalesQuotes/Edit.vue:376-384

### R14 — Reorden desde cotización/venta
  R14 | ✅ Implementado | ProductTable.vue tiene botón "Abastecer desde aquí" en fila expandible cuando available_stock <= 0. Usa QuickPurchaseModal con lookup de producto desde props.products. QuickPurchaseModal incluye fallback variants[0].pricing.cost para obtener costo cuando product.pricing.cost no está disponible en listados.

  Archivos: pos-frontend/resources/js/components/POS/ProductTable.vue (import, state, openQuickPurchase, handlePurchaseSuccess, botón en línea 331-349, modal al final), pos-frontend/resources/js/components/POS/QuickPurchaseModal.vue (resetForm línea 59 con fallback variants)

### R15 — Reordenar líneas de productos en cotización
  R15 | ✅ Implementado | vuedraggable v4.1.0 instalado. ProductTable.vue:92-98 envuelve tabla en <draggable> con handle=".drag-handle". GripVertical icon visible en línea 127 con title="Arrastrar para reordenar". usePOS.ts:374-377 reorderCart() sincroniza nuevo orden.

  Archivos: pos-frontend/resources/js/components/POS/ProductTable.vue:92-127, pos-frontend/resources/js/composables/usePOS.ts:374-377

### Requerimientos Reunión 2

| Req | Estado | Descripción |
|-----|--------|-------------|
| R20 | ✅ Implementado | Alerta de duplicado al crear producto/vehículo |
| R21 | ✅ Implementado | Motores como entidad separada reutilizable |
| R22 | ✅ Implementado | Fórmula de precio predeterminada por categoría |
| R23 | ✅ Implementado | Costo desglosado: base + ITBM = total en producto y OC |
| R24 | ✅ Implementado (cubierto por R30) | Precio de venta editable inline desde orden de compra |
| R25 | ✅ Implementado (ya existía) | Click en producto de OC muestra detalle/atributos |
| R26 | ✅ Implementado (ya existía) | Búsqueda por código en orden de compra |
| R27 | ✅ Implementado | Atributos del producto heredados en todas las variantes |
| R28 | ✅ Implementado | Stock mínimo cero — no permitir negativos |
| R29 | ✅ Implementado | Lista de abastecimiento previa a OC |
| R30 | ✅ Implementado | Precio de venta editable inline en OC al recibir |
| R31 | ✅ Implementado | Eliminar botón "Guardar" del POS |
| R32 | ✅ Implementado | X para quitar cotización de cola de caja |
| R33 | ✅ Implementado | Altura fija tarjetas en cola de caja |
| R34 | ✅ Implementado | Eliminar flechitas de inputs numéricos |
| R35 | ✅ Implementado | Cola de depósito/bodeguero |
| R36 | ✅ Implementado | Métodos de pago configurables desde Settings |
| R37 | ✅ Implementado | Atributos de categoría en columna vertical única, no dos columnas |
| R38 | ✅ Implementado | Quitar campo SKU variante — todas las variantes usan el código del producto padre |
| R39 | ✅ Implementado | Selección múltiple de motores en compatibilidad vehicular |
| R40 | ✅ Implementado | R09 más obvio — botón "Ver detalle" visible en búsqueda POS, no solo clic derecho |
```
### Bugs pendientes Reunión 2

| Bug | Estado | Descripción |
|-----|--------|-------------|
| B01 | ✅ Resuelto | Enter en atributos de categoría saca del campo |
| B02 | ✅ Resuelto | Nombre de categoría no se actualiza en panel derecho |
| B03 | ✅ Resuelto | Submenú de item en carrito sin forma obvia de cerrar |

---

## Resumen de auditoría (actualizado 21-mar-2026)

**Total de requerimientos:** 15

| Estado | Cant | Requerimientos |
|--------|------|----------------|
| ✅| Implementados | 10 | R02, R03, R04, R05, R07, R08, R12, R13, R14, R15 |
| Parciales | 2 | R09, R10 |
| Ausentes | 1 | R11 |
| N/A / Descarte | 2 | R01, R06 |

### Detalle de Parciales

| Req | Qué existe | Qué falta |
|-----|-----------|-----------|
| R09 | Modal detalle desde carrito | Botón "Ver detalle" en búsqueda |
| R10 | Cascada marca→modelo→motor | Búsqueda directa de motor sin modelo |

### Orden de implementación recomendado

**Fase P0 — Críticos para primera venta (8h)**
1. ~~R05 (2h) — Validación de rol en backend para editar precio~~ ✅ Completado
2. ~~R13 (3h) — Campo internal_notes en cotización/venta~~ ✅ Completado
3. ~~R06 (8h) — Flujo obligatorio cotización → venta~~ (Descartado)

**Fase P1 — Completitud operativa (15h)**
4. ~~R01 (6h) — Unificar formularios crear/editar producto~~ (N/A — descartado por diferencias fundamentales)
5. ~~R02 (2h) — Agregar sección specs técnicas a Edit.vue~~ ✅ Completado
6. R10 (4h) — Búsqueda directa de motor en VehicleSelector
7. R09 (3h) — Botón "Ver detalle" en ProductSearchModal

**Fase P2 — Nice-to-have (10h)**
8. ~~R14 (6h) — Botón back order desde línea de carrito~~ ✅ Completado
9. R11 (4h) — Soporte años 2 dígitos (requiere migración)

**Auditoría completada — 17 de marzo de 2026, sin modificaciones al código.**

## Correcciones críticas pre-lanzamiento — 2026-03-18

- Módulo C1 (seeders):
  - Se eliminó la doble invocación de `VehicleSeeder` en `DatabaseSeeder` (solo queda una llamada).
  - `VehicleSeeder` se hizo idempotente reemplazando todas las inserciones `create()` por `firstOrCreate()`.
  - También se corrigieron seeders con restricciones `unique`: `BrandSeeder` (`name`), `SupplierSeeder` (`name`), `WarehouseSeeder` (`code`), `CategorySeeder` (`slug`) y `DemoDataSeeder` para `ProductBatch` (`product_id` + `batch_number`) y `WarehouseBatch` (`warehouse_id` + `product_batch_id`).
  - Verificación: `php artisan db:seed --force` pasó en ejecuciones consecutivas; backend tests P0 y suite completa en verde.

- Módulo C2 (auth rutas web):
  - Se identificaron como públicas y se protegieron con `auth`: `/suppliers*`, `/purchase-orders*` y `/accounts-payable`.
  - Se usó el patrón existente `Route::middleware(['auth'])->group(...)` sin introducir estructura nueva.
  - Verificación manual: `/login` sigue accesible sin sesión y `/purchase-orders` redirige a login sin sesión.

- Módulo C3 (TypeScript):
  - Errores nuevos identificados: `resources/js/pages/PurchaseOrders/Receive.vue` y `resources/js/pages/PurchaseOrders/Show.vue` por uso de `product.sku` inexistente en el tipo.
  - Resultado: TS errors bajaron de 17 a 15 (`Found 15 errors in 4 files`, quedando solo los preexistentes de Products/SalesOrders + TwoFactor), build frontend OK.

## Correcciones de Roles, Caja y Vendedores — 2026-03-20

- **Roles & Permisos:**
  - `DemoDataSeeder.php` modificado para usar `updateOrCreate` en usuarios, forzando los roles correctos en cada seed.
  - Rutas web `/products/create` y `/products/{product}/edit` protegidas con middleware de rol para prevenir acceso de vendedores.
  - El campo `username` se añadió al `$fillable` en `User.php` del frontend para permitir guardar en edición de perfil.
- **Bodegas y Operaciones:**
  - `SalesQuoteController@pendingAtCashier` usa `current_warehouse_id` del usuario como fallback si el header `X-Warehouse-Id` falta (evita 403s innecesarios en frontend).
- **Flujo POS / Cotizaciones:**
  - Los vendedores ya no son redirigidos forzosamente a "/pos" al darle "Enviar a Caja" desde el listado.
  - En la lista de Órdenes de Venta se removió el botón "Nueva Orden", forzando que toda venta nazca de la interfaz POS.
  - **Conversión POS a Caja:** El módulo POS (`usePOS.ts`, `sales-order.ts`) ahora inyecta recursivamente el `source_quote_id` de la sesión base durante el checkout en `processSale()`. `SalesOrderController@store` lo recibe, y `SalesOrderService@create` localiza la cotización, la mapea vía `converted_order_id` y ejecuta `markCollected()`. Esto purga la cotización exitosamente de la bandeja "Cola de Caja" tras la facturación.

## Correcciones de Producto/Variantes — 2026-03-21

- **Pre-llenado de atributos en nueva variante (VariantManager.vue):**
  - `openCreateModal()` ahora usa `[...displayVariants.value].reverse().find(...)` para tomar la **última** variante con atributos en lugar de la primera.
  - Se reemplazó copia superficial `.map()` por `structuredClone()` para garantizar deep copy y evitar mutación cruzada entre variantes.
  - Build `✓`, TS errors 15 → 15 (sin nuevos errores), commit `fix(products): pre-fill variant attrs from last existing variant`.

- **Borrado silencioso de atributos al guardar variante (2026-03-21):**
  - Bug: `ProductVariantController::update()` llamaba `assignVariantAttributes()` aunque el payload trajera `attributes: []`, borrando todos los atributos existentes sin error visible.
  - Fix A — backend (`ProductVariantController.php` línea 228): añadida condición `!empty($validated['attributes'])` para no ejecutar el delete/recreate innecesariamente.
  - Fix B — frontend (`VariantManager.vue` `handleSubmit`): el payload de UPDATE solo incluye la clave `attributes` cuando hay al menos un atributo válido (`name + value` no vacíos); para CREATE se incluye siempre.
  - P0 (5/5 ✓), P1 (14/14 ✓), build ✓, 0 errores TS nuevos.
  - Commits: `fix(variants): prevent silent attribute deletion on save without changes` (backend) y `fix(variants): omit attributes from payload when none were modified` (frontend).

## Sesión Fix — confirm() carga solo monto pendiente + crédito dedicado en modal POS (2026-03-22)

- **Backend — `SalesOrder.php` (`confirm()`):**
  - Se corrigió el cargo al balance del cliente para usar solo monto pendiente:
    - Antes: siempre sumaba `total` completo.
    - Ahora: calcula `amountPaid = payments()->sum('amount')` y `amountToBill = max(0, total - amountPaid)`.
  - Solo crea `CustomerTransaction` tipo `sale` cuando `amountToBill > 0`.
  - Resultado: ventas totalmente pagadas ya no generan cargo `sale`; pagos parciales cargan únicamente el saldo pendiente.

- **Backend — Tests P2 (`tests/Feature/P2/CustomerBalanceTest.php`):**
  - Casos cubiertos:
    1) venta a crédito puro (genera 1 cargo `sale`),
    2) venta pagada completa (sin cargo `sale`),
    3) venta con pago parcial (cargo solo por pendiente),
    4) venta con `store_credit` (sin deuda incorrecta).
  - Ejecución: 4/4 tests en verde.

- **Frontend — `PaymentMethods.vue`:**
  - Se eliminó `creditoCliente` del `PAYMENT_TYPES`.
  - No había lógica especial adicional asociada a `creditoCliente`; solo estaba definido como método en el array.

- **Frontend — `POS/Index.vue` (modal de cobro):**
  - Se agregó sección dedicada **"Crédito del Cliente"** visible solo con cliente seleccionado.
  - Muestra límite, crédito disponible, balance actual, saldo a favor y días de crédito.
  - Se agregó `creditToCharge` (ref) y `creditAvailable` (computed).
  - El input de crédito usa tope `min(creditAvailable, remainingToPay)` y botón "Máximo".
  - `totalPaid` ahora incluye `creditToCharge`.
  - `paymentsArray` agrega método `credit` cuando `creditToCharge > 0`.
  - `creditToCharge` se resetea al cerrar modal y al cambiar cliente.

---

## Pendientes históricos (pre-reunión 2)

| # | Descripción | Estado |
|---|-------------|--------|
| R09 | Botón "Ver detalle" en búsqueda del POS | ❌ pendiente |
| R10 | Búsqueda directa por motor sin cascada | ❌ pendiente |
| R11 | Años en dos dígitos en compatibilidad vehicular | ❌ pendiente |
| — | Fix confirm() balance incorrecto | ✅ resuelto 2026-03-22 |
| — | Sección crédito dedicada en modal cobro | ✅ resuelto 2026-03-22 |
| — | Fix addPayment() deuda real en CxC | ❌ pendiente |
| — | Sección crédito en modal cobro (separada de Visa/MC) | ❌ pendiente |

---

## Mejoras de UX y Carrito POS (Requerimientos Extra 2026-03-30)

| Req     | Descripción | Estado |
|---------|-------------|--------|
| **R41**     | Banner estilizado en el Header del POS para *quoteMode* mostrando número de cotización o "NUEVA COTIZACIÓN" | ✅ implementado |
| **UX-001**  | Interceptor global de navegación (`router.on('before')`) y `beforeunload` para prevenir escape accidental con carrito lleno | ✅ implementado |
| **R42**     + **BUG-001** | Búsqueda vacía solicita todos los productos; el flujo siempre abre el modal (eliminando redirect ciego a un solo resultado) | ✅ implementado/verificado |
| **R43**     | Edición de búsqueda dentro del modal (emite `@search-in-modal`) sin cerrar la ventana principal | ✅ implementado |
| **R44**     | Buscador local y ordenamiento (Nombre, SKU, Cantidad, Precio) dentro del panel del carrito POS | ✅ implementado |
| **R45**     | Panel Lateral (Sidebar) colapsable con lista de categorías y marcas funcionando como filtro persistente | ✅ implementado |

---

## Correcciones de Precios y Redondeo Configurable — 2026-04-06

### Resumen
Implementación completa de consistencia de precios en la tabla de inventario y sistema de redondeo automático configurable por el administrador.

### Cambios implementados (todos verificados ✅)

**Backend:**
- `app/Helpers/PriceHelper.php` — [NUEVO] Helper estático `PriceHelper::roundUp(float $price, float $increment)` que usa `ceil()` para redondear hacia arriba al incremento más cercano. Soporta 0.25, 0.50, 1.00, 5.00, 10.00.
- `app/Http/Controllers/Api/StoreSettingController.php` — Agregado `price_rounding_increment` al array `$allowed` (línea 45), al bloque de grupos (`pricing`, línea 53) y al bloque de tipos (`number`, línea 59).
- `database/seeders/DefaultSettingsSeeder.php` — Agrega `Setting::set('price_rounding_increment', 1.00, 'number', 'pricing')`. Idempotente por `updateOrCreate`.
- `app/Http/Controllers/Api/InventoryController.php` — Ya incluía `base_price` y `cost` en el payload del listado desde el principio (líneas 78-79). No requirió cambio.

**Frontend:**
- `resources/js/composables/useStoreSettings.ts` — Interfaz `StoreSettings` incluye `price_rounding_increment: number` (línea 16) con default `1.00` (línea 34).
- `resources/js/pages/Settings/Store.vue` — Sección "Precios" (líneas 444-466) con `<select v-model.number="settings.price_rounding_increment">` con opciones 0.25, 0.50, 1.00, 5.00, 10.00 y ejemplos descriptivos.
- `resources/js/pages/Inventory/Index.vue` — Columna "Precio Venta" (líneas 217-314) ya renderiza `formatCurrency(item.base_price)` con color verde cuando tiene valor o `—` cuando es null.
- `resources/js/components/Products/VariantManager.vue` — Usa `useStoreSettings` con `fetchSettings()` en `onMounted` (Opción A: fetch directo, sin prop). Función `applyRounding()` (líneas 39-44) lee `storeSettings.value.price_rounding_increment` y aplica `ceil(price / increment) * increment`. Aplicado en `recalculatePriceFromCategory()` y en el watcher de `form.pricing.cost`. **Solo cuando `!priceManuallyEdited`** (respeta LL-013).

### ProductDetailModal.vue — Confirmado existente
`resources/js/components/Products/ProductDetailModal.vue` (608 líneas) es un modal completo de detalle de producto con:
- Carrusel de imágenes con upload/delete inline
- Grid de variantes/marcas con precios: en modo normal muestra `base_price`; en modo cliente (blindMode) muestra `calculated_price * (1 + tax_rate/100)` con toggle de ITBM
- Helper `getVariantPricing()` que prioriza pricing de variante sobre la del producto (fallback)
- Especificaciones técnicas por variante (con cache por variante)
- Tabla de compatibilidad vehicular
- Referencias OEM y alternativas/referencias cruzadas navegables
- Integración con POS via `usePOS().addToCart()`

### Archivos clave (mapa actualizado)
```
pos-backend/app/Helpers/PriceHelper.php                          → roundUp() estático
pos-backend/database/seeders/DefaultSettingsSeeder.php           → seed price_rounding_increment=1.00
pos-frontend/resources/js/pages/Settings/Store.vue               → sección "Precios" con select de redondeo
pos-frontend/resources/js/pages/Inventory/Index.vue              → columna "Precio Venta" con base_price
pos-frontend/resources/js/components/Products/VariantManager.vue → applyRounding() + fetchSettings()
pos-frontend/resources/js/components/Products/ProductDetailModal.vue → modal detalle completo con precios y blindMode
```

## Generación Automática de SKU Configurable — 2026-04-06

### Resumen
Implementación de un sistema flexible de generación de SKU basado en un formato definido por el usuario mediante arrastrar y soltar (Drag & Drop).

### Cambios implementados
- Reemplazo del obsoleto renderizador de SKU en Backend (`fetchSkuPreview`) a favor de un calculador en tiempo real en Frontend usando Vue Reactivity (`generateSkuFromFormat()`).
- Nueva propiedad `sku_format` (serializado como JSON) en `StoreSettingController.php`, expuesta centralmente vía `StoreSettings.ts`.
- Nueva UI visual para acomodar dinámicamente componentes constructores del SKU (`category_slug`, `brand`, `year`, campo custom) con previsualizador instantáneo en `Settings/Store.vue`.
- Incorporación del comportamiento inteligente de "Sobreescritura Manual" en `Create.vue` (`skuBaseManuallyEdited`) que frena cálculos automatizados y habilita el botón "Regenerar" como resguardo.

## Búsqueda Avanzada y Sidebar de Filtros (POS + Catálogo) — 2026-04-07

### Resumen
Se unificó el sistema de filtros para catálogo y punto de venta implementando un estilo "Amazon-like" con un panel lateral con filtros avanzados: SKU, precios, categorías, marcas, disponibilidad de stock y ordenamiento interactivo.

### Cambios implementados
- **Backend**: Se extendió `ProductController@index` para soportar `min_price`, `max_price`, `sku` (búsqueda parcial sobre `sku_base`) e `in_stock`. Se refactorizó la lógica de ordenamiento con un alias unificado `sort` y la adición de un `LEFT JOIN` a la tabla `product_pricing` (protegido temporalmente) exclusivo para el ordenamiento por precios.
- **Componentes Vue unificados**: Se crearon `ProductFilters.vue` (con sections unificadas) y `ProductSort.vue`. Se eliminó el componente local residual `POSFilterSidebar.vue`.
- **Integración POS**: Actualización de `ProductSearchModal.vue` ampliando el layout flex a `max-w-6xl` incorporando sidebar lateral (`hidden md:block`) con botón toggle para móviles y acoplándolo a un emit extendido de `@search-in-modal`.
- **Integración Catálogo**: Actualización de `Products/Index.vue` suprimiendo los filtros estáticos horizontales reemplazándolos con el nuevo sidebar de filtros para unificación del diseño de búsqueda.
- **Tipado TS**: Las nuevas propiedades de búsqueda quedaron registradas estrictamente en la interfaz `ProductFilters` dentro de `product.ts`.

## Sistema de Chasis + Compatibilidad Avanzada — 2026-04-07

### Resumen
Implementación de chasis como entidad de catálogo independiente (igual que motores), permitiendo asociar productos a motores específicos, chasis específicos (ej. carrocerías de motos o autos), o compatibilidad universal parcial mediante flags.

### Cambios implementados
- **Backend**: Nueva migración `vehicle_chassis` y `VehicleChassisController` nativo. Modificación de la tabla pivote `product_vehicle_compatibility` para incluir `chassis_id`, `all_engines` y `all_chassis`. Scope de búsqueda mejorado para encontrar chasis.
- **Corrección en Creación**: Inclusión crítica de las reglas `vehicle_chassis`, `all_engines` y `all_chassis` dentro de `StoreProductRequest` para prevenir la exclusión silenciosa de estos campos durante el `validated()` antes de que `ProductService` genere las relaciones con productos recién modelados.
- **Componentes Frontend**: Implementación de navegación por pestañas (`Tabs`: Motores / Chasis) y selectores modales instantáneos en los flujos principales de inventario (`Create.vue` y `Edit.vue`). Añadido de checkboxes para "Compatibilidad Universal al Modelo". Inclusión del wrapper estandarizado `useVehicleChassis.ts`.
- **Limpieza (Tech Debt)**: Componente inutilizado y arcaico `FitmentManager.vue` finalmente borrado del árbol de proyecto.
