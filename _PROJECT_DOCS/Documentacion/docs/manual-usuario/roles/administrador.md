# 👨‍💼 Manual del Administrador - HermeX POS

Guía completa para administradores del sistema. El administrador tiene acceso total al sistema y es responsable de la configuración, usuarios y seguridad.

## 🎯 Responsabilidades Principales

Como Administrador, serás responsable de:

- ✅ Configuración del sistema
- ✅ Gestión de usuarios y roles
- ✅ Gestión de sucursales
- ✅ Configuración de impuestos y métodos de pago
- ✅ Auditoría y logs
- ✅ Respaldos y recuperación
- ✅ Actualizaciones del sistema

---

## 📊 Dashboard del Administrador

### Acceso al Dashboard

1. Iniciar sesión con tu usuario administrativo
2. La página de inicio (Dashboard) se carga automáticamente

### Elementos del Dashboard

```
┌─────────────────────────────────────────┐
│  HermeX POS - Dashboard Administrativo   │
├─────────────────────────────────────────┤
│                                          │
│  📈 KPIs del Sistema                     │
│  ├─ Usuarios activos hoy                 │
│  ├─ Transacciones procesadas             │
│  ├─ Ingresos del día                     │
│  └─ Inventario total                     │
│                                          │
│  🏪 Estado de Sucursales                 │
│  ├─ Sucursal 1: Online ✅                │
│  ├─ Sucursal 2: Online ✅                │
│  └─ Sucursal 3: Offline ❌               │
│                                          │
│  ⚠️ Alertas                              │
│  ├─ 5 productos con stock bajo           │
│  ├─ Respaldo no ejecutado hace 3 días    │
│  └─ 2 usuarios sin rol asignado          │
│                                          │
└─────────────────────────────────────────┘
```

### Tarjetas de Inicio Rápido

- **🏢 Gestión de Sucursales** - Acceso directo a sucursales
- **👥 Gestión de Usuarios** - Crear y editar usuarios
- **⚙️ Configuración** - Acceder a todos los settings
- **🔒 Seguridad** - Logs, auditoría, permisos

---

## 👥 Gestión de Usuarios

### Crear un Nuevo Usuario

1. **Ir a:** Admin → Usuarios → "+ Nuevo Usuario"

2. **Completar Información:**
   ```
   • Nombre Completo *
   • Email *
   • Rol * (Administrador, Gerente, Vendedor, Cajero, Comprador, Asistente)
   • Sucursal * (seleccionar donde trabaja)
   • Estado: Activo/Inactivo
   ```

3. **Asignar Permisos Adicionales** (si aplica)
   - Por defecto, el rol otorga permisos estándar
   - Permisos adicionales: Reportes, Auditoría, Configuración

4. **Guardar**
   - Sistema envía email al usuario con credenciales temporales
   - Usuario debe cambiar contraseña en primer acceso

### Roles Disponibles

| Rol | Descripción | Acceso Principal |
|-----|-------------|------------------|
| **Administrador** | Control total del sistema | Todo |
| **Gerente** | Reportes, análisis, supervisión | Reportes, Dashboard |
| **Vendedor** | Crear y gestionar cotizaciones | Cotizaciones, Clientes |
| **Cajero** | Procesar ventas | POS |
| **Comprador** | Órdenes de compra, inventario | Compras, Inventario |
| **Asistente** | Tareas básicas de inventario | Inventario (limitado) |

### Editar Usuario Existente

1. **Ir a:** Admin → Usuarios
2. **Buscar usuario** en la tabla
3. **Hacer clic** en la fila
4. **Modificar** campos necesarios
5. **Guardar cambios**

### Desactivar Usuario

1. **Ir a:** Admin → Usuarios
2. **Seleccionar usuario**
3. **Cambiar Estado** a "Inactivo"
4. **Guardar**

⚠️ El usuario no podrá iniciar sesión pero sus datos se conservan.

### Resetear Contraseña de Usuario

1. **Ir a:** Admin → Usuarios
2. **Seleccionar usuario**
3. **Hacer clic** en "Resetear Contraseña"
4. Se envía email con contraseña temporal
5. Usuario debe cambiar en primer acceso

---

## 🏪 Gestión de Sucursales

### Crear Nueva Sucursal

1. **Ir a:** Admin → Sucursales → "+ Nueva Sucursal"

2. **Información Básica:**
   ```
   • Nombre *
   • Código de sucursal *
   • Dirección
   • Teléfono
   • Email
   • Gerente responsable
   ```

3. **Configuración:**
   - Zona horaria
   - Moneda (si aplica)
   - Almacén principal
   - Dirección de facturación

4. **Guardar**

### Ver Detalles de Sucursal

1. **Ir a:** Admin → Sucursales
2. **Hacer clic** en sucursal
3. Ver:
   - Usuarios asignados
   - Inventario
   - Ventas del período
   - Estado de conexión

### Editar Información de Sucursal

1. Seleccionar sucursal
2. Hacer clic en "Editar"
3. Modificar campos
4. Guardar

### Transferir Gerente de Sucursal

1. Seleccionar sucursal
2. Hacer clic en "Cambiar Gerente"
3. Seleccionar nuevo gerente de la lista
4. Guardar

---

## ⚙️ Configuración del Sistema

### Información de Empresa

**Ruta:** Admin → Configuración → Información de Empresa

Configurar:
- Nombre de empresa
- RUT/RFC
- Dirección principal
- Teléfono
- Email
- Logo
- Color de marca (opcional)

### Impuestos

**Ruta:** Admin → Configuración → Impuestos

1. **Crear Impuesto:**
   - Nombre (IVA, Impuesto Local, etc.)
   - Tasa (%)
   - Descripción

2. **Ejemplo:**
   ```
   IVA Estándar: 19%
   IVA Reducido: 8%
   Exento: 0%
   ```

3. **Aplicar por Defecto:**
   - Seleccionar impuesto como predeterminado
   - Se aplicará automáticamente a nuevos productos

### Métodos de Pago

**Ruta:** Admin → Configuración → Métodos de Pago

Habilitar según necesidad:

```
☑ Efectivo
  └─ Monto mínimo de cambio: [__]
  
☑ Tarjeta de Crédito
  └─ Comisión: 2.5%
  
☑ Tarjeta de Débito
  └─ Comisión: 1.5%
  
☑ Transferencia Bancaria
  └─ Nombre banco: [__]
  └─ Cuenta: [__]
  
☑ Crédito de Tienda
  └─ Límite de crédito default: [__]
```

### Redondeo de Precios

**Ruta:** Admin → Configuración → Redondeo

Definir cómo se redondean los precios:
- Sin redondeo
- Redondear a centena más cercana
- Redondear hacia arriba
- Redondear hacia abajo

### Formato de SKU

**Ruta:** Admin → Configuración → SKU

Configurar formato automático:
- Prefijo (ejemplo: "PROD-")
- Longitud de número: [__]
- Contador actual: [__]

Ejemplo: PROD-000001, PROD-000002...

---

## 🔒 Seguridad

### Logs de Actividad

**Ruta:** Admin → Auditoría → Logs

Ver registro de todas las acciones:
- Quién realizó la acción
- Qué se modificó
- Cuándo ocurrió
- Desde qué IP

Filtrar por:
- Usuario
- Tipo de acción (crear, editar, eliminar)
- Fecha
- Módulo

### Sesiones Activas

**Ruta:** Admin → Seguridad → Sesiones

Ver usuarios conectados:
- Usuario
- Dispositivo/IP
- Hora de conexión
- Última actividad

Opción para "cerrar forzosamente" sesión de usuario si es necesario.

### Permisos por Rol

**Ruta:** Admin → Seguridad → Permisos

Configurar permisos granulares por rol:
- Ver (read)
- Crear (create)
- Editar (update)
- Eliminar (delete)
- Exportar (export)

Ejemplo:
```
Rol: Vendedor
├─ Cotizaciones: Ver, Crear, Editar
├─ Clientes: Ver, Crear, Editar
├─ Productos: Ver (solo)
└─ Reportes: Ninguno
```

### Cambiar Contraseña Admin

1. **Ir a:** Mi Perfil (esquina superior derecha)
2. **Seleccionar:** "Cambiar Contraseña"
3. **Ingresar:**
   - Contraseña actual
   - Nueva contraseña
   - Confirmar contraseña
4. **Guardar**

---

## 💾 Respaldos y Recuperación

### Crear Respaldo Manual

1. **Ir a:** Admin → Mantenimiento → Respaldos
2. **Hacer clic:** "+ Crear Respaldo"
3. **Confirmar**
4. Descargará archivo .zip con toda la base de datos y archivos
5. **Guardar en lugar seguro** (drive externo, cloud, etc.)

### Configurar Respaldos Automáticos

1. **Ir a:** Admin → Mantenimiento → Respaldos Automáticos
2. **Habilitar:** "Crear respaldos automáticos"
3. **Configurar:**
   - Frecuencia: Diaria / Semanal / Mensual
   - Hora: [__:__]
   - Retención: [__] días
4. **Guardar**

### Restaurar desde Respaldo

1. **Ir a:** Admin → Mantenimiento → Restauración
2. **Seleccionar archivo** de respaldo
3. **Confirmar** (⚠️ esto sobrescribe datos actuales)
4. Sistema se reinicia
5. Esperar a que se complete

⚠️ **IMPORTANTE:** 
- Hacer respaldo ANTES de restaurar
- Comunicar a usuarios que el sistema estará fuera de servicio
- Probar restauración en ambiente de prueba primero

---

## 📈 Reportes Administrativos

### Dashboard Ejecutivo

**Ruta:** Admin → Reportes → Dashboard Ejecutivo

Ver KPIs principales:
- Total de ventas (período seleccionable)
- Comparativa vs período anterior
- Ingresos por sucursal
- Usuarios más activos
- Productos más vendidos
- Alerta de stock bajo

### Reporte de Usuarios

**Ruta:** Admin → Reportes → Usuarios

Información sobre usuarios:
- Nombre
- Rol
- Sucursal
- Última conexión
- Transacciones realizadas

### Reporte de Actividad

**Ruta:** Admin → Reportes → Actividad

Resumen de actividades:
- Logins
- Cambios de datos
- Eliminaciones
- Accesos a reportes

---

## 🔧 Mantenimiento del Sistema

### Estado del Sistema

**Ruta:** Admin → Mantenimiento → Estado del Sistema

Ver:
- ✅ Conexión a base de datos
- ✅ Conexión a servidor de archivos
- ✅ Sincronización de sucursales
- ✅ Espacio en disco disponible
- ✅ Versión del sistema

### Limpiar Cache

1. **Ir a:** Admin → Mantenimiento → Herramientas
2. **Hacer clic:** "Limpiar Cache"
3. Confirmar
4. Sistema limpia cache de servidor

Use si experimenta problemas de velocidad.

### Actualizar Sistema

1. **Ir a:** Admin → Mantenimiento → Actualizaciones
2. Si hay actualización disponible: "Actualizar"
3. Sistema descarga e instala actualización
4. Se reinicia automáticamente
5. ⚠️ Durante actualización el sistema estará fuera de servicio

---

## 📋 Configuración Inicial (First Setup)

### Checklist Completo

Cuando configures HermeX por primera vez:

- [ ] Información de empresa ingresada
- [ ] Al menos una sucursal creada
- [ ] Impuestos configurados
- [ ] Métodos de pago habilitados
- [ ] Usuarios creados con roles apropiados
- [ ] Primer respaldo completado
- [ ] Permisos por rol revisados
- [ ] Categorías de productos creadas
- [ ] Primer producto ingresado
- [ ] Prueba: crear una venta de prueba

---

## 📞 Soporte Técnico

Para problemas administrativos:

1. **FAQ del Admin:** Revisa [Solución de Problemas](/manual-usuario/troubleshooting)
2. **Email:** admin-support@hermexpos.app
3. **Teléfono:** +56 (XXXX) XXXX
4. **Horas:** Lunes-Viernes, 09:00-18:00

---

## 🔗 Recursos Relacionados

- [Manual de Operaciones](/operaciones/) - Guías de despliegue
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ
- [Seguridad](/manual-tecnico/security/) - Información técnica

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
