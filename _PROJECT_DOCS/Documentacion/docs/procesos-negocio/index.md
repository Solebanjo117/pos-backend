# 📊 Procesos de Negocio - HermeX POS

Documentación de los flujos de negocio principales del sistema con diagramas y explicaciones detalladas.

Esta sección conecta los procesos del mundo real con las funcionalidades de HermeX POS.

---

## 💰 [Flujo de Ventas](./flujo-ventas)

El proceso completo desde la cotización inicial hasta el cierre del pedido.

**Actores:**
- Vendedor (crea cotización)
- Cliente (acepta/rechaza)
- Bodeguero (prepara mercancía)
- Cajero (cobra)

**Estados:**
- Draft (borrador)
- Sent (enviada)
- Sent to Deposit (en bodega)
- Sent to Cashier (en caja)
- Collected (cobrada)

**Variantes:**
- Venta directa desde POS (sin cotización)
- Cotización con aceptación posterior
- Venta con abono y saldo

**Métricas:**
- Tasa de conversión (cotizaciones → ventas)
- Tiempo promedio de conversión
- Descuentos otorgados
- Métodos de pago usados

---

## 📦 [Flujo de Inventario](./flujo-inventario)

Gestión del stock de productos incluyendo movimientos y ajustes.

**Tipos de Movimientos:**
- **Inbound** - Entrada de mercancía (compra, devolución)
- **Outbound** - Salida de mercancía (venta)
- **Transfer** - Movimiento entre sucursales
- **Adjustment** - Ajuste (inventario físico)
- **Loss** - Pérdida/merma

**Características:**
- Gestión por lote y fecha de vencimiento
- Control multi-sucursal
- Alertas de stock bajo
- Reorden inteligente automático

**Reportes:**
- Stock actual por producto/sucursal
- Movimientos de inventario
- Productos con stock bajo
- Movimientos de merma

---

## 🛍️ [Flujo de Compras](./flujo-compras)

Proceso de adquisición de mercancía desde el proveedor.

**Etapas:**
1. **Reorden Inteligente** - Sistema detecta stock bajo
2. **Orden de Compra** - Se genera OC automática o manual
3. **Confirmación** - Proveedor confirma disponibilidad
4. **Envío** - Mercancía en tránsito
5. **Recepción** - Entrada en almacén
6. **Actualización de Precios** - Precio de venta se ajusta si es necesario
7. **Pago** - Se registra la deuda al proveedor

**Gestión de Proveedores:**
- Catálogo de proveedores
- Precios por producto
- Términos de pago
- Historial de compras

**Variantes:**
- Compra planificada (reorden diaria)
- Compra rápida desde POS (cuando falta stock)
- Compra directa (sin reorden automática)

---

## 💳 [Cuentas por Cobrar](./flujo-cuentas-cobrar)

Gestión de crédito y seguimiento de pagos de clientes.

**Características:**
- Límite de crédito por cliente
- Crédito de tienda (saldo a favor)
- Seguimiento de balance
- Reportes de vencidos

**Tipos de Crédito:**
1. **Crédito de Tienda** - Saldo acumulado en cuenta del cliente
   - Aplica automáticamente en nuevas compras
   - Genera intereses si se configura

2. **Crédito Extendido** - Días de pago después de la compra
   - Configurable por cliente
   - Seguimiento de vencimiento

**Procesos:**
- Crear cotización con crédito
- Otorgar crédito especial
- Cobro de saldo
- Seguimiento de pagos pendientes

---

## 💵 [Cuentas por Pagar](./flujo-cuentas-pagar)

Gestión de deudas con proveedores.

**Características:**
- Registro de facturas
- Seguimiento de pagos
- Vencimientos
- Reportes de deuda

**Procesos:**
- Recepción de mercancía genera deuda
- Seguimiento de facturas
- Programación de pagos
- Cierre de facturas

**Métricas:**
- Deuda total por proveedor
- Facturas vencidas
- Promedio de días de pago
- Rotación de cuentas por pagar

---

## 🔄 Ciclo Operativo Diario

### Apertura de Operaciones
1. Cajero abre su turno (abre caja)
2. Verifica efectivo inicial
3. Revisa órdenes pendientes
4. Consulta nivel de stock crítico

### Operaciones del Día
1. **Vendedores:**
   - Crean y envían cotizaciones
   - Responden consultas de clientes
   - Cierran ventas

2. **Cajeros:**
   - Procesan ventas desde POS
   - Reciben pagos (efectivo, tarjeta, crédito)
   - Imprimen tickets
   - Procesan devoluciones

3. **Bodegueros:**
   - Preparan pedidos de depósito
   - Realizan ajustes de inventario
   - Reciben mercancía nueva

4. **Compradores:**
   - Revisan nivel de stock
   - Generan órdenes de compra
   - Reciben mercancía
   - Actualiza precios

### Cierre de Operaciones
1. **Cierre de Caja:** Cajero cierra turno
   - Cuenta efectivo
   - Compara con sistema
   - Registra diferencias
   - Genera reporte Z

2. **Cierre de Bodega:** Bodeguero cierra turno
   - Verifica stock coincida con sistema
   - Anota discrepancias
   - Prepara orden para mañana

3. **Cierre de Ventas:** Gerente revisa ventas del día
   - Verifica totales
   - Revisa métodos de pago
   - Identifica tendencias

---

## 📈 Reporting y Análisis

### Reportes Diarios
- **Reporte Z:** Cierre de caja (ventas, pagos, descuentos)
- **Reporte de Inventario:** Stock actual
- **Reporte de Deudas:** Cuentas por cobrar/pagar vencidas

### Reportes Semanales
- Ventas por vendedor
- Productos más vendidos
- Rentabilidad por categoría
- Análisis de devoluciones

### Reportes Mensuales
- Performance de sucursales
- Análisis de márgenes
- Comparativa vs mes anterior
- Pronóstico para próximo mes

---

## ⚠️ Casos Especiales

### Devoluciones
- Cliente devuelve producto sin abrir
- Reembolso a crédito de tienda o efectivo
- Stock se incrementa
- Afecta comisión del vendedor

### Cambios
- Cliente cambia producto por otro
- Se actualiza OC y stock
- Diferencia de precio se cobra/devuelve

### Descuentos
- Descuento por pronto pago
- Descuento por volumen
- Descuento especial (autorización)
- Descuento por error/cortesía

### Ajustes de Inventario
- Merma por deterioro
- Pérdida por robo
- Error de conteo
- Donación

---

## 🔗 Documentación Relacionada

- [Manual de Usuario - Flujos](/manual-usuario/flujos/) - Guías paso a paso
- [Manual Técnico - Modelos](/manual-tecnico/) - Implementación técnica
- [AGENT_CONTEXT.md](../../AGENT_CONTEXT.md) - Features del sistema

---

## 📊 Ejemplos de Diagramas

Cada proceso tiene un diagrama Mermaid.js que visualiza el flujo:

```
Ejemplo:
Vendedor → Crea Cotización → Envía a Cliente
                                    ↓
                            ¿Cliente Acepta?
                            ↙              ↘
                        Sí              No
                        ↓               ↓
                    Envía a Depósito  Rechazada
                    → Bodeguero Prepara
                    → Envía a Caja
                    → Cajero Cobra
                    → Orden Confirmada
```

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0  
**Versión de Sistema:** HermeX POS v1.1+
