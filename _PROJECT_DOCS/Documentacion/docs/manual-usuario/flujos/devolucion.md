# 🔄 Flujo: Devolución de Productos

Guía completa del proceso de devolución, cambio, reembolso y reintegro al inventario.

**Tiempo total:** 5-15 minutos por devolución  
**Roles:** Cajero, Vendedor, Gerente  
**Requisitos:** Ticket original, producto, comprobante de pago

---

## 📊 Diagrama del Flujo

```
┌──────────────────┐
│ CLIENTE SOLICITA │
│    DEVOLUCIÓN    │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 1. VALIDAR       │
│    DEVOLUCIÓN    │
└────────┬─────────┘
         │
         ├─ VÁLIDA ──┐
         │           │
         │      INVÁLIDA ──┐
         │                 │
         ▼                 ▼
┌──────────────────┐ ┌──────────────┐
│ 2. EVALUAR       │ │ RECHAZAR     │
│    PRODUCTO      │ │ DEVOLUCIÓN   │
└────────┬─────────┘ └──────────────┘
         │
         ├─ BUEN ESTADO ──┐
         │                │
         │  DAÑADO ────────┤
         │                 │
         ▼                 ▼
┌──────────────────┐ ┌──────────────┐
│ 3. REGISTRAR     │ │ OFRECER      │
│    DEVOLUCIÓN    │ │ ALTERNATIVAS │
└────────┬─────────┘ └────────┬─────┘
         │                    │
         ▼                    ▼
┌──────────────────┐ ┌──────────────┐
│ 4. PROCESAR      │ │ 3B. REGISTRAR│
│    REEMBOLSO     │ │    PARCIAL   │
└────────┬─────────┘ └──────────────┘
         │
         ▼
┌──────────────────┐
│ 5. ACTUALIZAR    │
│    INVENTARIO    │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 6. DOCUMENTAR    │
│    Y CERRAR      │
└──────────────────┘
```

---

## ⚖️ Política de Devoluciones

### Criterios de Aceptación

```
PLAZO:
├─ Plazo máximo: 7 días desde compra
├─ Con ticket: Extensible a 14 días (si gerente aprueba)
└─ Sin ticket: Máximo con estado de cuenta

CONDICIÓN:
├─ Excelente: Aceptado (producto sin usar)
├─ Bueno: Aceptado (pequeños daños normales)
├─ Dañado por cliente: Evaluación caso a caso
└─ Defecto de fábrica: Siempre aceptado

EXCEPCIONES:
├─ Productos en liquidación: No aplica
├─ Artículos especiales: Gerente evalúa
├─ Productos vencidos: No aplica
└─ Accesorios pequeños: Gerente decide
```

---

## 🚀 Paso 1: Validar Devolución

### Recibir Cliente

```
CLIENTE LLEGA CON:
├─ Producto a devolver
├─ Ticket de compra (preferentemente)
└─ Identificación

SALUDOS Y EMPATÍA:
"Buenas, ¿en qué puedo ayudarte?"
"Entiendo, veamos qué pasó con el producto"
```

### Verificar Criterios

Hacer preguntas clave:

```
PREGUNTAS:
├─ ¿Cuándo compraste esto?
│  └─ [Nota la fecha]
├─ ¿Tiene el ticket?
│  └─ [Pide número o busca en sistema]
├─ ¿Qué problema tiene?
│  └─ [Escucha cuidadosamente]
└─ ¿Tienes la caja/empaque?
   └─ [Verifica embalaje original]

DECISIÓN INICIAL:
├─ ¿Dentro de 7 días? → Continuar
├─ ¿Fuera de plazo? → Verificar con gerente
└─ ¿Defecto evidente? → Proceder (generar)
```

### Buscar Transacción Original

**Ruta:** Cajero → Historial → Búsqueda

```
Si tiene ticket:
1. Ingresar número: "00001234"
2. Sistema muestra:
   ├─ Fecha compra: 01/04/2026
   ├─ Cajero: Juan Pérez
   ├─ Productos: Filtro Aire, Aceite
   ├─ Total: $650.00
   └─ Método pago: Efectivo

Si NO tiene ticket:
1. [Buscar por cliente]
2. Ingresar email o teléfono
3. Sistema muestra compras del cliente
4. Cliente identifica su compra
```

### Validación del Sistema

```
SISTEMA VERIFICA AUTOMÁTICAMENTE:
✓ ¿Compra existe en sistema?
✓ ¿Dentro de plazo?
✓ ¿Producto es devoluble?
✓ ¿Ya fue devuelto antes?
✓ ¿Historial del cliente?

RESULTADO:
├─ ✅ VÁLIDA: Continuar con paso 2
├─ ⚠️ FUERA DE PLAZO: Presentar a gerente
└─ ❌ INVÁLIDA: Rechazar con explicación
```

---

## 🔍 Paso 2: Evaluar Producto

### Inspección Visual

```
CHECKLIST DE INSPECCIÓN:

EMBALAJE:
├─ ¿Caja original intacta? → Sí/No
├─ ¿Sello de fábrica roto? → Sí/No
└─ ¿Daño evidente en caja? → Sí/No

PRODUCTO:
├─ ¿Limpio? → Sí/No
├─ ¿Sin marcas de uso? → Sí/No
├─ ¿Funciona correctamente? → Sí/No (testear si aplica)
└─ ¿Todas las piezas presentes? → Sí/No

SEÑALES DE USO:
├─ ¿Rayones? → Grado: Leve/Moderado/Grave
├─ ¿Suciedad? → Grado: Poca/Moderada/Mucha
└─ ¿Desgaste? → Grado: Ninguno/Leve/Evidente
```

### Clasificación

```
CATEGORÍA 1: EXCELENTE ✅
└─ Producto sin usar, con caja, sellado
   → Aceptación: 100% | Reembolso: 100%

CATEGORÍA 2: BUENO ✅
└─ Producto con mínimo uso, daño cosmético leve
   → Aceptación: 95% | Reembolso: 95%

CATEGORÍA 3: REGULAR ⚠️
└─ Producto con uso evidente, daño moderado
   → Aceptación: Gerente decide | Reembolso: 50-80%

CATEGORÍA 4: DEFECTUOSO ✅ (siempre)
└─ Defecto de fábrica, no relacionado con cliente
   → Aceptación: 100% | Reembolso: 100%

CATEGORÍA 5: INACEPTABLE ❌
└─ Daño severo, evidente negligencia del cliente
   → Aceptación: No | Reembolso: No
```

### Documentar Hallazgos

```
NOTAS IMPORTANTES:
"Filtro Aire en excelente estado, embalaje intacto"
"Aceite con etiqueta despegada, producto sin abrir"
"Bujías con caja abierta, pero producto completo"

[Tomar foto si es caso especial]
```

---

## 💼 Paso 3: Registrar Devolución en Sistema

### Iniciar Devolución

**Ruta:** Cajero → Transacciones → "+ Nueva Devolución"

```
INFORMACIÓN BÁSICA:
├─ Transacción original: #00001234 (automático si existe)
├─ Cliente: Juan García
├─ Fecha original: 01/04/2026
├─ Fecha devolución: 08/04/2026
├─ Días desde compra: 7 días ✅
└─ Gerente: María González
```

### Agregar Productos

```
OPCIÓN 1: DEVOLVER TODO

1. Sistema carga todos los productos
   ├─ Filtro Aire: 1 x $25.50
   ├─ Aceite 10W-40: 1 x $45.00
   └─ Total original: $70.50

2. Sistema calcula reembolso
   └─ Reembolso total: $70.50 ✅

3. Hacer clic: [Devolver todo]

OPCIÓN 2: DEVOLUCIÓN PARCIAL

1. Seleccionar productos:
   ├─ [✓] Filtro Aire: 1 ← Devolver
   ├─ [ ] Aceite 10W-40: 1 ← Mantener
   └─ Total devolución: $25.50

2. Sistema calcula reembolso parcial
   └─ Reembolso parcial: $25.50

3. Opciones cliente:
   ├─ [Dinero] Reembolso en efectivo
   ├─ [Cambio] Cambio por otro producto
   └─ [Crédito] Crédito en tienda
```

### Ingresar Motivo

```
RAZONES DISPONIBLES:
├─ Defecto de fábrica
├─ Cambio de opinión
├─ Producto incorrecto
├─ Producto dañado (por cliente)
├─ Cambio de preferencia
├─ Mejor precio en otro lugar
├─ Otra (especificar)

DESCRIPCIÓN:
"Filtro no encaja correctamente con el vehículo"

RESPONSABILIDAD:
├─ Si es defecto: Tienda absorbe costo
├─ Si es cliente: Descuento de reembolso (si aplica)
```

### Ajuste de Reembolso (Si aplica)

```
Si producto está en categoría "REGULAR":

Reembolso original: $70.50

AJUSTES POSIBLES:
├─ Daño cosmético: -5% → $66.98
├─ Marca de uso: -10% → $63.45
├─ Deterioro general: -15% → $59.93
└─ TOTAL REEMBOLSO: $66.98

Mostrar a cliente:
"Producto en muy buen estado, daremos reembolso del 95%"
```

---

## 💰 Paso 4: Procesar Reembolso

### Método 1: Reembolso en Efectivo

```
1. Hacer clic: [Procesar Pago]
2. Seleccionar: "Efectivo"
3. Sistema calcula:
   ├─ Reembolso: $70.50
   ├─ Método: Efectivo
   ├─ Receptor: Juan García
   └─ Fecha: 08/04/2026

4. [Confirmar Reembolso]

RESULTADO:
├─ Dinero saldrá de caja
├─ Se registra en sistema
├─ Recibo de devolución se imprime
```

**Imprimir recibo:**

```
╔══════════════════════════════╗
║  RECIBO DE DEVOLUCIÓN        ║
║  HermeX POS                  ║
╚══════════════════════════════╝

DEVOLUCIÓN #: DEV-00001234
Fecha: 08/04/2026
Cliente: Juan García

PRODUCTO DEVUELTO:
├─ Filtro Aire (1)           $25.50
├─ Aceite 10W-40 (1)         $45.00
└─ SUBTOTAL:                 $70.50

REEMBOLSO:                   $70.50

MÉTODO: Efectivo
CAJERO: Juan Pérez

Transacción original: #00001234
Compra: 01/04/2026

[Gracias por visitarnos]
```

### Método 2: Cambio por Otro Producto

```
1. Cliente elige producto alternativo
2. Sistema compara precios:
   ├─ Reembolso: $70.50
   ├─ Nuevo producto: $65.00
   └─ Diferencia: -$5.50 (cliente paga menos)
   
   O:
   ├─ Reembolso: $70.50
   ├─ Nuevo producto: $95.00
   └─ Diferencia: +$24.50 (cliente paga más)

3. Cliente decide:
   ├─ Si hay descuento: "Sí, gracias"
   ├─ Si hay que pagar: [Ingresar método de pago]

4. [Confirmar cambio]

RESULTADO:
├─ Producto devuelto agregado a inventario
├─ Nuevo producto salido de inventario
├─ Dinero ajustado en caja (si aplica)
```

### Método 3: Crédito en Tienda

```
1. Hacer clic: [Crédito en Tienda]
2. Sistema crea crédito:
   ├─ Cliente: Juan García
   ├─ Monto: $70.50
   ├─ Vencimiento: 30 días (configurable)
   └─ Código: CRE-00001234

3. Entrega tarjeta o código a cliente:
   "Este crédito puede usar en futuras compras"

4. [Confirmar]

RESULTADO:
├─ Cliente tiene saldo disponible
├─ Visible en próxima compra
├─ Efectivo no sale de caja
```

### Método 4: Devolución a Tarjeta

```
SI CLIENTE PAGÓ CON TARJETA ORIGINALMENTE:

1. Hacer clic: [Devolver a Tarjeta]
2. Sistema verifica:
   ├─ Última 4 dígitos: 5678
   ├─ Banco: Scotiabank
   └─ Monto: $70.50

3. [Procesar devolución]

RESULTADO:
├─ Devolución enviada al banco
├─ Tarda 3-5 días en acreditarse
├─ Mostrar comprobante a cliente:
│  "La devolución se procesará en 3-5 días hábiles"
└─ Recibo impreso como referencia
```

---

## 📦 Paso 5: Actualizar Inventario

### Registrar Entrada

Sistema automáticamente:

```
1. Genera orden de entrada:
   ├─ Tipo: "Devolución de cliente"
   ├─ Referencia: DEV-00001234
   ├─ Productos: Filtro Aire (1), Aceite (1)
   └─ Estado: Recibido

2. Verifica condición:
   ├─ Si Excelente: Stock normal
   ├─ Si Bueno: Stock normal (aplica pequeño ajuste)
   ├─ Si Regular: Stock con marcación especial
   └─ Si inaceptable: No entra a inventario

3. Asigna ubicación:
   ├─ Si está en stock: Ubicación normal
   ├─ Si es devolución parcial: Bodega evaluación
   └─ Si es defectuoso: Zona de devolución proveedor
```

### Caso Especial: Producto Dañado

```
SI PRODUCTO LLEGÓ DAÑADO:

1. Registrar como "Daño de envío"
2. Tomar fotos
3. NO ENTRA A STOCK NORMAL
4. Ir a: Inventario → Productos Dañados
5. Sistema automáticamente:
   ├─ Genera solicitud de devolución al proveedor
   ├─ Marca como "En disputa con proveedor"
   └─ Avisos al comprador

PRÓXIMA ACCIÓN:
├─ Comprador contacta proveedor
├─ Proveedor envía reemplazo o nota de crédito
└─ Inventario se actualiza
```

---

## 📋 Paso 6: Documentar y Cerrar

### Registro Completo

```
SISTEMA REGISTRA:
├─ Transacción original: #00001234
├─ Devolución registrada: DEV-00001234
├─ Cliente: Juan García
├─ Productos: Filtro Aire, Aceite 10W-40
├─ Motivo: Defecto de fábrica
├─ Monto devuelto: $70.50
├─ Método reembolso: Efectivo
├─ Condición productos: Excelente
├─ Cajero: Juan Pérez
├─ Gerente: María González
├─ Fecha/hora: 08/04/2026 15:30
├─ Timestamp: Sistema automático
└─ Versión: 1
```

### Archivar Documentación

```
DOCUMENTOS GENERADOS:
├─ Recibo de devolución (impreso y digital)
├─ Comprobante de reembolso
├─ Entrada de inventario
├─ Reporte para contabilidad
└─ Log en historial del cliente

ACCESO POSTERIOR:
├─ Cliente: Ver en "Mis compras" → "Mis devoluciones"
├─ Cajero: Historial → Búsqueda por referencia
├─ Gerente: Reportes → Devoluciones del mes
└─ Contador: Exportar para asientos contables
```

### Análisis de Devoluciones

```
SISTEMA GENERA AUTOMÁTICAMENTE:

ALERTAS AUTOMÁTICAS:
├─ Si cliente devuelve >3 veces: "Cliente con problemas"
├─ Si producto devuelto >5 veces: "Producto defectuoso"
├─ Si tasa devolución >10%: "Potencial problema de calidad"
└─ Si reembolsos exceden presupuesto: "Alerta gerencia"

REPORTES DISPONIBLES:
├─ Por cliente: "Este cliente devolvió X productos"
├─ Por producto: "Este producto tiene Y devoluciones"
├─ Por fecha: "Devoluciones mes de abril"
├─ Por razón: "X devoluciones por defecto de fábrica"
└─ Financiero: "Total reembolsos: $Z"
```

---

## 🆘 Problemas Comunes

### "Cliente fuera de plazo de 7 días"

```
SITUACIÓN: Compra fue hace 10 días

OPCIONES:
1. Revisar política flexible:
   ├─ ¿Defecto evidente? → Aceptar igualmente
   ├─ ¿Producto caducado? → Aceptar
   ├─ ¿Cliente frecuente? → Gerente decide

2. Presentar alternativas:
   ├─ Crédito en tienda (descuento 20%)
   ├─ Cambio por otro producto
   └─ Reparación del producto

3. Si cliente insiste:
   └─ Remitir a gerente
```

### "Cliente reclama defecto sin motivo aparente"

```
SITUACIÓN: Cliente dice "no funciona" sin causa clara

INVESTIGACIÓN:
1. ¿Producto funciona ahora? (Testear si aplica)
2. ¿Señales de negligencia? (Observar cuidadosamente)
3. ¿Cliente conocido por reclamaciones? (Revisar historial)

DECISIÓN:
├─ Si está claro que cliente lo rompió: Rechazar educadamente
├─ Si hay duda: Ofrecer evaluación de gerente
├─ Si es caso gris: Crédito parcial (50%)
└─ Si es cliente importante: Ser flexible
```

### "No tiene ticket"

```
SITUACIÓN: Cliente sin comprobante de compra

OPCIONES:
1. Buscar en sistema por:
   ├─ Email del cliente
   ├─ Teléfono del cliente
   ├─ Nombre del cliente
   └─ Aproximadamente cuándo compró

2. Si encuentra transacción:
   ├─ Verificar productos coinciden
   ├─ Proceder normalmente
   └─ Sistema reconoce venta

3. Si NO encuentra:
   ├─ Pedir identificación
   ├─ Verificar con gerente
   ├─ Ofrecer crédito en tienda en lugar de efectivo
   └─ Documentar bien en caso de investigación
```

### "Producto parcialmente usado"

```
SITUACIÓN: Cliente abrió y usó el producto

EVALUACIÓN:
1. ¿Es consumible? (Aceite, bujías, etc.)
   └─ NO ACEPTA devolución

2. ¿Es reutilizable? (Filtro, etc.)
   └─ Evaluar condición
   └─ Reembolso 50-70%

3. ¿Cliente tiene buena razón? (Defecto)
   └─ Aceptar 100%

4. ¿Cliente simplemente cambió de opinión?
   └─ Ofrecer crédito en tienda
   └─ Reembolso 50% en efectivo
```

### "Producto sin sellar/sin caja"

```
SITUACIÓN: Cliente devuelve filtro sin empaque original

ANÁLISIS:
├─ ¿Pero funciona bien? → Aceptar 80%
├─ ¿Está sucio? → Aceptar 60%
├─ ¿Tiene partes faltantes? → Rechazar o 30%
└─ ¿Es defectuoso? → Aceptar 100% de todas formas
```

---

## 📊 Tipos de Devoluciones - Matriz de Decisión

```
╔═══════════════════════════════════════════════════════════╗
║         MATRIZ DE DECISIÓN DE DEVOLUCIONES               ║
╠════════════════════════╦═════════════════════════════════╣
║ CASO                   ║ DECISIÓN                        ║
╠════════════════════════╬═════════════════════════════════╣
║ < 7 días, excelente    ║ ✅ 100% reembolso             ║
║ < 7 días, bueno        ║ ✅ 95% reembolso              ║
║ < 7 días, regular      ║ ⚠️ Gerente: 50-80%            ║
║ < 7 días, defecto      ║ ✅ 100% reembolso             ║
║                        ║                                 ║
║ 7-14 días, excelente   ║ ✅ 80% reembolso              ║
║ 7-14 días, bueno       ║ ⚠️ 70% reembolso              ║
║ 7-14 días, defecto     ║ ✅ 100% reembolso             ║
║ > 14 días              ║ ❌ Rechazar o crédito tienda   ║
║                        ║                                 ║
║ Sin ticket             ║ ⚠️ Gerente + Crédito tienda    ║
║ Consumible usado       ║ ❌ Rechazar siempre            ║
║ Inaceptable condición  ║ ❌ Rechazar                    ║
╚════════════════════════╩═════════════════════════════════╝
```

---

## ⏱️ Tiempos de Referencia

```
Validar devolución:           2-5 min
Evaluar producto:             3-5 min
Registrar en sistema:         2-3 min
Procesar reembolso:           2-5 min
Actualizar inventario:        1 min (automático)
Documentar y cerrar:          2 min
────────────────────────────
TIEMPO TOTAL TÍPICO:          5-15 min
```

---

## 📋 Checklist de Devolución

**VALIDACIÓN INICIAL:**
- [ ] Dentro de plazo de devolución
- [ ] Producto está en condiciones aceptables
- [ ] Cliente tiene ticket o podemos ubicar compra
- [ ] Cliente identificado en sistema

**EVALUACIÓN DEL PRODUCTO:**
- [ ] Inspección visual completada
- [ ] Condición clasificada correctamente
- [ ] Decisión de reembolso documentada
- [ ] Fotos tomadas (si aplica)

**PROCESAMIENTO:**
- [ ] Sistema: Devolución registrada
- [ ] Reembolso calculado correctamente
- [ ] Método de reembolso seleccionado
- [ ] Comprobante impreso y entregado

**CIERRE:**
- [ ] Inventario actualizado
- [ ] Documentación archivada
- [ ] Cliente satisfecho
- [ ] Registro completado en sistema

---

## 🔗 Recursos Relacionados

- [Manual del Cajero](/manual-usuario/roles/cajero) - Operación completa del POS
- [Flujo de Venta de Mostrador](/manual-usuario/flujos/venta-mostrador) - Proceso de venta
- [Módulo de Inventario](/manual-usuario/modulos/inventario) - Gestión de inventario

---

**Última actualización:** 2026-04-08  
**Versión:** 1.0.0
