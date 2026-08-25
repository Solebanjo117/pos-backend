# Deuda técnica registrada

## Balance Sheet: conciliación de Inventario y CxP

**Estado:** preexistente, fuera del alcance de la Etapa 1.

Durante el checkpoint local del 22 de agosto de 2026, el Balance Sheet mostró diferencias entre las cuentas de control del mayor y sus submódulos:

- Inventario FIFO: mayor `-$1,033,053.64`, submódulo `$15,140,968.20`, diferencia `-$16,174,021.84`.
- Cuentas por pagar: mayor `$47,822.50`, submódulo `$47,894.20`, diferencia `-$71.70`.

Los importes corresponden a la base local en la fecha del checkpoint y pueden cambiar con nuevos movimientos. La deuda consiste en identificar las transacciones históricas responsables y conciliar el mayor con los submódulos sin compensaciones artificiales.

No debe corregirse como parte de la Etapa 1 ni mediante cambios aislados de fórmula. Su reparación requiere una etapa aprobada explícitamente que incluya auditoría de fuentes, cutoff, backfill, asientos faltantes y pruebas de conciliación.

## PaymentMethodSeeder: acento en Crédito proveedor

**Estado:** preexistente, fuera del alcance de Caja — Checkpoint 1.

La prueba `PurchaseOrderPaymentCashClosingTest` espera el nombre `Crédito proveedor`, mientras que el seeder actual registra `Credito proveedor`. Esta diferencia textual no afecta las correcciones de apertura, movimientos, arqueo o cierre de Caja y no debe resolverse dentro de este checkpoint.

Debe revisarse en el checkpoint correspondiente a pagos/CxP, junto con sus consumidores y contratos visibles, para evitar cambiar aisladamente una etiqueta compartida.

## Productores de movimientos sin bloqueo uniforme de sesión

**Estado:** pendiente identificado en Caja — Checkpoint 1, reparación diferida por alcance.

El movimiento manual y el cierre de Caja coordinan concurrencia mediante bloqueo de la sesión. Los productores asociados a pagos de compras, gastos operativos y devoluciones todavía no utilizan uniformemente el mismo bloqueo antes de crear su movimiento de efectivo.

Cada productor debe auditarse y corregirse únicamente en su checkpoint funcional. No debe introducirse una modificación transversal fuera de alcance porque estos flujos incluyen transacciones, reversiones y reglas propias.
