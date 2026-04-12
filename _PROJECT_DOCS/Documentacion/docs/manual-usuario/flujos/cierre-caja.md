# 🔐 Flujo: Cierre de Caja

Guía completa del proceso de cierre de caja, arqueo de efectivo y registro de transacciones del turno.

**Tiempo total:** 15-30 minutos (depende del volumen)  
**Roles:** Cajero, Gerente  
**Requisitos:** POS activo, todas las transacciones del turno registradas

---

## 📊 Diagrama del Flujo

```
┌──────────────────┐
│ 1. FINALIZAR     │
│    VENTAS        │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 2. SUSPENDER     │
│    POS           │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 3. CONTAR        │
│    EFECTIVO      │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 4. REGISTRAR     │
│    MOVIMIENTOS   │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 5. RECONCILIAR   │
│    TOTALES       │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 6. RESOLVER      │
│    DISCREPANCIAS │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 7. DOCUMENTAR    │
│    Y CERRAR      │
└──────────────────┘
```

---

## 🚀 Paso 1: Finalizar Ventas

### Últimas Transacciones

Antes de cerrar, verificar:

```
CHECKLIST FINAL:
├─ ¿Hay clientes esperando? → Atender primero
├─ ¿Hay tickets pendientes de imprimir? → Imprimir
├─ ¿Carrito tiene productos? → Limpiar
└─ ¿Hay devoluciones pendientes? → Procesar
```

### Parar Aceptar Nuevas Ventas

```
En POS:
1. Hacer clic: [Más opciones] (⋮)
2. Seleccionar: "Suspender ventas"
3. Sistema mostrará: "POS en modo de cierre"

RESULTADO:
├─ No se pueden agregar nuevos productos
├─ Carrito deshabilitado
├─ Botón de pago grisado
└─ Sistema listo para arqueo
```

---

## 💵 Paso 2: Preparar Conteo de Efectivo

### Espacio Seguro

```
SETUP:
├─ Usar mostrador limpio
├─ Buena iluminación
├─ Sin distracciones
├─ Zona segura (atrás si es posible)
├─ Una persona contando (o dos si se requiere)
└─ Supervisor presente (preferentemente)
```

### Materiales Necesarios

```
NECESITAS:
├─ Recuento de dinero del sistema
├─ Hoja de arqueo física (en papel)
├─ Calculadora
├─ Bolígrafos
├─ Bolsas para dinero (si aplica)
└─ Bandeja para monedas
```

---

## 💰 Paso 3: Contar Efectivo

### Registro Físico

**Ruta:** Cajero → Caja → [Mi turno] → "Arqueo de Caja"

```
DINERO EN CAJA:

BILLETES:
├─ $10,000: 5 billetes = $50,000
├─ $5,000:  8 billetes = $40,000
├─ $1,000: 15 billetes = $15,000
├─ $500:   20 monedas = $10,000
└─ $100:   30 monedas = $ 3,000

MONEDAS:
├─ $100:  10 = $1,000
├─ $50:   15 = $  750
├─ $10:   20 = $  200
└─ $5:    25 = $  125

────────────────────────────
TOTAL EFECTIVO CONTADO:    $120,075
```

### Ingreso Manual en Sistema

```
1. En "Arqueo de Caja" hacer clic: [+ Efectivo]
2. Ingresar por denominación:
   ├─ Billetes $10,000: [5]
   ├─ Billetes $5,000: [8]
   ├─ Billetes $1,000: [15]
   ├─ etc...
3. Monedas (opcional):
   ├─ $100: [10]
   ├─ $50: [15]
   ├─ etc...
4. Hacer clic: [Calcular Total]

RESULTADO:
└─ Sistema calcula: $120,075
```

### Comparación Automática

Sistema muestra:

```
ARQUEO DE CAJA - RESUMEN
────────────────────────────
Efectivo esperado (sistema):  $120,050
Efectivo contado (físico):    $120,075
────────────────────────────
DIFERENCIA:                   +$25 ✅

DIFERENCIA %: +0.02%
ESTADO: CONFORME
```

---

## 📝 Paso 4: Registrar Movimientos Especiales

### Gastos del Día

Si hay gastos (suministros, etc.):

```
HACER CLIC: [+ Gasto]

TIPO DE GASTO:
├─ Suministros (papel, bolsas)
├─ Mantenimiento
├─ Reposición de cambio
├─ Otro (especificar)

DETALLE:
├─ Descripción: "Rollo de papel térmica"
├─ Monto: $5,000
├─ Recibo: [Adjuntar imagen si aplica]
├─ Autorizado por: [Gerente]

[Guardar Gasto]
```

Sistema ajusta:
```
Efectivo esperado: $120,050 - $5,000 = $115,050
Efectivo contado: $120,075
NUEVA DIFERENCIA: +$25,025 ⚠️
```

### Ingresos Extras

Si recibiste dinero sin venta (préstamo devuelto, etc.):

```
HACER CLIC: [+ Ingreso]

TIPO DE INGRESO:
├─ Préstamo devuelto
├─ Depósito de cliente
├─ Venta de mostrador no registrada (corregir)
└─ Otro

[Similar a gastos, ingresa monto]
```

### Movimientos de Efectivo

Si moviste dinero durante el día:

```
HACER CLIC: [+ Movimiento]

EJEMPLOS:
├─ Ingreso a caja fuerte: $50,000 (a las 14:00)
├─ Cambio recibido de gerente: $10,000
└─ Depositar cheque del cliente: No aplica aquí
```

---

## ✅ Paso 5: Reconciliación de Totales

### Resumen del Día

Sistema automáticamente calcula:

```
RESUMEN DE CAJA - TURNO COMPLETO
════════════════════════════════

APERTURA:
├─ Efectivo inicial: $500.00
└─ Fecha/Hora: 08/04/2026 08:00

VENTAS:
├─ Transacciones: 47
├─ Ingresos por venta: $125,800.50
├─ Efectivo: $95,600.50
├─ Tarjetas: $27,500.00
├─ Transferencias: $ 2,700.00

DEVOLUCIONES:
├─ Transacciones: 2
├─ Reembolso: ($1,200.00)
├─ Método: Efectivo

GASTOS:
├─ Suministros: ($5,000.00)
└─ Total gastos: ($5,000.00)

INGRESOS EXTRAS:
├─ Préstamo devuelto: $10,000.00
└─ Total ingresos: $10,000.00

────────────────────────────

ESPERADO:
├─ Apertura: $500.00
├─ + Ventas (efectivo): $95,600.50
├─ - Devoluciones: ($1,200.00)
├─ - Gastos: ($5,000.00)
├─ + Otros ingresos: $10,000.00
└─ ESPERADO FINAL: $100,100.50

CONTADO FÍSICAMENTE:
└─ CONTADO FINAL: $100,125.50

DIFERENCIA:
├─ Monto: +$25.00
├─ Porcentaje: +0.025%
└─ ESTADO: ✅ CONFORME

RESPONSABLE: Juan Pérez
SUPERVISOR: María González
TIMESTAMP: 08/04/2026 17:00
```

### Validaciones del Sistema

Sistema verifica automáticamente:

```
✅ VALIDACIONES:
├─ Total de ventas concuerda con transacciones
├─ Devoluciones están registradas
├─ Efectivo esperado = Apertura + Ventas - Devoluciones ± Otros
├─ Diferencia < 1% (tolerancia típica)
├─ Supervisión registrada
└─ Timestamp válido
```

---

## 🔍 Paso 6: Resolver Discrepancias

### Si Todo Concuerda (Caso Ideal)

```
DIFERENCIA: $0 - $25 (dentro de tolerancia)
DECISIÓN: ✅ ACEPTAR

Hacer clic: [Aprobar Cierre]
```

### Pequeña Discrepancia (+/- $100)

```
DIFERENCIA: -$150 (falta efectivo)

ACCIONES:
1. Revisar transacciones recientes
2. ¿Hay devoluciones sin registrar?
3. ¿Hay retiradas de efectivo no registradas?
4. Si no encuentra causa:
   ├─ Reportar al gerente
   ├─ Documentar el faltante
   ├─ Continuar con cierre

REGISTRO DE DISCREPANCIA:
├─ Motivo: "Faltante sin causa aparente"
├─ Monto: $150
├─ Reportado por: Juan Pérez
├─ Fecha: 08/04/2026
└─ [Continuar cierre igual]
```

### Discrepancia Grande (+/- $500 o más)

```
DIFERENCIA: -$500

⚠️ PARAR INMEDIATAMENTE

PROCEDIMIENTO:
1. Avisar al gerente/supervisor
2. Supervisor revisará:
   ├─ Transacciones de todo el día
   ├─ Historial de devoluciones
   ├─ Retiradas de efectivo
   ├─ Logs del sistema
   └─ Grabaciones de cámara (si aplica)

3. Posibles causas:
   ├─ Error en conteo (recontar)
   ├─ Transacción no registrada
   ├─ Devolución sin procesar
   ├─ Retirada de efectivo no documentada
   └─ Robo (caso grave)

4. NO CERRAR hasta resolver

RESULTADO:
├─ Si se encuentra error: Corregir
├─ Si no se encuentra: Reportar, documentar, continuar
└─ Investigación posterior si es necesario
```

---

## 📊 Paso 7: Documentar y Cerrar

### Información Final

```
CIERRE FINAL:

Caja: Caja Principal
Cajero: Juan Pérez
Turno: 08:00 - 17:00
Supervisor: María González

Efectivo esperado: $100,100.50
Efectivo contado: $100,125.50
Diferencia: +$25.00 ✅

Observaciones: Ninguna

[Confirmar Cierre Final]
```

### Generar Comprobante

Sistema automáticamente genera:

```
╔══════════════════════════════════╗
║  CIERRE DE CAJA - COMPROBANTE    ║
║  HermeX POS - Sucursal Centro    ║
╚══════════════════════════════════╝

Turno #: 001234
Cajero: Juan Pérez
Supervisor: María González
Fecha: 08/04/2026
Hora cierre: 17:00

RESUMEN:
Transacciones: 47 ventas, 2 devoluciones
Monto total: $124,600.50

FORMA DE PAGO:
├─ Efectivo: $95,625.50
├─ Tarjeta: $27,500.00
├─ Transferencia: $ 2,700.00

ARQUEO:
├─ Esperado: $100,100.50
├─ Contado: $100,125.50
├─ Diferencia: +$25.00 ✅

Aprobado: María González (Gerente)
Timestamp: 08/04/2026 17:05

[Código de cierre: CC-001234-080426]
```

### Enviar Informe

```
AUTOMÁTICAMENTE:
1. Copia al correo del cajero
2. Copia al correo del gerente
3. Copia a contabilidad
4. Guardado en historial del sistema
5. Disponible en reportes

ACCESO POSTERIOR:
Gerente → Reportes → Cierres de Caja
│
├─ Por fecha
├─ Por sucursal
├─ Por cajero
├─ Por turno
└─ Exportar a Excel
```

### Paso Final: Desalojar la Caja

```
ANTES DE IRSE:

1. ✅ Caja cerrada y archivada en sistema
2. ✅ Dinero contado y en bolsa (si aplica)
3. ✅ Dinero entregado a gerente
4. ✅ Dinero depositado en caja fuerte
5. ✅ Dinero llevado a banco (si corresponde)
6. ✅ Recibo de depósito guardado
7. ✅ POS apagado (o sesión cerrada)
8. ✅ Mostrador limpiado
9. ✅ Área segura

CONFIRMACIÓN:
[Fin de turno - Sesión cerrada]
```

---

## 🆘 Problemas Comunes

### "No cuadra el efectivo"

```
DIFERENCIA: -$200

INVESTIGACIÓN:
1. ¿Contaste bien?
   └─ Recontar lentamente

2. ¿Hay transacciones sin registrar?
   └─ Revisar historial de último 1 hora

3. ¿Devoluciones sin procesar?
   └─ Buscar clientes esperando cambio

4. ¿Retirada de dinero no documentada?
   └─ Pregunta a gerente

5. Si no encuentras:
   └─ Documentar y reportar
```

### "Se apagó la computadora antes de cerrar"

```
SITUACIÓN: POS cerró sin guardar cierre

RECUPERACIÓN:
1. Reiniciar POS
2. Sistema detecciona cierre incompleto
3. [Recuperar último turno]
4. Opción A: Continuar con cierre anterior
5. Opción B: Iniciar nuevo cierre

IMPORTANTE:
└─ No perderás datos (están en servidor)
```

### "Clienta reclama devolucion después de cierre"

```
SITUACIÓN: Caja ya cerrada pero cliente vuelve con producto

OPCIONES:
1. Si dentro de política (7 días típico):
   ├─ Gerente abre retención de caja
   ├─ Procesar devolución normalmente
   ├─ Actualiza cierre de caja automáticamente

2. Si fuera de política:
   └─ Referir a gerente/supervisor

RESULTADO:
├─ Caja se reabre
├─ Se procesa devolución
├─ Arqueo se recalcula
└─ Nuevo cierre se genera
```

### "Terminal de tarjeta reporta diferencia"

```
DISCREPANCIA: POS dice $27,500 en tarjeta
               Terminal dice $27,450

ACCIÓN:
1. Revisar transacciones de tarjeta
2. ¿Hay transacción rechazada?
3. ¿Hay transacción "pendiente"?
4. Contactar a banco si diferencia > $100
5. Registrar en sistema

RESOLUCIÓN:
├─ Normalmente se resuelve en 24 horas
├─ Banco cancela o confirma transacción
└─ Próximo cierre concuerda
```

---

## ⏱️ Tiempos de Referencia

```
Contar efectivo:              10-20 min
Registrar en sistema:         5 min
Reconciliar:                  2-5 min
Resolver discrepancias:       5-20 min
Generar comprobante:          1 min
Enviar informes:              Automático
────────────────────────────
TIEMPO TOTAL TÍPICO:          15-30 min
```

---

## 📋 Checklist de Cierre

**ANTES DE EMPEZAR:**
- [ ] Todas las ventas finalizadas
- [ ] POS en modo de cierre
- [ ] Supervisor disponible
- [ ] Área limpia y segura

**DURANTE CONTEO:**
- [ ] Dinero contado sin interrupciones
- [ ] Registrado en sistema
- [ ] Gastos documentados
- [ ] Ingresos extras anotados

**RECONCILIACIÓN:**
- [ ] Efectivo esperado calculado
- [ ] Efectivo contado verificado
- [ ] Diferencia dentro de tolerancia
- [ ] Supervisor revisó

**CIERRE FINAL:**
- [ ] Comprobante generado
- [ ] Informe enviado a correo
- [ ] Dinero asegurado
- [ ] POS desconectado
- [ ] Mostrador limpio

---

## 🔗 Recursos Relacionados

- [Manual del Cajero](/manual-usuario/roles/cajero) - Operación completa del POS
- [Flujo de Venta de Mostrador](/manual-usuario/flujos/venta-mostrador) - Proceso de venta
- [Módulo de POS](/manual-usuario/modulos/pos) - Documentación técnica del POS

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
