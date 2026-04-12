# 💳 Manual del Cajero - HermeX POS

Guía completa para cajeros. El rol de Cajero es fundamental para operar el Punto de Venta (POS).

## 🎯 Rol del Cajero

**Responsabilidades principales:**
- ✅ Operar el Punto de Venta (POS)
- ✅ Procesar ventas y cobros
- ✅ Manejar efectivo
- ✅ Imprimir tickets
- ✅ Procesar devoluciones
- ✅ Cierre de caja diario
- ✅ Reportar discrepancias

**Horario:** Definido por sucursal (típicamente 8-20 horas)

---

## 🚀 Inicio de Turno

### Abrir Caja

Cada cajero debe abrir su caja al inicio del turno:

1. **Acceder a la aplicación**
   - Abrir navegador
   - Ir a: http://localhost:3000 (o URL del servidor)
   - Ingresar credenciales

2. **Ir a:** POS → Mis Cajas → Abrir Caja

3. **Ingresar efectivo inicial:**
   ```
   Efectivo Base: $ [__]
   (cantidad que existe en la caja al inicio)
   ```

4. **Confirmar apertura**
   - Sistema registra hora y cajero
   - Caja ahora está "Abierta"
   - Puedes comenzar a vender

### Verificar Estado de Caja

En cualquier momento ver:
- Estado actual (Abierta, Cerrada, Suspendida)
- Efectivo actual
- Ventas hasta el momento
- Último movimiento

**Ruta:** POS → Mis Cajas → [Seleccionar caja]

---

## 🛒 Venta de Mostrador (Operación Principal)

### Paso 1: Iniciar Nueva Venta

1. **Ir a:** POS → Punto de Venta
2. Interface del POS se carga:
   ```
   ┌────────────────────────────────────────┐
   │  HermeX POS - Punto de Venta           │
   ├────────────────────────────────────────┤
   │                                         │
   │  [Buscar producto]                     │
   │  ┌──────────────────────────────────┐  │
   │  │ PRODUCTOS               │ CARRITO│  │
   │  ├──────────────────────────────────┤  │
   │  │ • Producto 1    $10.00  │ Total │  │
   │  │ • Producto 2    $25.50  │ $0.00 │  │
   │  │ • Producto 3    $8.75   │       │  │
   │  └──────────────────────────────────┘  │
   │                                         │
   │  [Procesar Pago] [Cancelar] [Limpiar]  │
   │                                         │
   └────────────────────────────────────────┘
   ```

### Paso 2: Buscar Producto

**Método 1: Búsqueda por Nombre**
1. Hacer clic en [Buscar producto]
2. Escribir nombre del producto (ej: "filtro")
3. Presionar Enter o esperar resultados
4. Hacer clic en el producto deseado

**Método 2: Escanear Código de Barras**
1. Hacer clic en campo de búsqueda
2. Usar lector de código de barras
3. Scanear código del producto
4. Producto se agrega automáticamente

**Método 3: Navegación por Categoría**
1. Hacer clic en categoría (en panel izquierdo)
2. Ver productos de esa categoría
3. Hacer clic en producto

### Paso 3: Agregar al Carrito

El producto se agrega automáticamente. En el carrito ves:

```
CARRITO
─────────────────────
Producto           Cant. Precio   Subtotal
─────────────────────
Filtro Aire         1    $25.50   $25.50
Aceite Motor        2    $45.00   $90.00
─────────────────────
                          Subtotal: $115.50
                          Impuesto:  $21.94
                          ──────────────────
                          TOTAL:     $137.44
```

### Paso 4: Ajustar Cantidad

Si necesitas cambiar cantidad:

1. En carrito, hacer clic en el producto
2. Cambiar cantidad: [- 1 +]
3. O escribe cantidad directamente
4. El subtotal se actualiza automáticamente

### Paso 5: Aplicar Descuento (si es necesario)

**Descuento por Producto:**
1. Seleccionar producto en carrito
2. Hacer clic en "Aplicar Descuento"
3. Ingresar:
   - % descuento (ej: 10)
   - Motivo (ej: "promoción", "cliente frecuente")
4. Guardar

**Descuento General:**
1. Hacer clic en "Descuento General"
2. Ingresar % o monto
3. Requiere aprobación de supervisor

⚠️ **Nota:** Descuentos > 15% requieren aprobación de gerente

### Paso 6: Procesar Pago

1. **Hacer clic:** [Procesar Pago]

2. **Seleccionar método de pago:**
   ```
   ☑ Efectivo
   ☑ Tarjeta de Crédito
   ☑ Tarjeta de Débito
   ☑ Transferencia Bancaria
   ☑ Crédito de Tienda
   ☑ Combinado (múltiples métodos)
   ```

#### Pago en Efectivo

1. Seleccionar "Efectivo"
2. Ingresar monto recibido: [__]
3. Sistema calcula cambio:
   ```
   Total:      $137.44
   Recibido:   $150.00
   ──────────────────
   Cambio:     $ 12.56
   ```
4. **Confirmar pago**
5. Sistema imprime ticket

#### Pago con Tarjeta

1. Seleccionar "Tarjeta de Crédito" o "Débito"
2. Sistema indica: "Pasar tarjeta en terminal"
3. Cliente pasa tarjeta
4. Esperar confirmación de terminal
5. Si es aprobado: ✅ transacción completada
6. Si es rechazado: ❌ intentar otro método

#### Crédito de Tienda

1. Seleccionar "Crédito de Tienda"
2. Sistema valida:
   - ¿Tiene límite disponible?
   - ¿Cuál es su balance actual?
3. Si es válido: aplicar
4. Cliente queda con saldo deudor

#### Pago Combinado

Cuando cliente paga con múltiples métodos:

1. Seleccionar "Combinado"
2. Para primer método:
   - Seleccionar método
   - Ingresar monto
3. Repetir para cada método adicional
4. Confirmar total coincide

Ejemplo:
```
Efectivo:        $100.00
Tarjeta:         $ 37.44
────────────────────────
Total:           $137.44 ✅
```

### Paso 7: Ticket y Cierre

**Automáticamente:**
1. Se imprime ticket
2. Se registra venta en sistema
3. Se descuenta del inventario
4. Se actualiza caja

**Información del Ticket:**
```
╔══════════════════════════════╗
║   TIENDA LA REFACCIÓN        ║
║   Sucursal Centro            ║
║══════════════════════════════║
║
║ Producto              Can. Precio Total
║ ────────────────────────────────────
║ Filtro Aire             1   25.50  25.50
║ Aceite Motor            2   45.00  90.00
║ ────────────────────────────────────
║ Subtotal:                          115.50
║ Impuesto (IVA 19%):                 21.94
║ ════════════════════════════════════
║ TOTAL:                            137.44
║
║ Método Pago: Efectivo
║ Cambio:                             12.56
║
║ Cajero: Juan Pérez
║ Folio:  #00001234
║ Fecha:  08/04/2026 14:23
║
║ ¡Gracias por su compra!
║ Vuelva pronto
║
╚══════════════════════════════╝
```

---

## ↩️ Devoluciones

### Procesar Devolución

Si cliente devuelve producto:

1. **Ir a:** POS → Devoluciones
2. **Ingresar número de ticket** del que se devuelve
3. Sistema carga venta original
4. **Seleccionar productos** a devolver
5. **Motivo de devolución:**
   - Producto defectuoso
   - Cliente cambió de opinión
   - Producto incorrecto
   - Otro (especificar)
6. **Método de reembolso:**
   - Crédito de tienda
   - Efectivo
   - Tarjeta (retorno al mismo medio)
7. **Confirmar**
8. Sistema imprime comprobante de devolución

### Devolución Parcial

Si devuelve solo ALGUNOS productos:

1. En la pantalla de devolución
2. Seleccionar SOLO los productos a devolver
3. El resto permanece vendido
4. Sistema calcula nuevo total
5. Procesar reembolso de diferencia

---

## 💰 Cola de Caja (Cashier Queue)

Cuando hay varios clientes esperando:

### Suspender Venta Actual

1. En POS, hacer clic: [Suspender Venta]
2. Sistema guarda carrito actual
3. Limpiar pantalla para siguiente cliente

### Retomar Venta Suspendida

1. **Ir a:** POS → Cola de Espera
2. Ver lista de ventas suspendidas
3. Hacer clic en la que necesitas retomar
4. Carrito se carga nuevamente
5. Continuar procesamiento

---

## 🔐 Cierre de Caja

**⚠️ TAREA CRÍTICA** - Realizar al final del turno

### Procedimiento de Cierre

1. **Ir a:** POS → Mis Cajas → Cerrar Caja

2. **Sistema muestra resumen:**
   ```
   RESUMEN DEL TURNO
   ─────────────────────────
   Hora Apertura: 08:00
   Hora Cierre:   20:15
   
   VENTAS:
   ├─ Total ventas: $12,450.50
   ├─ Efectivo: $8,200.00
   ├─ Tarjeta Crédito: $3,050.50
   ├─ Tarjeta Débito: $1,200.00
   ├─ Crédito: $ 0.00
   └─ Devoluciones: $ 0.00
   
   CAJERO:
   ├─ Efectivo Base: $ 500.00
   ├─ + Ventas Efectivo: $8,200.00
   ├─ - Devoluciones: $ 0.00
   └─ = Efectivo Esperado: $8,700.00
   ```

3. **Contar dinero en caja**
   - Separar billetes por denominación
   - Contar monedas
   - Total efectivo

4. **Ingresar cantidad contada:**
   ```
   Efectivo Contado: $ [__]
   ```

5. **Reconciliación automática:**
   ```
   Efectivo Esperado: $8,700.00
   Efectivo Contado:  $8,700.00
   Diferencia:        $    0.00 ✅
   ```

### Casos de Discrepancias

**Si hay diferencia:**

```
Efectivo Esperado: $8,700.00
Efectivo Contado:  $8,695.50
Diferencia:        $  -4.50 ❌

[Motivo de diferencia]
☑ Vuelto insuficiente
☑ Transacción no registrada
☑ Error de cajero
☑ Otro

Descripción: [__________________]
```

Reportar al gerente o administrador.

### Finalizar Cierre

1. Hacer clic: [Confirmar Cierre]
2. Caja se cierra automáticamente
3. Se genera reporte "Cierre Z"
4. **Guardar/Archivar reporte**
5. Realizar respaldo diario de caja

---

## 📊 Consultas Rápidas

### Ver Inventario

**Ruta:** POS → Consultar Stock

Ver cantidad disponible de cualquier producto:
1. Escribir nombre del producto
2. Sistema muestra:
   - Stock actual
   - Cantidad vendida hoy
   - Precio base
   - Stock mínimo

### Ver Crédito de Cliente

**Ruta:** POS → Clientes → Buscar Cliente

Ver información de cliente:
- Nombre y contacto
- Crédito de tienda disponible
- Balance actual
- Historial de compras recientes

### Imprimir Informe Rápido

**Ruta:** POS → Reportes → [Turno Actual]

Imprimir:
- Resumen de ventas
- Productos más vendidos
- Clientes principales
- Comparativa vs día anterior

---

## ⌨️ Atajos de Teclado

| Atajo | Función |
|-------|---------|
| `F1` | Buscar producto |
| `F2` | Abrir/Cerrar caja |
| `F3` | Procesar pago |
| `F4` | Aplicar descuento |
| `F5` | Cola de espera |
| `F6` | Devoluciones |
| `Esc` | Limpiar/Cancelar |
| `Ctrl+P` | Imprimir |
| `Ctrl+Z` | Deshacer última acción |

---

## 🆘 Problemas Comunes

### "Producto no encontrado"

- Verificar ortografía
- Probar por código de barras
- Consultar con bodeguero si existe
- Verificar que producto esté habilitado

### "No tengo autorización para este descuento"

- Descuentos > 15% requieren supervisor
- Llamar a gerente para aprobación
- Ingresar código de supervisor

### "La impresora no funciona"

- Verificar conexión
- Papel terminado
- Contactar a IT
- Mientras: usar impresora alternativa

### "La tarjeta fue rechazada"

- Pedir cliente que verifique tarjeta
- Probar con otra tarjeta
- Pedir DNI para verificación
- Ofrecer pago en efectivo o crédito

### "El sistema está lento"

- Verificar conexión a internet
- Recargar página (F5)
- Reiniciar navegador
- Contactar a IT

---

## 📋 Checklist Diario

**Al inicio del turno:**
- [ ] Abrir caja
- [ ] Verificar efectivo inicial
- [ ] Probar impresora
- [ ] Revisar scanner de códigos

**Durante el turno:**
- [ ] Mantener caja organizada
- [ ] Reportar problemas inmediatamente
- [ ] No prestar a caja de otro cajero
- [ ] Registrar todas las devoluciones

**Al final del turno:**
- [ ] Hacer cierre de caja
- [ ] Arqueo de efectivo
- [ ] Generar reporte Z
- [ ] Archivar recibos y reportes
- [ ] Informar diferencias al gerente

---

## 📞 Soporte

**Problemas técnicos:**
1. Llamar al número de soporte (escrito en el mostrador)
2. Chat en vivo (botón abajo a la derecha)
3. Email: soporte@hermexpos.app

**Procedimientos:**
- Preguntar al gerente/supervisor
- Consultar esta guía
- Pedir ayuda a cajero más experimentado

---

## 🔗 Recursos Relacionados

- [Flujo de Venta Completo](/manual-usuario/flujos/venta-mostrador)
- [Solución de Problemas](/manual-usuario/troubleshooting)
- [Manual del Gerente](/manual-usuario/roles/gerente) - Para autorizar descuentos

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
