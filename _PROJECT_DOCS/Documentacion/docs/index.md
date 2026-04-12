# 🏪 HermeX POS - Documentación Oficial

Bienvenido a la documentación completa del sistema de punto de venta **HermeX POS**. Esta guía te ayudará a entender, usar y mantener el sistema.

## 📚 Secciones Principales

### [📖 Manual de Usuario](/manual-usuario/)
Guías operativas para cada rol del sistema. Aprende cómo usar todas las características de HermeX POS en tu día a día.

**Para:**
- 👤 Administradores - Gestión del sistema y usuarios
- 👨‍💼 Gerentes - Dashboard y reportes
- 🛒 Vendedores - Cotizaciones y clientes
- 💳 Cajeros - Punto de venta (POS) y cierre de caja
- 📦 Compradores - Órdenes de compra e inventario

### [🔧 Manual Técnico](/manual-tecnico/)
Documentación técnica para desarrolladores y personal IT. Cubre arquitectura, API, base de datos y guías de desarrollo.

**Incluye:**
- 🏗️ Arquitectura del sistema (backend, frontend, infraestructura)
- 📡 Documentación completa de API con ejemplos
- 🗄️ Diagramas y esquema de base de datos
- 💻 Guías de desarrollo y patrones de código
- 🔐 Seguridad y autenticación

### [⚙️ Manual de Operaciones](/operaciones/)
Procedimientos para despliegue, mantenimiento y optimización en producción.

**Cubre:**
- 🚀 Instalación y despliegue en servidores
- 💾 Estrategias de backup y recuperación
- 📊 Monitoreo y optimización de performance
- 🔒 Seguridad operativa y certificados SSL

### [📊 Procesos de Negocio](/procesos-negocio/)
Diagramas y explicaciones de los flujos de negocio principales del sistema.

**Flujos documentados:**
- 💰 Ventas (cotización → venta → cobro)
- 📦 Inventario (ajustes, transferencias, merma)
- 🛍️ Compras (reorden → recepción → pago)
- 📈 Cuentas por cobrar y crédito de clientes
- 💳 Cuentas por pagar a proveedores

---

## 🚀 Inicio Rápido

### Soy usuario final
⏱️ **3 minutos**
1. Accede a [Manual de Usuario](/manual-usuario/)
2. Encuentra tu rol (Administrador, Vendedor, Cajero, etc.)
3. Sigue la guía paso a paso

### Soy desarrollador
⏱️ **10 minutos**
1. Lee [Arquitectura General](/manual-tecnico/arquitectura/overview)
2. Configura tu entorno: [Setup](/manual-tecnico/development/setup)
3. Revisa [Patrones de Código](/manual-tecnico/development/patterns)
4. Consulta [API Documentation](/manual-tecnico/api/)

### Estoy desplegando a producción
⏱️ **30 minutos**
1. Verifica [Requisitos de Servidor](/operaciones/deployment/requisitos)
2. Sigue [Guía de Instalación](/operaciones/deployment/instalacion)
3. Configura [Variables de Entorno](/operaciones/deployment/configuracion)
4. Lee [Procedimiento de Backup](/operaciones/administracion/backup)

---

## 📊 Estadísticas del Sistema

| Métrica | Valor |
|---------|-------|
| **Controladores API** | 42 |
| **Modelos de Base de Datos** | 48 |
| **Servicios Principales** | 13+ |
| **Migraciones de BD** | 132+ |
| **Endpoints API** | 180+ |
| **Páginas Frontend** | 80+ |
| **Composables Vue** | 40+ |
| **Roles de Usuario** | 6 |
| **Módulos Principales** | 8+ |

---

## 🎯 Características Principales

### 💳 Punto de Venta (POS)
- Búsqueda rápida de productos
- Carrito de compras inteligente
- Múltiples métodos de pago (efectivo, tarjeta, transferencia, crédito)
- Descuentos y promociones
- Impresión de tickets

### 📦 Gestión de Inventario
- Control multi-sucursal
- Gestión de lotes y fecha de vencimiento
- Transferencias entre sucursales
- Ajustes de stock y merma
- Alertas de reorden inteligentes

### 🚗 Compatibilidad Vehicular
- Catálogo de vehículos (marcas, modelos, motores, chasis)
- Búsqueda por compatibilidad
- Vinculación de productos a vehículos
- Filtros avanzados

### 💼 Gestión de Compras
- Órdenes de compra automatizadas
- Gestión de proveedores
- Recepción de mercancía
- Actualización de precios al recibir
- Cuentas por pagar

### 👥 Gestión de Clientes
- Base de datos de clientes
- Crédito de tienda personalizado
- Historial de compras
- Cotizaciones guardadas

### 📈 Reportes y Análisis
- Reporte de ventas diarias
- Análisis de inventario
- Reportes financieros
- Cierre Z (cierre diario)

### 👤 Control de Acceso
6 roles con permisos granulares:
- **Administrador** - Control total del sistema
- **Gerente** - Reportes y análisis
- **Vendedor** - Cotizaciones y clientes
- **Cajero** - Punto de venta
- **Comprador** - Órdenes de compra
- **Asistente** - Inventario y movimientos

---

## 🔧 Stack Tecnológico

### Backend
- **Framework:** Laravel 12 (PHP 8.2)
- **Base de Datos:** PostgreSQL 15
- **Cache:** Redis 7
- **Queue:** Redis Queue
- **API:** RESTful con Laravel Sanctum

### Frontend
- **Framework:** Vue 3.5
- **Server-side Rendering:** Inertia.js
- **Lenguaje:** TypeScript
- **UI Components:** Reka UI + shadcn/vue
- **Estado:** Composables Vue + localStorage

### Infraestructura
- **Servidor:** Ubuntu 22.04 LTS
- **Web Server:** nginx
- **Process Manager:** Supervisor
- **SSL:** Let's Encrypt

---

## 📞 Soporte y Contacto

### Encontraste un error en la documentación?
Por favor reporte en el [repositorio de GitHub](https://github.com/hermexpos/docs/issues)

### Necesitas ayuda?
- 📧 Correo: support@hermexpos.app
- 💬 Chat en vivo en la aplicación
- 📞 Teléfono: (disponible en horas de negocio)

---

## 📅 Últimas Actualizaciones

- **2026-04-08**: Lanzamiento de Fase 1 - Infraestructura
- **2026-03-27**: Implementación de Vehicle Chassis
- **2026-03-15**: Release de Essential Seeder
- **2026-02-01**: Lanzamiento de HermeX POS v1.0

---

## ℹ️ Información Adicional

### Documentación en Repositorio
Toda la documentación está versionada en Git. Puedes acceder a versiones anteriores o reportar cambios necesarios en el repositorio.

### Descargar como PDF
Cada sección puede exportarse a PDF para lectura offline. Busca el icono de descarga en la esquina superior derecha.

### Buscar
Usa la función de búsqueda (Ctrl+K o Cmd+K) para encontrar rápidamente lo que necesitas.

---

**Última actualización:** 2026-04-08  
**Versión de Documentación:** 1.0.0  
**Versión de Sistema:** HermeX POS v1.1+
