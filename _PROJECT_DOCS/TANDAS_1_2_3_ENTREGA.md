# Entrega de las tandas 1, 2 y 3 — HermeX POS

Fecha de cierre: 27 de septiembre de 2026.

## Estado de la entrega

Las tres tandas están implementadas en código en los repositorios Laravel API y Vue. Esta entrega incluye pruebas automáticas, migraciones aditivas, integración en master y publicación Git. No equivale a una puesta en producción ni a aceptación por un operador.

| Elemento | Estado |
|---|---|
| Código backend | Commit 65a01abc208bda4698b3a936ee653f81c267bc3c |
| Código frontend | 8d17806bd8f8882be0e9273476f54b4dc0161685 |
| Integración Git | Integración de las ramas de trabajo en master; comprobar el HEAD remoto al terminar la publicación |
| Pruebas backend SQLite | 148 aprobadas, 933 aserciones; una prueba exclusiva de PostgreSQL omitida |
| Pruebas frontend | 114 casos aprobados entre ejecución conjunta y repetición focalizada |
| PostgreSQL aislado | Reportes y concurrencia probados durante la implementación; véase evidencia y alcance abajo |
| Navegador autenticado | Pendiente: el acceso al reporte redirigió a login |
| Migraciones en la base de trabajo/producción | No aplicadas; nueve archivos nuevos |
| Despliegue | No ejecutado |

Los commits de código incluyen los cambios locales de las tres tandas. El reporte se incorpora en un commit documental posterior. Se conservaron las ramas de origen y no se reescribió el historial.

## 1. Tanda 1 — Transacciones, cotizaciones y Caja

### Selector superior de almacén

Se retiró el selector de la cabecera compartida. La sucursal sigue resolviéndose internamente y se exige una asignación válida. No se toma un almacén arbitrario para suplir una asignación ausente. Los campos de almacén propios de operaciones concretas permanecen.

### Transacciones

Se añadió /transactions reutilizando el listado operativo de ventas. Incluye órdenes y cotizaciones que todavía no tienen orden vinculada. Después de la conversión aparece una sola fila de orden, con acceso e información de su cotización original.

El listado proporciona tipo, folio, fecha, cliente, vendedor, estado, total y saldo, con búsqueda y filtros en servidor. El saldo de una cotización no se considera deuda cobrada. Las métricas separan ventas y cotizaciones. Los índices anteriores redirigen con el filtro correspondiente; se conservan rutas de detalle y documentos.

Las acciones dependen del permiso y estado real: continuar, consultar, enviar al flujo de bodega/Caja, ampliar o devolver. Una orden vinculada todavía controlada por la cotización no habilita edición o cancelación independiente que deje documentos incoherentes.

### Autoguardado y recuperación

El editor de cotizaciones trabaja con un borrador separado de los carritos de venta. Guarda después de 800 ms sin cambios, serializa peticiones y envía posteriormente la versión más reciente. Un UUID estable identifica el documento y una revisión esperada impide sobrescribir otra sesión.

Se conservan cliente, vehículo/motor, vendedor autorizado, líneas, cantidades, precios, descuentos, impuestos y notas. No se crean documentos vacíos. El autoguardado se limita a draft/sent sin orden vinculada y no cambia automáticamente el estado.

La navegación interna espera confirmación del guardado. Un fallo conserva el borrador local y ofrece reintento; el cierre de pestaña advierte solamente si quedan cambios sin confirmar. Los conflictos se muestran sin sobrescribir silenciosamente el documento remoto. Guardar una cotización no abre Caja, cobra, reserva o consume inventario.

### Editor y POS

Nueva cotización ya no presenta pestañas de carritos ni depende del carrito activo de venta. El encabezado incorpora contexto del documento y estado de guardado.

Las ventas cobradas admiten ampliaciones mediante artículos nuevos o incrementos. El servidor impide reducciones y cambios no permitidos en líneas originales; las devoluciones conservan su recorrido. Se cobran los incrementos con métodos configurados, cuenta y referencia cuando corresponden, preservando pagos y descuentos históricos.

Las ampliaciones usan control de versión, transacciones, trazabilidad e idempotencia para evitar duplicar consumo o cobro. Mantienen las restricciones de rol, sucursal y Caja abierta; no se amplió el permiso de edición del cajero.

Se redujeron avisos repetidos de stock y confirmaciones innecesarias. Permanecen los errores que impiden guardar, cobrar u operar y las confirmaciones de envío a Caja/bodega.

### Vehículos

La búsqueda combina marca, modelo, generación, año, motor, cilindrada, VIN, combustible y aspiración. Interpreta rangos de años abiertos, comparte configuraciones entre catálogo y selector, conserva vehículo/motor al recuperar documentos y evita ocultar motores distintos por compartir código.

### Evidencia técnica

- Backend: TransactionListService, SalesQuoteDraftService, SalesOrderAmendmentService y VehicleConfigurationController.
- Frontend: Transactions/Index.vue, useSalesQuoteDraft.ts, usePOS.ts, POS/Index.vue y VehicleSelector.vue.
- Pruebas: TransactionListTest, SalesQuoteDraftAutosaveTest, SalesOrderAmendmentTest, VehicleConfigurationYearSearchTest y pruebas de componentes/composables.
- Casos cubiertos: una fila tras conversión, filtros y permisos, pérdida de respuesta/reintento, revisión obsoleta, aislamiento de Caja/inventario, ampliaciones sin duplicados y conservación de cuenta/referencia.

## 2. Tanda 2 — Métodos de pago, gastos, aportaciones y bancos

### Configuración dinámica

Se extendió el catálogo existente con cuenta financiera requerida y cuenta predeterminada opcional. La exigencia de referencia y los contextos de uso permanecen separados. El panel compartido presenta los campos conforme a esa configuración.

Los pagos conservan su clasificación al registrarse. Cambiar posteriormente el método no reclasifica el historial. Gastos, aportaciones y compras admiten efectivo/banco como dinero; crédito pendiente no se registra como desembolso.

Un pago bancario autorizado sin cuenta queda pendiente de asignación, visible por método pero sin aumentar el saldo de una cuenta. Administrador/gerente pueden asignarlo mediante una operación auditable e idempotente.

### Gastos y aportaciones

Se conserva Gastos/Funding con selector Gasto/Aportación. Aportación es un tipo de operación, no un método. Se elimina la imposición interna de efectivo.

Enviar a Caja crea una solicitud sin afectar saldos. Su confirmación usa el panel compartido para distribuir entre métodos, cuentas y referencias. La suma debe coincidir con el importe solicitado y no genera cambio. Se conserva la justificación de diferencias para gastos estimados y el importe de solicitudes fijas.

Se registra una operación económica con sus distribuciones, evitando sumar nuevamente pagos y movimientos de capital vinculados. Se mantienen cancelaciones, permisos y comprobantes.

### Compras desde Caja

El detalle permite solicitar el saldo total o un abono, con notas. Solo se admite una solicitud activa por compra; una cancelación autorizada permite solicitar otra.

Caja decide los métodos combinados. Antes de liquidar se bloquean y revalidan solicitud, compra y deuda. Un cambio de saldo incompatible detiene la operación. Una solicitud pendiente no reduce deuda ni dinero. La liquidación conserva historial, cuenta/referencia y el flujo existente de pagos/reversiones del proveedor.

### Movimientos por método

Caja dispone de resumen y detalle de cobros, devoluciones y neto por método, con filtros de sucursal, fechas, sesión, operador y método. Permite consultar documento, cuenta, referencia y responsable y distinguir pagos sin cuenta.

Ventas/devoluciones quedan separadas de gastos, aportaciones, compras y transferencias. El neto representa cobros menos reembolsos; no es utilidad ni efectivo disponible.

### Efectivo acumulado y transferencias

Se incorpora una conciliación inicial por sucursal con saldo, corte, responsable y motivo. A partir del corte se registran los movimientos efectivos una sola vez. Aperturas/cierres de sesiones no crean dinero nuevo. Se conserva fecha efectiva y contexto comercial.

Sin conciliación se muestra saldo sin inicializar y se bloquean transferencias desde ese acumulado. Las correcciones posteriores son ajustes auditables.

Las transferencias admiten Efectivo de la sucursal y una cuenta bancaria concreta. Validan autorización, sesión, saldo y reintentos, con bloqueo para evitar sobregiros concurrentes. Las reversiones compensan los movimientos originales.

### Evidencia técnica

- Servicios: OperationalPaymentService, PurchasePaymentRequestService, WarehouseCashService, CashPaymentQueryService y FinancialTransferService.
- Pantallas: OperationalPaymentPanel, Gastos/Funding, cola de Caja, detalle de compra, configuración de pagos y transferencias.
- Pruebas: OperationalPaymentsTest, PurchasePaymentRequestTest, WarehouseCashBalanceTest y flujos existentes de Caja/proveedores.
- Casos cubiertos: gasto efectivo/Yappy exacto, aportación combinada contabilizada una vez, cuenta posterior sin duplicar, abono pendiente sin efectos, deuda revalidada y transferencias sin saldo/inicialización.

## 3. Tanda 3 — Finanzas, rentabilidad y reportes del dinero

### Dónde consultar efectivo, Yappy, tarjeta y otros métodos

En Caja → Movimientos por método para consulta operativa y en Reportes/Finanzas → Cobros por método para consulta financiera. Comparten componentes/consultas.

Los pagos combinados se distribuyen según importes realmente recibidos. Los abonos pertenecen al período de cobro aunque la venta sea anterior. Solo reembolsos efectivos cuentan como salidas monetarias; notas de crédito y saldos a favor tienen tratamiento separado.

Hay resumen, detalle paginado, filtros, enlaces al documento, cuenta/referencia y pagos pendientes de asignar cuenta.

### Efectivo y bancos

El reporte distingue disponible actual, saldo al cierre de una fecha y movimientos de un período con saldo inicial, entradas, salidas y saldo final.

El detalle separa cobros, devoluciones, gastos, compras, aportaciones, transferencias y ajustes. Filtrar método, operador o documento no convierte ese subconjunto en el saldo total de la sucursal.

Antes de la conciliación se informa saldo histórico no disponible. Sin conciliación se mantiene saldo sin inicializar. No se reconstruyen saldos sin respaldo ni se suman aperturas de Caja.

La transferencia al banco reduce el efectivo y aumenta la cuenta correspondiente; conserva ventas, cobros históricos y utilidad. Una reversión deja ambos movimientos trazables. Los saldos bancarios se muestran por cuenta, no se inventa un saldo de Yappy a partir de sus cobros. El apartado bancario identifica sus saldos como actuales.

### Ejemplo de aceptación

| Concepto | Importe |
|---|---:|
| Saldo conciliado | 500 |
| Cobros en efectivo | +5,000 |
| Devoluciones y otras salidas | -1,000 |
| Transferencia al banco | -3,000 |
| Efectivo disponible | 1,500 |

La prueba automatizada utiliza un gasto de 1,000 como salida y comprueba 1,500 de efectivo, 5,000 de cobros históricos y 3,000 en banco. La reversión del depósito recupera 4,500 de efectivo y deja el banco en cero.

### Ventas y rentabilidad

Los reportes comparten una base por líneas para totales, producto/variante, marca, categoría, cliente y vendedor. El detalle incluye documento, fecha, sucursal, cliente/vendedor, SKU, cantidades, precios, descuentos, venta neta, costo, utilidad y margen.

Se excluyen cotizaciones, borradores y cancelaciones. Se conservan ventas confirmadas, en proceso y completadas. Las devoluciones aprobadas reducen ventas en su período; su reembolso se refleja por separado cuando se paga.

El cálculo utiliza evidencia histórica de costo, prorratea únicamente el descuento general pendiente de aplicar y resuelve centavos de forma determinista. Impuestos y envío quedan separados de la rentabilidad de artículos. El margen agregado se calcula sobre los totales.

La falta de costo respaldado produce rentabilidad incompleta. Las ampliaciones nuevas conservan líneas originales e incrementos con sus fechas. Las bases históricas de ampliaciones sin evidencia suficiente se excluyen con advertencia, sin atribuir otra vez toda la venta ni inventar costos.

Se conserva el estado de resultados y se distingue utilidad bruta de artículos del resultado después de gastos.

### Cuentas y exportaciones

Se completa la tabla existente de cuentas con búsqueda, filtros, ordenamiento, paginación, institución, número enmascarado, moneda, saldo, estado y acceso a movimientos/transferencias.

Los totales se separan por moneda. El efectivo de sucursal no se suma como una cuenta bancaria adicional. Se conservan los permisos administrativos y las restricciones de cuentas históricas.

CSV y Excel reutilizan filtros y cálculos completos, no solo la página visible. Exportan advertencias relevantes y respetan los filtros aplicados en pantalla. Los rangos usan la zona horaria configurada del negocio, con accesos al mes actual, hoy, mes anterior, últimos dos meses y rango personalizado.

### Evidencia técnica

- Servicios: SalesLineReportService, CashPositionReportService, FinancialAccountReportService y extensiones de WarehouseCashService.
- API/exportación: MoneyReportController, FinancialReportRequest, MoneyReportExport.
- Componentes: CashPaymentsReport, CashPositionReport, SalesLinesReport y ReportPeriod.
- Pruebas: MoneyReportsTest, CashTransferPostgresConcurrencyTest, MoneyReports.test.ts, Financial.tabs.test.ts y Accounts/Index.test.ts.

## 4. Contratos principales

Todos los endpoints siguientes pertenecen al prefijo /api/v1. Mantienen autenticación, permisos y alcance de sucursal según su operación.

| Operación | Contrato |
|---|---|
| Listado unificado | GET /transactions |
| Borrador estable | PUT /sales-quotes/drafts/{draftUuid} |
| Solicitudes de pago de compra | GET/POST /purchase-orders/{purchaseOrder}/payment-requests |
| Cancelación de solicitud | POST /purchase-orders/{purchaseOrder}/payment-requests/{paymentRequest}/cancel |
| Disponibilidad y conciliación | GET /cash/availability; POST /cash/reconciliation |
| Resumen y detalle por método | GET /cash/payment-summary; GET /cash/payment-movements |
| Asignación posterior de cuenta | POST /cash/payments/{scope}/{payment}/account |
| Efectivo histórico | GET /reports/cash-position |
| Renglones de ventas/devoluciones | GET /reports/sales-lines |
| Opciones de filtros | GET /reports/sales-line-options |
| Cuentas paginadas | GET /financial-accounts/report |
| Historial financiero | GET /financial-accounts/{financialAccount}/movements |
| Exportaciones nuevas | GET /reports/{report}/export/{format} |

Los reportes exportables son cash-position, sales-lines y payment-methods; formatos csv y xlsx. Se conservan contratos anteriores de consulta, conversión y pagos para sus consumidores autorizados. No se fusionaron tablas de cotizaciones/órdenes ni se renumeraron documentos históricos.

## 5. Pruebas y alcance real de validación

### Cierre conjunto

- Backend: 148 pruebas aprobadas, 933 aserciones, una omitida por requerir PostgreSQL. Ejecución de todos los archivos de pruebas modificados/agregados; no se presenta como suite completa del repositorio.
- Frontend: 18 archivos, 114 casos. En la ejecución conjunta pasaron 113 y un caso del encabezado de cotización agotó 5 segundos. Ese archivo pasó después solo, 8/8, sin alterar aserciones ni aumentar el timeout.
- Tras limpieza de imports/código sin uso: 23 pruebas focalizadas de POS/cotizaciones/pestañas financieras aprobadas. El archivo del POS se volvió a ejecutar tras retirar los últimos cálculos sin consumidores.
- Laravel Pint: 99 archivos aprobados.
- TypeScript/Vue: vue-tsc --noEmit aprobado.
- Vite: compilación de producción aprobada. Emitió avisos de anotaciones de dependencias y tiempos de plugins; no impidieron compilar.
- ESLint aplicado a los archivos TypeScript/Vue modificados; no equivale a una revisión de todos los archivos históricos.

### PostgreSQL aislado durante la implementación

MoneyReportsTest pasó sus 10 pruebas y 101 aserciones en una base temporal de pruebas. La prueba separada de concurrencia también pasó: dos transferencias simultáneas de 70 contra 100 disponibles produjeron una operación aceptada y otra rechazada, con efectivo 30 y banco 70; los reintentos no duplicaron efectos.

La base de pruebas tenía prefijo codex_tanda3_test_ y fue eliminada al terminar. Sus migraciones no se aplicaron a la base habitual. Estas verificaciones se realizaron durante la implementación y no se repitieron al publicar, ya que el cierre no modificó la lógica backend.

### Validación todavía pendiente

El intento de abrir el reporte en navegador redirigió a login. No existe evidencia de un recorrido completo autenticado de extremo a extremo ni de aceptación por un operador.

Debe verificarse con sesión autorizada: cotizar → salir → recuperar en Transacciones → enviar a Caja → cobrar combinado → devolver parcialmente → consultar métodos → revisar efectivo → transferir → comprobar cuenta, historial y exportación. Añadir gasto/aportación combinados y abono de compra.

Los resultados de GitHub Actions no se sustituyen por los resultados locales ni se dan por aprobados sin consultar su ejecución.

## 6. Migraciones pendientes

Los nueve archivos son aditivos. Las pruebas usan SQLite y PostgreSQL aislado. No se asignaron cuentas, costos ni movimientos históricos por suposición.

1. 2026_09_27_000001_add_draft_revision_to_sales_quotes.php
2. 2026_09_27_180226_add_pos_session_to_sales_order_payments.php
3. 2026_09_27_204052_add_financial_account_configuration_to_payment_methods.php
4. 2026_09_27_204228_create_operational_payments_table.php
5. 2026_09_27_204801_add_liquidity_snapshot_to_document_payments.php
6. 2026_09_27_204946_create_purchase_payment_requests_table.php
7. 2026_09_27_205421_create_warehouse_cash_balances_table.php
8. 2026_09_27_205421_create_warehouse_cash_entries_table.php
9. 2026_09_27_230745_create_sales_line_amendments_table.php

La última añade evidencia de ampliaciones y datos históricos de costos/contexto necesarios para reportes. Las migraciones de la tanda 2 son dependencia de la tanda 3.

## 7. Puesta en marcha pendiente

1. Preparar respaldo y comprobar la base y versión de destino.
2. Publicar backend y frontend de forma coordinada y aplicar las migraciones en la ventana autorizada.
3. Revisar métodos: contextos, referencia, cuenta requerida y cuenta predeterminada. No asignar cuentas históricas sin respaldo.
4. Un administrador debe confirmar el saldo real y fecha de corte de cada sucursal antes de habilitar transferencias del efectivo acumulado.
5. Completar el recorrido autenticado y comparar documentos, pagos, saldos y exportaciones.
6. Revisar advertencias de costos/ampliaciones históricas y pagos sin cuenta, resolviéndolas con evidencia y auditoría.
7. Verificar los procesos de CI y la configuración real de despliegue.

## 8. Exclusiones y límites

- El cambio del folio a fecha/hora YYMMDD quedó separado de estas tandas.
- No se cambiaron los permisos para permitir al cajero ampliar ventas.
- No se reconstruyeron saldos anteriores a conciliación ni costos históricos faltantes.
- No se creó un saldo disponible por método bancario: varias formas de cobro pueden llegar a la misma cuenta.
- Transferir dinero no elimina el cobro del reporte histórico; reduce el efectivo disponible.
- No se ejecutaron migraciones en la base habitual, despliegue ni pruebas con operadores/dispositivos reales.
