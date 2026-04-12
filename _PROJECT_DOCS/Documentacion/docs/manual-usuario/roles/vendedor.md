# 🛒 Manual del Vendedor - HermeX POS

Guía para vendedores de tienda. El vendedor crea cotizaciones, gestiona clientes y convierte ventas.

## 🎯 Rol del Vendedor

**Responsabilidades:**
- ✅ Crear cotizaciones
- ✅ Gestionar clientes
- ✅ Seguimiento de cotizaciones
- ✅ Convertir cotizaciones en ventas
- ✅ Reportar obstáculos a gerencia
- ✅ Mantener relaciones con clientes

**Comisión:** Típicamente 2-5% de ventas realizadas

---

## 📊 Dashboard del Vendedor

### Mi Desempeño Hoy

```
┌─────────────────────────────────────────┐
│  Mi Dashboard - Juan Pérez              │
├─────────────────────────────────────────┤
│                                          │
│  💰 HOY                                  │
│  ├─ Cotizaciones Creadas: 8             │
│  ├─ Ventas Convertidas: 5               │
│  ├─ Monto Vendido: $4,250.50            │
│  └─ Comisión Hoy: $212.53               │
│                                          │
│  📈 ESTA SEMANA                          │
│  ├─ Cotizaciones: 42                    │
│  ├─ Conversión: 65%                     │
│  ├─ Monto: $18,920                      │
│  └─ Comisión: $945 (proyectada)         │
│                                          │
│  🎯 CLIENTES PENDIENTES                  │
│  ├─ Por contactar: 3                    │
│  ├─ En seguimiento: 8                   │
│  └─ Listos para cerrar: 2               │
│                                          │
└─────────────────────────────────────────┘
```

---

## 💬 Crear Cotización

### Paso 1: Iniciar Cotización

**Ruta:** Vendedor → Cotizaciones → "+ Nueva Cotización"

Información del cliente:
```
Cliente: [Buscar en BD]
├─ Si es nuevo:
│  ├─ Nombre completo *
│  ├─ Email
│  ├─ Teléfono
│  ├─ Dirección
│  └─ RUT/RFC
└─ Si es existente:
   └─ Seleccionar de lista
```

### Paso 2: Agregar Productos

1. **Buscar producto:**
   - Por nombre (ej: "filtro")
   - Por código/SKU
   - Por categoría

2. **Agregar al carrito de cotización:**
   - Seleccionar producto
   - Ingresar cantidad
   - Sistema calcula subtotal

3. **Ajustar precio si es necesario:**
   - Precio unitario: [__] (puede variar por cantidad)
   - Sistema sugiere descuento por volumen automáticamente

4. **Repetir hasta completar cotización**

### Paso 3: Validaciones Automáticas

El sistema verifica:
- ✅ ¿Producto disponible?
- ✅ ¿Precio es competitivo?
- ✅ ¿Cliente tiene crédito disponible?
- ✅ ¿Cantidad solicitada es razonable?

**Alertas posibles:**
- ⚠️ Stock bajo (solo X unidades)
- 💡 Sugerencia: este cliente compraba Y antes
- ⛔ Este cliente tiene pago vencido

### Paso 4: Resumen de Cotización

```
COTIZACIÓN #CO-0001234
Cliente: Refaccionista Juan García
Fecha: 08/04/2026

Productos:
┌────────────────────────────────────┐
│ Descripción      Cant Precio Total │
├────────────────────────────────────┤
│ Filtro Aire       5    25.50  127.50
│ Aceite 10W-40     10   45.00  450.00
│ Bujías (6 un)     2    18.50   37.00
├────────────────────────────────────┤
│ Subtotal:                   614.50 │
│ Descuento (10%):            -61.45 │
│ Impuesto (19%):             105.10 │
│ ───────────────────────────────────│
│ TOTAL:                      658.15 │
└────────────────────────────────────┘

Plazo de validez: 7 días (hasta 15/04/2026)
```

### Paso 5: Guardar Cotización

1. Hacer clic: [Guardar Cotización]
2. Sistema asigna número único (CO-001234)
3. Cotización entra en estado "Borrador"
4. Puedes seguir editando si es necesario

---

## 📧 Enviar Cotización

### Enviar por Email

1. **Seleccionar cotización**
2. Hacer clic: [Enviar Email]
3. Se abre editor:
   ```
   De: Mi Email (auto)
   Para: Cliente Email
   Asunto: Cotización HermeX #CO-001234
   Mensaje: [Personalizar si deseas]
   
   [Adjuntar PDF] [Vista previa]
   ```
4. **Personalizar mensaje (opcional):**
   ```
   Estimado Juan,
   
   Le enviamos cotización para su revisión.
   Válida hasta el 15 de abril.
   Cualquier consulta me contacta al [XXXX]
   
   Saludos,
   [Mi Nombre]
   ```
5. [Enviar]

**Sistema registra:**
- Fecha de envío
- Email del cliente
- PDF adjunto

### Enviar por WhatsApp

Si cliente tiene WhatsApp:

1. Hacer clic: [Enviar WhatsApp]
2. Se genera:
   - Número cotización
   - Resumen de productos
   - Total y plazo
   - Link a PDF (si está disponible)
3. Abre WhatsApp con message pre-cargado
4. Enviar

### Enviar PDF Impreso

Para clientes que prefieren papel:

1. [Descargar PDF]
2. Imprimir
3. Entregar personalmente
4. Registrar en sistema: "Impreso y entregado"

---

## 🔄 Seguimiento de Cotizaciones

### Ver Mis Cotizaciones

**Ruta:** Vendedor → Mis Cotizaciones

Filtrar por estado:
```
ESTADO DE COTIZACIÓN:
├─ Borradores (3) - sin enviar
├─ Enviadas (8) - esperando respuesta
├─ Aceptadas (5) - listas para procesar
├─ Rechazadas (2) - cliente no aceptó
└─ Vencidas (1) - plazo expiró
```

### Seguimiento Automático

El sistema te notifica:

**Por Email:**
- Cuando cliente dice "sí" (acepta)
- Cuando cliente dice "no" (rechaza)
- Antes de que expire (recordatorio)

**En Navbar:**
- Contador de cotizaciones aceptadas
- Contador de cotizaciones por vencer

### Contactar Cliente

Si cotización está en "Enviadas":

1. Hacer clic: [Contactar Cliente]
2. Opciones:
   - Enviar reminder por email
   - Enviar mensaje por WhatsApp
   - Registrar llamada telefónica
   - Cambiar fecha de seguimiento

---

## ✅ Convertir Cotización en Venta

Cuando cliente acepta cotización:

### Opción 1: Cliente Acepta Directamente

1. Sistema notifica: "Cotización #XX aceptada por cliente"
2. Cotización cambia a estado "Aceptada"
3. Hacer clic: [Procesar Venta]
4. Seleccionar:
   - Método de pago
   - Fecha de entrega
   - Dirección de envío (si aplica)
5. [Confirmar Venta]
6. Se genera Orden de Venta
7. Se descuenta del inventario
8. Se registra comisión para el vendedor

### Opción 2: Cliente Paga en POS

Cliente lleva cotización a caja:

1. Cajero escanea código de cotización
2. Sistema carga productos de cotización
3. Cajero procesa el pago
4. Venta se registra automáticamente
5. Sistema vincula venta a tu comisión

### Opción 3: Enviar a Depósito

Para ventas grandes que necesitan preparación:

1. **Cotización en estado "Aceptada"**
2. Hacer clic: [Enviar a Depósito]
3. Bodeguero recibe orden:
   - Preparar productos
   - Verificar cantidades
   - Empacar

4. Cuando está listo, bodeguero marca: [Listo para Retirar]
5. Cliente puede retirar o se envía

---

## 👥 Gestión de Clientes

### Crear Cliente Nuevo

**Ruta:** Vendedor → Clientes → "+ Nuevo Cliente"

Información requerida:
```
DATOS BÁSICOS:
├─ Nombre completo *
├─ Tipo: Persona / Empresa
├─ Email
├─ Teléfono
├─ RUT/RFC

DIRECCIÓN:
├─ Dirección comercial
├─ Ciudad
├─ Región/Estado

INFORMACIÓN COMERCIAL:
├─ ¿Tiene crédito? Sí/No
├─ Si sí: Límite de crédito
├─ Plazo de pago (15/30/45 días)
└─ Notas especiales
```

### Ver Cliente Existente

1. **Ruta:** Vendedor → Clientes
2. Buscar cliente por nombre
3. Ver:
   - Historial de compras
   - Saldo adeudado
   - Crédito disponible
   - Cotizaciones activas
   - Preferencias conocidas

### Actualizar Datos de Cliente

1. Seleccionar cliente
2. [Editar]
3. Actualizar información
4. [Guardar]

Ejemplo: cambiar email, teléfono, dirección

---

## 💡 Tips de Vendedor

### Aumentar Conversión de Cotizaciones

**Estrategia 1: Precio Competitivo**
- Revisar precios de competencia
- Solicitar descuento a administrador si es necesario
- Empacar: "tengo precio especial solo para hoy"

**Estrategia 2: Seguimiento**
- Enviar cotización con mensaje personalizado
- Hacer seguimiento después de 2 días
- Recordar 3 días antes de vencer
- Ser persistente pero respetuoso

**Estrategia 3: Volumen**
- Sugerir descuento por cantidad
- Preguntar: "¿necesitas más?"
- Agrupar productos relacionados

**Estrategia 4: Relación**
- Recordar preferencias del cliente
- Ofrecer productos que antes compraba
- Avisar cuando llega producto que le interesa

### Maximizar Comisión

1. **Volumen:** Más cotizaciones = más conversiones
2. **Promedio:** Cotizaciones más grandes = más dinero
3. **Conversión:** 70% > 50% = más ventas reales
4. **Velocidad:** Convertir rápido = menos rechazos

---

## 📊 Mi Comisión

### Ver Comisiones

**Ruta:** Vendedor → Mis Comisiones

Información:
```
PERÍODO: Abril 2026
─────────────────────────────────────
Cotizaciones: 42
Conversión: 65%
Ventas Realizadas: 27
Monto: $18,920.00
Comisión (%): 5%
────────────────────────────────────
COMISIÓN DEL MES: $946.00
Pagado: $500.00 (15/04)
Pendiente: $446.00 (será pagado 30/04)
```

### Desglose por Producto

Ver cuáles productos vende más:
```
Producto          Cantidad  Monto    Comisión
Filtro Aire       32        $816     $40.80
Aceite Motor      28        $1,260   $63.00
Bujías            15        $555     $27.75
```

---

## 🆘 Problemas Comunes

### "Cliente no responde a cotización"

**Soluciones:**
1. Esperar 3-5 días
2. Enviar recordatorio
3. Llamar por teléfono
4. Preguntar si necesita cambio en precio
5. Si pasa 7 días: marcar como "vencida"

### "Necesito cambiar precio de cotización"

**Antes de enviar:**
1. Seleccionar cotización
2. [Editar]
3. Cambiar precios
4. [Guardar]
5. Luego enviar

**Después de enviar:**
1. Contactar a cliente
2. Explicar cambio de precio
3. Enviar nueva cotización
4. Vieja automáticamente se marca como "supercedida"

### "Cliente pide descuento grande"

**Procedimiento:**
1. Si descuento > 15%:
   - Solicitar aprobación del gerente
   - Esperar respuesta
   - Informar a cliente
2. Si descuento > 30%:
   - Escalar a administrador
   - Presentar justificación
   - Esperar aprobación

---

## 📋 Checklist Diario

- [ ] Revisar cotizaciones aceptadas
- [ ] Contactar clientes con cotizaciones por vencer
- [ ] Buscar 5 clientes nuevos o antiguas para re-enganche
- [ ] Hacer seguimiento a 3 cotizaciones "calientes"
- [ ] Registrar en sistema cualquier contacto con cliente
- [ ] Revisar meta de conversión (¿llegará a 60%+?)
- [ ] Preparar cotizaciones para mañana

---

## 📚 Recursos Relacionados

- [Manual del Cajero](/manual-usuario/roles/cajero) - Para procesar ventas
- [Flujo de Cotización a Venta](/manual-usuario/flujos/cotizacion-a-venta)
- [Solución de Problemas](/manual-usuario/troubleshooting)
- [Módulo de Cotizaciones](/manual-usuario/modulos/cotizaciones)

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
