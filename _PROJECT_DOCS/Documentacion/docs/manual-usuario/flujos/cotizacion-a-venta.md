# 💬 Flujo: De Cotización a Venta

Guía completa del proceso desde que se crea una cotización hasta que se convierte en venta final.

**Tiempo total:** Variable (1-7 días típicamente)  
**Roles:** Vendedor, Cajero, Bodeguero  
**Prerequisitos:** Cliente identificado, productos disponibles

---

## 📊 Diagrama del Flujo

```
┌────────────────────┐
│ 1. CREAR COTIZACIÓN │
│    (Vendedor)      │
└─────────┬──────────┘
          │
          ▼
┌────────────────────┐
│ 2. ENVIAR AL CLIENTE│
│    (Email/WhatsApp)│
└─────────┬──────────┘
          │
          ▼
    ┌─────────────┐
    │ ESPERA →    │
    │ Cliente     │
    │ revisa      │
    └─────┬───────┘
          │
    ┌─────▼──────────┐
    │ DECISIÓN CLIENTE
    ├─────┬──────┬──────────┐
    │     │      │          │
    ▼     ▼      ▼          ▼
   SÍ   NO     CAMBIOS   CONSULTA
    │     │      │          │
    │ ┌───▼─┐ ┌──▼─┐    ┌───▼───┐
    │ │Rech.│ │Edit│    │Seguim.│
    │ └─────┘ └──┬─┘    └───────┘
    │            │
    ├────────────┘
    │
    ▼
┌────────────────────┐
│ 3. CLIENTE ACEPTA  │
│ (Estado: Aceptada) │
└─────────┬──────────┘
          │
          ├──────────────────┐
          │                  │
    ┌─────▼──────┐      ┌────▼─────┐
    │ 4. ENVIAR A │      │ 4B. VENTA │
    │    BODEGA   │      │ EN POS    │
    │ (Preparar)  │      │ (Directo) │
    └─────┬──────┘      └────┬─────┘
          │                  │
          ▼                  │
    ┌──────────────┐         │
    │ 5. BODEGUERO │         │
    │   PREPARA    │         │
    └─────┬────────┘         │
          │                  │
          ▼                  │
    ┌──────────────┐         │
    │ 6. ENVIAR A  │         │
    │    CAJA      │         │
    └─────┬────────┘         │
          │                  │
          ├──────────────────┤
          │                  │
          ▼                  ▼
    ┌──────────────────────────────┐
    │ 7. CAJERO PROCESA VENTA      │
    │    (Pago, ticket, cierre)    │
    └──────────────────────────────┘
          │
          ▼
    ┌──────────────────────────────┐
    │ 8. VENTA FINALIZADA ✅       │
    │    (Stock descuentado)       │
    └──────────────────────────────┘
```

---

## 🚀 Fase 1: Crear Cotización

### Paso 1: Vendedor Crea Cotización

**Ruta:** Vendedor → Cotizaciones → "+ Nueva Cotización"

```
INFORMACIÓN DEL CLIENTE:
├─ Nombre: "Taller Mecánico Juan"
├─ Email: taller@email.com
├─ Teléfono: 9-XXXXXXXX
└─ Tipo: Empresa

PRODUCTOS:
├─ Filtro Aire (5 unidades)       $127.50
├─ Aceite 10W-40 (10 botellas)    $450.00
├─ Bujías (2 cajas)               $ 37.00
├─────────────────────────────────
├─ Subtotal:                       $614.50
├─ Descuento (10%):               -$61.45
├─ Impuesto (19%):                $105.08
└─ TOTAL:                         $658.13

PLAZO: 7 días (válida hasta 15/04/2026)
```

### Paso 2: Guardar Cotización

```
1. Hacer clic: [Guardar Cotización]
2. Sistema asigna número: #CO-001234
3. Estado: "Borrador"
4. Cotización guardada localmente
```

---

## 📧 Fase 2: Enviar Cotización

### Opción A: Enviar por Email

```
1. Seleccionar cotización
2. Hacer clic: [Enviar Email]
3. Se abre editor:
   ├─ De: Mi Email (auto)
   ├─ Para: Email del cliente
   ├─ Asunto: "Cotización #CO-001234"
   └─ Mensaje: [Personalizado]

   Ejemplo:
   "Estimado Juan,
   
   Le enviamos cotización con los productos
   solicitados. La cotización es válida por 7 días.
   
   Total: $658.13
   
   ¿Alguna consulta? Me contactas.
   
   Saludos,
   [Mi nombre]"

4. [Enviar]
5. Estado: "Enviada"
6. Sistema registra:
   ├─ Fecha de envío
   ├─ Email del cliente
   └─ Copia en historial
```

### Opción B: Enviar por WhatsApp

```
1. Hacer clic: [WhatsApp]
2. Se abre WhatsApp Web con:
   ├─ Número del cliente
   ├─ Texto pre-cargado:
   │  "Cotización #CO-001234
   │   Total: $658.13
   │   Válida hasta 15/04
   │   [link a PDF]"
   └─ PDF adjunto

3. Revisar y [Enviar]
4. Sistema registra envío
```

### Opción C: Entregar en Persona

```
1. Hacer clic: [Descargar PDF]
2. Se descarga cotización en PDF
3. Imprimir
4. Entregar personalmente a cliente
5. En sistema: marcar como "Impreso y Entregado"
6. Pedir firma si es necesario
```

---

## ⏳ Fase 3: Espera y Seguimiento

### Estados Posibles

```
DURANTE LA ESPERA:

Estado: "Enviada"
├─ Visibilidad en: "Mis Cotizaciones - Enviadas"
├─ Contador: "3 días esperando"
└─ Notificación: Sistema avisa si pasa 3 días sin respuesta

Automáticamente:
├─ Día 3: Recordatorio al vendedor
├─ Día 5: Último recordatorio
├─ Día 7: Se marca como "Próxima a Vencer"
└─ Día 8+: Se marca como "Vencida"
```

### Contactar Cliente

Si cliente no responde:

```
1. Hacer clic: [Contactar Cliente]
2. Opciones:
   ├─ Enviar email de recordatorio
   ├─ Enviar mensaje WhatsApp
   ├─ Registrar llamada telefónica
   └─ Cambiar fecha de seguimiento

3. Registrar en sistema:
   ├─ Fecha de contacto
   ├─ Método (email, teléfono, presencial)
   ├─ Respuesta del cliente
   └─ Próxima acción

Ejemplo:
"Contacté por teléfono.
Cliente dice: 'Necesito validar con contador'
Próximo contacto: Viernes"
```

### Respuestas del Cliente

**Cliente dice "Necesito tiempo":**
```
→ Extender plazo de validez
→ Registrar nueva fecha
→ Mantener cotización activa
```

**Cliente pide "Cambiar cantidad":**
```
→ Editar cotización
→ Recalcular totales
→ Enviar versión actualizada
→ Vieja se marca como "Supercedida"
```

**Cliente pide "Mejor precio":**
```
→ Evaluar margen
→ Pedir aprobación a gerencia si es necesario
→ Crear nueva versión con descuento
→ Enviar como "Versión Actualizada"
```

---

## ✅ Fase 4: Cliente Acepta

### Notificación Automática

```
Sistema notifica:
├─ Email al vendedor: "Cotización #XX aceptada"
├─ En navbar: Contador de aceptadas
├─ Estado: "Aceptada"
└─ Disponible en: "Mis Cotizaciones - Aceptadas"
```

### Qué Sucede Automáticamente

```
✅ Sistema:
├─ Reserva el stock (no lo vende a otro)
├─ Bloquea cambios de precio
├─ Registra fecha de aceptación
├─ Prepara para procesamiento
└─ Notifica a bodeguero/cajero
```

---

## 🎯 Fase 5: Procesar Venta

### Opción A: Enviar a Bodega (Compra Grande)

```
1. Cotización en estado "Aceptada"
2. Hacer clic: [Enviar a Bodega]
3. Sistema notifica bodeguero:
   ├─ Título: "Orden de preparación"
   ├─ Número: CO-001234
   ├─ Productos a preparar
   └─ Cliente: Taller Mecánico Juan

4. Bodeguero prepara:
   ├─ Recoge productos
   ├─ Verifica cantidades
   ├─ Empacar
   └─ [Marcar como Listo]

5. Cuando está listo:
   ├─ Sistema notifica a cajero
   ├─ Estado: "Enviada a Caja"
   └─ Esperando pago
```

### Opción B: Cliente Paga en POS

Cliente lleva cotización a caja:

```
1. Cajero recibe cotización
2. En POS: Hacer clic [Cargar Cotización]
3. Ingresar número: CO-001234
4. Sistema carga todos los productos
5. Verificar que coincida con cotización
6. Procesar pago (ver flujo Venta de Mostrador)
7. ✅ Venta completada
```

### Opción C: Envío a Domicilio

Para clientes que no pueden retirar:

```
1. Cotización aceptada
2. Hacer clic: [Coordinar Envío]
3. Ingresar:
   ├─ Dirección de envío
   ├─ Fecha preferida
   ├─ Contacto para entrega
   └─ Número de seguimiento (si aplica)

4. Sistema:
   ├─ Notifica a bodeguero
   ├─ Coordina con transportista
   ├─ Genera orden de envío
   └─ Registra progreso

5. Cliente recibe y paga:
   ├─ En destino (contado)
   ├─ Por transferencia (después)
   └─ Otro método acordado
```

---

## 💳 Fase 6: Pago

### Procesar Pago en Caja

Cuando cliente va a caja:

```
CAJERO:
1. Carga cotización en POS
2. Total aparece: $658.13
3. Solicita método de pago
4. Procesa pago (ver Flujo Venta de Mostrador)
5. Imprime ticket
6. Entrega productos/Organiza retiro

SISTEMA AUTOMÁTICAMENTE:
├─ Descuenta inventario
├─ Registra venta oficial
├─ Calcula comisión del vendedor
├─ Actualiza estado a "Venta Finalizada"
└─ Notifica a vendedor: "¡Venta cerrada!"
```

### Métodos de Pago Disponibles

```
1. Efectivo
2. Tarjeta de Crédito/Débito
3. Transferencia Bancaria
4. Crédito de Tienda (saldo a favor)
5. Combinado (múltiples métodos)
```

---

## 📊 Fase 7: Cierre y Comisión

### Registro Automático

```
VENTA COMPLETADA:
├─ Número de venta: V-001234
├─ Vinculada a cotización: CO-001234
├─ Monto: $658.13
├─ Vendedor: [Tu nombre]
├─ Comisión: 5% = $32.91
└─ Fecha: 08/04/2026

VENDEDOR VE:
├─ En "Mis Comisiones": Venta registrada
├─ Comisión pendiente para pago
├─ Historial de todas sus ventas
└─ Meta de comisiones (si la hay)
```

### Historial Completo

```
Sistema mantiene registro completo:
├─ Cotización original (CO-001234)
├─ Fecha de envío
├─ Fecha de aceptación
├─ Venta final (V-001234)
├─ Monto y comisión
└─ Disponible para reportes
```

---

## 🆘 Casos Especiales

### Cliente Rechaza Cotización

```
1. Cliente dice "No"
2. Sistema notifica: "Cotización rechazada"
3. Estado: "Rechazada"
4. Vendedor puede:
   ├─ Crear nueva cotización con cambios
   ├─ Contactar nuevamente
   ├─ Registrar "Razón del rechazo"
   └─ Documentar para seguimiento
```

### Cotización Vence Sin Respuesta

```
Día 8 (pasa fecha):
├─ Estado: "Vencida"
├─ Notificación al vendedor
├─ Cliente ve: "Esta cotización expiró"
├─ Si quiere comprar ahora:
│  └─ Crear nueva cotización
└─ Motivo: Precios pueden haber cambiado
```

### Cliente Pide Cambios Después de Aceptar

```
1. Si cambios son menores:
   ├─ Editar cotización
   ├─ Sistema genera "Versión 2"
   ├─ Enviar a cliente
   └─ Re-aceptar

2. Si cambios son mayores:
   ├─ Crear nueva cotización desde cero
   ├─ Marcar antigua como "Supercedida"
   ├─ Enviar nueva
   └─ Re-negociar si es necesario
```

### Devolución Después de Venta

```
Cliente regresa con productos:
1. Hacer clic: [Procesar Devolución]
2. Mostrar ticket original (V-001234)
3. Seleccionar productos a devolver
4. Sistema:
   ├─ Revierte comisión (se recalcula)
   ├─ Suma stock nuevamente
   ├─ Registra devolución
   └─ Genera crédito o reembolso

Ver flujo específico: [Devoluciones]
```

---

## 📈 Análisis y Reportes

### Ver Tus Cotizaciones

```
Ruta: Vendedor → Mis Cotizaciones

Filtros:
├─ Borradores (sin enviar)
├─ Enviadas (esperando respuesta)
├─ Aceptadas (listas para procesar)
├─ Ventas completadas
├─ Rechazadas
└─ Vencidas

Métricas:
├─ Total cotizaciones: 42
├─ Aceptadas: 27
├─ Conversión: 64% (27/42)
├─ Monto promedio: $658
└─ Comisión total mes: $945
```

### Mejorar Conversión

```
OBJETIVO: Pasar de 60% a 70% de conversión

ESTRATEGIA:
1. Seguimiento agresivo (llamar día 3)
2. Precio competitivo (revisar vs competencia)
3. Personalizar oferta (recordar qué compraba antes)
4. Velocidad (responder rápido a consultas)
5. Volumen (ofrecer descuento por cantidad)

RESULTADO:
├─ Si logras 70%:
├─ 42 cotizaciones × 70% = 29 ventas
├─ 2 ventas más = $1,316 adicionales
├─ 2 ventas × 5% = $65.80 en comisión
└─ = $15/día extra (aproximadamente)
```

---

## ⏱️ Tiempos de Referencia

```
Crear cotización:              10-15 min
Enviar al cliente:             2-5 min
Espera típica (cliente decide): 2-3 días
Preparación en bodega:         30-60 min
Procesar pago en caja:         3-5 min
─────────────────────────────
TIEMPO TOTAL TÍPICO:           2-4 días
```

---

## 📋 Checklist Completo

**Como Vendedor:**
- [ ] Cotización creada correctamente
- [ ] Productos y precios verificados
- [ ] Cliente tiene contacto actualizado
- [ ] Cotización enviada al cliente
- [ ] Seguimiento realizado (día 3)
- [ ] Cliente contactado para confirmar
- [ ] Cliente aceptó
- [ ] Bodeguero notificado (si es necesario)

**Cuando va a Caja:**
- [ ] Cotización cargada en POS
- [ ] Productos coinciden
- [ ] Método de pago procesado
- [ ] Ticket impreso y entregado
- [ ] Cliente satisfecho

**Después de Venta:**
- [ ] Comisión aparece en "Mis Comisiones"
- [ ] Historial completo guardado
- [ ] Cliente seguimiento (satisfacción)
- [ ] Documentar para análisis

---

## 🔗 Recursos Relacionados

- [Manual del Vendedor](/manual-usuario/roles/vendedor) - Más detalles sobre cotizaciones
- [Flujo de Venta de Mostrador](/manual-usuario/flujos/venta-mostrador) - Cómo procesar pago en POS
- [Flujo de Devoluciones](/manual-usuario/flujos/devolucion) - Si cliente devuelve
- [Solución de Problemas](/manual-usuario/troubleshooting) - FAQ

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
