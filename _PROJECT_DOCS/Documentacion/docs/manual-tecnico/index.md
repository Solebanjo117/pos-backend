# 🔧 Manual Técnico - HermeX POS

Documentación técnica completa para desarrolladores, administradores de sistemas e ingenieros de infraestructura.

## 🎯 Propósito

Este manual cubre:
- ✅ Arquitectura del sistema (backend, frontend, infraestructura)
- ✅ API completa con ejemplos
- ✅ Diseño y schema de base de datos
- ✅ Guías de desarrollo y patrones
- ✅ Seguridad y autenticación

---

## 🏗️ Arquitectura

### [Visión General](./arquitectura/overview)
Diagrama de alto nivel del sistema completo, stack tecnológico y flujo de autenticación.

### [Arquitectura Backend](./arquitectura/backend)
- Estructura de Laravel (Controllers → Services → Models)
- Patrón de rutas modulares (29 archivos de rutas)
- Middleware stack
- Service layer pattern
- Queue workers y scheduled tasks

### [Arquitectura Frontend](./arquitectura/frontend)
- Vue 3 Composition API
- Inertia.js pattern
- Arquitectura de composables
- Cliente API (Axios)
- Gestión de estado

### [Base de Datos](./arquitectura/database)
- Diseño de schema
- Relaciones entre entidades
- Índices y optimizaciones

### [Infraestructura](./arquitectura/infraestructura)
- Stack de infraestructura
- Configuración de servicios
- Dependencias del sistema

---

## 📡 API Documentation

### [Introducción a la API](./api/index)
Guía general de la API REST de HermeX POS.

### [Autenticación](./api/authentication)
Cómo autenticarse usando Laravel Sanctum:
```php
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}
```

### [Endpoints](./api/endpoints)
Referencia completa de los 180+ endpoints de la API (auto-generada con Scribe).

Endpoints agrupados por módulo:
- **Autenticación** - Login, logout, refresh
- **Usuarios** - CRUD de usuarios
- **Productos** - Catálogo de productos
- **Inventario** - Movimientos de stock
- **Ventas** - Órdenes y cotizaciones
- **Compras** - Órdenes de compra
- **Clientes** - Gestión de clientes
- **Vehículos** - Catálogo vehicular
- **Reportes** - Endpoints de análisis

### [Paginación y Filtros](./api/pagination)
Cómo usar paginación, búsqueda y filtros en los endpoints.

---

## 🗄️ Base de Datos

### [Schema Overview](./database/schema)
Descripción de todas las tablas principales:
- **Core**: users, warehouses, settings
- **Catalog**: products, brands, categories, vehicles
- **Sales**: sales_orders, sales_quotes, customers
- **Purchasing**: purchase_orders, suppliers
- **Inventory**: inventory, inventory_movements, batches

### [Diagramas ERD](./database/erd)
Diagramas entidad-relación visuales de la base de datos:
- Productos y variantes
- Ventas y cotizaciones
- Inventario y movimientos
- Compras y proveedores
- Vehículos y compatibilidad

### [Migraciones](./database/migrations)
Cómo crear y ejecutar migraciones de base de datos.

### [Seeders](./database/seeders)
Cómo cargar datos de prueba en la base de datos.

---

## 💻 Frontend

### [Arquitectura Frontend](./frontend/arquitectura)
Vue 3 Composition API + Inertia.js

### [Componentes](./frontend/componentes)
Biblioteca de componentes reutilizables.

### [Composables](./frontend/composables)
40+ composables para lógica compartida.

### [Estado](./frontend/estado)
Estrategia de gestión de estado con composables y localStorage.

### [Testing](./frontend/testing)
Testing de componentes y E2E.

---

## 🛠️ Desarrollo

### [Setup - Configuración del Entorno](./development/setup)
Guía para configurar tu entorno de desarrollo:
- Laravel Herd (para macOS/Windows)
- PostgreSQL 15
- Redis 7
- Node.js 18+
- Composer y npm

### [Patrones de Código](./development/patterns)
Patrones y convenciones usadas en el proyecto. Enlace a [PATTERNS.md](../../PATTERNS.md) del proyecto.

### [Testing - Estrategia de Testing](./development/testing)
- Framework: Pest PHP
- Tipos de tests: P0 (críticos), P1 (importante), P2 (soporte)
- Cómo correr tests

### [Debugging](./development/debugging)
Herramientas y técnicas:
- Laravel Pail (logs en tiempo real)
- Laravel Telescope (profiler)
- Debugbar
- VS Code debugger

---

## 🔐 Seguridad

### [Autenticación](./security/authentication)
Sistema de autenticación:
- Laravel Sanctum
- Session cookies
- Token refresh

### [Autorización](./security/authorization)
Control de acceso:
- Roles (admin, manager, cashier, seller, buyer, assistant)
- Permissions granulares
- CheckRole middleware

### [Mejores Prácticas](./security/best-practices)
- Validación de entrada
- CSRF protection
- SQL injection prevention
- XSS protection
- Rate limiting

---

## 📊 Estadísticas del Código

| Métrica | Valor |
|---------|-------|
| Controladores API | 42 |
| Modelos Eloquent | 48 |
| Servicios | 13+ |
| Migraciones | 132+ |
| Endpoints API | 180+ |
| Líneas de código (Backend) | ~50,000 |
| Páginas Frontend | 80+ |
| Componentes Vue | 100+ |
| Composables | 40+ |
| Líneas de código (Frontend) | ~30,000 |

---

## 🚀 Inicio Rápido para Desarrolladores

### 1. Clonar y Configurar (10 minutos)
```bash
# Backend
cd herd/pos-backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed

# Frontend
cd ../herd/pos-frontend
npm install
npm run dev
```

### 2. Iniciar Servidores (5 minutos)
```bash
# Terminal 1: Backend
cd herd/pos-backend
php artisan serve

# Terminal 2: Frontend
cd herd/pos-frontend
npm run dev
```

### 3. Acceder a la Aplicación
- Frontend: http://localhost:3000
- Backend API: http://localhost:8000/api

### 4. Credenciales de Prueba
- Email: admin@example.com
- Password: password

---

## 🔗 Recursos Relacionados

- [Manual de Usuario](/manual-usuario/) - Guías para usuarios finales
- [Manual de Operaciones](/operaciones/) - Despliegue y mantenimiento
- [PATTERNS.md](../../PATTERNS.md) - Patrones de código del proyecto
- [AGENT_CONTEXT.md](../../AGENT_CONTEXT.md) - Contexto completo del sistema
- [LESSONS_LEARNED.md](../../LESSONS_LEARNED.md) - Lecciones y soluciones conocidas

---

## 💡 Convenciones del Proyecto

### Nombrado
- **Backend**: snake_case en base de datos, camelCase en JSON
- **Frontend**: camelCase en variables/funciones
- **APIs**: snake_case en requests y responses

### Timestamps
- Formato: ISO 8601 (YYYY-MM-DDTHH:mm:ss)
- Zona horaria: UTC en backend, local en frontend

### IDs
- Numéricos auto-incrementales o UUID v4
- Consistentes entre frontend y backend

---

## 📞 Soporte Técnico

### Encontraste un bug?
Abre un issue en GitHub con:
- Descripción del problema
- Pasos para reproducir
- Stack trace o logs relevantes
- Entorno (OS, navegador, versión)

### Necesitas ayuda?
1. Busca en esta documentación (Ctrl+K)
2. Revisa [LESSONS_LEARNED.md](../../LESSONS_LEARNED.md)
3. Pregunta en el canal de Slack #dev
4. Contacta al tech lead

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0  
**Versión de Sistema:** HermeX POS v1.1+
