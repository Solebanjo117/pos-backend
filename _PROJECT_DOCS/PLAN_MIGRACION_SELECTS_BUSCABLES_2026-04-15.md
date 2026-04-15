# Plan de Migracion: Selects Simples a SearchableSelect

Fecha: 2026-04-15
Alcance: pos-frontend (pages + componentes POS)

## 1) Objetivo

Estandarizar la experiencia de seleccion en pantallas con catalogos grandes usando el componente reutilizable SearchableSelect, priorizando velocidad operativa y consistencia visual.

## 2) Hallazgos de la investigacion

- Componente reutilizable identificado en: resources/js/components/ui/SearchableSelect.vue
- Adopcion actual detectada en productos, vehiculos, supply list y partes de POS.
- Se encontraron 88 coincidencias de <select> en pages (incluye al menos 1 falso positivo por tipado en TS) y 8 adicionales en componentes POS.
- Hay pantallas con mezcla de enfoque: en POS ya existe SearchableSelect en algunos componentes, pero siguen selects simples en otros (ejemplo: filtros de categoria/marca en POSInlineSearch).

## 3) Criterio de migracion

Migrar a SearchableSelect cuando se cumpla uno o mas:

1. Catalogo dinamico con volumen medio/alto (aprox. > 12 opciones reales).
2. Usuario necesita localizar por nombre/codigo con teclado.
3. El selector participa en flujo frecuente de venta/compra/inventario.

Mantener select nativo cuando:

1. El listado es enum corto y estable (estado, tipo, motivo, combustible, etc.).
2. El campo se usa de forma esporadica y tiene pocas opciones (< 8).

## 4) Priorizacion por fases

## Fase 0 - Endurecer el componente base (previo)

Objetivo: evitar retrabajo al migrar en lote.

Tareas:

1. Agregar props opcionales para textos: searchPlaceholder, emptyResultsText.
2. Agregar opcion clearable (boton de limpiar seleccion) para filtros.
3. Revisar accesibilidad basica (aria-expanded, aria-controls, role listbox/option).
4. Documentar patron de uso comun con option nula (Todos / Sin seleccionar).

Archivos foco:

- resources/js/components/ui/SearchableSelect.vue

## Fase 1 - Quick wins (dolor reportado por operacion)

Objetivo: resolver pantallas reportadas por el usuario y zonas de alto uso diario.

Migrar:

1. Vehiculos > Chasis (Marca, Modelo)
2. Vehiculos > Motores (filtros Marca, Modelo)
3. POS (filtros Categoria, Marca en busqueda inline)

Archivos:

- resources/js/pages/Vehicles/Chassis/Create.vue
- resources/js/pages/Vehicles/Chassis/Edit.vue
- resources/js/pages/Vehicles/Engines/Index.vue
- resources/js/components/POS/POSInlineSearch.vue

## Fase 2 - Flujos comerciales criticos (ventas, cotizaciones, compras)

Objetivo: acelerar seleccion de cliente/proveedor/almacen en procesos de transaccion.

Migrar:

1. Ordenes de Venta: Cliente y Almacen en formulario de item.
2. Cotizaciones: Cliente.
3. Ordenes de Compra: Proveedor y Almacen.
4. Filtro de proveedor en listado de Ordenes de Compra.

Archivos:

- resources/js/pages/SalesOrders/Create.vue
- resources/js/pages/SalesOrders/Edit.vue
- resources/js/pages/SalesQuotes/Create.vue
- resources/js/pages/SalesQuotes/Edit.vue
- resources/js/pages/PurchaseOrders/Create.vue
- resources/js/pages/PurchaseOrders/Index.vue

## Fase 3 - Inventario y reportes (filtros de analitica y operacion)

Objetivo: mejorar navegacion cuando hay muchos almacenes, usuarios, marcas y categorias.

Migrar:

1. Inventario/Transferencias/Movimientos: selectores de almacen y proveedor.
2. Reportes: almacen, usuario, marca, categoria.
3. Sesiones POS: filtros de sucursal y cajero.

Archivos:

- resources/js/pages/InventoryTransfers/Create.vue
- resources/js/pages/Inventory/Index.vue
- resources/js/pages/Inventory/Inventory.vue
- resources/js/pages/Inventory/Batches.vue
- resources/js/pages/Inventory/Waste.vue
- resources/js/pages/InventoryMovements/CreateInbound.vue
- resources/js/pages/InventoryMovements/CreateOutbound.vue
- resources/js/pages/InventoryMovements/CreateTransfer.vue
- resources/js/pages/Reports/StockLevels.vue
- resources/js/pages/Reports/MovementHistory.vue
- resources/js/pages/Reports/Financial.vue
- resources/js/pages/Reports/ZReport.vue
- resources/js/pages/POS/Sessions/Index.vue

Notas de esta fase:

1. En tablas por fila (ej. variantes/lotes por item), migrar solo si el volumen real de opciones lo justifica.
2. Donde ya exista ProductCombobox, no duplicar comportamiento; complementar solo selects auxiliares.

## Fase 4 - Candidatos secundarios (segun volumen real)

Objetivo: completar estandar donde aporte valor, evitando sobreingenieria.

Candidatos:

1. Categorias (Categoria Padre).
2. Vehiculos > Modelos (filtro/seleccion de marca).
3. Smart Reorder (filtro de almacen).

Archivos:

- resources/js/pages/Categories/Create.vue
- resources/js/pages/Categories/Edit.vue
- resources/js/pages/Vehicles/Models/Index.vue
- resources/js/pages/Vehicles/Models/Create.vue
- resources/js/pages/Vehicles/Models/Edit.vue
- resources/js/pages/PurchaseOrders/SmartReorder.vue

## 5) Selects que NO deben migrarse por ahora

Mantener nativos (enum corto/estable):

1. Estados y tipos simples (draft, pending, active, etc.).
2. Motivos de movimiento de caja.
3. Combustible en catalogo de motores.
4. Campos de rol/tipo donde la lista es pequena y fija.

Ejemplos representativos:

- resources/js/pages/Customers/Create.vue
- resources/js/pages/Customers/Edit.vue
- resources/js/pages/Suppliers/Create.vue
- resources/js/pages/Suppliers/Edit.vue
- resources/js/pages/Warehouses/Create.vue
- resources/js/pages/Warehouses/Edit.vue
- resources/js/components/POS/CashMovementModal.vue
- resources/js/pages/Vehicles/Engines/Create.vue
- resources/js/pages/Vehicles/Engines/Edit.vue

## 6) Estandar tecnico de implementacion

Para cada migracion:

1. Sustituir select por SearchableSelect.
2. Convertir datos a { value, label, sublabel? } via computed local.
3. Conservar semantica de opcion nula (Todos, Ninguno, Sin cliente, etc.).
4. Mantener hooks actuales de recarga (@change) usando watch sobre el model.
5. Evitar cambios de estilo global; respetar layout existente.

## 7) Plan de validacion

Checklist por pantalla migrada:

1. Seleccion, cambio y limpieza de valor funcionan.
2. Los filtros siguen disparando carga de datos correctamente.
3. No se rompe teclado: Tab, Enter, Esc, flechas.
4. Placeholder y valor inicial son correctos.
5. Tema claro/oscuro conserva contraste.

Validacion transversal:

1. npm run build sin errores.
2. Prueba manual en desktop y mobile para overlays/dropdowns.

## 8) Riesgos y mitigaciones

Riesgo: dropdown dentro de contenedores con overflow oculto.
Mitigacion: priorizar pruebas en modales y tablas; ajustar z-index/posicionamiento en SearchableSelect.

Riesgo: selects por fila con gran cantidad de renders.
Mitigacion: migrar por etapas; medir performance antes de convertir tablas completas.

Riesgo: divergencia de comportamiento entre filtros existentes y nuevo componente.
Mitigacion: usar una plantilla de migracion unica y checklist de QA por pantalla.

## 9) Entregables

1. Componente SearchableSelect fortalecido (Fase 0).
2. Migracion por lotes segun fases 1 a 4.
3. Lista final de selects nativos justificados y documentados.
