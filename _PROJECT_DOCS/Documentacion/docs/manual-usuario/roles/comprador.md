# 📦 Manual del Comprador - HermeX POS

Guía para compradores y gestores de inventario. El comprador gestiona órdenes de compra, proveedores y abastecimiento.

## 🎯 Rol del Comprador

**Responsabilidades:**
- ✅ Crear órdenes de compra
- ✅ Gestionar proveedores
- ✅ Recibir mercancía
- ✅ Actualizar precios de venta
- ✅ Gestionar cuentas por pagar
- ✅ Negociar con proveedores
- ✅ Prevenir desabastecimiento

---

## 📊 Dashboard del Comprador

### Mi Desempeño

```
┌─────────────────────────────────────────┐
│  Dashboard - Comprador                  │
├─────────────────────────────────────────┤
│                                          │
│  📋 ÓRDENES PENDIENTES                   │
│  ├─ Pendiente de envío: 3               │
│  ├─ En tránsito: 5                      │
│  ├─ Listas para recibir: 2              │
│  └─ Total valor: $45,320.50             │
│                                          │
│  ⚠️ ALERTAS                              │
│  ├─ Stock bajo: 12 productos            │
│  ├─ Stock crítico: 2 productos          │
│  ├─ Facturas vencidas: 1                │
│  └─ Proveedores atrasados: 0            │
│                                          │
│  💰 CUENTAS POR PAGAR                    │
│  ├─ Debido este mes: $8,500             │
│  ├─ Vencido: $0                         │
│  └─ Total adeudado: $28,450             │
│                                          │
└─────────────────────────────────────────┘
```

---

## 📋 Crear Orden de Compra

### Paso 1: Iniciar Orden

**Ruta:** Comprador → Órdenes de Compra → "+ Nueva Orden"

Información básica:
```
PROVEEDOR:
├─ Seleccionar de lista [▼]
└─ Si es nuevo:
   ├─ Nombre
   ├─ Contacto
   ├─ Email
   ├─ Teléfono
   └─ Cuenta corriente/Banco

FECHAS:
├─ Fecha de OC: [Hoy automático]
├─ Fecha esperada de entrega: [__]
└─ Plazo de pago: [30 días / 15 días / al contado]
```

### Paso 2: Agregar Productos

**Sistema sugiere automáticamente:**
1. Productos con stock bajo
2. Productos sin movimiento pronto
3. Productos más vendidos

**O agregar manualmente:**

1. Buscar producto
2. Ingresar cantidad
3. Verificar precio:
   - Precio unitario sugerido (basado en histórico)
   - Puedes cambiar si es necesario
4. Repetir para otros productos

**Ejemplo de carrito:**
```
ORDEN DE COMPRA #OC-001234
Proveedor: Distribuidora Centro

Producto         Cantidad  Precio Unit  Subtotal
Filtro Aire      100       18.50        1,850.00
Aceite 10W-40    50        35.00        1,750.00
Bujías (6 un)    30        15.00          450.00
─────────────────────────────────────────────
Subtotal:                              4,050.00
Impuesto (IVA):                          769.50
─────────────────────────────────────────────
TOTAL:                                 4,819.50
```

### Paso 3: Validar Antes de Enviar

Sistema verifica:
- ✅ ¿Tenemos presupuesto?
- ✅ ¿Espacioén bodega?
- ✅ ¿Precio es competitivo?
- ✅ ¿Proveedor tiene stock?

**Advertencias posibles:**
- 💡 Comparación de precios (otro proveedor es más barato)
- 📊 Proyección de rotación (este producto demora en vender)
- 🚨 Presupuesto limitado (sobrepasa límite)

### Paso 4: Enviar Orden

1. Hacer clic: [Confirmar y Enviar]
2. Orden entra en estado "Enviada"
3. Sistema envía email automático a proveedor
4. Proveedor confirma recepción y plazo

---

## 📞 Gestión de Proveedores

### Ver Proveedores

**Ruta:** Comprador → Proveedores

Lista de todos los proveedores:

| Proveedor | Categoría | Contacto | Teléfono | Rating |
|-----------|-----------|----------|----------|--------|
| Dist. Centro | General | Juan | 2211223 | ⭐⭐⭐⭐⭐ |
| Oil Lubes | Aceites | María | 2223456 | ⭐⭐⭐⭐ |
| Bujías Plus | Bujías | Carlos | 2234567 | ⭐⭐⭐ |

### Agregar Proveedor Nuevo

1. [+ Nuevo Proveedor]
2. Información:
   ```
   Nombre de empresa
   Rut/RFC
   Contacto (nombre)
   Email
   Teléfono
   Productos que ofrecen
   Forma de pago preferida
   Plazo de entrega típico
   ```
3. Guardar

### Evaluar Desempeño del Proveedor

Revisar:
- **Puntualidad:** ¿llega a tiempo?
- **Calidad:** ¿productos sin defectos?
- **Precio:** ¿competitivo?
- **Comunicación:** ¿responde rápido?
- **Flexibilidad:** ¿se adapta a cambios?

Rating:
```
⭐⭐⭐⭐⭐ Excelente (siempre confiable)
⭐⭐⭐⭐ Bueno (generalmente confiable)
⭐⭐⭐ Promedio (algunos problemas)
⭐⭐ Bajo (problemas frecuentes)
⭐ Muy bajo (considerar cambiar)
```

---

## 📦 Recibir Mercancía

### Paso 1: Generar Recepción

Cuando llega mercancía del proveedor:

**Ruta:** Comprador → Recepciones → "+ Nueva Recepción"

Opción 1: Vincular a OC existente
- Seleccionar OC
- Sistema carga productos esperados

Opción 2: Recepción sin OC (compra rápida)
- Agregar productos manualmente
- Ingresar cantidad

### Paso 2: Verificar Mercancía

**Tareas del bodeguero (supervisado por comprador):**

```
CHECKLIST DE RECEPCIÓN:
────────────────────────────────
☐ Verificar cantidad recibida vs OC
☐ Verificar condición de productos
☐ Revisar fecha de vencimiento
☐ Verificar serie/código de producto
☐ Inspeccionar embalaje
☐ Contar unidades físicamente
☐ Registrar daños o faltantes
```

### Paso 3: Registrar Discrepancias

Si hay problemas:

```
PRODUCTO: Filtro Aire
ESPERADO: 100 unidades
RECIBIDO: 98 unidades
FALTANTES: 2
MOTIVO: No especificado
ACCIÓN: Contactar proveedor
```

Opciones:
- Devolver mercancía defectuosa
- Solicitar reembolso
- Solicitar reemplazo

### Paso 4: Confirmar Recepción

1. Bodeguero marca: [Recepción Completada]
2. Sistema registra:
   - Mercancía entra al inventario
   - Se descuenta de "Órdenes Pendientes"
   - Se suma a "Cuentas por Pagar"
3. Factura del proveedor llega para pago

---

## 💰 Actualizar Precios de Venta

Cuando recibe mercancía:

### Análisis de Costo

Sistema calcula automáticamente:

```
Producto: Filtro Aire
─────────────────────────────────
Costo anterior: $18.50
Costo nuevo: $17.80
Cambio: -$0.70 (-3.8%)

Precio venta actual: $25.50
Margen actual: 37.8%

RECOMENDACIÓN:
├─ Mantener a $25.50 (margen sube a 43%)
├─ Bajar a $24.50 (margen baja a 37%)
└─ Subir a $26.50 (margen sube a 49%)
```

### Cambiar Precio

Si costo bajó:
- Bajar precio competitivo
- Aumentar margen
- Ambas cosas

Si costo subió:
- Subir precio (si lo permite la competencia)
- Reducir margen
- Estudiar viabilidad

**Ruta para cambiar precio:**
1. Seleccionar producto
2. [Editar Precio]
3. Ingresar nuevo precio
4. Guardar
5. Efecto inmediato en futuras ventas

---

## 📊 Reorden Automática

El sistema puede sugerir o hacer reorden automáticamente.

### Configurar Parámetros

**Ruta:** Comprador → Configuración → Reorden Automática

Por cada producto:
```
Filtro Aire
├─ Cantidad mínima: 50 unidades
├─ Cantidad a ordenar: 200 unidades
├─ Stock de seguridad: 10 unidades
├─ Proveedor preferido: Dist. Centro
└─ Punto de reorden: automático
```

### Cómo Funciona

Sistema verifica diariamente:
```
SI Stock < Cantidad Mínima
ENTONCES Generar Sugerencia de OC

Ejemplo:
Stock actual: 45 unidades
Cantidad mínima: 50 unidades
→ ALERTA: Crear OC de 200 unidades
```

### Recibir Sugerencias

Comprador recibe notificaciones:
- Email diario con productos que necesitan reorden
- Dashboard muestra "12 productos a ordenar"
- Puede crear OC con un clic

---

## 💳 Cuentas por Pagar

### Ver Deuda con Proveedores

**Ruta:** Comprador → Cuentas por Pagar

Estado de deuda:
```
Proveedor          Factura  Fecha    Vencimiento  Monto     Estado
Dist. Centro       #F001    02/04    07/05        $4,819.50 Pendiente
Oil Lubes          #F002    01/04    16/04        $2,150.00 Vencida
Bujías Plus        #F003    05/04    20/04        $890.50   Pendiente
────────────────────────────────────────────────────────────
TOTAL ADEUDADO:                                   $7,860.00
```

### Pagar Factura

1. Seleccionar factura
2. [Registrar Pago]
3. Ingresar:
   - Método de pago (efectivo, transferencia, cheque)
   - Monto pagado
   - Fecha de pago
   - Referencia (número de comprobante)
4. Guardar

**Sistema genera:**
- Comprobante de pago
- Descuenta de deuda
- Registra en contabilidad

### Seguimiento de Pagos

```
DEUDA HISTÓRICA:
├─ Pagos esta semana: $2,000
├─ Próximos pagos: $4,819.50 (07/05)
├─ Deuda vencida: $0
└─ Promedio de pago: 28 días
```

---

## 📊 Análisis de Compras

### Gasto por Proveedor

**Ruta:** Comprador → Análisis → Gasto por Proveedor

```
Proveedor        Compras  Gasto Total  % del Total  Trend
Dist. Centro     45       $85,420      58%          ↑
Oil Lubes        23       $42,150      29%          ↓
Bujías Plus      12       $18,900      13%          ↑
────────────────────────────────────────────────────
TOTAL                     $146,470     100%
```

Acciones:
- Concentrar compras con proveedor más barato
- Negociar volumen con principales
- Revisar si hay mejor precio en otro lado

### Productos Más Comprados

```
Producto              Compras  Gasto   Margen  ROI
Filtro Aire           450      $8,100  35%    2.8x
Aceite 10W-40         320      $11,200 32%    2.4x
Bujías (6 pack)       280      $4,200  38%    3.2x
```

Insight: Bujías tienen mejor margen, aumentar stock

---

## 🆘 Problemas Comunes

### "Proveedor no entrega a tiempo"

**Acciones:**
1. Contactar proveedor por teléfono
2. Preguntar motivo del retraso
3. Solicitar fecha nueva
4. Si sigue retrasado:
   - Buscar alternativa
   - Encargar a otro proveedor
   - Notificar a gerencia

### "Mercancía llega dañada"

**Procedimiento:**
1. Documentar daño (foto)
2. Registrar en sistema como "Devolución"
3. Contactar proveedor
4. Solicitar:
   - Reemplazo
   - Reembolso
   - Descuento

### "Precio sube constantemente"

**Estrategia:**
1. Buscar alternativas de proveedor
2. Negociar volumen con proveedor actual
3. Aumentar precio de venta gradualmente
4. Evaluar cambio de proveedor

---

## 📋 Checklist Diario

- [ ] Revisar órdenes pendientes (¿llegan en tiempo?)
- [ ] Recibir mercancía (inspeccionar y registrar)
- [ ] Validar discrepancias (¿faltantes?)
- [ ] Revisar alertas de stock bajo
- [ ] Crear nuevas órdenes de reorden
- [ ] Revisar cuentas por pagar (próximos vencimientos)
- [ ] Actualizar precios si cambió costo
- [ ] Contactar proveedores si hay problemas

---

## 📚 Recursos Relacionados

- [Manual del Administrador](/manual-usuario/roles/administrador)
- [Módulo de Compras](/manual-usuario/modulos/compras)
- [Flujo de Orden de Compra](/manual-usuario/flujos/orden-compra)
- [Solución de Problemas](/manual-usuario/troubleshooting)

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
