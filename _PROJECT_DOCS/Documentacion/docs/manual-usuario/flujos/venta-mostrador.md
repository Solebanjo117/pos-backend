# 💰 Flujo de Venta de Mostrador

Guía paso a paso completa para procesar una venta directa desde el Punto de Venta (POS).

**Tiempo total:** 3-5 minutos por transacción  
**Roles:** Cajero, Vendedor  
**Requisitos:** POS abierto, cliente listo para comprar

---

## 📊 Diagrama del Flujo

```
┌─────────────────┐
│  CLIENTE LLEGA  │
└────────┬────────┘
         │
         ▼
┌──────────────────────┐
│ 1. ABRIR POS         │
│    (Si no está abierto)
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 2. BUSCAR PRODUCTOS  │
│    (Nombre/Código)   │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 3. AGREGAR AL CARRITO│
│    (Cantidad/Precio) │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 4. APLICAR DESCUENTO │
│    (Si corresponde)  │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 5. PROCESAR PAGO     │
│    (5 métodos)       │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 6. IMPRIMIR TICKET   │
│    (Recibo)          │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│ 7. CIERRE            │
│    (Actualizar stock)│
└──────────────────────┘
```

---

## 🔍 Requisitos Previos

Antes de iniciar:

✅ **Sistema:**
- POS está encendido
- Conexión a internet funciona
- Caja está abierta (turno iniciado)

✅ **Hardware:**
- Impresora conectada
- Lector de código de barras (si aplica)
- Terminal de tarjeta (si se aceptan tarjetas)

✅ **Datos:**
- Catálogo de productos actualizado
- Precios vigentes
- Cliente identificado

---

## 🚀 Paso 1: Abrir POS (Si es necesario)

### Primera vez del día

1. **Abrir navegador**
   - URL: http://localhost:3000 (o URL de tu servidor)

2. **Iniciar sesión**
   - Email: [Tu email de cajero]
   - Contraseña: [Tu contraseña]

3. **Ir a POS**
   - Hacer clic: "Punto de Venta" en menú
   - Interface del POS se carga

4. **Abrir caja** (Primera vez)
   - Sistema pregunta: "¿Abrir caja?"
   - Ingresar efectivo base: $500 (ejemplo)
   - [Confirmar Apertura]
   - ✅ Caja está abierta y lista

### Si ya estás en POS

Si la caja ya está abierta del cliente anterior:
- POS continúa activo
- Puedes empezar directamente con nuevo cliente
- Carrito anterior se limpia automáticamente

---

## 🔎 Paso 2: Buscar Productos

### Método 1: Búsqueda por Nombre

```
1. Hacer clic en: [Buscar producto]
2. Escribir nombre: "filtro"
3. Sistema muestra:
   ├─ Filtro Aire (FA-001)     $25.50
   ├─ Filtro Aceite (FA-002)   $18.50
   └─ Filtro Cabina (FA-003)   $22.00
4. Hacer clic en producto deseado
```

**Ejemplo de búsqueda:**
```
Cliente pide: "Un filtro de aire para un Toyota"
├─ Busca: "filtro aire"
├─ Sistema muestra opciones
├─ Cliente señala: "ese" (FA-001)
└─ [Clic] → Se agrega al carrito
```

### Método 2: Escanear Código de Barras

```
1. Hacer clic en: [Buscar producto] (o campo queda activo)
2. Usar lector: Escanear código
3. Sistema reconoce automáticamente
4. Producto se agrega al carrito
5. Cantidad: 1 (puedes cambiar después)
```

**Ventaja:** Más rápido, sin errores de escritura

### Método 3: Categorías

```
1. Panel izquierdo muestra categorías:
   ├─ Filtros
   ├─ Aceites
   ├─ Bujías
   └─ ...

2. Hacer clic en categoría
3. Ver productos de esa categoría
4. Hacer clic en producto
5. Se agrega al carrito
```

**Útil cuando:** Cliente no sabe nombre exacto

### Método 4: Búsqueda Rápida (Favoritos)

Si tienes productos que vuelven mucho:

```
- Filtro Aire (1 clic)
- Aceite 10W-40 (1 clic)
- Bujías Standard (1 clic)
```

Configurables en Configuración Personal

---

## 🛒 Paso 3: Agregar al Carrito

### Cantidad y Precio

Después de hacer clic en producto:

```
CARRITO
─────────────────────────────
Producto: Filtro Aire
Cantidad: [1] [+] [-]
Precio:   $25.50
Subtotal: $25.50
```

### Cambiar Cantidad

Si cliente quiere más de uno:

```
Opción 1: Hacer clic en [+] varias veces
Opción 2: Escribir cantidad: [5]
Opción 3: Si ya está en carrito, clic para editar

Resultado:
├─ Filtro Aire: 5 × $25.50 = $127.50
```

### Cambiar Precio (Si autorizado)

Si hay negociación de precio:

```
Hacer clic en producto en carrito
[Editar precio]
Precio nuevo: [_____]
Justificación: "Cliente mayorista" / "Descuento"
Guardar
```

**Nota:** Precios muy bajos requieren aprobación de supervisor

### Agregar Múltiples Productos

```
1. Filtro Aire: 5 unidades → $127.50
2. Hacer clic [Buscar producto]
3. Aceite 10W-40: 10 botellas → $450.00
4. Hacer clic [Buscar producto]
5. Bujías (6-pack): 2 cajas → $37.00

CARRITO ACTUAL:
├─ Filtro Aire (5)           $127.50
├─ Aceite 10W-40 (10)        $450.00
├─ Bujías 6-pack (2)         $ 37.00
├─────────────────────────────
├─ Subtotal:                 $614.50
├─ Impuesto (19%):           $116.76
└─ TOTAL:                    $731.26
```

---

## 💰 Paso 4: Aplicar Descuento (Si Corresponde)

### Descuento por Cantidad

Cliente compra mucho = descuento automático

```
Sistema sugiere:
"5 unidades ≥ 5 → Descuento automático 5%"

Nuevo precio:
├─ Subtotal: $614.50
├─ Descuento 5%: -$30.73
├─ Subtotal con desc: $583.77
├─ Impuesto: $110.92
└─ TOTAL: $694.69
```

### Descuento Manual

Si gerente autorizó:

```
Hacer clic: [Aplicar Descuento]

Tipo de descuento:
├─ % (porcentaje)
├─ $ (monto fijo)

Ingresar: 10%
Motivo: "Cliente frecuente"
Guardar
```

**Resultado:**
```
├─ Subtotal: $614.50
├─ Descuento 10%: -$61.45
├─ Subtotal con desc: $553.05
├─ Impuesto: $105.08
└─ TOTAL: $658.13
```

### Descuento Especial (>15%)

Si descuento es grande (>15%):

```
1. Intentar aplicar descuento > 15%
2. Sistema dice: "Requiere aprobación"
3. Notifica al gerente
4. Gerente revisa y aprueba/rechaza
5. Si aprueba: se aplica automáticamente
```

---

## 💳 Paso 5: Procesar Pago

### Método 1: Pago en Efectivo

```
1. Hacer clic: [Procesar Pago]
2. Seleccionar: "Efectivo"
3. Total a pagar: $658.13
4. Ingresar monto recibido: [_____]
   └─ Ejemplo: 700
5. Sistema calcula:
   ├─ Total: $658.13
   ├─ Recibido: $700.00
   └─ CAMBIO: $41.87
6. [Confirmar Pago]
7. ✅ Transacción completada
```

**Entregar cambio:** $41.87 (billetes grandes primero)

### Método 2: Pago con Tarjeta

```
1. Hacer clic: [Procesar Pago]
2. Seleccionar: "Tarjeta Crédito" o "Débito"
3. Sistema muestra: "Acercar/Pasar tarjeta"
4. Cliente pasa tarjeta en terminal
5. Terminal solicita PIN (cliente lo ingresa)
6. Esperar confirmación:
   ├─ ✅ Aprobada → Continuar
   └─ ❌ Rechazada → Ofrecer otro método
7. [Confirmar Pago]
```

**Si tarjeta es rechazada:**
```
- "Fondos insuficientes"
- "Tarjeta expirada"
- "Activar en banco"

Opciones:
├─ Intentar con otra tarjeta
├─ Pago combinado (tarjeta + efectivo)
└─ Crédito de tienda (si aplica)
```

### Método 3: Transferencia Bancaria

Para compras grandes o clientes corporativos:

```
1. Seleccionar: "Transferencia"
2. Sistema muestra:
   ├─ Banco: Scotiabank
   ├─ Cuenta: 1234567890
   ├─ Monto: $658.13
   ├─ Concepto: "Venta HermeX"
3. Cliente realiza transferencia desde su banco
4. Sistema verifica confirmación:
   ├─ Automática (si aplica) en 10-30 min
   └─ O manual (digitar confirmación)
5. [Confirmar Pago]
```

### Método 4: Crédito de Tienda

Cliente tiene saldo previo (confianza):

```
1. Seleccionar: "Crédito de Tienda"
2. Sistema verifica:
   ├─ ¿Tiene crédito disponible?
   ├─ ¿Límite es suficiente?
   └─ ¿Sin pagos vencidos?
3. Si todo es OK: aplicar crédito
4. Nuevo balance:
   ├─ Anterior: -$200 (debe)
   ├─ Esta compra: -$658.13
   └─ Nuevo balance: -$858.13
5. [Confirmar Pago]
```

### Método 5: Pago Combinado

Cliente paga con 2 métodos:

```
1. Seleccionar: "Combinado"
2. Primer método:
   ├─ Método: "Efectivo"
   ├─ Monto: $400
3. Segundo método:
   ├─ Método: "Tarjeta"
   ├─ Monto: $258.13 (resto)
4. Total: $658.13 ✅
5. [Confirmar Pago]
```

**Pasos:**
```
- Recibir efectivo primero
- Pasar tarjeta
- Entregar ambas formas
```

---

## 🖨️ Paso 6: Imprimir Ticket

Automáticamente después de confirmar pago:

```
╔══════════════════════════════╗
║   TIENDA LA REFACCIÓN        ║
║   Sucursal Centro            ║
║   Dirección: Calle 5 #123    ║
║══════════════════════════════║
║
║ TICKET #00001234
║ Fecha: 08/04/2026
║ Hora: 14:23
║
║ PRODUCTOS:
║ ────────────────────────────
║ Filtro Aire            5 x 25.50
║   Subtotal:                127.50
║ Aceite 10W-40         10 x 45.00
║   Subtotal:                450.00
║ Bujías 6-pack          2 x 18.50
║   Subtotal:                 37.00
║
║ ────────────────────────────
║ SUBTOTAL:                  614.50
║ Descuento (10%):           -61.45
║ ────────────────────────────
║ SUBTOTAL CON DESC:         553.05
║ Impuesto (19%):            105.08
║ ════════════════════════════════
║ TOTAL:                     658.13
║
║ MÉTODO DE PAGO: Tarjeta
║ CAJERO: Juan Pérez
║ CLIENTE: [Si es miembro]
║
║ ════════════════════════════════
║ ¡Gracias por su compra!
║ Vuelva pronto
║
║ Política de devoluciones:
║ Máximo 7 días con ticket
║
╚══════════════════════════════╝
```

### Problemas con Impresora

Si impresora no funciona:

```
1. Verificar conexión física
2. Papelpaper está cargado
3. Si sigue sin funcionar:
   ├─ Usar impresora alternativa
   ├─ Reimprimir desde: [Reimprime Ticket]
   ├─ O entregar cupón manual
4. Avisar a supervisor
```

---

## ✅ Paso 7: Cierre

### Automático

Sistema automáticamente:
- ✅ Registra venta en historial
- ✅ Descuenta del inventario
- ✅ Actualiza caja
- ✅ Envía datos a servidor
- ✅ Limpia carrito para próximo cliente

### Manual: Siguiente Cliente

```
1. Carrito se limpia automáticamente
2. Hacer clic en: [Nueva Venta]
3. O ir a búsqueda de nuevo producto
4. ¡Listo para siguiente cliente!
```

### Resumen de Caja

Al final del turno:

```
Caja está disponible en:
Menú → Mis Cajas → Cierre

Ver:
├─ Total vendido hoy
├─ Número de transacciones
├─ Método de pago más usado
├─ Efectivo en caja
└─ Próximo paso: Cierre formal
```

---

## 🆘 Problemas Comunes

### "Cliente cambió de opinión"

```
Si aún no pagó:
├─ Hacer clic en producto en carrito
├─ [X] Eliminar
└─ Limpiar carrito si es necesario

Si ya pagó → Ver flujo de DEVOLUCIONES
```

### "Producto no está disponible"

```
1. Sistema muestra: "Sin stock"
2. Opciones:
   ├─ Buscar producto alternativo
   ├─ Ofertar "Podemos traerlo mañana"
   ├─ Compra rápida (si es cliente frecuente)
   └─ Registrar interés para seguimiento
```

### "Precio en sistema está mal"

```
1. No vender a precio incorrecto
2. Hacer clic: [Reportar problema]
3. Avisar a supervisor
4. Supervisor actualiza precio
5. Reintentar venta
```

### "La tarjeta no funciona"

```
1. Intenta de nuevo
2. Si rechaza: "Contacte su banco"
3. Ofrecer:
   ├─ Otra tarjeta
   ├─ Efectivo
   ├─ Crédito de tienda
   └─ Volver después
```

---

## ⏱️ Tiempos de Referencia

```
Búsqueda de productos:           30-60 seg
Agregar productos al carrito:    5-15 seg c/u
Aplicar descuento (si aplica):   30 seg
Procesar pago:
  ├─ Efectivo:                   30 seg
  ├─ Tarjeta:                    60-90 seg
  └─ Transferencia:              Variable
Imprimir ticket:                 15 seg
─────────────────────────────────
TOTAL POR TRANSACCIÓN:           3-5 minutos
```

---

## 📋 Checklist de Venta

- [ ] Cliente listo para comprar
- [ ] Productos correctos en carrito
- [ ] Cantidades verificadas
- [ ] Descuentos aplicados (si corresponde)
- [ ] Método de pago seleccionado
- [ ] Pago procesado exitosamente
- [ ] Ticket impreso y entregado
- [ ] Cliente satisfecho
- [ ] Carrito limpiado para próximo cliente

---

## 🔗 Recursos Relacionados

- [Manual del Cajero](/manual-usuario/roles/cajero) - Más detalles de operación POS
- [Flujo de Devoluciones](/manual-usuario/flujos/devolucion) - Cómo procesar devoluciones
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ común

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
