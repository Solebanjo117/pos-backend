# 🤝 Manual del Asistente - HermeX POS

Guía para asistentes de inventario. El asistente apoya operaciones de bodega y tareas básicas de inventario.

## 🎯 Rol del Asistente

**Responsabilidades:**
- ✅ Movimientos de inventario (entrada/salida)
- ✅ Transferencias entre sucursales
- ✅ Recuento físico de stock
- ✅ Registrar merma y daños
- ✅ Apoyo a bodeguero
- ✅ Preparación de pedidos
- ✅ Limpieza y organización de bodega

**Acceso:** Limitado a funciones de inventario

---

## 📊 Dashboard del Asistente

### Mis Tareas Hoy

```
┌─────────────────────────────────────────┐
│  Mi Dashboard - Asistente               │
├─────────────────────────────────────────┤
│                                          │
│  📋 TAREAS ASIGNADAS                     │
│  ├─ Pedidos para preparar: 5            │
│  ├─ Transferencias pendientes: 2        │
│  ├─ Recuento físico: Pasillo A          │
│  └─ Revisión de fechas de vencimiento   │
│                                          │
│  📦 INVENTARIO HOY                       │
│  ├─ Movimientos registrados: 12         │
│  ├─ Stock agregado: 450 unidades        │
│  ├─ Stock retirado: 320 unidades        │
│  └─ Merma reportada: 8 unidades         │
│                                          │
│  ⚠️ ALERTAS                              │
│  ├─ Productos vencidos: 0               │
│  ├─ Productos por vencer (7 días): 2    │
│  └─ Stock crítico: 3 productos          │
│                                          │
└─────────────────────────────────────────┘
```

---

## 📥 Registrar Entrada de Mercancía

### Paso 1: Iniciar Entrada

**Ruta:** Asistente → Inventario → Movimientos → "+ Entrada"

Información:
```
TIPO DE ENTRADA:
├─ Compra (recepción de orden de compra)
├─ Devolución de cliente
├─ Ajuste positivo (corrección)
└─ Otra (especificar)

DETALLES:
├─ Documento referencia (OC, factura, etc.)
├─ Fecha
├─ Observaciones
```

### Paso 2: Agregar Productos

1. **Buscar producto** (por nombre o código)
2. **Ingresar cantidad**
3. **Número de lote** (si aplica)
4. **Fecha de vencimiento** (si aplica)
5. Repetir para otros productos

**Ejemplo:**
```
Filtro Aire         : 100 unidades
Aceite 10W-40       : 50 unidades
Bujías (6 pack)     : 30 unidades
```

### Paso 3: Validar y Confirmar

Sistema verifica:
- ✅ Cantidad válida
- ✅ Producto existe
- ✅ Espacio en bodega

Hacer clic: [Confirmar Entrada]
- Se actualiza stock automáticamente
- Se registra en historial
- Se notifica a supervisor si es grandes cantidades

---

## 📤 Registrar Salida de Mercancía

### Paso 1: Iniciar Salida

**Ruta:** Asistente → Inventario → Movimientos → "+ Salida"

Tipos de salida:
```
TIPO:
├─ Venta (cliente compra)
├─ Devolución a proveedor
├─ Ajuste negativo (corrección)
├─ Rotura/Daño
└─ Muestra/Regalo
```

### Paso 2: Agregar Productos

Similar a entrada pero el stock disminuye.

**Casos especiales:**

**Daño:** Si producto está dañado
```
Producto: Filtro Aire
Cantidad: 3 unidades
Motivo: Rotura en embalaje
Observación: No se puede reparar
```

**Vencimiento:** Si producto venció
```
Producto: Aceite Motor
Cantidad: 10 botellas
Motivo: Stock vencido
Fecha vencimiento: 15/03/2026
```

### Paso 3: Confirmar

[Confirmar Salida]
- Stock se descuenta automáticamente
- Se registra el motivo
- Si es daño: se reporta a gerencia

---

## 🔄 Transferencias Entre Sucursales

### Enviar Productos a Otra Sucursal

**Ruta:** Asistente → Inventario → Transferencias → "+ Nueva Transferencia"

Información:
```
DE SUCURSAL: [Esta sucursal - automático]
HACIA SUCURSAL: [Seleccionar destino]

PRODUCTOS:
├─ Filtro Aire: 50 unidades
├─ Aceite: 30 unidades
└─ Bujías: 20 unidades

TRANSPORTE:
├─ Fecha de envío: [__]
├─ Transportista: [__]
├─ Referencia de envío: [__]
```

### Recibir Transferencia

En sucursal destino:

1. **Notificación:** "Transferencia de Sucursal Centro llegó"
2. [Recibir Transferencia]
3. **Verificar productos recibidos**
4. **Confirmar cantidades**
5. Si hay discrepancia: reportar diferencia
6. [Confirmar Recepción]

Stock se actualiza automáticamente en ambas sucursales.

---

## 📋 Recuento Físico (Inventario)

### Iniciar Recuento

**Ruta:** Asistente → Inventario → Recuento Físico → "+ Nuevo Recuento"

```
ÁREA A CONTAR:
├─ Pasillo A (Filtros)
├─ Pasillo B (Aceites)
├─ Pasillo C (Bujías)
├─ Almacén frío (Baterías)
└─ Estantes especiales

INFORMACIÓN:
├─ Fecha del recuento
├─ Responsable: [Tu nombre - automático]
├─ Supervisor: [Seleccionar]
```

### Proceso de Recuento

1. **Equipamiento:**
   - Libreta o tablet
   - Lápiz/stylus
   - Código de barras (opcional)

2. **Procedimiento:**
   - Ir producto por producto
   - Contar cantidad física
   - Anotar en sistema
   - Si hay diferencia: marcar

3. **Ejemplo de entrada:**
   ```
   Filtro Aire (SKU: FA-001)
   Ubicación: Pasillo A, Estante 2
   Sistema dice: 100 unidades
   Contaste: 98 unidades
   Diferencia: -2
   Motivo: Posible error anterior
   ```

### Finalizar Recuento

1. Hacer clic: [Finalizar Recuento]
2. Sistema compara:
   - Stock en sistema
   - Stock contado
   - Calcula diferencias
3. Genera reporte de discrepancias
4. Supervisor revisa y aprueba
5. Se actualiza sistema si corresponde

---

## ⏰ Gestión de Vencimientos

### Revisar Productos por Vencer

**Ruta:** Asistente → Inventario → Vencimientos

Ver productos organizados por:
- **Vencidos:** Hoy
- **Próximos 7 días:** Urgente
- **Próximos 30 días:** Advertencia
- **Próximos 90 días:** Información

### Registrar Producto Vencido

Si encuentras producto vencido:

1. Separar físicamente en bodega
2. **Ruta:** Asistente → Inventario → Movimientos
3. Crear "Salida" con motivo "Vencido"
4. Ingresar cantidad
5. Sistema descuenta automáticamente

---

## 🧹 Tareas de Bodega

### Organización Diaria

**Checklist:**

```
INICIO DE TURNO:
□ Revisar áreas de trabajo
□ Verificar iluminación
□ Revisar temperaturas (si aplica)
□ Ver alertas de productos vencidos

DURANTE TURNO:
□ Organizar productos por categoría
□ Limpiar derrames
□ Reportar daños observados
□ Mantener áreas de circulación limpias
□ Organizar productos recibidos

FIN DE TURNO:
□ Limpiar herramientas
□ Barrido de bodega
□ Dejar áreas ordenadas
□ Reportar problemas al supervisor
```

### Preparar Pedidos para Venta

Cuando hay pedidos:

1. **Recibir aviso:** "Pedido #XX listo para preparar"
2. **Recolectar productos:**
   - Buscar cada producto en bodega
   - Verificar cantidad y calidad
   - Marcar en lista del pedido

3. **Ejemplo de pedido:**
   ```
   PEDIDO #1234
   ├─ Filtro Aire: 5 ✓
   ├─ Aceite 10W-40: 10 ✓
   ├─ Bujías: 2 (solo 1 disponible)
   └─ Stock: Reportar falta al gerente
   ```

4. **Empacar:**
   - Usar materiales apropiados
   - Proteger productos frágiles
   - Etiquetar con número de pedido

5. **Entregar:** Llevar a caja o área de despacho

---

## 🆘 Reportar Problemas

### Problema: Producto Dañado

1. Separar físicamente
2. Fotografiar daño si es posible
3. **Reportar:**
   - Qué producto
   - Cantidad afectada
   - Tipo de daño
   - Posible causa

4. Supervisor determina:
   - ¿Devolver a proveedor?
   - ¿Vender con descuento?
   - ¿Descartar?

### Problema: Stock No Coincide

Si al contar encuentras diferencia:

1. **Recount:** Contar de nuevo
2. **Revisar sistema:** ¿Hay movimientos sin registrar?
3. **Reportar al supervisor:**
   - Cantidad que falta/sobra
   - Dónde fue encontrado
   - Posible causa

### Problema: Producto No Encontrado

Si un producto no está donde debería:

1. Buscar en otras ubicaciones
2. Revisar historial de movimientos
3. Preguntar a otros asistentes
4. Si no aparece: reportar como "extraviado"

---

## 📊 Acceso Limitado

Como Asistente, tienes acceso a:

✅ **Puedes:**
- Ver inventario actual
- Registrar movimientos básicos
- Participar en recuentos
- Ver productos por vencer
- Hacer transferencias (con aprobación)

❌ **No puedes:**
- Cambiar precios de productos
- Ver reportes financieros
- Crear órdenes de compra
- Autorizar descuentos
- Eliminar movimientos (solo supervisor)

---

## 📱 Consola Móvil (Opcional)

Si tu tienda tiene tablets:

1. **Acceder:** App HermeX en tablet
2. **Funciones disponibles:**
   - Registrar movimientos en tiempo real
   - Escanear códigos de barras
   - Consultar stock instantáneamente
   - Recibir tareas asignadas

3. **Ventajas:**
   - No necesitas ir a computadora
   - Stock se actualiza al instante
   - Menos errores de transcripción

---

## ⏰ Horarios Típicos

- **Turno matutino:** 07:00 - 14:00
- **Turno vespertino:** 14:00 - 21:00
- **Turno nocturno:** 21:00 - 06:00 (si aplica)

**Tareas por horario:**
- **Mañana:** Recibir mercancía, organizar
- **Tarde:** Preparar pedidos, actualizar stock
- **Noche:** Limpieza, recuentos especiales

---

## 💡 Tips Importantes

### Precisión Primero
- Siempre contar dos veces
- No apresurarse
- Usar lentes si es necesario
- Buena iluminación

### Seguridad
- Usar equipo de protección (guantes, etc.)
- Levantar correctamente (sin forzar espalda)
- Reportar peligros inmediatamente
- Mantener área de trabajo segura

### Comunicación
- Informar al supervisor sobre problemas
- Preguntar si tienes dudas
- Compartir información con otros asistentes
- Registrar todo en sistema

---

## 📋 Checklist Diario

**Inicio:**
- [ ] Revisar tareas asignadas
- [ ] Verificar áreas de trabajo
- [ ] Contar efectivo si es necesario
- [ ] Revisar alertas del sistema

**Durante:**
- [ ] Registrar movimientos correctamente
- [ ] Mantener bodega organizada
- [ ] Reportar problemas inmediatamente
- [ ] Responder a pedidos rápidamente

**Cierre:**
- [ ] Limpiar áreas de trabajo
- [ ] Reportar al supervisor
- [ ] Documentar tareas completadas
- [ ] Preparar para siguiente turno

---

## 📚 Recursos Relacionados

- [Manual del Comprador](/manual-usuario/roles/comprador) - Para entender órdenes de compra
- [Módulo de Inventario](/manual-usuario/modulos/inventario) - Guía completa de inventario
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ común

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
