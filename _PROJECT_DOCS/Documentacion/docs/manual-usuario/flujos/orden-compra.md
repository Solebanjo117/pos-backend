# 📦 Flujo: Orden de Compra

Guía completa del proceso de crear, recibir y procesar una orden de compra.

**Tiempo total:** 2-7 días (depende del proveedor)  
**Roles:** Comprador, Bodeguero, Administrador  
**Requisitos:** Acceso a módulo de compras, proveedores registrados

---

## 📊 Diagrama del Flujo

```
┌──────────────────┐
│ 1. NECESIDAD DE  │
│    STOCK         │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 2. CREAR ORDEN   │
│    DE COMPRA     │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 3. ENVIAR A      │
│    PROVEEDOR     │
└────────┬─────────┘
         │
         ▼
┌──────────────────────┐
│ 4. PROVEEDOR CONFIRMA│
│    (Plazo: 1-2 días)│
└────────┬─────────────┘
         │
         ▼
┌──────────────────┐
│ 5. RECEPCIÓN     │
│    MERCANCÍA     │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 6. VERIFICACIÓN  │
│    DE STOCK      │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 7. PAGO AL       │
│    PROVEEDOR     │
└──────────────────┘
```

---

## 🚀 Paso 1: Identificar Necesidad de Stock

### Revisar Inventario

Comprador revisa diariamente:

```
Ruta: Comprador → Dashboard → Alertas

ALERTAS DE STOCK:
├─ Stock bajo: 12 productos
├─ Stock crítico: 2 productos
└─ Stock agotado: 0 productos

ANÁLISIS:
├─ Filtro Aire: 45 unidades, vende 8/día → se agota en 5-6 días
├─ Aceite 10W-40: 30 unidades, vende 4/día → se agota en 7-8 días
└─ Bujías: 20 unidades, vende 3/día → se agota en 6-7 días
```

### Decisión de Reorden

```
CRITERIOS:
├─ Si stock < 2 semanas de venta → ORDENAR YA
├─ Si stock < 1 semana → URGENTE
└─ Si stock agotado → CRÍTICO (Compra rápida)

EJEMPLO:
Filtro Aire: 45 unidades ÷ 8/día = 5.6 días
→ Menos de 1 semana → ORDENAR INMEDIATAMENTE
```

---

## ✍️ Paso 2: Crear Orden de Compra

### Acceder al Formulario

**Ruta:** Comprador → Órdenes de Compra → "+ Nueva Orden"

### Información Básica

```
PROVEEDOR:
├─ Nombre: Distribuidora Centro
├─ Contacto: Juan García
├─ Email: compras@distcentro.cl
└─ Teléfono: 2-2345678

FECHAS Y TÉRMINOS:
├─ Fecha OC: 08/04/2026 (hoy)
├─ Plazo esperado: 2 días
├─ Plazo de pago: 30 días (a fin de mes)
└─ Referencia: OC-001234
```

### Agregar Productos

```
PRODUCTO 1:
├─ Filtro Aire (SKU: FA-001)
├─ Cantidad: 100 unidades
├─ Precio unitario: $18.50 (histórico)
├─ Subtotal: $1,850.00
└─ Lote: Automático

PRODUCTO 2:
├─ Aceite 10W-40 (SKU: AC-002)
├─ Cantidad: 50 botellas
├─ Precio: $35.00
├─ Subtotal: $1,750.00
└─ Lote: Automático

PRODUCTO 3:
├─ Bujías 6-pack (SKU: BU-003)
├─ Cantidad: 30 cajas
├─ Precio: $15.00
├─ Subtotal: $450.00
└─ Lote: Automático

────────────────────────────
SUBTOTAL:           $4,050.00
IMPUESTO (IVA 19%): $  769.50
────────────────────────────
TOTAL:              $4,819.50
```

### Revisión Antes de Enviar

```
SISTEMA VALIDA:
✓ Presupuesto disponible
✓ Espacio en bodega
✓ Proveedor activo
✓ Precios razonables

SI TODO ES OK:
→ [Confirmar y Enviar]
```

---

## 📧 Paso 3: Enviar a Proveedor

### Envío Automático

```
1. [Confirmar y Enviar]
2. Sistema genera:
   ├─ Documento PDF de OC
   ├─ Email automático al proveedor
   ├─ Copia al email del comprador
   └─ Registro en historial

3. Estado: "Enviada"
4. Notificación: Sistema confirma envío
```

### Información Enviada

```
EMAIL AL PROVEEDOR:

Asunto: Orden de Compra #OC-001234

Cuerpo:
"Estimado proveedor,

Le adjuntamos orden de compra #OC-001234
Plazo de entrega esperado: 10/04/2026
Plazo de pago: 30 días

Productos:
- Filtro Aire (100) @ $18.50 = $1,850
- Aceite 10W-40 (50) @ $35.00 = $1,750
- Bujías 6-pack (30) @ $15.00 = $450

TOTAL: $4,819.50 (IVA incluido)

Favor confirmar recepción y plazo.

Saludos,
[Comprador]"

PDF adjunto: Documento oficial de OC
```

---

## ⏳ Paso 4: Confirmación del Proveedor

### Esperar Confirmación

```
TÍPICAMENTE:
├─ 1-2 horas: Confirmación de disponibilidad
├─ Mensaje: Email del proveedor con número de orden
├─ Información: Stock disponible, plazo real
└─ Estado en sistema: Se actualiza a "Confirmada"

EJEMPLO DE RESPUESTA:
"Confirmamos disponibilidad de todos los productos.
Entrega: 10/04/2026
Nuestro número: REF-98765
Atentamente,
Distribuidora Centro"
```

### Si Proveedor NO Puede

```
ESCENARIOS:

1. Producto sin stock:
   └─ Opción: Reducir cantidad o buscar otro proveedor

2. Plazo más largo:
   └─ Opción: Aceptar o cancelar orden

3. Precio cambió:
   └─ Opción: Renegociar o usar otro proveedor
```

---

## 📦 Paso 5: Recibir Mercancía

### Notificación de Llegada

```
Sistema notifica:
├─ "Mercancía llegando" (1-2 días antes)
├─ Número de seguimiento (si aplica)
├─ Fecha estimada de entrega
└─ Contacto del transportista
```

### Proceso de Recepción

**Ruta:** Comprador → Recepciones → "+ Nueva Recepción"

```
1. Seleccionar OC: #OC-001234
   └─ Sistema carga productos esperados

2. BODEGUERO VERIFICA:
   ├─ Cantidad: 100 Filtros → Recibidos: 100 ✓
   ├─ Cantidad: 50 Aceites → Recibidos: 50 ✓
   ├─ Cantidad: 30 Bujías → Recibidos: 30 ✓
   └─ Condición: Ningún daño ✓

3. Hacer clic: [Confirmar Recepción]

4. Sistema automáticamente:
   ├─ Actualiza stock
   ├─ Descuenta de OC pendiente
   ├─ Genera comprobante de recepción
   └─ Registra en cuentas por pagar
```

### Discrepancias en Recepción

Si hay diferencia:

```
EJEMPLO:
OC: 100 Filtros Aire
Recibido: 98 Filtros Aire
Faltantes: 2

PROCEDIMIENTO:
1. Registrar discrepancia
2. Fotografiar faltantes
3. Contactar proveedor:
   └─ "Faltaron 2 unidades en OC-001234"
4. Proveedor options:
   ├─ Enviar faltantes
   ├─ Ofrecer descuento
   └─ Validar si fueron cargados en viaje
```

---

## ✅ Paso 6: Verificación de Stock

### Actualización Automática

```
Una vez confirmada recepción:

STOCK EN SISTEMA:
├─ Filtro Aire: 45 + 100 = 145 unidades
├─ Aceite 10W-40: 30 + 50 = 80 botellas
├─ Bujías: 20 + 30 = 50 cajas
└─ Stock total: Actualizado ✓

ALERTAS CANCELADAS:
├─ "Stock bajo de Filtro" → Cancelada
├─ "Reorden necesario" → Cumplido
└─ Dashboard se actualiza
```

### Ajuste de Precios

Si el costo cambió:

```
ANTES:
├─ Costo: $18.50
├─ Precio venta: $25.50
└─ Margen: 37.8%

AHORA (si bajó a $17.80):
├─ Costo: $17.80
├─ Opciones:
│  ├─ Mantener $25.50 (margen sube a 43%)
│  ├─ Bajar a $24.50 (margen sigue en 37%)
│  └─ Subir a $26.50 (margen sube a 49%)
└─ Comprador recomienda acción
```

---

## 💰 Paso 7: Pago al Proveedor

### Registro en Cuentas por Pagar

```
Sistema automáticamente crea:
├─ Factura #F-12345 (del proveedor)
├─ Monto: $4,819.50
├─ Fecha vencimiento: 08/05/2026 (30 días)
├─ Estado: "Pendiente de pago"
└─ Visible en: Cuentas por Pagar
```

### Programar Pago

**Ruta:** Comprador → Cuentas por Pagar → Búsqueda: OC-001234

```
FACTURA VISTA:
├─ Proveedor: Distribuidora Centro
├─ Monto: $4,819.50
├─ Vencimiento: 08/05/2026
├─ Días restantes: 30
└─ Estado: Pendiente

OPCIONES:
├─ [Pagar Ahora] (si hay descuento por pronto pago)
├─ [Programar Pago] (seleccionar fecha)
└─ [Diferir] (si no hay presupuesto)
```

### Realizar Pago

```
1. Hacer clic: [Pagar]
2. Seleccionar método:
   ├─ Transferencia bancaria
   ├─ Cheque
   ├─ Efectivo
   └─ Tarjeta de crédito

3. TRANSFERENCIA (ejemplo):
   ├─ Banco: Scotiabank
   ├─ Cuenta: 1234567890
   ├─ Monto: $4,819.50
   ├─ Concepto: "Pago OC-001234"
   └─ Referencia: OC-001234

4. Realizar transferencia en banco
5. En sistema: Registrar número de comprobante
6. [Confirmar Pago]

RESULTADO:
├─ Estado: "Pagada"
├─ Fecha de pago registrada
├─ Comprobante guardado
└─ Factura cerrada
```

---

## 📊 Análisis de Compras

### Ver Histórico de OC

**Ruta:** Comprador → Órdenes de Compra → Historial

```
FILTROS:
├─ Todas las órdenes
├─ Por proveedor
├─ Por rango de fechas
├─ Por estado (enviadas, recibidas, pagadas)
└─ Por rango de monto
```

### Análisis de Gasto

```
ESTE MES:
├─ Total gastado: $145,320
├─ Proveedor principal: Dist. Centro (58%)
├─ Promedio por OC: $4,800
├─ OC mayor: $12,450
├─ OC menor: $890
└─ Plazo promedio: 28 días
```

---

## 🆘 Problemas Comunes

### "Proveedor atrasado"

```
ACCIÓN:
1. Contactar proveedor por teléfono
2. Preguntar por mercancía
3. Si es urgente: Compra rápida con otro proveedor
4. Registrar incidente en sistema
5. Considerar cambiar proveedor
```

### "Mercancía llega dañada"

```
PROCEDIMIENTO:
1. Documentar con fotos
2. Registrar en recepción como "dañado"
3. Contactar proveedor inmediatamente
4. Solicitar:
   ├─ Reemplazo
   ├─ Devolución con reembolso
   └─ Descuento por daño
5. Guardar evidencia
```

### "Precio de OC subió al recibir"

```
NO ACEPTAR:
1. Verificar que el precio en OC es correcto
2. Si proveedor cambió precio: rechazar mercancía
3. Contactar a gerencia
4. Renegociar o usar otro proveedor
5. Importante: no pagar más de lo acordado
```

---

## ⏱️ Tiempos de Referencia

```
Revisar alertas de stock:         5-10 min
Crear orden de compra:            10-15 min
Enviar a proveedor:               2-5 min
Confirmación del proveedor:       1-2 horas (típico)
Espera entrega:                   1-3 días (típico)
Recepción y verificación:         15-30 min
Pago:                             5-10 min
────────────────────────────────
TIEMPO TOTAL TÍPICO:              2-4 días
```

---

## 📋 Checklist de Orden de Compra

**ANTES DE CREAR:**
- [ ] Necesidad de stock confirmada
- [ ] Proveedor está disponible
- [ ] Presupuesto disponible
- [ ] Plazo es factible

**AL CREAR:**
- [ ] Productos correctos
- [ ] Cantidades verificadas
- [ ] Precios validados
- [ ] Términos de pago acordados

**DESPUÉS DE ENVIAR:**
- [ ] Confirmación recibida del proveedor
- [ ] Número de orden registrado
- [ ] Seguimiento configurado
- [ ] Equipo notificado

**AL RECIBIR:**
- [ ] Cantidad coincide
- [ ] Calidad verificada
- [ ] Fecha vencimiento anotada
- [ ] Stock actualizado en sistema

**AL PAGAR:**
- [ ] Factura verificada
- [ ] Monto correcto
- [ ] Comprobante guardado
- [ ] Cuentas por pagar actualizado

---

## 🔗 Recursos Relacionados

- [Manual del Comprador](/manual-usuario/roles/comprador) - Más detalles
- [Módulo de Compras](/manual-usuario/modulos/compras) - Guía completa
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
