### 1. Stack técnico confirmado

- Backend (composer.json): PHP ^8.2, laravel/framework ^12.0, laravel/tinker ^2.10.1, barryvdh/laravel-dompdf, maatwebsite/excel, picqer/php-barcode-generator, spatie/laravel-activitylog.
- Backend dev (composer.json): fakerphp/faker ^1.23, laravel/boost ^1.0, laravel/dusk, laravel/pail ^1.2.2, laravel/pint ^1.24, laravel/sail ^1.41, mockery/mockery ^1.6, nunomaduro/collision ^8.6, pestphp/pest ^4.3, pestphp/pest-plugin-laravel ^4.0.
- Frontend/runtime (package.json): Vue ^3.5.13, @inertiajs/vue3 ^2.3.7, axios ^1.13.5, @vueuse/core ^12.8.2, chart.js ^4.5.1, vue-chartjs ^5.3.3, class-variance-authority ^0.7.1, clsx ^2.1.1, date-fns ^4.1.0, jsbarcode ^3.12.3, laravel-vite-plugin ^2.0.0, lucide-vue-next ^0.468.0, reka-ui ^2.8.2, tailwind-merge ^3.2.0, tailwindcss ^4.1.1, tw-animate-css ^1.2.5, vue-input-otp ^0.3.2, vuedraggable ^4.1.0.
- Frontend/dev (package.json): TypeScript ^5.2.2, vite ^7.0.4, vue-tsc ^2.2.4, @vitejs/plugin-vue ^6.0.0, @tailwindcss/vite ^4.1.11, @eslint/js ^9.19.0, @vue/eslint-config-typescript ^14.3.0, eslint ^9.17.0, eslint-config-prettier ^10.0.1, eslint-import-resolver-typescript ^4.4.4, eslint-plugin-import ^2.32.0, eslint-plugin-vue ^9.32.0, prettier ^3.4.2, prettier-plugin-tailwindcss ^0.6.11, concurrently ^9.0.1, @laravel/vite-plugin-wayfinder ^0.1.3, @types/node ^22.13.5.

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
pos-backend/routes/api_vehicles.php → define recursos de marcas, modelos, motores, VIN y compatibilidad vehicular.
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
pos-backend/app/Http/Requests/StoreProductRequest.php → valida creación de producto, pricing, inventario inicial, compatibilidad, VIN y referencias OEM.
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
pos-backend/database/seeders/DefaultSettingsSeeder.php → define defaults de configuración para store_tax_rate y modules_work_orders_enabled.
pos-backend/tests/Unit/ExampleTest.php → prueba unitaria de ejemplo del backend.
pos-backend/app/Models/WorkOrder.php → modelo WorkOrder con estados, transiciones, folio auto-generado y relaciones a customer, warehouse, creator, assignee e items.
pos-backend/app/Models/WorkOrderItem.php → modelo WorkOrderItem con relaciones a WorkOrder, Product, ProductVariant y Warehouse.
pos-backend/database/migrations/2026_03_17_000001_create_work_orders_table.php → crea tablas work_orders y work_order_items con FK a customers, warehouses, users, products y product_variants.
pos-backend/app/Http/Requests/StoreWorkOrderRequest.php → valida creación de work orders (warehouse, customer opcional, assigned_to, items con product, variant, warehouse, quantity y unit_price).
pos-backend/app/Http/Controllers/Api/WorkOrderController.php → controlador CRUD + status transition para work orders; descuenta inventario en store y lo restaura en cancel/delete.
pos-backend/routes/api_work_orders.php → 6 rutas: GET/POST /work-orders, GET/PUT/DELETE /work-orders/{id}, POST /work-orders/{id}/transition.
pos-backend/tests/Feature/P2/WorkOrderTest.php → 9 tests P2: creación mínima, folio auto-generado, decremento de inventario, validaciones, listado paginado, transición de estado, transición inválida y restauración de stock al cancelar.
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
pos-frontend/resources/js/composables/usePOS.ts → composable POS con persistencia de sesiones y carrito en localStorage; ahora incluye available_stock en cada línea de carrito para validar checkout por stock.
pos-frontend/resources/js/composables/usePosSession.ts → composable para sesión activa de POS; ahora sincroniza/limpia estado en localStorage según la sesión real devuelta por backend.
pos-frontend/resources/js/composables/usePosSettings.ts → composable de configuración POS con fallback de sucursal en localStorage.
pos-frontend/resources/js/composables/useProductAttributes.ts → composable que consulta y muta atributos de producto vía apiClient.
pos-frontend/resources/js/composables/useProductFitment.ts → composable que consulta y muta compatibilidad vehicular de productos vía apiClient.
pos-frontend/resources/js/composables/useProducts.ts → composable para operaciones de productos.
pos-frontend/resources/js/composables/usePurchaseOrders.ts → composable para órdenes de compra.
pos-frontend/resources/js/composables/useReports.ts → composable para reportes.
pos-frontend/resources/js/composables/useSalesOrders.ts → composable para órdenes de venta.
pos-frontend/resources/js/composables/useStoreSettings.ts → composable que lee y actualiza store settings y logo vía apiClient.
pos-frontend/resources/js/components/AppSidebar.vue → navegación principal; ahora filtra entradas de work orders cuando modules_work_orders_enabled es false.
pos-frontend/resources/js/composables/useSuppliers.ts → composable para proveedores.
pos-frontend/resources/js/composables/useToast.ts → composable de toasts en memoria para success, error, warning e info.
pos-frontend/resources/js/composables/useTwoFactorAuth.ts → composable para flujos de two-factor authentication.
pos-frontend/resources/js/composables/useUsers.ts → composable que obtiene y maneja usuarios vía apiClient.
pos-frontend/resources/js/composables/useVehicleContext.ts → composable para contexto vehicular en la UI.
pos-frontend/resources/js/composables/useVehicleEngines.ts → composable CRUD de motores de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleMakes.ts → composable CRUD de marcas de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleModels.ts → composable CRUD de modelos de vehículo vía apiClient.
pos-frontend/resources/js/composables/useVehicleVins.ts → composable CRUD de VIN por motor vía apiClient.
pos-frontend/resources/js/composables/useWarehouses.ts → composable para operaciones de sucursales.
pos-frontend/resources/js/composables/useWorkOrders.ts → composable para CRUD de work orders y transición de estado vía apiClient.
pos-frontend/resources/js/pages/ → árbol de páginas Inertia/Vue para auth, dashboard, POS, inventario, compras, ventas, reportes, vehículos, settings y catálogos.
pos-frontend/resources/js/pages/WorkOrders/Index.vue → listado de work orders con tabla Folio/Vehículo/Estado/Sucursal/Fecha, filtro por estado y búsqueda por texto.
pos-frontend/resources/js/pages/POS/Index.vue → página principal POS; ahora valida checkout por stock y consulta sesión activa una sola vez después de inicializar sucursal.
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
Tests: pos-backend/tests/Feature/ tiene 15 archivos (P0+P1+P2+example); última corrida conjunta P0+P1+P2: 28 passed, 1 skipped (58 assertions); pos-frontend/tests/Feature/ tiene 12 archivos y cubre auth, dashboard, settings y example.
Cache/Queue: config/cache.php usa `database` como store por defecto; config/queue.php usa `database` como conexión por defecto y `database-uuids` para failed jobs (migración a Redis no aplicada: redis-cli no disponible en entorno).
Store settings: store_tax_rate y modules_work_orders_enabled expuestos como settings configurables (con defaults vía seeder).
Frontend offline: no encontré service worker, IndexedDB ni PWA en package.json, resources/js/app.ts, resources/js/services/api/client.ts ni en el barrido de resources/js/composables/.

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
    [ ] Módulo 2.3: Migrar cache/queue a Redis (opcional por entorno, omitido: Redis no disponible)
  
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
  FASE 7 — Sanctum tokens [requiere F6]
  FASE 8 — QuickBooks/Xero [requiere todo estable]