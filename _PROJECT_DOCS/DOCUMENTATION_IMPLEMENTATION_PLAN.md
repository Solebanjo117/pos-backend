# Plan de Implementación: Documentación Completa del Sistema HermeX POS

## Contexto

HermeX POS es un sistema punto de venta integral para tiendas de refacciones automotrices, compuesto por:

- **Backend**: Laravel 12 (PHP 8.2) con 42 controladores API, 48 modelos, 13 servicios, 132 migraciones
- **Frontend**: Vue 3.5 + Inertia.js + TypeScript con 80+ páginas, 40+ composables

**Funcionalidad principal**:
- Punto de venta con cotizaciones y cola de caja
- Gestión de inventario multi-sucursal con lotes
- Sistema de compatibilidad vehicular (marcas/modelos/motores/chasis)
- Órdenes de compra y cuentas por pagar
- Gestión de clientes con crédito de tienda
- Órdenes de trabajo (módulo de servicio)
- Reportes y análisis avanzados
- Control de acceso basado en roles (admin, manager, cajero, vendedor, comprador, asistente)

**Documentación existente (EXCELENTE)**:
- [PATTERNS.md](./PATTERNS.md) - Patrones de código del proyecto
- [AGENT_CONTEXT.md](./AGENT_CONTEXT.md) - Contexto completo del sistema
- [LESSONS_LEARNED.md](./LESSONS_LEARNED.md) - Historial de bugs y reglas derivadas
- Documentos estratégicos (análisis competitivo, roadmap)

**Gaps críticos (NO EXISTE)**:
1. ❌ Manuales de usuario (instalación, guías operativas por rol)
2. ❌ Documentación de API (no hay OpenAPI/Swagger)
3. ❌ Diagramas de arquitectura (sistema, base de datos, flujos)
4. ❌ Guías de despliegue y operaciones
5. ❌ Documentación frontend (mínima)

---

## Estrategia de Documentación

### 1. Stack Tecnológico Recomendado

**Generador de Sitio: VitePress**
- Razón: Nativo Vue 3 (alineado con el frontend), basado en Markdown, búsqueda integrada, exportación a PDF
- Alternativas consideradas: Docusaurus (React, no alineado), MkDocs (Python)

**Diagramas: Mermaid.js + dbdiagram.io**
- Mermaid.js: Diagramas de arquitectura y flujos (basado en texto, versionable)
- dbdiagram.io: Diagramas entidad-relación (ERD) de la base de datos

**Documentación API: Laravel Scribe**
- Auto-generación desde anotaciones PHPDoc en controladores
- Genera: HTML, Postman collection, OpenAPI spec

**Capturas: ShareX + OBS Studio**
- ShareX: Capturas de pantalla con anotaciones
- OBS Studio: Grabación de videos tutoriales

**Costo total**: $0 (todas herramientas gratuitas/open-source)

### 2. Estructura de Documentación

```
hermex-docs/                           # Repositorio raíz de documentación
├── .vitepress/
│   └── config.ts                      # Configuración VitePress
├── docs/
│   ├── index.md                       # Home de la documentación
│   │
│   ├── manual-usuario/                # MANUAL DE USUARIO (80-120 págs)
│   │   ├── index.md                   # Introducción al sistema
│   │   ├── instalacion.md             # Guía de instalación
│   │   ├── roles/                     # Guías por rol
│   │   │   ├── administrador.md       # Manual del administrador
│   │   │   ├── cajero.md              # Manual del cajero
│   │   │   ├── vendedor.md            # Manual del vendedor
│   │   │   ├── comprador.md           # Manual del comprador
│   │   │   └── gerente.md             # Manual del gerente
│   │   ├── modulos/                   # Guías por módulo
│   │   │   ├── pos.md                 # Punto de venta
│   │   │   ├── inventario.md          # Gestión de inventario
│   │   │   ├── compras.md             # Órdenes de compra
│   │   │   ├── cotizaciones.md        # Cotizaciones
│   │   │   ├── clientes.md            # Gestión de clientes
│   │   │   ├── vehiculos.md           # Catálogo vehicular
│   │   │   ├── reportes.md            # Reportes
│   │   │   └── ordenes-trabajo.md     # Órdenes de trabajo
│   │   ├── flujos/                    # Flujos de trabajo
│   │   │   ├── venta-mostrador.md     # Venta directa
│   │   │   ├── cotizacion-a-venta.md  # Flujo de cotización
│   │   │   ├── orden-compra.md        # Flujo de compra
│   │   │   ├── cierre-caja.md         # Cierre de caja
│   │   │   └── devolucion.md          # Devoluciones
│   │   └── troubleshooting.md         # Solución de problemas y FAQ
│   │
│   ├── manual-tecnico/                # MANUAL TÉCNICO (60-80 págs)
│   │   ├── index.md                   # Introducción técnica
│   │   ├── arquitectura/              # Arquitectura del sistema
│   │   │   ├── overview.md            # Visión general
│   │   │   ├── backend.md             # Arquitectura backend
│   │   │   ├── frontend.md            # Arquitectura frontend
│   │   │   ├── database.md            # Diseño de base de datos
│   │   │   └── infraestructura.md     # Infraestructura
│   │   ├── api/                       # Documentación de API
│   │   │   ├── index.md               # Introducción a la API
│   │   │   ├── authentication.md      # Autenticación Sanctum
│   │   │   ├── endpoints.md           # Referencia de endpoints (auto-generado)
│   │   │   └── pagination.md          # Paginación y filtros
│   │   ├── database/                  # Base de datos
│   │   │   ├── schema.md              # Visión general del schema
│   │   │   ├── erd.md                 # Diagramas ERD
│   │   │   ├── migrations.md          # Guía de migraciones
│   │   │   └── seeders.md             # Datos de prueba
│   │   ├── frontend/                  # Frontend
│   │   │   ├── arquitectura.md        # Arquitectura Vue/Inertia
│   │   │   ├── componentes.md         # Biblioteca de componentes
│   │   │   ├── composables.md         # Composables Vue
│   │   │   ├── estado.md              # Gestión de estado
│   │   │   └── testing.md             # Testing frontend
│   │   ├── development/               # Guías de desarrollo
│   │   │   ├── setup.md               # Configuración del entorno
│   │   │   ├── patterns.md            # Patrones de código (enlace a PATTERNS.md)
│   │   │   ├── testing.md             # Estrategia de testing (Pest)
│   │   │   └── debugging.md           # Herramientas de debugging
│   │   └── security/                  # Seguridad
│   │       ├── authentication.md      # Sistema de autenticación
│   │       ├── authorization.md       # Control de acceso
│   │       └── best-practices.md      # Mejores prácticas
│   │
│   ├── operaciones/                   # MANUAL DE OPERACIONES (40-50 págs)
│   │   ├── index.md                   # Introducción
│   │   ├── deployment/                # Despliegue
│   │   │   ├── requisitos.md          # Requisitos del servidor
│   │   │   ├── instalacion.md         # Instalación en producción
│   │   │   ├── configuracion.md       # Configuración de .env y servicios
│   │   │   └── actualizaciones.md     # Proceso de actualización
│   │   ├── administracion/            # Administración del sistema
│   │   │   ├── backup.md              # Estrategia de respaldos
│   │   │   ├── recovery.md            # Recuperación ante desastres
│   │   │   ├── monitoring.md          # Monitoreo del sistema
│   │   │   └── logs.md                # Gestión de logs
│   │   ├── performance/               # Optimización
│   │   │   ├── optimizacion.md        # Guía de optimización
│   │   │   ├── caching.md             # Estrategia de caché (Redis)
│   │   │   └── database-tuning.md     # Optimización de base de datos
│   │   └── security/                  # Seguridad operativa
│   │       ├── ssl-certificates.md    # Certificados SSL/TLS
│   │       ├── firewall.md            # Configuración de firewall
│   │       └── updates.md             # Actualizaciones de seguridad
│   │
│   └── procesos-negocio/              # PROCESOS DE NEGOCIO (30-40 págs)
│       ├── index.md                   # Introducción
│       ├── flujo-ventas.md            # Flujo de ventas (cotización → venta)
│       ├── flujo-inventario.md        # Flujo de inventario (ajustes, transferencias)
│       ├── flujo-compras.md           # Flujo de compras (reorden → recepción)
│       ├── flujo-cuentas-cobrar.md    # Cuentas por cobrar y crédito
│       └── flujo-cuentas-pagar.md     # Cuentas por pagar
│
├── public/
│   ├── screenshots/                   # Capturas de pantalla
│   ├── diagrams/                      # Diagramas exportados
│   └── videos/                        # Videos tutoriales (opcional)
└── package.json
```

---

## Fases de Implementación

### **FASE 1: Infraestructura (Semana 1)** - 16 horas

**Objetivos**:
- Configurar el stack de documentación
- Crear la estructura de carpetas
- Configurar herramientas de captura y diagramas

**Tareas**:
1. [ ] Crear directorio `hermex-docs` en raíz del proyecto
2. [ ] Instalar y configurar VitePress
   ```bash
   mkdir hermex-docs && cd hermex-docs
   npm init -y
   npm add -D vitepress
   npx vitepress init
   ```
3. [ ] Configurar tema, navegación y barra lateral en `.vitepress/config.ts`
4. [ ] Integrar Mermaid.js en VitePress (plugin `vitepress-plugin-mermaid`)
5. [ ] Instalar Laravel Scribe en backend
   ```bash
   cd ../pos-backend
   composer require --dev knuckleswtf/scribe
   php artisan vendor:publish --tag=scribe-config
   ```
6. [ ] Configurar Scribe (`config/scribe.php`) para español
7. [ ] Crear estructura de carpetas completa (según árbol anterior)
8. [ ] Configurar ShareX para capturas consistentes
9. [ ] Crear cuenta en dbdiagram.io para ERDs
10. [ ] Crear `index.md` inicial con visión general

**Entregables**:
- Sitio VitePress funcional con navegación
- Laravel Scribe instalado y configurado
- Estructura de carpetas completa

---

### **FASE 2: Manual de Usuario (Semanas 2-4)** - 90 horas

**Objetivos**:
- Crear manuales operativos para todos los roles
- Documentar todos los módulos del sistema
- Incluir capturas de pantalla y guías paso a paso

**Semana 2** (30 horas):
1. [ ] **Introducción al sistema** (`manual-usuario/index.md`)
   - Qué es HermeX POS
   - Características principales
   - Roles y permisos
   - Navegación general
   
2. [ ] **Guía de instalación** (`manual-usuario/instalacion.md`)
   - Requisitos del cliente (navegador)
   - Configuración de impresoras
   - Hardware POS (lectores, cajón de efectivo)

3. [ ] **Manual del Administrador** (`manual-usuario/roles/administrador.md`)
   - Dashboard y métricas
   - Gestión de usuarios
   - Gestión de sucursales
   - Configuración del sistema (Settings)
     - Impuestos
     - Métodos de pago
     - Módulos opcionales
     - Redondeo de precios
     - Formato de SKU
   - Respaldos y restauración
   - Logs de actividad

4. [ ] **Manual del Cajero** (`manual-usuario/roles/cajero.md`)
   - Punto de venta (POS)
     - Buscar productos
     - Agregar al carrito
     - Aplicar descuentos
     - Procesar pagos (efectivo, tarjeta, transferencia, crédito)
     - Imprimir tickets
   - Cola de caja (cashier queue)
   - Sesión de caja (open/close)
   - Devoluciones
   
   **Archivos de referencia**:
   - `../pos-frontend/resources/js/pages/POS/Index.vue`
   - `../pos-frontend/resources/js/pages/POS/CashierQueue.vue`
   - `./routes/api_pos_sessions.php`

**Semana 3** (30 horas):
5. [ ] **Manual del Vendedor** (`manual-usuario/roles/vendedor.md`)
   - Crear cotizaciones
   - Editar cotizaciones
   - Enviar cotizaciones (PDF/email)
   - Enviar a depósito/caja
   - Gestión de clientes
   
   **Archivos de referencia**:
   - `./routes/api_sales_quotes.php`
   - `./app/Models/SalesQuote.php`

6. [ ] **Manual del Comprador** (`manual-usuario/roles/comprador.md`)
   - Lista de abastecimiento
   - Órdenes de compra (crear, recibir)
   - Compra rápida desde POS
   - Ajustar precios de venta al recibir
   - Cuentas por pagar
   - Gestión de proveedores
   
   **Archivos de referencia**:
   - `./routes/api_purchase_orders.php`
   - `./app/Services/PurchaseOrderService.php`

7. [ ] **Inventario** (`manual-usuario/modulos/inventario.md`)
   - Consultar inventario
   - Ajustes de inventario
   - Movimientos (entrada/salida/transferencia/ajuste/merma)
   - Transferencias entre sucursales
   - Lotes y merma
   - Reordenamiento inteligente
   
   **Archivos de referencia**:
   - `./routes/api_inventory.php`
   - `./app/Services/InventoryService.php`

**Semana 4** (30 horas):
8. [ ] **Catálogo de productos** (`manual-usuario/modulos/productos.md`)
   - Crear producto (básico, precios, variantes, atributos, compatibilidad, imágenes)
   - Editar producto
   - Búsqueda y filtros
   - Categorías y marcas

9. [ ] **Catálogo vehicular** (`manual-usuario/modulos/vehiculos.md`)
   - Marcas, modelos, motores, chasis
   - Búsqueda por compatibilidad
   
   **Archivos de referencia**:
   - `./routes/api_vehicles.php`

10. [ ] **Reportes** (`manual-usuario/modulos/reportes.md`)
    - Reporte de ventas
    - Reporte de inventario
    - Reporte financiero
    - Reporte Z (cierre diario)

11. [ ] **Flujos de trabajo** (todos los archivos en `manual-usuario/flujos/`)
    - Venta de mostrador (directo)
    - Cotización → venta
    - Orden de compra → recepción
    - Cierre de caja
    - Devoluciones

12. [ ] **Solución de problemas** (`manual-usuario/troubleshooting.md`)
    - Problemas comunes (sesión expirada, impresora, stock negativo)
    - FAQ

**Entregables**:
- 80-120 páginas de documentación de usuario
- 50+ capturas de pantalla anotadas
- Guías paso a paso para todos los roles

---

### **FASE 3: Manual Técnico (Semanas 5-7)** - 70 horas

**Objetivos**:
- Documentar arquitectura del sistema
- Generar documentación de API
- Crear diagramas ERD de la base de datos
- Guías de desarrollo

**Semana 5** (24 horas):
1. [ ] **Arquitectura general** (`manual-tecnico/arquitectura/overview.md`)
   - Diagrama de alto nivel (Mermaid.js):
   ```mermaid
   graph TB
       A[Navegador] -->|HTTPS| B[nginx]
       B --> C[Laravel Backend]
       B --> D[Vue Frontend]
       C --> E[PostgreSQL]
       C --> F[Redis Cache]
       C --> G[Redis Queue]
   ```
   - Stack tecnológico completo
   - Flujo de autenticación (Sanctum session cookies)

2. [ ] **Arquitectura backend** (`manual-tecnico/arquitectura/backend.md`)
   - Estructura Laravel (Controllers → Services → Models)
   - Patrón de rutas modulares (29 archivos de rutas)
   - Middleware stack (CheckRole, EnforceWarehouseContext, RequestTimingMiddleware)
   - Service layer pattern
   - Queue workers y scheduled tasks
   
   **Archivos de referencia**:
   - `./routes/api.php`
   - `./PATTERNS.md`

3. [ ] **Arquitectura frontend** (`manual-tecnico/arquitectura/frontend.md`)
   - Vue 3 Composition API
   - Inertia.js pattern (server-side routing)
   - Arquitectura de composables (40+ composables)
   - Cliente API (Axios)
   - Biblioteca de componentes (Reka UI + shadcn/vue)
   - Gestión de estado (composables + localStorage)
   
   **Archivos de referencia**:
   - `../pos-frontend/resources/js/app.ts`
   - `../pos-frontend/resources/js/composables/`
   - `../pos-frontend/resources/js/services/api/client.ts`

**Semana 6** (24 horas):
4. [ ] **Base de datos** (`manual-tecnico/database/`)
   - **schema.md**: Visión general de entidades
     - Core: users, warehouses, settings
     - Catalog: products, brands, categories, vehicles
     - Sales: sales_orders, sales_quotes, customers
     - Purchasing: purchase_orders, suppliers
     - Inventory: inventory, inventory_movements, batches
   
   - **erd.md**: Diagramas entidad-relación (5-7 diagramas usando dbdiagram.io)
     - Diagrama de productos y variantes
     - Diagrama de ventas
     - Diagrama de inventario
     - Diagrama de compras
     - Diagrama de vehículos
   
   - **migrations.md**: Guía de migraciones
   - **seeders.md**: Datos de prueba
   
   **Archivos de referencia**:
   - `./database/migrations/` (132 migraciones)
   - `./app/Models/` (48 modelos)

5. [ ] **Documentación de API** - Auto-generación con Scribe
   - Anotar controladores con PHPDoc:
   ```php
   /**
    * @group Productos
    * 
    * APIs para gestionar el catálogo de productos
    */
   class ProductController extends Controller
   {
       /**
        * Listar productos
        * 
        * Obtiene una lista paginada de productos con filtros opcionales.
        * 
        * @queryParam search string Búsqueda por nombre, SKU o código de barras. Example: filtro
        * @queryParam category_id int Filtrar por categoría. Example: 5
        * @queryParam brand_id int Filtrar por marca. Example: 3
        */
       public function index(Request $request) { ... }
   }
   ```
   
   - Generar documentación:
   ```bash
   php artisan scribe:generate
   ```
   
   - Crear secciones manuales en `manual-tecnico/api/`:
     - authentication.md (flujo de Sanctum)
     - pagination.md (formato de paginación)
   
   **Archivos de referencia**:
   - `./app/Http/Controllers/Api/` (42 controladores)

**Semana 7** (22 horas):
6. [ ] **Frontend detallado** (`manual-tecnico/frontend/`)
   - componentes.md: Biblioteca de componentes
   - composables.md: Documentar los 40+ composables
   - estado.md: Estrategia de gestión de estado
   - testing.md: Testing frontend

7. [ ] **Guía de desarrollo** (`manual-tecnico/development/`)
   - setup.md: Configuración del entorno (Laravel Herd, PostgreSQL, Redis)
   - patterns.md: Enlazar a PATTERNS.md existente
   - testing.md: Estrategia de testing (Pest, P0/P1/P2)
   - debugging.md: Herramientas (Laravel Pail, Telescope)

8. [ ] **Seguridad** (`manual-tecnico/security/`)
   - authentication.md: Sistema de autenticación
   - authorization.md: Control de acceso por roles
   - best-practices.md: Mejores prácticas

**Entregables**:
- 60-80 páginas de documentación técnica
- 5-7 diagramas ERD
- 3-5 diagramas de arquitectura
- Documentación API completa (auto-generada)
- Postman collection + OpenAPI spec

---

### **FASE 4: Manual de Operaciones (Semanas 8-9)** - 40 horas

**Objetivos**:
- Guías de despliegue en producción
- Procedimientos de respaldo y recuperación
- Monitoreo y optimización

**Semana 8** (20 horas):
1. [ ] **Despliegue** (`operaciones/deployment/`)
   - requisitos.md: Servidor Ubuntu 22.04, nginx, PHP 8.2, PostgreSQL 15, Redis 7, Node.js 18
   - instalacion.md: Instalación paso a paso en producción
   - configuracion.md: Configuración de .env, nginx, Supervisor, cron jobs
     - Ejemplo de configuración nginx (hermexpos.app)
     - Configuración de CORS (ver LESSONS_LEARNED.md LL-005)
     - SSL/TLS con Let's Encrypt
   - actualizaciones.md: Proceso de actualización de versiones

2. [ ] **Administración** (`operaciones/administracion/`)
   - backup.md: Estrategia de respaldos (PostgreSQL, archivos)
   - recovery.md: Recuperación ante desastres
   - monitoring.md: Monitoreo del sistema (logs, errores, performance)
   - logs.md: Gestión de logs (storage/logs, rotación)

**Semana 9** (20 horas):
3. [ ] **Performance** (`operaciones/performance/`)
   - optimizacion.md: Guía de optimización general
   - caching.md: Estrategia de caché con Redis
   - database-tuning.md: Optimización de PostgreSQL (índices, queries)

4. [ ] **Seguridad operativa** (`operaciones/security/`)
   - ssl-certificates.md: Certificados SSL/TLS
   - firewall.md: Configuración de firewall (ufw)
   - updates.md: Actualizaciones de seguridad

**Archivos de referencia**:
- `./.env.example`
- `./config/`
- `./LESSONS_LEARNED.md`

**Entregables**:
- 40-50 páginas de documentación de operaciones
- Guía completa de despliegue
- Procedimientos de backup y recovery
- Configuraciones de ejemplo (nginx, Supervisor)

---

### **FASE 5: Procesos de Negocio (Semana 10)** - 24 horas

**Objetivos**:
- Documentar flujos de negocio con diagramas
- Conectar procesos del mundo real con funcionalidades del sistema

**Tareas**:
1. [ ] **Flujo de ventas** (`procesos-negocio/flujo-ventas.md`)
   - Diagrama Mermaid.js:
   ```mermaid
   graph LR
       A[Vendedor crea cotización] --> B{Cliente acepta?}
       B -->|Sí| C[Enviar a depósito]
       C --> D[Bodeguero prepara]
       D --> E[Enviar a caja]
       E --> F[Cajero cobra]
       F --> G[Orden confirmada]
       G --> H[Descuento inventario]
       B -->|No| I[Cotización rechazada]
       J[Venta directa mostrador] --> F
   ```
   - Estados de cotización (draft → sent → sent_to_deposit → sent_to_cashier → collected)
   - Venta directa desde POS
   - Flujo de devoluciones

2. [ ] **Flujo de inventario** (`procesos-negocio/flujo-inventario.md`)
   - Ajuste de stock
   - Movimientos (inbound/outbound)
   - Transferencias entre sucursales
   - Registro de merma

3. [ ] **Flujo de compras** (`procesos-negocio/flujo-compras.md`)
   - Generación de lista de abastecimiento (stock bajo)
   - Orden de compra diaria (bucket)
   - Compra rápida desde POS
   - Recepción de mercancía
   - Actualización de precios de venta
   - Pago a proveedores

4. [ ] **Cuentas por cobrar** (`procesos-negocio/flujo-cuentas-cobrar.md`)
   - Límites de crédito de clientes
   - Crédito de tienda (saldo a favor)
   - Seguimiento de balance
   - Aplicación de pagos

5. [ ] **Cuentas por pagar** (`procesos-negocio/flujo-cuentas-pagar.md`)
   - Pagos a proveedores
   - Seguimiento de deuda

**Archivos de referencia**:
- `./app/Models/SalesQuote.php` (máquina de estados)
- `./app/Models/SalesOrder.php` (ciclo de vida)
- `./app/Services/SalesOrderService.php`
- `./app/Services/PurchaseOrderService.php`

**Entregables**:
- 30-40 páginas de documentación de procesos
- 5+ diagramas de flujo de negocio

---

### **FASE 6: Revisión y Publicación (Semana 11)** - 20 horas

**Objetivos**:
- Revisión completa de toda la documentación
- Correcciones y mejoras
- Despliegue del sitio de documentación

**Tareas**:
1. [ ] Revisión exhaustiva de contenido
   - Verificar enlaces rotos
   - Validar ejemplos de código
   - Revisar capturas de pantalla
   - Verificar diagramas
   - Correcciones de ortografía y gramática

2. [ ] Optimización de búsqueda
   - Configurar índice de búsqueda de VitePress
   - Optimizar metadatos (titles, descriptions)

3. [ ] Generar versiones exportables
   - PDFs individuales por sección (VitePress print CSS)
   - PDF completo consolidado

4. [ ] Desplegar sitio de documentación
   - Opción 1: GitHub Pages (gratuito)
   - Opción 2: docs.hermexpos.app (subdominio)

5. [ ] Crear página de inicio con navegación clara

6. [ ] Anunciar disponibilidad a usuarios

**Entregables**:
- Sitio de documentación completo y publicado
- PDFs descargables por sección
- Índice de búsqueda funcional

---

## Estrategia de Mantenimiento

### 1. Versionado de Documentación

**Estrategia**: Documentación versionada junto con el software

```
hermex-docs/docs/
├── v1.0/    # Versión inicial
├── v1.1/    # Versión actual
└── latest/  # Symlink a la versión actual
```

**Configuración VitePress**:
```typescript
// .vitepress/config.ts
export default {
  themeConfig: {
    nav: [
      { text: 'v1.1', link: '/v1.1/' },
      { text: 'v1.0', link: '/v1.0/' }
    ]
  }
}
```

### 2. Workflow de Actualización

**Proceso**:
1. Desarrollador crea feature
2. Desarrollador actualiza anotaciones Scribe (si es API)
3. Desarrollador actualiza diagramas Mermaid (si cambia arquitectura)
4. Technical writer actualiza manual de usuario (si es user-facing)
5. Capturas se actualizan si UI cambió
6. Commit de documentación junto con feature

**Git Hook de Recordatorio** (`.git/hooks/pre-commit`):
```bash
#!/bin/bash
# Recordar actualizar ERD si cambiaron migraciones
if git diff --cached --name-only | grep -q "database/migrations"; then
    echo "⚠️  Migraciones modificadas. Recuerda actualizar el ERD."
fi

# Recordar anotar si cambiaron controladores API
if git diff --cached --name-only | grep -q "app/Http/Controllers/Api"; then
    echo "⚠️  Controladores API modificados. Recuerda actualizar anotaciones Scribe."
fi
```

### 3. Regeneración Automática de API Docs

**GitHub Actions** (`.github/workflows/docs.yml`):
```yaml
name: Regenerar Documentación API

on:
  push:
    branches: [main, develop]
    paths:
      - 'app/Http/Controllers/Api/**'

jobs:
  regenerate-api-docs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
      - name: Instalar dependencias
        run: composer install --no-dev
      - name: Generar docs API
        run: php artisan scribe:generate
      - name: Commit docs generadas
        run: |
          git config user.name "GitHub Actions"
          git config user.email "actions@github.com"
          git add public/docs hermex-docs/docs/manual-tecnico/api/endpoints.md
          git commit -m "docs: regenerar documentación API" || exit 0
          git push
```

### 4. Automatización Parcial de Capturas

**Laravel Dusk para Flujos Críticos** (`tests/Browser/Documentation/ScreenshotsTest.php`):
```php
public function test_capturar_screenshots_pos()
{
    $this->browse(function (Browser $browser) {
        $browser->loginAs(User::factory()->create(['role' => 'cashier']))
                ->visit('/pos')
                ->screenshot('pos/01-pantalla-principal')
                ->type('@search-input', 'filtro')
                ->screenshot('pos/02-buscar-producto')
                ->click('@first-result')
                ->screenshot('pos/03-producto-seleccionado');
    });
}
```

**Ejecutar periódicamente**:
```bash
php artisan dusk tests/Browser/Documentation
```

### 5. Ciclo de Revisión

**Trimestral**:
- ✅ Verificar enlaces rotos
- ✅ Validar que ejemplos funcionen
- ✅ Actualizar capturas si UI cambió
- ✅ Revisar FAQ basado en tickets de soporte
- ✅ Actualizar troubleshooting con problemas comunes

**Anual**:
- ✅ Auditoría completa de documentación
- ✅ Encuesta de satisfacción de usuarios
- ✅ Refresh de videos tutoriales
- ✅ Evaluación de búsqueda/navegación

### 6. Modelo de Responsabilidades

| Rol | Responsabilidad |
|-----|----------------|
| **Technical Writer** | Manual de usuario, procesos de negocio |
| **Lead Developer** | Manual técnico, diagramas de arquitectura |
| **DevOps Engineer** | Manual de operaciones, guías de despliegue |
| **Todos los Developers** | Anotaciones Scribe, comentarios en código |

**PRs de Documentación**:
- Documentación se revisa como código
- Debe incluir ejemplos
- Debe pasar spell check
- Debe compilar exitosamente (VitePress build)

---

## Estimación de Esfuerzo

| Fase | Horas | Semanas |
|------|-------|---------|
| Fase 1: Infraestructura | 16 | 1 |
| Fase 2: Manual de Usuario | 90 | 3 |
| Fase 3: Manual Técnico | 70 | 3 |
| Fase 4: Manual de Operaciones | 40 | 2 |
| Fase 5: Procesos de Negocio | 24 | 1 |
| Fase 6: Revisión y Publicación | 20 | 1 |
| **TOTAL** | **260 horas** | **11 semanas** |

**Asignación de Recursos**:
- 1 Technical Writer (tiempo completo): Manual de usuario, procesos de negocio
- 1 Lead Developer (medio tiempo 50%): Manual técnico, documentación API
- 1 DevOps Engineer (cuarto de tiempo 25%): Manual de operaciones

**Timeline**: 11 semanas con recursos dedicados

---

## Métricas de Éxito

### Calidad de Documentación

1. **Completitud**:
   - ✅ 100% de funcionalidades user-facing documentadas
   - ✅ 100% de endpoints API documentados
   - ✅ Todos los procesos de negocio críticos tienen diagramas de flujo

2. **Precisión**:
   - ✅ Cero enlaces rotos
   - ✅ Todos los ejemplos de código probados y funcionando
   - ✅ Capturas de pantalla coinciden con UI actual (v1.1)

3. **Usabilidad**:
   - ✅ Tiempo promedio para encontrar respuesta < 2 minutos
   - ✅ Búsqueda retorna resultados relevantes (95%+ precisión)
   - ✅ Calificación de satisfacción de usuarios > 4/5

4. **Mantenibilidad**:
   - ✅ Documentación actualizada dentro de 1 sprint después de release
   - ✅ Docs API auto-regeneradas en cada deploy
   - ✅ Suite de capturas se ejecuta semanalmente

---

## Archivos Críticos de Referencia

### Para Manual de Usuario
1. [AGENT_CONTEXT.md](./AGENT_CONTEXT.md) - Lista completa de características
2. `../pos-frontend/resources/js/pages/` - Todas las páginas UI para capturas
3. `./routes/api_*.php` - Entender funcionalidad disponible

### Para Manual Técnico - Backend
1. [PATTERNS.md](./PATTERNS.md) - Patrones de código
2. `./app/Http/Controllers/Api/` - 42 controladores
3. `./app/Services/` - Lógica de negocio
4. `./routes/api.php` - Estructura de routing
5. `./app/Http/Middleware/CheckRole.php` - Patrón de autorización

### Para Manual Técnico - Frontend
1. `../pos-frontend/resources/js/app.ts` - Bootstrap e inicialización
2. `../pos-frontend/resources/js/composables/` - 40+ composables
3. `../pos-frontend/resources/js/services/api/client.ts` - Cliente API
4. `../pos-frontend/resources/js/components/AppSidebar.vue` - Estructura de navegación
5. `../pos-frontend/resources/js/pages/POS/Index.vue` - Componente más complejo

### Para Manual Técnico - Base de Datos
1. `./database/migrations/` - 132 migraciones
2. `./app/Models/` - 48 modelos Eloquent
3. `./database/seeders/` - Estructura de datos seed

### Para Manual de Operaciones
1. `./.env.example` - Configuración de entorno
2. `./config/` - Todas las configuraciones Laravel
3. [LESSONS_LEARNED.md](./LESSONS_LEARNED.md) - Problemas comunes y soluciones

### Para Procesos de Negocio
1. `./app/Models/SalesQuote.php` - Máquina de estados de cotizaciones
2. `./app/Models/SalesOrder.php` - Ciclo de vida de órdenes
3. `./app/Models/PurchaseOrder.php` - Flujo de compras
4. `./app/Services/SalesOrderService.php` - Lógica de ventas
5. `./app/Services/PurchaseOrderService.php` - Lógica de compras

---

## Herramientas y Costos

| Herramienta | Propósito | Licencia | Costo |
|-------------|-----------|----------|-------|
| VitePress | Generador de sitio estático | MIT | Gratis |
| Mermaid.js | Diagramas de arquitectura | MIT | Gratis |
| dbdiagram.io | Diagramas ERD | Tier gratuito | Gratis |
| Laravel Scribe | Documentación API | MIT | Gratis |
| ShareX | Capturas de pantalla | GPL v3 | Gratis |
| OBS Studio | Grabación de videos | GPL v2 | Gratis |
| Figma | Anotaciones de diagramas | Tier gratuito | Gratis |
| Laravel Dusk | Automatización de capturas | MIT | Gratis |

**Costo Total**: $0 (todas herramientas gratuitas)

---

## Verificación y Validación

Antes de considerar completo cada fase:

**Fase 2 (Manual de Usuario):**
- [ ] Usuario de cada rol puede completar flujo de trabajo sin asistencia
- [ ] Todas las capturas son claras y están anotadas
- [ ] Guías paso a paso son completas y precisas

**Fase 3 (Manual Técnico):**
- [ ] Desarrollador nuevo puede configurar entorno de desarrollo
- [ ] Diagramas ERD están actualizados con schema actual
- [ ] Documentación API cubre todos los endpoints (180+)
- [ ] Ejemplos de código ejecutan sin errores

**Fase 4 (Manual de Operaciones):**
- [ ] DevOps puede desplegar en servidor nuevo siguiendo la guía
- [ ] Procedimiento de backup funciona y ha sido probado
- [ ] Guías de performance están validadas en ambiente de producción

**Fase 5 (Procesos de Negocio):**
- [ ] Diagramas de flujo coinciden con implementación real
- [ ] Gerente de negocio puede entender flujos sin conocimiento técnico

---

## Próximos Pasos

1. **Aprobar este plan** ✋
2. **Asignar recursos** (technical writer + developer time)
3. **Comenzar Fase 1** (Infraestructura - Semana 1)
4. **Ejecutar Fases 2-6** secuencialmente

---

## Resumen Ejecutivo

Este plan proporciona un enfoque integral y práctico para crear documentación profesional del sistema HermeX POS. Aprovecha la excelente documentación de desarrolladores existente, utiliza herramientas apropiadas (todas gratuitas/open-source), y establece procesos mantenibles.

**Fortalezas clave**:
- ✅ Enfoque por fases permite progreso incremental
- ✅ Automatización (Scribe, Mermaid, Dusk) reduce mantenimiento manual
- ✅ Documentación versionada (Markdown + Git)
- ✅ Soporte bilingüe (principalmente español con secciones técnicas)
- ✅ Herramientas de costo cero
- ✅ Modelo claro de responsabilidades y mantenimiento

**Factores críticos de éxito**:
1. Technical writer dedicado para manual de usuario
2. Compromiso de developers para mantener anotaciones API
3. Ciclo regular de actualización de capturas
4. Cultura de documentación-como-código (docs se revisan como código)

**Resultado esperado**:
Documentación profesional de 260+ páginas que sirva tanto a usuarios finales como a personal técnico, reduzca la carga de soporte, y facilite la incorporación de nuevos miembros del equipo.