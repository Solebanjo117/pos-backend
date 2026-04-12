# 👔 Manual del Gerente - HermeX POS

Guía para gerentes de sucursal. El gerente supervisa operaciones diarias y toma decisiones basadas en datos.

## 🎯 Rol del Gerente

**Responsabilidades:**
- ✅ Supervisar operaciones de la sucursal
- ✅ Autorizar descuentos y excepciones
- ✅ Analizar reportes y rendimiento
- ✅ Gestionar inventario y reórdenes
- ✅ Resolver problemas operacionales
- ✅ Capacitar personal
- ✅ Reportar a administración

---

## 📊 Dashboard del Gerente

### Acceso al Dashboard

1. Iniciar sesión como Gerente
2. Dashboard se carga automáticamente
3. Ver KPIs de la sucursal

### Elementos Principales

```
┌─────────────────────────────────────────┐
│  Dashboard - Sucursal Centro            │
├─────────────────────────────────────────┤
│                                          │
│  📈 HOYACTUAL                            │
│  ├─ Ventas: $12,450.50 ↑15% vs ayer     │
│  ├─ Transacciones: 156 tickets          │
│  ├─ Ticket Promedio: $79.80             │
│  └─ Clientes Atendidos: 142             │
│                                          │
│  💰 COMPARATIVA                          │
│  ├─ Esta semana: $75,320 (vs $68,920)   │
│  ├─ Este mes: $285,450 (proyectado)     │
│  └─ Año: $2.8M (meta: $3M)              │
│                                          │
│  📦 INVENTARIO                           │
│  ├─ Stock Bajo: 12 productos            │
│  ├─ Stock Crítico: 2 productos          │
│  └─ Rotación: 8.5 días promedio         │
│                                          │
│  👥 EQUIPO                               │
│  ├─ Cajeros conectados: 4               │
│  ├─ Vendedores: 3                       │
│  └─ Última venta: hace 2 min             │
│                                          │
└─────────────────────────────────────────┘
```

---

## 📈 Reportes

### Reporte de Ventas Diarias

**Ruta:** Gerente → Reportes → Ventas Diarias

Información detallada:
- Total de ventas por hora
- Productos más vendidos
- Métodos de pago utilizados
- Descuentos otorgados
- Devoluciones

**Filtros disponibles:**
- Fecha (hoy, semana, mes, rango)
- Vendedor/Cajero específico
- Categoría de producto
- Método de pago

**Exportar:** 
- Excel (.xlsx)
- PDF
- CSV

### Reporte de Rendimiento por Vendedor

**Ruta:** Gerente → Reportes → Vendedores

Ver desempeño de cada vendedor:

| Vendedor | Cotizaciones | Conversión | Monto | Comisión |
|----------|-------------|-----------|-------|----------|
| Juan Pérez | 24 | 62% | $18,920 | $945 |
| María López | 31 | 71% | $22,150 | $1,108 |
| Carlos Ruiz | 18 | 44% | $12,300 | $615 |

Identificar top performers y entrenar a los que rezagados.

### Reporte de Inventario

**Ruta:** Gerente → Reportes → Inventario

Monitorear stock:
- Productos con stock bajo
- Productos sin movimiento (más de 30 días)
- Rotación por categoría
- Valor total de inventario
- Recomendaciones de reorden

**Alerta de Acción:**
- ⚠️ Stock bajo: < 5 unidades
- 🔴 Stock crítico: 0-2 unidades
- 💤 Sin movimiento: 30+ días sin venta

### Reporte de Clientes

**Ruta:** Gerente → Reportes → Clientes

Análisis de cartera:
- Cliente más frecuente
- Cliente con mayor compra
- Clientes con crédito vencido
- Tasa de retención
- NPS (Net Promoter Score)

---

## 🔐 Autorización de Descuentos

La mayoría de descuentos requieren aprobación del gerente.

### Proceso de Autorización

1. **Cajero/Vendedor solicita descuento > 15%**
   - Justificación requerida
   - Sistema pausa transacción

2. **Notificación al Gerente**
   - Alerta en navbar
   - Details de solicitud

3. **Revisar:**
   ```
   Producto: Filtro Aire
   Monto Original: $25.50
   Descuento Solicitado: 20% ($5.10)
   Monto Final: $20.40
   Motivo: "Cliente frecuente"
   Cajero: Juan Pérez
   ```

4. **Autorizar o Rechazar:**
   - [✅ Autorizar] o [❌ Rechazar]

### Límites de Descuento

| Descuento | Autorización |
|-----------|-------------|
| 0-10% | Automático (Cajero) |
| 10-15% | Automático (Cajero) |
| 15-20% | Gerente |
| 20-30% | Gerente + Auditoría |
| 30%+ | Administrador |

---

## 🔄 Gestión de Devoluciones

### Autorizar Devolución Grande

Devoluciones > $500 requieren aprobación:

1. Cajero reporta devolución
2. Sistema notifica al gerente
3. Revisar motivo y ticket original
4. Verificar cliente en base de datos
5. Autorizar o contactar a administrador

### Investigar Patrón de Devoluciones

Si hay muchas devoluciones (>5% del volumen):

1. **Ir a:** Gerente → Análisis → Devoluciones
2. **Ver:**
   - Producto con más devoluciones
   - Vendedor con más devoluciones
   - Causas principales
3. **Acciones:**
   - Revisar calidad del producto
   - Capacitar a vendedor
   - Contactar a proveedor

---

## 📦 Gestión de Inventario

### Monitoreo de Stock Bajo

**Ruta:** Gerente → Inventario → Stock Bajo

Diariamente revisar:
- Productos con stock bajo
- Productos agotados
- Proyección de agotamiento

**Acciones:**
```
Si Producto tiene Ventas/día = 5:
├─ Stock 10: Se agota en 2 días → ORDENAR YA
├─ Stock 20: Se agota en 4 días → ORDENAR HOY
└─ Stock 5: Se agota mañana → CRÍTICO
```

### Autorizar Compra Rápida desde POS

Cuando stock se agota y cliente espera:

1. **Cajero solicita:** Compra Rápida
2. **Sistema notifica al gerente**
3. **Revisar:**
   - ¿Proveedor disponible?
   - ¿Costo razonable?
   - ¿Cliente lo espera?
4. **Autorizar compra al instante**
5. Proveedor entrega mismo día (si es posible)

### Reorden Automático

El sistema puede configurarse para reordenar automáticamente:

**Ruta:** Gerente → Configuración → Reorden Automático

Establecer:
- Cantidad mínima por producto
- Cantidad a reordenar
- Proveedor preferido
- Margen de seguridad

---

## 💬 Comunicación con Equipo

### Mensajes a Equipo

**Ruta:** Gerente → Comunicaciones → Nuevo Mensaje

Enviar mensajes a:
- Vendedores específicos
- Cajeros
- Todo el equipo
- Bodeguero

Ejemplo: Notificar cierre de caja, cambios de política, etc.

### Avisos Importantes

Crear avisos que aparecen en login:

1. Ir a: Gerente → Avisos
2. "+ Nuevo Aviso"
3. Escribir mensaje
4. Seleccionar destinatarios
5. Guardar

---

## 👥 Supervisión de Equipo

### Ver Actividad en Tiempo Real

**Ruta:** Gerente → Monitoreo → Actividad en Tiempo Real

Ver:
- Quién está conectado ahora
- Qué está haciendo cada uno
- Última actividad
- Conexión/Desconexión

### Revisar Desempeño Diario

**Ruta:** Gerente → Equipo → Desempeño

Por cada vendedor/cajero:
- Número de transacciones
- Monto manejado
- Tiempo en sistema
- Errores o incidentes
- Puntuación de rendimiento

### Resolver Conflictos

Si hay problema entre empleados:

1. Revisar logs de actividad
2. Identificar qué pasó
3. Hablar con personas involucradas
4. Documentar en sistema
5. Reportar a administrador si es grave

---

## 🔍 Análisis de Tendencias

### Productos Estrella

**Ruta:** Gerente → Análisis → Productos Estrella

Identificar:
- Productos más vendidos
- Margen de ganancia promedio
- Tendencias (subiendo/bajando)
- Recomendaciones de inversión

**Acción:** Aumentar stock de productos que suben, reducir los que bajan

### Análisis de Estacionalidad

Ver patrones:
- ¿Qué vende más cada día de la semana?
- ¿Horarios de mayor venta?
- ¿Productos de temporada?

**Acción:** Anticipar demanda, preparar stock

### Análisis de Competencia

Si tienes acceso a datos de competencia:
- Precios comparativos
- Productos similares
- Estrategia de posicionamiento

---

## 📞 Autorización de Crédito

### Crédito a Cliente Nuevo

Si cliente solicita crédito (compra sin pagar):

1. Solicitud llega al gerente
2. Revisar:
   - ¿Documento de identidad?
   - ¿Referencias comerciales?
   - ¿Monto solicitado razonable?
3. Establecer:
   - Límite de crédito
   - Plazo de pago (15, 30, 45 días)
   - Garantía o aval si es necesario
4. Guardar en sistema

### Seguimiento de Crédito Vencido

**Ruta:** Gerente → Créditos → Vencidos

Ver clientes con:
- Pago vencido hace días
- Monto adeudado
- Historial de pagos

**Acciones:**
1. Contactar cliente
2. Recordatorio de pago
3. Renegociar si es necesario
4. Escalar a administrador si persiste

---

## 📋 Cierre de Turno

### Revisión Final

Al final del día como gerente:

1. **Revisar cajas cerradas:**
   - ¿Todas cerraron correctamente?
   - ¿Hay discrepancias?

2. **Confirmar inventario:**
   - ¿Coincide con sistema?
   - ¿Hay merma reportada?

3. **Revisar reportes del día:**
   - ¿Ventas normales?
   - ¿Incidentes?

4. **Validar documentación:**
   - ¿Recibos archivados?
   - ¿Reportes guardados?

5. **Generar Reporte Z:**
   - Cierre oficial del día
   - Enviar a administración

---

## 🆘 Problemas Frecuentes

### "Impresora está fuera de servicio"

**Solución:**
1. Verificar conexión física
2. Reiniciar impresora
3. Instalar drivers si es necesario
4. Si no funciona: usar impresora de respaldo
5. Llamar a técnico

### "Un cajero cierra con diferencia"

**Investigar:**
1. Revisar transacciones del día
2. Contar efectivo nuevamente
3. Ver si hay transacciones sin registrar
4. Preguntar al cajero qué pasó
5. Documentar diferencia

**Acción:** Capacitar o discutir con el equipo

### "Tenemos mucho stock de un producto"

**Estrategia:**
1. Reducir precio temporalmente (promoción)
2. Vender en lote (bundle)
3. Devolver al proveedor si es posible
4. Donar (deducible fiscal)

---

## 📊 KPIs Clave para Monitorear

| KPI | Meta | Frecuencia |
|-----|------|-----------|
| Venta Diaria | $X | Diario |
| Ticket Promedio | $80 | Diario |
| Conversión (Cot→Venta) | >60% | Semanal |
| Rotación Inventario | 8 días | Mensual |
| Margen Bruto | >35% | Mensual |
| Satisfacción Cliente | >4.5/5 | Mensual |
| Retención Empleados | >90% | Trimestral |

---

## 📚 Recursos Relacionados

- [Manual del Administrador](/manual-usuario/roles/administrador) - Configuración del sistema
- [Manual del Cajero](/manual-usuario/roles/cajero) - Operaciones POS
- [Reportes](/manual-usuario/modulos/reportes) - Guía completa de reportes
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
