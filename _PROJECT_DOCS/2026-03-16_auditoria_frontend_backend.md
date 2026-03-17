# Plan de Implementación por Fases — POS Autopartes

Fecha: 2026-03-16  
Basado en: Auditoría técnica completada 2026-03-16

---

## Cómo leer este plan

Cada fase tiene **módulos independientes** que pueden ejecutarse en paralelo dentro de la misma fase. Cada módulo sigue este ciclo:

```
LEER archivos afectados → IMPLEMENTAR cambio mínimo → VERIFICAR (test/smoke) → COMMIT → siguiente tarea
```

Las verificaciones marcadas con 🔁 son **recursivas**: deben ejecutarse también al finalizar cada fase completa, no solo después del módulo puntual, para detectar regresiones acumuladas.

**Regla de no romper:** antes de modificar cualquier archivo, leer su contenido actual completo. Ningún cambio en un controlador, modelo o ruta elimina funcionalidad existente sin un test de regresión explícito que lo cubra primero.

---

# Auditoría Frontend y Backend (Resumen base)

Fecha: 2026-03-16

## 1. Resumen Ejecutivo

El producto actual ya cubre una parte importante del núcleo operativo de un POS especializado en autopartes: catálogo con compatibilidad vehicular, POS, inventario, compras básicas, clientes, proveedores y reportes base. La mayor fortaleza diferencial frente a Lightspeed sigue siendo el núcleo vehicular y de fitment nativo, visible en la búsqueda por VIN, filtros OEM y contexto de vehículo.

Sin embargo, contra los requisitos planteados para una auditoría de readiness empresarial, el sistema aún está en una arquitectura monolítica modular, sin microservicios, sin API Gateway, sin rate limiting explícito, sin observabilidad formal y sin capacidades offline reales de tipo PWA. En frontend hay buena cobertura funcional, pero no hay evidencia de búsqueda por placa, ni de workflows completos de órdenes de trabajo de taller, ni de optimización específica para operación sin conectividad.

### Estado General

- Frontend/UI-UX: funcional y consistente, con base reusable y soporte responsive razonable, pero todavía orientado más a escritorio administrativo que a taller mobile-first.
- Backend/API: monolito Laravel bien modularizado por dominios, con autenticación por sesión y control de roles, pero lejos del objetivo de escalabilidad distribuida y compliance endurecido.
- Seguridad/compliance: base aceptable para MVP interno, insuficiente para un entorno con requisitos formales de PCI DSS, GDPR/CCPA y movilidad multi-canal.

### Riesgos Críticos

- No hay evidencia de rate limiting en la API autenticada.
- No hay arquitectura de microservicios ni API Gateway; el escalado sigue acoplado al monolito.
- No existe offline real: no se halló PWA, service worker, IndexedDB ni sincronización diferida.
- No existe búsqueda por placa ni módulo de órdenes de trabajo de taller.
- No hay integraciones activas con Stripe, Square, QuickBooks, Xero ni catálogos OEM/aftermarket externos.
- No se puede demostrar el cumplimiento de objetivos de performance p95 < 200ms o carga inicial < 3s porque no hay telemetría/APM configurada en el repositorio.

## 2. Alcance y Evidencia Revisada

### Frontend

- Stack y resolución de páginas Inertia: [resources/js/app.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/app.ts)
- Cliente API global: [resources/js/services/api/client.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/services/api/client.ts)
- POS principal: [resources/js/pages/POS/Index.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/pages/POS/Index.vue)
- Gestión de productos: [resources/js/pages/Products/Index.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/pages/Products/Index.vue)
- Catálogo visual: [resources/js/components/Products/ProductCatalogView.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/Products/ProductCatalogView.vue)
- Selector vehicular: [resources/js/components/VehicleSelector.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/VehicleSelector.vue)
- VIN composable: [resources/js/composables/useVehicleVins.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/useVehicleVins.ts)
- Lazy/load-more de productos: [resources/js/composables/useProducts.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/useProducts.ts)
- Persistencia local del POS: [resources/js/composables/usePOS.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/usePOS.ts)
- Hallazgos de navegación real previos: [_PROJECT_DOCS/frontend_ui_audit.md.resolved](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/frontend_ui_audit.md.resolved)

### Backend

- Entrada API v1: [routes/api.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/routes/api.php)
- Stack backend: [composer.json](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/composer.json)
- Auth y guards: [config/auth.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/auth.php)
- Queue config: [config/queue.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/queue.php)
- Cache config: [config/cache.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/cache.php)
- Servicios externos configurados: [config/services.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/services.php)
- Roles: [app/Models/User.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/User.php)
- Middleware de roles: [app/Http/Middleware/CheckRole.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Middleware/CheckRole.php)
- Búsqueda/filtros de productos: [app/Http/Controllers/Api/ProductController.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Controllers/Api/ProductController.php)
- Search scope del modelo: [app/Models/Product.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/Product.php)
- Soporte a imágenes: [database/migrations/2026_02_14_030833_add_image_url_to_products_table.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/database/migrations/2026_02_14_030833_add_image_url_to_products_table.php)
- Tests críticos: [tests/Feature/P0/RoleMiddlewareTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/RoleMiddlewareTest.php), [tests/Feature/P0/WarehouseEnforcementTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/WarehouseEnforcementTest.php), [tests/Feature/P0/InventoryTransferFlowTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/InventoryTransferFlowTest.php)

### Benchmark y Contexto Estratégico

- Benchmark Lightspeed: [_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md)
- Gap funcional ERP/taller: [erp_audit.md.resolved](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/erp_audit.md.resolved)

## 3. Auditoría Frontend UI/UX

### 3.1 Responsive Design

#### Hallazgos

- La base visual usa Vue 3 + Inertia + Tailwind, lo que favorece responsive por defecto: [resources/js/app.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/app.ts), [package.json](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/package.json).
- El selector vehicular sí contempla disposición móvil progresiva con `grid-cols-1`, `sm:grid-cols-2`, `lg:grid-cols-4`, adecuado para tablet y escritorio: [resources/js/components/VehicleSelector.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/VehicleSelector.vue).
- El catálogo visual usa grid responsivo y alterna entre grid/lista: [resources/js/components/Products/ProductCatalogView.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/Products/ProductCatalogView.vue).
- El POS principal es funcionalmente robusto, pero el archivo evidencia una pantalla muy densa y con muchas interacciones simultáneas, más cercana a workstation de caja que a flujo mobile-first de técnico en taller: [resources/js/pages/POS/Index.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/pages/POS/Index.vue).

#### Evaluación

- Mobile-first para técnicos en taller: parcial.
- Desktop optimizado para gestión administrativa: sí.
- Touch-friendly para tablets: parcial; hay botones y modales adecuados, pero no se observa una estrategia explícita de targets táctiles, estados offline ni simplificación extrema para entorno de taller.

#### Riesgos UX

- El POS prioriza densidad funcional sobre reducción de fricción móvil.
- No hay evidencia de una variante dedicada para tablet/taller.
- No hay un modo degradado para conectividad deficiente.

### 3.2 Experiencia de Usuario Específica del Dominio

#### Búsqueda inteligente de partes por VIN/placa/modelo

- Sí existe búsqueda por VIN y por contexto de motor/fitment: [app/Http/Controllers/Api/ProductController.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Controllers/Api/ProductController.php), [resources/js/composables/useVehicleVins.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/useVehicleVins.ts), [resources/js/components/VehicleSelector.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/VehicleSelector.vue).
- Sí existe búsqueda por OEM y atributos técnicos en catálogo/producto: [app/Http/Controllers/Api/ProductController.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Controllers/Api/ProductController.php), [app/Models/Product.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/Product.php).
- No hay evidencia de búsqueda por placa. El search scope del modelo no contempla matrícula/license plate y no existe módulo de placas: [app/Models/Product.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/Product.php).

#### Workflows optimizados para órdenes de trabajo

- No hay evidencia de un dominio `WorkOrder`, `ServiceOrder`, `RepairOrder` o `Labor` en la arquitectura revisada.
- El benchmark interno reconoce este gap como oportunidad/pendiente frente a Lightspeed: [_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md), [erp_audit.md.resolved](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/erp_audit.md.resolved).

#### Gestión visual de inventario con imágenes de partes

- Existe soporte de `image_url` a nivel de producto: [database/migrations/2026_02_14_030833_add_image_url_to_products_table.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/database/migrations/2026_02_14_030833_add_image_url_to_products_table.php).
- Existe una vista de catálogo pensada para exploración visual: [resources/js/components/Products/ProductCatalogView.vue](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/components/Products/ProductCatalogView.vue).
- Aun así, no se observa en el código revisado una estrategia completa de inventario visual operativa para taller, como galerías optimizadas, placeholders consistentes, zoom táctil o escaneo asistido.

### 3.3 Performance Frontend

#### Hallazgos

- Hay carga incremental de productos mediante `fetchMoreProducts`, lo que cubre parcialmente el requisito de lazy loading para catálogos extensos: [resources/js/composables/useProducts.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/useProducts.ts).
- La resolución de páginas Inertia usa `import.meta.glob`, pero no se evidencia una estrategia de budgeting, prefetching crítico o particionado optimizado por journeys: [resources/js/app.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/app.ts).
- El cliente API tiene timeout de 15s y manejo global de errores, pero no instrumenta métricas ni fallback offline: [resources/js/services/api/client.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/services/api/client.ts).
- La persistencia del POS usa `localStorage`, útil para continuidad mínima de sesión, pero insuficiente para offline transaccional: [resources/js/composables/usePOS.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/composables/usePOS.ts).

#### Gap contra objetivo

- Tiempo de respuesta < 200ms en búsquedas críticas: no demostrable con la evidencia actual.
- Offline para taller sin internet: no cumplido.
- Lazy loading de catálogos: parcialmente cumplido.

### 3.4 Accesibilidad y Calidad de Interacción

- No se encontró un pipeline específico de auditoría WCAG 2.1 ni tooling de accesibilidad en dependencias.
- La auditoría previa de navegación reporta problemas UX reales: estados desincronizados de caja, botones bloqueados tras fallos de API, validaciones delegadas tarde al backend y enlaces huérfanos: [_PROJECT_DOCS/frontend_ui_audit.md.resolved](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/frontend_ui_audit.md.resolved).

#### Conclusión Frontend

La UI actual es suficientemente madura para operación administrativa y POS básico, pero todavía no cumple el estándar solicitado de experiencia mobile-first para taller ni el de resiliencia offline. Su mejor activo es la especialización vehicular; sus brechas más serias están en offline, work orders, búsqueda por placa y observabilidad de performance.

## 4. Auditoría Backend: Arquitectura y API

### 4.1 Arquitectura Escalable

#### Estado actual

- El backend es un monolito Laravel 12, no una arquitectura de microservicios: [composer.json](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/composer.json), [routes/api.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/routes/api.php).
- La segmentación por dominio existe a nivel de rutas y controladores, con archivos separados para ventas, inventario, compras, vehículos, usuarios, reportes y configuración: [routes/api.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/routes/api.php).
- Esto facilita modularidad interna, pero no desacopla despliegue, escalado, ownership por dominio ni aislamiento de fallos.

#### Gap contra requisito

- Microservicios separados por dominio: no cumplido.
- API Gateway con rate limiting y autenticación centralizada: no cumplido.
- Colas de mensajería para procesamiento asíncrono: parcialmente cumplido solo a nivel de infraestructura Laravel, no como backbone distribuido.

### 4.2 Colas y Asincronía

- El sistema soporta colas con `database` por defecto y opciones `redis`, `sqs`, `beanstalkd`, `failover`: [config/queue.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/queue.php).
- El `composer.json` de desarrollo arranca `queue:listen`, lo que confirma intención de procesamiento asíncrono: [composer.json](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/composer.json).
- No se revisó evidencia fuerte de un patrón intensivo de jobs/event-driven entre dominios. La infraestructura existe, pero no se observa una arquitectura orientada a mensajería como requisito principal.

### 4.3 API y Dominios Disponibles

- Inventario, productos, ventas, compras, clientes, proveedores, reportes, vehículos, usuarios y settings están expuestos dentro de `/api/v1`: [routes/api.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/routes/api.php).
- El controlador de productos incluye filtros por VIN, OEM, atributos, stock y contexto de motor, lo que es sólido para autopartes: [app/Http/Controllers/Api/ProductController.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Controllers/Api/ProductController.php).

#### Observación crítica

La API está bien organizada funcionalmente, pero sigue siendo una API interna de monolito autenticado por sesión. No está preparada todavía como plataforma de integraciones externas de alto volumen.

### 4.4 Integraciones Críticas

#### Estado actual

- No se observan configuraciones de Stripe, Square, QuickBooks o Xero en servicios de terceros: [config/services.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/services.php).
- En frontend, los pagos expuestos son `cash`, `card`, `transfer`, `credit`, pero son métodos internos de negocio, no gateways integrados: [resources/js/services/payments.ts](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-frontend/resources/js/services/payments.ts).
- No se halló evidencia de conectores a catálogos OEM/aftermarket externos en el código revisado.
- No se halló integración con software contable.

#### Gap contra requisito

- APIs de catálogos OEM/aftermarket: no cumplido.
- POS externos: no cumplido.
- Procesadores de pago: no cumplido.
- Software contable: no cumplido.

### 4.5 Seguridad y Compliance

#### Autenticación y autorización

- La API usa `web` + `auth` y se apoya en sesión/cookies: [routes/api.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/routes/api.php), [config/auth.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/auth.php).
- Existe guard `sanctum` en configuración, pero no se observó uso operativo de tokens/API auth en rutas revisadas: [config/auth.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/config/auth.php).
- El middleware de roles está implementado y probado, lo cual es una fortaleza: [app/Http/Middleware/CheckRole.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Middleware/CheckRole.php), [tests/Feature/P0/RoleMiddlewareTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/RoleMiddlewareTest.php).
- El modelo de usuario define roles claros y `password` con cast `hashed`: [app/Models/User.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/User.php).

#### Riesgos

- No hay evidencia de OAuth2.0/JWT en operación.
- No hay evidencia de rate limiting en la API v1 revisada.
- No se puede acreditar que el 100% de endpoints tenga una capa uniforme de autorización más allá del agrupamiento `auth` y middlewares por archivo; esto requiere trazabilidad adicional endpoint por endpoint.
- No hay evidencia de controles específicos para PCI DSS ni de flujos tokenizados de pago.
- No hay evidencia de procesos GDPR/CCPA como export/delete de datos, retención, consentimiento o clasificación de PII.

#### Evaluación frente a requisitos

- PCI DSS: no cumplido.
- GDPR/CCPA: no cumplido.
- OAuth2.0/JWT: no cumplido.

### 4.6 Testing y Calidad Técnica

- Existen tests críticos de rol, enforcement de almacén y transferencias: [tests/Feature/P0/RoleMiddlewareTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/RoleMiddlewareTest.php), [tests/Feature/P0/WarehouseEnforcementTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/WarehouseEnforcementTest.php), [tests/Feature/P0/InventoryTransferFlowTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Feature/P0/InventoryTransferFlowTest.php).
- También hay base para Dusk/browser tests: [tests/Browser/LoginTest.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/tests/Browser/LoginTest.php).
- La cantidad total de tests encontrados es baja para el tamaño funcional del sistema.
- No se encontraron errores activos del editor en frontend ni backend durante esta auditoría.

#### Conclusión Backend

La base backend es sólida para un MVP serio y bien encaminada a modularidad de dominio, pero no responde todavía al target solicitado de backend enterprise distribuido, observable y compliance-ready. El mayor salto pendiente no es de CRUD sino de arquitectura operativa: auth de API, rate limiting, integración, observabilidad y desacoplamiento.

## 5. Priorización por Impacto

### Crítico

- Implementar rate limiting y protección de abuso para API y login.
- Definir arquitectura de autenticación de API para clientes móviles y terceros.
- Introducir observabilidad real para poder medir p95, errores y uptime.
- Diseñar estrategia offline real si el taller debe operar sin internet.

### Alto impacto

- Búsqueda por placa y mejora del journey VIN/modelo/motor desde POS y catálogo.
- Módulo de work orders/taller/service con vínculo cliente-vehículo-historial.
- Integraciones de pagos y contabilidad prioritarias según mercado objetivo.
- Migración productiva de cache/queue a Redis si se espera mayor concurrencia.

### Medio impacto

- Accesibilidad WCAG 2.1 y correcciones UX detectadas en navegación real.
- Hardening de seguridad para PII, retención y auditoría de cumplimiento.
- Catálogo visual más fuerte para imágenes, equivalencias, marcas y alternativas.

### Bajo impacto

- Ajustes cosméticos y refinamiento visual no ligados a flujos críticos.
- Automatizaciones secundarias no bloqueantes para operación inicial.

## 6. Roadmap Priorizado

### Sprint 1-3: Correcciones críticas

- Añadir rate limiting a API y endpoints sensibles.
- Instrumentar métricas con APM y logs estructurados.
- Definir y ejecutar benchmark real de búsquedas críticas, carga inicial y uptime.
- Endurecer autenticación de API con Sanctum u otra estrategia de tokenización.
- Corregir bugs UX ya detectados en POS y formularios según auditoría de navegación: [_PROJECT_DOCS/frontend_ui_audit.md.resolved](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/frontend_ui_audit.md.resolved).

### Sprint 4-6: Mejoras de alto impacto

- Implementar búsqueda por placa y un journey unificado VIN/placa/modelo/motor.
- Diseñar y lanzar módulo de órdenes de trabajo para taller.
- Migrar cache y queue de producción a Redis.
- Implementar conectores iniciales de pago y contabilidad.
- Diseñar primera versión offline-first para POS/taller con sincronización diferida.

### Sprint 7+: Features diferenciales

- Catálogos OEM/aftermarket conectados externamente.
- Historial de vehículo por cliente, garantías y trabajo asociado.
- Recomendaciones inteligentes de partes compatibles y sustitutas.
- Portal B2B/taller móvil y analytics del negocio.

## 7. Matriz de Competencia vs Lightspeed Retail

### Ventajas actuales

- Núcleo vehicular/fitment nativo más específico para autopartes que un retail generalista: [_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/_PROJECT_DOCS/2_LIGHTSPEED_BENCHMARK.md).
- Modelo de búsqueda por VIN, OEM y atributos técnicos integrado en producto: [app/Http/Controllers/Api/ProductController.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Http/Controllers/Api/ProductController.php), [app/Models/Product.php](c:/Users/JUANANTONIOCASTILLAO/Herd/pos-backend/app/Models/Product.php).
- Control granular por almacén/roles ya incorporado.

### Gaps frente a Lightspeed o estándar enterprise esperado

- No hay work orders/service/labor para taller.
- No hay integraciones verticales ni financieras activas.
- No hay offline mode real de navegador/app.
- No hay plataforma de observabilidad ni capa API preparada para ecosistema externo.

### Oportunidad de propuesta de valor única

- Especialización real en marca/modelo/año/motor.
- Compatibilidad cruzada, equivalencias y refacciones reconstruidas.
- Integración con distribuidores/fabricantes locales y sistemas de diagnóstico automotriz.

## 8. Recomendaciones Técnicas

### Stack y arquitectura recomendados

- Mantener Laravel modular a corto plazo, pero separar contratos de dominio y bordes de integración desde ahora.
- No iniciar microservicios por moda; primero consolidar bounded contexts, eventos, colas y observabilidad. Migrar a servicios separados solo cuando haya presión real de escala, equipos o integración.
- Adoptar Redis para cache, colas y posiblemente locks distribuidos en producción.
- Formalizar contratos API con OpenAPI/Swagger.

### Herramientas recomendadas

- Frontend: Lighthouse y New Relic Browser.
- Backend: Datadog APM o Prometheus + Grafana.
- Negocio: Mixpanel o Amplitude.
- Seguridad: OWASP ZAP y Snyk.

### KPIs sugeridos

- p95 de búsqueda de producto/VIN/OEM.
- Tiempo medio de creación de orden/venta en caja.
- Tasa de conversión búsqueda a venta.
- Rotación por categoría y por compatibilidad vehicular.
- Tiempo promedio de reparación/trabajo cuando exista el módulo de taller.
- CSAT/NPS por canal mostrador vs taller.

## 9. Conclusión Final

El sistema está mejor posicionado como POS/autopartes especializado que como plataforma enterprise fully compliant. Lo más valioso ya existe: dominio vehicular, fitment, POS e inventario con estructura coherente. Lo que falta no es cosmético; son capacidades de plataforma y operación avanzada.

Si el objetivo inmediato es producción controlada, la ruta correcta es endurecer API, seguridad, observabilidad y UX crítica antes de pensar en microservicios completos. Si el objetivo es competir directamente con una suite tipo Lightspeed para talleres y retail omnicanal, entonces los siguientes grandes bloques son inevitables: offline real, work orders, integraciones financieras/contables y autenticación/API para ecosistema externo.

## 10. Resultado Frente a Requisitos Solicitados

| Área | Requisito | Estado |
|---|---|---|
| Frontend | Mobile-first para técnicos | Parcial |
| Frontend | Desktop administrativo | Cumplido |
| Frontend | Touch-friendly tablets | Parcial |
| Frontend | Búsqueda VIN/modelo | Cumplido |
| Frontend | Búsqueda por placa | No cumplido |
| Frontend | Workflows de órdenes de trabajo | No cumplido |
| Frontend | Inventario visual con imágenes | Parcial |
| Frontend | Respuesta < 200ms p95 | No demostrable |
| Frontend | Offline | No cumplido |
| Frontend | Lazy loading catálogo | Parcial |
| Backend | Microservicios por dominio | No cumplido |
| Backend | API Gateway + rate limiting | No cumplido |
| Backend | Colas asíncronas | Parcial |
| Backend | Catálogos OEM/aftermarket | No cumplido |
| Backend | POS externos | No cumplido |
| Backend | Stripe/Square | No cumplido |
| Backend | QuickBooks/Xero | No cumplido |
| Backend | PCI DSS | No cumplido |
| Backend | GDPR/CCPA | No cumplido |
| Backend | OAuth2/JWT | No cumplido |

---

---

# Plan de Implementación por Fases — POS Autopartes

## Principios de ejecución

1. **Leer antes de tocar.** Antes de editar cualquier archivo, leerlo completo para entender su estado actual.
2. **Cambio mínimo.** Cada tarea modifica solo los archivos estrictamente necesarios para esa tarea.
3. **Verificación recursiva.** Al final de cada módulo se ejecutan los tests P0 base. Al final de cada fase se ejecuta la suite completa disponible más un smoke manual.
4. **Commit atómico.** Cada módulo completo = 1 commit con mensaje descriptivo. Nunca commitear trabajo a medio completar.
5. **Feature flags.** Toda nueva funcionalidad que afecta flujos existentes se enciende con una variable de entorno/config antes de activarse en producción.
6. **No eliminar.** Ningún campo, endpoint ni relación existente se elimina. Solo se agrega o se depreca con un período de transición documentado.

---

## Comandos de verificación base (ejecutar en `pos-backend/`)

```bash
# Suite P0 completa — debe pasar verde en todo momento
php artisan test tests/Feature/P0 --stop-on-failure

# Verificación estática de rutas
php artisan route:list --columns=method,uri,action,middleware | grep api/v1

# Verificación de modelos y migraciones
php artisan migrate:status

# Verificación de relaciones Eloquent (no hay errores en boot)
php artisan tinker --execute="App\Models\Product::first()?->load(['variants','inventories','attributes','compatibilities']); echo 'OK';"
```

```bash
# Frontend — en pos-frontend/
npm run type-check          # TypeScript sin errores
npm run build               # Build sin errores de Vite
```

---

# FASE 1 — Seguridad Crítica y Estabilidad

**Prioridad:** P0 — Debe completarse antes de cualquier otra fase  
**Duración estimada:** 2–3 días  
**Riesgo de regresión:** Bajo (solo agrega restricciones, no modifica lógica de negocio)

---

## Módulo 1.1 — Rate Limiting en API

**Problema:** Ningún endpoint tiene `throttle`. Un cliente puede hacer miles de requests por segundo contra `/api/v1/products`, `/api/v1/sales-orders`, etc.

### Archivos a modificar

#### `pos-backend/routes/api.php`

Buscar el grupo principal `Route::group(['middleware' => ['web', 'auth']], ...)` y agregar throttle:

```php
// ANTES (esquema actual):
Route::group(['middleware' => ['web', 'auth'], 'prefix' => 'v1'], function () {
		// ...rutas...
});

// DESPUÉS:
Route::group([
		'middleware' => ['web', 'auth', 'throttle:120,1'],   // 120 req/min por usuario autenticado
		'prefix' => 'v1',
], function () {
		// ...rutas sin cambios...
});
```

#### `pos-backend/routes/web.php` o el archivo de rutas de autenticación

Buscar la ruta `POST /login` y aplicar throttle más estricto:

```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
		->middleware(['guest', 'throttle:10,1'])   // 10 intentos/min
		->name('login');
```

### Verificación del módulo 1.1 🔁

```bash
# 1. Tests P0 deben seguir verdes
php artisan test tests/Feature/P0 --stop-on-failure

# 2. Verificar que el throttle aparece en las rutas
php artisan route:list --columns=method,uri,middleware | grep throttle

# 3. Smoke manual: hacer 121 requests rápidos al endpoint de productos y confirmar 429 en el 121
# Usar curl o Postman con runner de 121 iteraciones
```

**No debe romperse:** la autenticación de sesión existente, el flujo de login normal (10 requests en 1 hora no activan el límite).

---

## Módulo 1.2 — Bugs críticos de UX en POS

**Problema:** Tres bugs documentados que bloquean operación del cajero en producción.

### Bug 1.2a — Botón "Checkout" activo con stock = 0

**Archivo:** `pos-frontend/resources/js/pages/POS/` (leer primero el componente del carrito)

Buscar el botón de checkout en el componente POS. La condición actual muy probablemente es:

```typescript
// Condición actual (aproximada):
const canCheckout = computed(() => cart.value.items.length > 0)

// Condición corregida:
const canCheckout = computed(() =>
	cart.value.items.length > 0 &&
	cart.value.items.every(item => item.quantity <= (item.available_stock ?? Infinity))
)
```

La propiedad `available_stock` debe llegar desde la API en el objeto de ítem del carrito. Si no existe, leer `ProductController@show` en el backend para confirmar qué campos devuelve y agregar el campo faltante.

### Bug 1.2b — Botón congelado tras error de API

**Archivo:** `pos-frontend/resources/js/composables/usePOS.ts`

Buscar la función que hace el submit del checkout. Agregar `finally` para siempre liberar el estado de carga:

```typescript
// Patrón actual (aproximado):
async function submitCheckout() {
	isLoading.value = true
	try {
		await api.post('/pos/checkout', payload)
	} catch (e) {
		// manejo de error
	}
	// isLoading puede quedar true si el catch no lo resetea
}

// Patrón corregido:
async function submitCheckout() {
	isLoading.value = true
	try {
		await api.post('/pos/checkout', payload)
	} catch (e) {
		handleError(e)
	} finally {
		isLoading.value = false   // siempre se libera
	}
}
```

### Bug 1.2c — Estado de caja desincronizado al abrir sesión POS

**Archivos:** `pos-frontend/resources/js/composables/usePosSession.ts` y el componente de apertura de caja

Leer `usePosSession.ts` para ver cómo se inicializa. El bug ocurre porque el estado visual se toma de localStorage pero el estado real en DB puede ser diferente. La corrección es:

1. Al montar el componente POS, hacer `GET /api/v1/pos/sessions/active` antes de mostrar la UI.
2. Si el servidor devuelve sesión activa → usarla como fuente de verdad y actualizar localStorage.
3. Si el servidor devuelve 404/null → limpiar localStorage de sesión anterior y mostrar formulario de apertura.

### Verificación del módulo 1.2 🔁

```bash
# TypeScript sin errores nuevos
cd pos-frontend && npm run type-check

# Build sin errores
npm run build
```

Smoke manual en navegador:
- [ ] Agregar producto con stock=1, aumentar cantidad a 2 → botón checkout debe estar deshabilitado o mostrar advertencia
- [ ] Simular error de red durante checkout (Network > Offline en DevTools) → botón no debe quedar congelado
- [ ] Abrir POS en pestaña nueva después de cerrar una sesión → no debe mostrar sesión fantasma

---

## Módulo 1.3 — Form Requests faltantes

**Problema:** 34 controladores pero solo 8 Form Requests. La mayoría de endpoints validan inline o no validan. Esto es un vector de inyección y corrupción de datos.

**Estrategia:** Crear Form Requests para los 5 controladores de mayor riesgo primero.

### Controladores prioritarios (P0)

| Controlador | Request a crear | Campos críticos a validar |
|---|---|---|
| `SalesOrderController@store` | `StoreSalesOrderRequest` | `customer_id` integer, `items` array min:1, `items.*.product_id` exists:products, `items.*.quantity` min:1, `items.*.unit_price` min:0 |
| `PosController@checkout` | `PosCheckoutRequest` | `session_id` exists:pos_sessions, `payments` array, `payments.*.method` in:cash,card,transfer,credit, `payments.*.amount` numeric min:0.01 |
| `UserController@store` | `StoreUserRequest` | `email` email unique:users, `role` in:admin,manager,buyer,seller,cashier,assistant, `password` min:8 confirmed |
| `InventoryController@store` | `StoreInventoryRequest` | `product_id` exists, `warehouse_id` exists, `quantity` integer min:0 |
| `SupplierController@store` | `StoreSupplierRequest` | `name` string max:255, `email` nullable email, `phone` nullable |

### Patrón de Form Request (ejemplo para SalesOrder)

```php
// pos-backend/app/Http/Requests/StoreSalesOrderRequest.php
<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesOrderRequest extends FormRequest
{
		public function authorize(): bool
		{
				return true;  // autorización ya la maneja CheckRole middleware
		}

		public function rules(): array
		{
				return [
						'customer_id'            => ['nullable', 'integer', 'exists:customers,id'],
						'items'                  => ['required', 'array', 'min:1'],
						'items.*.product_id'     => ['required', 'integer', 'exists:products,id'],
						'items.*.variant_id'     => ['nullable', 'integer', 'exists:product_variants,id'],
						'items.*.quantity'       => ['required', 'integer', 'min:1'],
						'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
						'items.*.discount'       => ['nullable', 'numeric', 'between:0,100'],
						'warehouse_id'           => ['required', 'integer', 'exists:warehouses,id'],
						'notes'                  => ['nullable', 'string', 'max:1000'],
				];
		}
}
```

En el controlador, reemplazar `Request $request` por `StoreSalesOrderRequest $request` — Laravel inyecta y valida automáticamente antes de entrar al método.

### Verificación del módulo 1.3 🔁

```bash
php artisan test tests/Feature/P0 --stop-on-failure

# Verificar que los requests se pueden resolver sin errores de binding
php artisan tinker --execute="app(App\Http\Requests\StoreSalesOrderRequest::class); echo 'OK';"
```

---

## Verificación de Fase 1 (recursiva completa) 🔁

```bash
# 1. Suite P0 verde
php artisan test tests/Feature/P0

# 2. No hay rutas sin throttle en la API
php artisan route:list | grep "api/v1" | grep -v "throttle"
# Si devuelve rutas → revisar que el middleware group las cubre todas

# 3. TypeScript compila
cd ../pos-frontend && npm run type-check && npm run build

# 4. Crear test de regresión para los bugs fixed
# tests/Feature/P1/PosCheckoutTest.php (ver plantilla en §Plantillas de test)
```

---

# FASE 2 — Cobertura de Tests y Observabilidad

**Prioridad:** P1 — Ejecutar en paralelo o justo después de Fase 1  
**Duración estimada:** 3–4 días  
**Riesgo de regresión:** Ninguno (solo agrega tests y logging)

---

## Módulo 2.1 — Ampliar suite de tests (P1)

**Estado actual:** 3 tests en `tests/Feature/P0/`. Cobertura efectiva: < 5%.  
**Meta Fase 2:** Llegar a tests para los 10 flujos de negocio más críticos.

### Tests a crear en `tests/Feature/P1/`

```
tests/Feature/P1/
├── SalesOrderFlowTest.php       — crear orden, agregar ítems, confirmar, generar PDF
├── PosSessionFlowTest.php       — abrir caja, procesar venta, cerrar caja
├── ProductSearchTest.php        — buscar por nombre, SKU, VIN, OEM, filtros de vehículo
├── InventoryMovementTest.php    — entrada, salida, transferencia entre almacenes
├── UserRoleAccessTest.php       — cashier no puede acceder a /api/v1/users, admin sí
├── WarehouseIsolationTest.php   — usuario de almacén A no ve inventario de almacén B
├── PurchaseOrderFlowTest.php    — crear OC, recibir parcial, recibir total
├── RateLimitTest.php            — 121 requests rápidos devuelven 429
├── FormValidationTest.php       — payloads inválidos devuelven 422 con mensajes
└── PricingFormulaTest.php       — fórmulas de precio calculan valores esperados
```

### Plantilla base (Pest v4)

```php
<?php
// tests/Feature/P1/SalesOrderFlowTest.php

use App\Models\User;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Customer;

beforeEach(function () {
		$this->artisan('migrate:fresh', ['--seed' => false]);
		$this->warehouse = Warehouse::factory()->create();
		$this->user = User::factory()->create(['role' => 'seller', 'warehouse_id' => $this->warehouse->id]);
		$this->actingAs($this->user);
});

it('creates a sales order with valid items', function () {
		$product = Product::factory()->create();
		// crear inventario mínimo para el producto en el almacén

		$response = $this->postJson('/api/v1/sales-orders', [
				'warehouse_id' => $this->warehouse->id,
				'items' => [
						['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100.00],
				],
		]);

		$response->assertStatus(201)
						 ->assertJsonPath('data.status', 'pending');
});

it('rejects order with non-existent product', function () {
		$response = $this->postJson('/api/v1/sales-orders', [
				'warehouse_id' => $this->warehouse->id,
				'items' => [
						['product_id' => 999999, 'quantity' => 1, 'unit_price' => 100.00],
				],
		]);

		$response->assertStatus(422);
});
```

### Verificación del módulo 2.1 🔁

```bash
php artisan test tests/Feature/P0 tests/Feature/P1 --stop-on-failure
```

---

## Módulo 2.2 — Logging estructurado y APM básico

**Problema:** Los logs actuales son texto plano. Imposible correlacionar requests con errores en producción.

### Cambios en `pos-backend/config/logging.php`

Agregar canal JSON para producción:

```php
'channels' => [
		// ... canales existentes sin tocar ...

		'json' => [
				'driver'    => 'daily',
				'path'      => storage_path('logs/app.log'),
				'level'     => env('LOG_LEVEL', 'info'),
				'days'      => 14,
				'formatter' => Monolog\Formatter\JsonFormatter::class,
		],
],
```

En `.env.production`:
```
LOG_CHANNEL=json
LOG_LEVEL=warning
```

### Middleware de timing de requests

Crear archivo `pos-backend/app/Http/Middleware/RequestTimingMiddleware.php`:

```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestTimingMiddleware
{
		public function handle(Request $request, Closure $next)
		{
				$start = microtime(true);
				$response = $next($request);
				$duration = round((microtime(true) - $start) * 1000, 2);

				if ($duration > 500) {  // solo loguear requests lentos (> 500ms)
						Log::warning('slow_request', [
								'method'   => $request->method(),
								'uri'      => $request->path(),
								'duration' => $duration,
								'user_id'  => $request->user()?->id,
						]);
				}

				$response->headers->set('X-Response-Time', $duration . 'ms');
				return $response;
		}
}
```

Registrar en `pos-backend/bootstrap/app.php` dentro del grupo de middleware de API:

```php
->withMiddleware(function (Middleware $middleware) {
		$middleware->appendToGroup('api', \App\Http\Middleware\RequestTimingMiddleware::class);
		// resto sin cambios
})
```

### Verificación del módulo 2.2 🔁

```bash
php artisan test tests/Feature/P0 --stop-on-failure

# Verificar que el canal de log existe y es válido
php artisan tinker --execute="Log::channel('json')->info('test'); echo 'OK';"

# Verificar que el middleware se registró
php artisan route:list | grep "RequestTiming"
```

---

## Módulo 2.3 — Migrar Cache y Queue a Redis (opcional por entorno)

**Estado:** `CACHE_STORE=database` y `QUEUE_CONNECTION=database`. Funciona pero escala mal.

**Estrategia:** Hacer la migración controlada por variable de entorno para no afectar desarrollo local.

### Cambios en `.env`

```bash
# Solo cambiar en .env.production, dejar .env local intacto
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

Los archivos `config/cache.php` y `config/queue.php` ya tienen la configuración de Redis — solo se activa con la variable de entorno.

### Verificación del módulo 2.3 🔁

```bash
# Solo si Redis está disponible en el entorno de test
php artisan tinker --execute="cache()->put('test', 1, 10); echo cache()->get('test');"
# Debe imprimir: 1

# Verificar que los jobs de queue se procesan
php artisan queue:work --once

php artisan test tests/Feature/P0 --stop-on-failure
```

---

## Verificación de Fase 2 (recursiva completa) 🔁

```bash
# Suite P0 + P1
php artisan test tests/Feature/P0 tests/Feature/P1

# Build frontend sin errores
cd ../pos-frontend && npm run type-check && npm run build

# Verificar que artisan no lanza warnings críticos al arrancar
php artisan config:cache 2>&1 | grep -i error
php artisan route:cache 2>&1 | grep -i error
```

---

# FASE 3 — Búsqueda Avanzada de Vehículos (Placa + VIN unificado)

**Prioridad:** P1 — Alta según auditoría de dominio  
**Duración estimada:** 3–4 días  
**Riesgo de regresión:** Medio (modifica `ProductController` y `VehicleVinController`)

---

## Módulo 3.1 — Soporte de placa vehicular en base de datos

**Estado actual:** `vehicle_vins` tabla existe pero no tiene campo `license_plate`.

### Crear migración

```bash
php artisan make:migration add_license_plate_to_vehicle_vins_table
```

Contenido de la migración:

```php
public function up(): void
{
		Schema::table('vehicle_vins', function (Blueprint $table) {
				$table->string('license_plate', 20)->nullable()->after('vin');
				$table->index('license_plate');
		});
}

public function down(): void
{
		Schema::table('vehicle_vins', function (Blueprint $table) {
				$table->dropIndex(['license_plate']);
				$table->dropColumn('license_plate');
		});
}
```

```bash
php artisan migrate
```

### Actualizar modelo `VehicleVin`

**Archivo:** `pos-backend/app/Models/VehicleVin.php`

Agregar `license_plate` al `$fillable`:

```php
protected $fillable = [
		// campos existentes sin tocar +
		'license_plate',
];
```

### Verificación del módulo 3.1 🔁

```bash
php artisan migrate:status | grep license_plate
# Debe aparecer como "Ran"

php artisan test tests/Feature/P0 --stop-on-failure

# Verificar que VehicleVin acepta el nuevo campo
php artisan tinker --execute="
\$v = new App\Models\VehicleVin(['vin' => 'TEST123', 'license_plate' => 'ABC-001']);
echo in_array('license_plate', \$v->getFillable()) ? 'OK' : 'FALTA EN FILLABLE';
"
```

---

## Módulo 3.2 — Búsqueda por placa en backend

### Actualizar `VehicleVinController`

**Archivo:** `pos-backend/app/Http/Controllers/Api/VehicleVinController.php`

Leer el archivo primero. Luego en el método `index` (o `search`), agregar filtro por placa:

```php
public function index(Request $request)
{
		$query = VehicleVin::with(['vehicleEngine.vehicleModel.vehicleMake']);

		if ($request->filled('vin')) {
				$query->where('vin', 'like', '%' . $request->vin . '%');
		}

		// NUEVO: filtro por placa
		if ($request->filled('license_plate')) {
				$query->where('license_plate', 'like', '%' . $request->license_plate . '%');
		}

		// resto del método sin cambios
}
```

### Actualizar `ProductController` — filtro unificado por vehículo

**Archivo:** `pos-backend/app/Http/Controllers/Api/ProductController.php`

Leer el archivo primero (especialmente el scope `intelligentSearch` y los filtros de vehículo). Agregar parámetro `license_plate` a los filtros existentes sin tocar los VIN/OEM:

```php
// En el método index(), junto a los filtros de vehículo existentes:

if ($request->filled('license_plate')) {
		// Resolver la placa a un engine_id a través de VehicleVin
		$engineIds = VehicleVin::where('license_plate', 'like', '%' . $request->license_plate . '%')
				->pluck('vehicle_engine_id');

		if ($engineIds->isNotEmpty()) {
				$query->whereHas('compatibilities', fn($q) =>
						$q->whereIn('vehicle_engine_id', $engineIds)
				);
		}
}
```

### Verificación del módulo 3.2 🔁

```bash
php artisan test tests/Feature/P0 --stop-on-failure

# Smoke: buscar productos por placa ficticia devuelve array (puede estar vacío)
php artisan tinker --execute="
\$res = app(App\Http\Controllers\Api\ProductController::class)
		->index(new Illuminate\Http\Request(['license_plate' => 'TEST']));
echo 'HTTP ' . \$res->getStatusCode();
"
```

---

## Módulo 3.3 — VehicleSelector unificado en frontend

**Archivo principal:** `pos-frontend/resources/js/components/VehicleSelector.vue`  
**Composable:** `pos-frontend/resources/js/composables/useVehicleVins.ts`

Leer ambos archivos antes de modificar.

### Cambios en `useVehicleVins.ts`

Agregar función de búsqueda por placa al composable existente:

```typescript
// Agregar después de las funciones existentes, no reemplazar nada:

const searchByPlate = async (licensePlate: string) => {
	if (!licensePlate.trim()) return []
	const { data } = await apiClient.get('/vehicle-vins', {
		params: { license_plate: licensePlate }
	})
	return data.data ?? []
}

// Exportar junto a las funciones existentes:
return {
	// ...exports existentes...,
	searchByPlate,
}
```

### Cambios en `VehicleSelector.vue`

Agregar tab/campo "Buscar por placa" que usa `searchByPlate`. El selector cascading existente (Make → Model → Engine) no se toca — solo se añade como entrada alternativa.

```vue
<!-- Agregar antes del selector cascading existente -->
<div class="flex gap-2 mb-4">
	<Input
		v-model="plateInput"
		placeholder="Placa (ej. ABC-001)"
		@keyup.enter="searchByPlate"
	/>
	<Button @click="onSearchByPlate" :disabled="!plateInput.trim()">
		Buscar por placa
	</Button>
</div>

<!-- Mostrar resultados debajo, sin ocultar el selector existente -->
<ul v-if="plateResults.length > 0" class="border rounded p-2 text-sm">
	<li
		v-for="vin in plateResults"
		:key="vin.id"
		class="cursor-pointer hover:bg-muted px-2 py-1"
		@click="selectVin(vin)"
	>
		{{ vin.license_plate }} — {{ vin.vehicleEngine?.vehicleModel?.vehicleMake?.name }}
		{{ vin.vehicleEngine?.vehicleModel?.name }} {{ vin.vehicleEngine?.name }}
	</li>
</ul>
```

### Verificación del módulo 3.3 🔁

```bash
cd pos-frontend && npm run type-check && npm run build
```

Smoke manual:
- [ ] El selector de vehículos original (Make/Model/Engine) sigue funcionando exactamente igual
- [ ] Búsqueda por placa con texto vacío no dispara petición
- [ ] Búsqueda por placa con texto válido muestra resultados o mensaje "sin resultados"

---

## Verificación de Fase 3 (recursiva completa) 🔁

```bash
# Backend
php artisan test tests/Feature/P0 tests/Feature/P1 --stop-on-failure

# Verificar que la migración nueva no afecta seeder/factory existentes
php artisan migrate:fresh --seed 2>&1 | tail -5

# Frontend
cd ../pos-frontend && npm run type-check && npm run build
```

Crear test de regresión antes de merging:

```bash
# tests/Feature/P1/LicensePlateSearchTest.php
# - puede buscar VINs por placa → devuelve array
# - puede buscar productos pasando license_plate en query → no lanza 500
# - VINs sin placa siguen siendo accesibles por vin param
```

---

# FASE 4 — Módulo de Órdenes de Trabajo (Work Orders)

**Prioridad:** P1 — Requerimiento de dominio crítico para talleres  
**Duración estimada:** 6–8 días  
**Riesgo de regresión:** Medio-Alto (nueva entidad central, relacionada con ventas y clientes)

> **Regla de aislamiento:** El módulo WorkOrder es completamente nuevo. NO modifica `SalesOrder`, `Customer`, ni `Product`. Se conecta a ellos solo a través de foreign keys. Si en el futuro se necesita generar una `SalesOrder` desde una `WorkOrder`, se hace con un método `createSalesOrder()` en el servicio, no modificando los flujos existentes.

---

## Módulo 4.1 — Dominio backend: Migraciones y Modelos

### Migración `work_orders`

```bash
php artisan make:migration create_work_orders_table
```

```php
Schema::create('work_orders', function (Blueprint $table) {
		$table->id();
		$table->foreignId('customer_id')->constrained()->cascadeOnDelete();
		$table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
		$table->foreignId('user_id')->comment('Técnico asignado')->constrained()->nullOnDelete();
		$table->foreignId('vehicle_vin_id')->nullable()->constrained('vehicle_vins')->nullOnDelete();
		$table->string('status', 30)->default('pending');
		// pending | diagnosed | in_progress | waiting_parts | completed | delivered | cancelled
		$table->text('description')->nullable();
		$table->text('diagnosis')->nullable();
		$table->decimal('labor_cost', 12, 2)->default(0);
		$table->timestamp('estimated_completion_at')->nullable();
		$table->timestamp('completed_at')->nullable();
		$table->timestamps();
		$table->softDeletes();
		$table->index(['customer_id', 'status']);
		$table->index(['warehouse_id', 'status']);
});
```

### Migración `work_order_items`

```bash
php artisan make:migration create_work_order_items_table
```

```php
Schema::create('work_order_items', function (Blueprint $table) {
		$table->id();
		$table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
		$table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
		$table->string('description')->nullable()->comment('Para ítems de mano de obra sin producto');
		$table->decimal('quantity', 10, 2)->default(1);
		$table->decimal('unit_price', 12, 2)->default(0);
		$table->decimal('discount', 5, 2)->default(0);
		$table->timestamps();
});
```

### Modelos Eloquent

**`pos-backend/app/Models/WorkOrder.php`**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
		use SoftDeletes;

		protected $fillable = [
				'customer_id', 'warehouse_id', 'user_id', 'vehicle_vin_id',
				'status', 'description', 'diagnosis', 'labor_cost',
				'estimated_completion_at', 'completed_at',
		];

		protected $casts = [
				'labor_cost'              => 'decimal:2',
				'estimated_completion_at' => 'datetime',
				'completed_at'            => 'datetime',
		];

		public function customer(): BelongsTo  { return $this->belongsTo(Customer::class); }
		public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
		public function technician(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
		public function vehicleVin(): BelongsTo { return $this->belongsTo(VehicleVin::class); }
		public function items(): HasMany        { return $this->hasMany(WorkOrderItem::class); }

		public function totalPartsAmount(): float
		{
				return $this->items->sum(fn($i) =>
						$i->unit_price * $i->quantity * (1 - $i->discount / 100)
				);
		}

		public function totalAmount(): float
		{
				return $this->totalPartsAmount() + (float) $this->labor_cost;
		}
}
```

### Verificación del módulo 4.1 🔁

```bash
php artisan migrate
php artisan migrate:status | tail -5

# Verificar que los modelos existentes (Customer, User, Warehouse) no tienen errores
php artisan tinker --execute="
App\Models\Customer::first();
App\Models\Warehouse::first();
App\Models\User::first();
echo 'Modelos existentes OK';
"

php artisan test tests/Feature/P0 --stop-on-failure
```

---

## Módulo 4.2 — Servicio y Controlador WorkOrder

### `pos-backend/app/Services/WorkOrderService.php`

```php
<?php
namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkOrderService
{
		public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
		{
				$query = WorkOrder::with(['customer', 'technician', 'vehicleVin', 'items'])
						->orderBy('created_at', 'desc');

				if (!empty($filters['warehouse_id'])) {
						$query->where('warehouse_id', $filters['warehouse_id']);
				}
				if (!empty($filters['status'])) {
						$query->where('status', $filters['status']);
				}
				if (!empty($filters['customer_id'])) {
						$query->where('customer_id', $filters['customer_id']);
				}

				return $query->paginate($perPage);
		}

		public function create(array $data): WorkOrder
		{
				$items = $data['items'] ?? [];
				unset($data['items']);

				$workOrder = WorkOrder::create($data);

				foreach ($items as $item) {
						$workOrder->items()->create($item);
				}

				return $workOrder->load(['customer', 'items']);
		}

		public function updateStatus(WorkOrder $workOrder, string $status): WorkOrder
		{
				$workOrder->update(['status' => $status]);
				if ($status === 'completed') {
						$workOrder->update(['completed_at' => now()]);
				}
				return $workOrder->fresh();
		}
}
```

### `pos-backend/app/Http/Controllers/Api/WorkOrderController.php`

```php
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WorkOrderController extends Controller
{
		public function __construct(private WorkOrderService $service) {}

		public function index(Request $request): JsonResponse
		{
				$filters = $request->only(['warehouse_id', 'status', 'customer_id']);
				return response()->json($this->service->list($filters));
		}

		public function store(Request $request): JsonResponse
		{
				$validated = $request->validate([
						'customer_id'              => ['required', 'integer', 'exists:customers,id'],
						'warehouse_id'             => ['required', 'integer', 'exists:warehouses,id'],
						'vehicle_vin_id'           => ['nullable', 'integer', 'exists:vehicle_vins,id'],
						'description'              => ['nullable', 'string', 'max:2000'],
						'estimated_completion_at'  => ['nullable', 'date'],
						'items'                    => ['nullable', 'array'],
						'items.*.product_id'       => ['nullable', 'integer', 'exists:products,id'],
						'items.*.description'      => ['nullable', 'string'],
						'items.*.quantity'         => ['required', 'numeric', 'min:0.01'],
						'items.*.unit_price'       => ['required', 'numeric', 'min:0'],
				]);

				return response()->json($this->service->create($validated), 201);
		}

		public function show(WorkOrder $workOrder): JsonResponse
		{
				return response()->json($workOrder->load(['customer', 'technician', 'vehicleVin', 'items.product']));
		}

		public function updateStatus(Request $request, WorkOrder $workOrder): JsonResponse
		{
				$validated = $request->validate([
						'status' => ['required', 'in:pending,diagnosed,in_progress,waiting_parts,completed,delivered,cancelled'],
				]);

				return response()->json($this->service->updateStatus($workOrder, $validated['status']));
		}

		public function destroy(WorkOrder $workOrder): JsonResponse
		{
				$workOrder->delete();
				return response()->json(null, 204);
		}
}
```

### Registrar rutas en `pos-backend/routes/api.php`

Dentro del grupo autenticado existente, agregar:

```php
Route::apiResource('work-orders', WorkOrderController::class);
Route::patch('work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus']);
```

### Verificación del módulo 4.2 🔁

```bash
php artisan route:list | grep work-order
# Debe mostrar 5 rutas (index, store, show, update, destroy) + 1 de status

php artisan test tests/Feature/P0 --stop-on-failure

# Smoke rápido
php artisan tinker --execute="
\$c = App\Models\Customer::first();
\$w = App\Models\Warehouse::first();
if (\$c && \$w) {
		\$wo = App\Models\WorkOrder::create(['customer_id'=>\$c->id,'warehouse_id'=>\$w->id,'status'=>'pending']);
		echo 'WorkOrder creado ID: ' . \$wo->id;
		\$wo->delete();
} else {
		echo 'Necesita datos semilla: customer y warehouse';
}
"
```

---

## Módulo 4.3 — Frontend: página y composable WorkOrders

### Composable `pos-frontend/resources/js/composables/useWorkOrders.ts`

```typescript
import { ref } from 'vue'
import apiClient from '@/services/api/client'

export interface WorkOrder {
	id: number
	customer_id: number
	warehouse_id: number
	vehicle_vin_id: number | null
	status: 'pending' | 'diagnosed' | 'in_progress' | 'waiting_parts' | 'completed' | 'delivered' | 'cancelled'
	description: string | null
	diagnosis: string | null
	labor_cost: string
	estimated_completion_at: string | null
	completed_at: string | null
	customer?: Record<string, unknown>
	items?: WorkOrderItem[]
}

export interface WorkOrderItem {
	id: number
	work_order_id: number
	product_id: number | null
	description: string | null
	quantity: number
	unit_price: string
	discount: number
}

export function useWorkOrders() {
	const workOrders = ref<WorkOrder[]>([])
	const currentWorkOrder = ref<WorkOrder | null>(null)
	const isLoading = ref(false)
	const error = ref<string | null>(null)

	const fetchWorkOrders = async (filters: Record<string, string | number> = {}) => {
		isLoading.value = true
		error.value = null
		try {
			const { data } = await apiClient.get('/work-orders', { params: filters })
			workOrders.value = data.data
		} catch (e: unknown) {
			error.value = (e as Error).message
		} finally {
			isLoading.value = false
		}
	}

	const createWorkOrder = async (payload: Partial<WorkOrder> & { items?: Partial<WorkOrderItem>[] }) => {
		isLoading.value = true
		error.value = null
		try {
			const { data } = await apiClient.post('/work-orders', payload)
			workOrders.value.unshift(data)
			return data as WorkOrder
		} catch (e: unknown) {
			error.value = (e as Error).message
			throw e
		} finally {
			isLoading.value = false
		}
	}

	const updateStatus = async (id: number, status: WorkOrder['status']) => {
		const { data } = await apiClient.patch(`/work-orders/${id}/status`, { status })
		const idx = workOrders.value.findIndex(wo => wo.id === id)
		if (idx !== -1) workOrders.value[idx] = data
		return data as WorkOrder
	}

	return {
		workOrders,
		currentWorkOrder,
		isLoading,
		error,
		fetchWorkOrders,
		createWorkOrder,
		updateStatus,
	}
}
```

### Páginas a crear

```
pos-frontend/resources/js/pages/WorkOrders/
├── Index.vue       — lista paginada con filtros de estado, acciones rápidas de cambio de estado
└── Show.vue        — detalle completo, lista de ítems, historial de estado, botón "generar venta"
```

La estructura de estas páginas debe seguir exactamente el mismo patrón visual de `pages/SalesOrders/Index.vue` — leer ese archivo antes de crear los de WorkOrders.

### Registrar rutas en Inertia

**Archivo:** `pos-frontend/routes/web.php`

```php
Route::get('/work-orders', fn() => inertia('WorkOrders/Index'))->name('work-orders.index');
Route::get('/work-orders/{id}', fn() => inertia('WorkOrders/Show'))->name('work-orders.show');
```

### Verificación del módulo 4.3 🔁

```bash
cd pos-frontend && npm run type-check && npm run build
```

Smoke manual:
- [ ] `/work-orders` carga sin error de consola JS
- [ ] La página de SalesOrders existente sigue funcionando
- [ ] El sidebar muestra el enlace de Work Orders (verificar que se agregó al nav)

---

## Verificación de Fase 4 (recursiva completa) 🔁

```bash
# Backend completo
php artisan test tests/Feature/P0 tests/Feature/P1 --stop-on-failure

# Verificar que modelos relacionados no tienen cambios involuntarios
php artisan tinker --execute="
echo App\Models\Customer::first()?->salesOrders()->count() . ' órdenes cliente 1\n';
echo App\Models\Product::first()?->variants()->count() . ' variantes producto 1\n';
echo 'Modelos core intactos';
"

# Frontend
cd ../pos-frontend && npm run type-check && npm run build
```

Crear test de regresión antes de merging:

```bash
# tests/Feature/P2/WorkOrderTest.php
# - crear work order → 201
# - cambiar status → OK
# - customer sin work orders no aparece en lista
# - SalesOrder sigue creándose independientemente
```

---

# FASE 5 — PWA y Modo Offline básico para POS

**Prioridad:** P2  
**Duración estimada:** 4–5 días  
**Riesgo de regresión:** Medio (modifica vite.config.ts y usePOS.ts — archivos críticos)

> **Punto de no retorno:** Los cambios en `usePOS.ts` son los más delicados de todo el plan. Existe lógica de migración de formato legacy de localStorage (`pos_sessions_user_{id}`). Esa lógica DEBE mantenerse intacta. Solo se agrega IndexedDB como almacenamiento secundario; localStorage no se elimina hasta confirmar estabilidad.

---

## Módulo 5.1 — Web App Manifest y Service Worker básico

### Instalar vite-plugin-pwa

```bash
cd pos-frontend
npm install -D vite-plugin-pwa
```

### Actualizar `pos-frontend/vite.config.ts`

Leer el archivo primero. Agregar el plugin sin tocar la configuración existente de Inertia/Laravel:

```typescript
import { VitePWA } from 'vite-plugin-pwa'

// Dentro de plugins: [...existentes, VitePWA({...})]
VitePWA({
	registerType: 'prompt',  // NO autoUpdate para no sorprender al usuario en el POS
	includeAssets: ['favicon.ico', 'images/**'],
	manifest: {
		name: 'POS Autopartes',
		short_name: 'POS',
		theme_color: '#1a1a2e',
		icons: [
			{ src: '/images/icon-192.png', sizes: '192x192', type: 'image/png' },
			{ src: '/images/icon-512.png', sizes: '512x512', type: 'image/png' },
		],
	},
	workbox: {
		// Solo cachear assets estáticos, NO requests de API
		globPatterns: ['**/*.{js,css,woff2}'],
		runtimeCaching: [
			{
				urlPattern: /^\/api\/v1\//,
				handler: 'NetworkFirst',        // siempre intentar red primero
				options: {
					cacheName: 'api-cache',
					networkTimeoutSeconds: 5,     // si no responde en 5s, usar cache
					expiration: { maxEntries: 100, maxAgeSeconds: 3600 },
				},
			},
		],
	},
}),
```

### Agregar iconos

Crear dos archivos de imagen en `pos-frontend/public/images/`:
- `icon-192.png` (192×192px)
- `icon-512.png` (512×512px)

Pueden ser el logo existente redimensionado.

### Verificación del módulo 5.1 🔁

```bash
cd pos-frontend && npm run build
# Debe generar sw.js y manifest.webmanifest en public/build/

ls public/build/ | grep -E "sw|manifest"

npm run type-check
```

---

## Módulo 5.2 — IndexedDB para carrito offline

**Archivo:** `pos-frontend/resources/js/composables/usePOS.ts`

Este archivo debe leerse completo antes de tocar. El plan de cambio es:

1. Mantener toda la lógica `localStorage` existente intacta
2. Agregar IndexedDB como almacenamiento paralelo (no reemplazante)
3. En la escritura del carrito: guardar en ambos (localStorage + IndexedDB)
4. En la lectura del carrito: intentar IndexedDB primero; si falla, usar localStorage

```typescript
// Agregar al inicio del composable, después de los imports existentes:

async function openPosDB(): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open('pos_offline_db', 1)
		request.onupgradeneeded = (e) => {
			const db = (e.target as IDBOpenDBRequest).result
			if (!db.objectStoreNames.contains('sessions')) {
				db.createObjectStore('sessions', { keyPath: 'key' })
			}
		}
		request.onsuccess = () => resolve(request.result)
		request.onerror = () => reject(request.error)
	})
}

async function saveToIndexedDB(key: string, value: unknown): Promise<void> {
	try {
		const db = await openPosDB()
		const tx = db.transaction('sessions', 'readwrite')
		tx.objectStore('sessions').put({ key, value })
	} catch {
		// IndexedDB no disponible — localStorage sigue activo como fallback
	}
}
```

La función de guardado existente de carrito entonces llama a `saveToIndexedDB` en paralelo después de escribir localStorage — sin `await` para no bloquear la UI.

### Verificación del módulo 5.2 🔁

```bash
cd pos-frontend && npm run type-check && npm run build
```

Smoke manual en navegador:
- [ ] Abrir POS, agregar ítems al carrito
- [ ] Abrir DevTools → Application → IndexedDB → pos_offline_db → sessions: debe tener un registro
- [ ] Abrir la misma página en nueva pestaña → carrito persiste (desde localStorage como antes)
- [ ] Simular `indexedDB = undefined` en consola e intentar modificar el carrito → no debe lanzar error

---

## Verificación de Fase 5 (recursiva completa) 🔁

```bash
cd pos-frontend && npm run type-check && npm run build

# Verificar que el SW no intercepta rutas de autenticación
# (el workbox config excluye /login, /api/* con NetworkFirst)
```

Prueba de regresión crítica:
- [ ] El carrito multiusuario de localStorage (`pos_sessions_user_{id}`) sigue separando sesiones por usuario
- [ ] La migración de formato legacy (documentada en el composable) sigue ejecutándose

---

# FASE 6 — Integraciones de Pago (Stripe)

**Prioridad:** P2  
**Duración estimada:** 4–5 días  
**Riesgo de regresión:** Bajo-Medio (nuevo dominio, no modifica flujo de pagos existente)

> **Advertencia de seguridad:** Las claves de Stripe NUNCA deben estar en el código. Solo en variables de entorno. El webhook de Stripe debe validad la firma `Stripe-Signature` en cada request.

---

## Módulo 6.1 — Backend Stripe

```bash
cd pos-backend
composer require stripe/stripe-php
```

### Variable de entorno

```bash
# En .env (nunca en código):
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### Config en `pos-backend/config/services.php`

Agregar al final del array existente:

```php
'stripe' => [
		'key'            => env('STRIPE_KEY'),
		'secret'         => env('STRIPE_SECRET'),
		'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```

### Controlador de webhook

**`pos-backend/app/Http/Controllers/Api/StripeWebhookController.php`**

```php
<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrderPayment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
		public function handle(Request $request): JsonResponse
		{
				$payload   = $request->getContent();
				$sigHeader = $request->header('Stripe-Signature');
				$secret    = config('services.stripe.webhook_secret');

				try {
						$event = Webhook::constructEvent($payload, $sigHeader, $secret);
				} catch (SignatureVerificationException $e) {
						return response()->json(['error' => 'Invalid signature'], 400);
				}

				match ($event->type) {
						'payment_intent.succeeded' => $this->handlePaymentSucceeded($event->data->object),
						'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
						default => null,
				};

				return response()->json(['received' => true]);
		}

		private function handlePaymentSucceeded(object $paymentIntent): void
		{
				SalesOrderPayment::where('stripe_payment_intent_id', $paymentIntent->id)
						->update(['status' => 'paid', 'paid_at' => now()]);
		}

		private function handlePaymentFailed(object $paymentIntent): void
		{
				SalesOrderPayment::where('stripe_payment_intent_id', $paymentIntent->id)
						->update(['status' => 'failed']);
		}
}
```

### Ruta del webhook (FUERA del grupo autenticado)

```php
// En routes/api.php, ANTES del grupo ['web', 'auth']:
Route::post('v1/webhooks/stripe', [StripeWebhookController::class, 'handle'])
		->name('webhooks.stripe');
		// Sin middleware 'auth' — Stripe no tiene sesión de usuario
		// La seguridad la maneja la firma del webhook
```

### Migración para `stripe_payment_intent_id`

```bash
php artisan make:migration add_stripe_fields_to_sales_order_payments_table
```

```php
Schema::table('sales_order_payments', function (Blueprint $table) {
		$table->string('stripe_payment_intent_id')->nullable()->after('method');
		$table->index('stripe_payment_intent_id');
});
```

### Verificación del módulo 6.1 🔁

```bash
php artisan migrate
php artisan test tests/Feature/P0 tests/Feature/P1 --stop-on-failure

# Verificar que la ruta del webhook no requiere auth
php artisan route:list | grep webhook
# must show: POST  api/v1/webhooks/stripe   NO 'auth' middleware

# Verificar que SalesOrderPayment existente no está roto
php artisan tinker --execute="
echo App\Models\SalesOrderPayment::count() . ' pagos existentes — intactos';
"
```

---

## Verificación de Fase 6 (recursiva completa) 🔁

```bash
php artisan test tests/Feature/P0 tests/Feature/P1 --stop-on-failure

# Verificar que los métodos de pago existentes (cash, card, transfer, credit) siguen funcionando
php artisan tinker --execute="
\$order = App\Models\SalesOrder::with('payments')->first();
echo \$order?->payments->pluck('method')->implode(', ') ?: 'sin pagos';
"
```

---

# FASE 7 — Tokens API / Sanctum (para clientes externos o app móvil futura)

**Prioridad:** P2 — Solo si hay integración externa o app móvil planificada  
**Duración estimada:** 1–2 días  
**Riesgo de regresión:** Bajo (agrega opción, no modifica autenticación de sesión existente)

## Módulo 7.1 — Activar Sanctum API Tokens

Sanctum ya está instalado. Solo se activa:

### `pos-backend/config/auth.php`

```php
'guards' => [
		'web' => [
				'driver'   => 'session',
				'provider' => 'users',
		],
		'sanctum' => [    // NUEVO — no elimina el guard 'web' existente
				'driver'   => 'sanctum',
				'provider' => 'users',
		],
],
```

### Modelo `User`

Verificar que ya tiene `use HasApiTokens` de Sanctum. Si no:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
		use HasApiTokens, // agregar
				// ...traits existentes sin eliminar
}
```

### Ruta de generación de token

```php
// En routes/api.php, dentro del grupo autenticado:
Route::post('v1/tokens/create', function (Request $request) {
		$request->validate(['token_name' => 'required|string|max:100']);
		$token = $request->user()->createToken($request->token_name);
		return response()->json(['token' => $token->plainTextToken], 201);
})->middleware(['web', 'auth']);
```

### Verificación del módulo 7.1 🔁

```bash
php artisan test tests/Feature/P0 --stop-on-failure

# La autenticación web existente debe ser completamente transparente al cambio
php artisan tinker --execute="
echo class_uses_recursive(App\Models\User::class)['Laravel\Sanctum\HasApiTokens'] ? 'HasApiTokens OK' : 'Falta HasApiTokens';
"
```

---

# FASE 8 — Integraciones Contables (QuickBooks / Xero)

**Prioridad:** P3 — Planificar pero no ejecutar hasta tener Fase 1-4 estables en producción  
**Duración estimada:** 6–8 días  
**Riesgo de regresión:** Medio (afecta `SalesOrder` y `PurchaseOrder` con eventos de sincronización)

> Esta fase requiere credenciales OAuth2 de QuickBooks y/o Xero. No iniciar hasta tener las credenciales de la cuenta de producción.

## Diseño de alto nivel (no comenzar hasta Fase 4 estable)

1. **Instalar paquete:** `composer require spatie/laravel-data socialiteproviders/quickbooks`
2. **Tabla de mapeo:** `create_accounting_sync_records_table` — registra qué entidad local corresponde a qué ID externo
3. **Eventos:** `SalesOrderPaid` → job `SyncInvoiceToAccounting` (asíncrono, queue)
4. **No modificar:** el flujo de creación de ventas. La sincronización es siempre unidireccional (POS → Contabilidad) y asíncrona
5. **Rollback:** si el job falla, reintenta 3 veces con backoff exponencial; si sigue fallando, alerta al admin pero no bloquea la operación de venta

---

# Resumen: Orden de ejecución y dependencias

```
FASE 1 (seguridad + bugs)
		│
		├─→ FASE 2 (tests + observabilidad) [en paralelo con Fase 1]
		│
		└─→ FASE 3 (búsqueda por placa) [requiere Fase 1 completa]
						│
						└─→ FASE 4 (work orders) [requiere Fase 1 completa]
										│
										├─→ FASE 5 (PWA) [requiere Fase 2 completa]
										│
										└─→ FASE 6 (Stripe) [requiere Fase 1 completa]
														│
														└─→ FASE 7 (Sanctum tokens)
																		│
																		└─→ FASE 8 (QuickBooks/Xero) [requiere todo estable]
```

---

# Checklist de "No Romper" (ejecutar antes de cada merge a main)

```bash
#!/bin/bash
# pos-backend/scripts/regression_check.sh

set -e

echo "=== Verificación de regresión ==="

echo "1. Tests P0 (críticos)"
php artisan test tests/Feature/P0 --stop-on-failure

echo "2. Tests P1 (si existen)"
php artisan test tests/Feature/P1 --stop-on-failure 2>/dev/null || echo "P1 aún vacío"

echo "3. Rutas de API accesibles"
php artisan route:list --columns=uri | grep api/v1 | wc -l

echo "4. Migraciones al día"
# No debe haber migraciones pendientes
PENDING=$(php artisan migrate:status | grep "Pending" | wc -l)
if [ "$PENDING" -gt 0 ]; then echo "ALERTA: hay $PENDING migraciones pendientes"; exit 1; fi

echo "5. Config cacheable sin errores"
php artisan config:cache
php artisan config:clear  # limpiar para no dejar cache con datos de test

echo "=== Todo OK ==="
```

```bash
# pos-frontend/
npm run type-check && npm run build && echo "=== Frontend OK ==="
```

---

# Plantillas de tests adicionales

## Plantilla: RateLimitTest

```php
<?php
// tests/Feature/P1/RateLimitTest.php
use App\Models\User;

it('throttles api after 120 requests per minute', function () {
		$user = User::factory()->create();
		$this->actingAs($user);

		// Los primeros 120 deben pasar
		for ($i = 0; $i < 120; $i++) {
				$this->getJson('/api/v1/products')->assertStatus(200);
		}

		// El 121 debe ser rechazado
		$this->getJson('/api/v1/products')->assertStatus(429);
});
```

## Plantilla: WorkOrderTest

```php
<?php
// tests/Feature/P2/WorkOrderTest.php
use App\Models\User;
use App\Models\Customer;
use App\Models\Warehouse;

beforeEach(function () {
		$this->artisan('migrate:fresh');
		$this->warehouse = Warehouse::factory()->create();
		$this->user = User::factory()->create(['role' => 'manager', 'warehouse_id' => $this->warehouse->id]);
		$this->customer = Customer::factory()->create();
		$this->actingAs($this->user);
});

it('creates a work order', function () {
		$this->postJson('/api/v1/work-orders', [
				'customer_id'  => $this->customer->id,
				'warehouse_id' => $this->warehouse->id,
				'description'  => 'Cambio de aceite',
		])->assertStatus(201)
			->assertJsonPath('status', 'pending');
});

it('updates work order status', function () {
		$wo = \App\Models\WorkOrder::create([
				'customer_id'  => $this->customer->id,
				'warehouse_id' => $this->warehouse->id,
				'status'       => 'pending',
		]);

		$this->patchJson("/api/v1/work-orders/{$wo->id}/status", [
				'status' => 'in_progress',
		])->assertStatus(200)
			->assertJsonPath('status', 'in_progress');
});

it('does not break existing sales orders', function () {
		$this->getJson('/api/v1/sales-orders')->assertStatus(200);
});
```

---

*Fin del plan — v1.0 — 2026-03-16*
