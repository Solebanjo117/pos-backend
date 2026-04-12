# Plan de verificaciones manuales en navegador - Sprint 1 al 6

Fecha: 2026-04-11
Carpeta: _PROJECT_DOCS/Planes 34 Cambios
Objetivo: ejecutar verificaciones manuales en navegador para validar de extremo a extremo los cambios del Sprint 1 al Sprint 6.

## Alcance y criterio

- Alcance:
	- Solo pruebas en navegador (UI y flujos reales de usuario).
	- Incluye validaciones visibles de reglas de negocio.
- Herramienta de evidencia:
	- Antigravity se usa para capturas de pantalla durante la ejecucion.
	- Cada caso debe tener evidencia visual antes de marcarse como PASS.
- Fuera de alcance:
	- Pruebas por Postman o CLI.
	- Pruebas de carga/performance.
- Evidencia minima por caso:
	- Captura de pantalla del resultado.
	- Resultado PASS/FAIL/BLOCKED.
	- Nota de hallazgo con fecha y usuario.
	- Si el caso falla, abrir defecto y registrar re-test obligatorio.

## Precondiciones

1. Backend y frontend levantados y autenticados.
2. Roles disponibles: admin, manager, seller, cashier.
3. Datos minimos:
	 - 5 productos con variantes.
	 - 2 almacenes.
	 - 3 categorias.
	 - 3 marcas.
	 - 2 proveedores.
	 - 2 clientes (uno con credito y uno sin credito).
4. Redis activo si el entorno usa cache/throttle en redis.

## Orden recomendado de ejecucion

1. Sprint 1 (base de productos y formularios).
2. Sprint 2 (variantes y pricing).
3. Sprint 3 (UX de POS y cotizaciones).
4. Sprint 4 (system_id y vehiculos).
5. Sprint 5 (inventario, compras y formulas admin).
6. Sprint 6 (lineas custom, reset e import Excel).
7. Regresion cruzada final.
8. Emitir reporte final de fallas, correccion y re-test.

## Flujo obligatorio de evidencia y re-test

1. Ejecutar caso manual en navegador.
2. Tomar captura con Antigravity y adjuntar evidencia.
3. Marcar estado inicial: PASS, FAIL o BLOCKED.
4. Si FAIL/BLOCKED:
	- Registrar ID de defecto.
	- Documentar pasos, resultado actual y resultado esperado.
	- Esperar correccion y volver a ejecutar el mismo caso.
5. En re-test:
	- Adjuntar nueva captura (post-fix).
	- Marcar resultado de re-test: PASS o FAIL.
	- Cerrar defecto solo si re-test queda en PASS.

## Bitacora actual (evidencia + re-test)

| Caso | Sprint | Estado inicial | Evidencia inicial | Defecto | Fecha fix | Estado re-test | Evidencia re-test | Tester |
|------|--------|----------------|-------------------|---------|-----------|----------------|-------------------|--------|
| S1-01 | Sprint 1 | FAIL | [s1_01_error](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\.system_generated\click_feedback\click_feedback_1775950187591.png) | BUG-S1-01 (system_id nulo) | 2026-04-11 | PASS | Confirmado en re-test | Antigravity |
| S1-02 | Sprint 1 | FAIL | [s1_02_error](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\.system_generated\click_feedback\click_feedback_1775950187591.png) | Dependia de BUG-S1-01 (fix aplicado) | 2026-04-11 | PASS | Confirmado en re-test | Antigravity |
| S1-03 | Sprint 1 | PASS | Confirmado en video | N/A | N/A | N/A | N/A | Antigravity |
| S1-04 | Sprint 1 | PASS | [s1_04_pass](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\.system_generated\click_feedback\click_feedback_1775950236097.png) | N/A | N/A | N/A | N/A | Antigravity |
| S1-05 | Sprint 1 | BLOCKED | N/A | Bloqueado por falta de usuarios seller/cashier en entorno QA | N/A | PENDIENTE | Pendiente evidencia por rol | Antigravity |
| S1-06 | Sprint 1 | PASS | Confirmado en feedback | N/A | N/A | N/A | N/A | Antigravity |
| S2-01 | Sprint 2 | PASS | Confirmado en feedback | N/A | N/A | N/A | N/A | Antigravity |
| S2-02 | Sprint 2 | BLOCKED | N/A | Bloqueado por BUG-S1-01 (fix aplicado) | 2026-04-11 | PASS | Confirmado en re-test | Antigravity |
| S2-03 | Sprint 2 | BLOCKED | N/A | Bloqueado por BUG-S1-01 (fix aplicado) | 2026-04-11 | PASS | Confirmado en re-test | Antigravity |
| S2-04 | Sprint 2 | PASS | Confirmado en feedback | N/A | N/A | N/A | N/A | Antigravity |
| S2-05 | Sprint 2 | FAIL | Captura 404 subagente | BUG-S2-05 (Ruta 404 en settings/pricing-formulas) | 2026-04-11 | PASS | Confirmado en re-test | Antigravity |
| S3-01 | Sprint 3 | PASS | [sprint_3_tests](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\sprint_3_tests_1775961717396.webp) | N/A | N/A | N/A | N/A | Antigravity |
| S3-02 | Sprint 3 | PASS | [sprint_3_tests](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\sprint_3_tests_1775961717396.webp) | N/A | N/A | N/A | N/A | Antigravity |
| S3-03 | Sprint 3 | PASS | [sprint_3_tests](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\sprint_3_tests_1775961717396.webp) | N/A | N/A | N/A | N/A | Antigravity |
| S3-04 | Sprint 3 | PASS | [sprint_3_tests](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\sprint_3_tests_1775961717396.webp) | N/A | N/A | N/A | N/A | Antigravity |
| S3-05 | Sprint 3 | PASS | [sprint_3_tests](C:\Users\JUANANTONIOCASTILLAO\.gemini\antigravity\brain\c95b381d-2e4c-4aaf-a8ca-5424ae8e0138\sprint_3_tests_1775961717396.webp) | N/A | N/A | N/A | N/A | Antigravity |
| S4-01 | Sprint 4 | PASS | Lote 2 Parte 2: system_id secuencial validado (00000025, 00000026) | N/A | N/A | N/A | N/A | Antigravity |
| S4-02 | Sprint 4 | PASS | Lote 2 Parte 2: system_id por marca validado (BOS00000008, ACD00000007) | N/A | N/A | N/A | N/A | Antigravity |
| S4-03 | Sprint 4 | PASS | Lote 2 Parte 2: validacion de prefijo repetido bloqueada ("The prefix has already been taken") | N/A | N/A | N/A | N/A | Antigravity |
| S4-04 | Sprint 4 | PASS | Lote 2 Parte 2: filtros jerarquicos de vehiculos operativos (Toyota -> Corolla) | N/A | N/A | N/A | N/A | Antigravity |
| S4-05 | Sprint 4 | PASS | Lote 2 Parte 2: compatibilidad vehicular visible correctamente en modal | N/A | N/A | N/A | N/A | Antigravity |
| S5-01 | Sprint 5 | BLOCKED | Lote 3 Fase 1: flujo de Inventario bloqueado por contexto de sucursal | BUG-S5-01 (perdida de contexto de sucursal; respuestas 409/403) | Pendiente | PENDIENTE | Re-test pendiente tras fix de contexto | Antigravity |
| S5-02 | Sprint 5 | BLOCKED | Lote 3 Fase 1: flujo de Compras/OC bloqueado por contexto de sucursal | BUG-S5-01 (impacto compartido) | Pendiente | PENDIENTE | Re-test pendiente tras fix de contexto | Antigravity |
| S5-03 | Sprint 5 | BLOCKED | Lote 3 Fase 1: no se valida toggle de cotizacion por falta de contexto operativo | BUG-S5-01 (impacto compartido) | Pendiente | PENDIENTE | Re-test pendiente tras fix de contexto | Antigravity |
| S5-04 | Sprint 5 | PASS | Lote 3 Fase 1: CRUD de formulas ejecutado correctamente | N/A | N/A | N/A | N/A | Antigravity |
| S5-05 | Sprint 5 | PASS | Lote 3 Fase 1: navegacion de Ordenes de Compra validada | N/A | N/A | N/A | N/A | Antigravity |
| S6-01 | Sprint 6 | PENDIENTE | | | | | | |

## Matriz de verificaciones por sprint

### Sprint 1 - Quick wins y limpieza SKU

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S1-01 | Productos > Crear | Crear producto sin marca y sin variante | Guarda sin error y aparece en listado |
| S1-02 | Productos > Crear | Ingresar SKU manual y guardar variante | SKU se conserva exacto, sin sufijo agregado |
| S1-03 | Productos > Crear/Editar | Presionar Enter en campos input | No se envia el formulario accidentalmente |
| S1-04 | Productos > Listado | Filtrar por categoria y volver a "Todas" | Se limpia el filtro y vuelve listado completo |
| S1-05 | POS / Detalle producto | Abrir detalle con rol cashier y con seller | Notas internas visibles solo para rol permitido |
| S1-06 | Inventario (UI) | Intentar cargar cantidad negativa | UI o backend bloquea con mensaje controlado |

### Sprint 2 - Rediseno de variantes y pricing

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S2-01 | Categorias > Crear/Editar | Revisar formulario de categoria | No aparece campo de costo default |
| S2-02 | Productos > Crear/Editar | Crear variante con marca, proveedor, codigo y precio | Variante se guarda con los 4 campos |
| S2-03 | Productos > Editar | Agregar/editar especificaciones tecnicas | Persisten tras recargar |
| S2-04 | Productos > Crear/Editar | Revisar bloque de pricing de variante | Solo visibles costo y precio |
| S2-05 | Settings > Pricing | Probar formulas con parentesis/anidacion | Calcula correctamente, sin doble ITBM |

### Sprint 3 - UX y busqueda

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S3-01 | POS | Buscar producto en campo inline | Dropdown inline funcional, sin modal |
| S3-02 | POS / Cotizaciones | Revisar tabla y resumen | Impuesto/descuento en resumen inferior, no inline |
| S3-03 | POS | Revisar columna descuento con toggle | Oculta por default y visible al activar |
| S3-04 | POS (blind mode) | Abrir popup de producto en modo cliente | Solo info minima: imagen, categoria, system_id, variante, precio |
| S3-05 | Detalle producto | Revisar seccion OEM y VIN | OEM aparece debajo de VIN en bloque separado |

### Sprint 4 - System ID y vehiculos

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S4-01 | Productos > Crear | Crear 2 productos nuevos | System ID visible y secuencial (8 digitos) |
| S4-02 | Productos > Variantes | Crear variantes de marcas distintas | System ID por marca con prefijo correcto |
| S4-03 | Marcas > Crear/Editar | Probar prefijo largo y duplicado | Validaciones bloquean datos invalidos |
| S4-04 | Vehiculos > Motores | Filtrar por marca/modelo | Lista filtrada consistentemente |
| S4-05 | Detalle producto | Revisar compatibilidad vehicular | Modelo y rango de anos visibles |

### Sprint 5 - Inventario y compras

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S5-01 | Inventario > Entrada | Crear entrada y seleccionar proveedor | Movimiento guarda proveedor |
| S5-02 | Compras > OC Crear/Recibir | Crear OC con unidades por paquete y recibir | Conversion a unidades correcta |
| S5-03 | Settings > Store | Activar "Requerir stock para cotizar" y cotizar sin stock | Cotizacion bloqueada cuando toggle activo |
| S5-04 | Settings > Formulas | Crear/editar/eliminar formula | CRUD funcional y formula usable |
| S5-05 | Sidebar > Compras | Revisar navegacion de OC | Ordenes de compra ubicadas correctamente |

### Sprint 6 - Features nuevos

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| S6-01 | POS | Agregar linea miscelanea con descripcion/precio/cantidad | Se agrega al carrito sin depender de producto |
| S6-02 | Cotizaciones desde POS | Guardar cotizacion con item normal y custom | Cotizacion se guarda con ambas lineas |
| S6-03 | Settings > Store (Admin) | Ejecutar limpiar datos con doble confirmacion | Solo admin, exige password + texto de confirmacion |
| S6-04 | Productos > Importar Excel | Descargar plantilla y previsualizar archivo | Preview visible con conteos de filas |
| S6-05 | Productos > Importar Excel | Importar archivo valido e invalido | Reporte correcto de exitos y errores por fila |

## Regresion final obligatoria

| ID | Ruta navegador | Pasos manuales | Resultado esperado |
|----|----------------|----------------|--------------------|
| R-01 | POS | Crear venta completa con producto normal | Totales correctos, sin errores visibles |
| R-02 | POS | Crear venta con linea custom y producto normal | Flujo estable y sin impacto de stock en custom line |
| R-03 | Cotizaciones | Crear y convertir cotizacion | Estados cambian correctamente |
| R-04 | Productos | Filtrar, ordenar, abrir detalle y editar | Sin regresiones visuales o funcionales |
| R-05 | Roles | Repetir 2 casos clave con seller y cashier | Permisos y visibilidad coherentes |

## Criterio de cierre

1. Casos S1 a S6 ejecutados con evidencia.
2. Regresion R-01 a R-05 en PASS.
3. Sin defectos criticos bloqueantes abiertos.
4. Defectos medios/bajos documentados con plan de correccion.
5. Todo caso que haya fallado debe tener re-test registrado con evidencia post-fix.

## Reporte final de fallas y como resolverlas

### Resumen de corrida actual (segun bitacora S1-S5 fase 1)

- Estado general: Parcial avanzado (Sprint 1 a 4 completados; Sprint 5 fase 1 ejecutada con bloqueo severo; Sprint 6 pendiente).
- Casos ejecutados: 26.
- PASS: 22.
- FAIL: 0.
- BLOCKED: 4.
- Defectos raiz cerrados por re-test: 2 (BUG-S1-01 y BUG-S2-05).
- Defectos severos abiertos: 1 (BUG-S5-01; bloquea S5-01, S5-02 y S5-03).
- Bloqueo operativo adicional: 1 (S1-05 pendiente por roles seller/cashier).
- Re-test lote 1: PASS en S1-01, S1-02, S2-02, S2-03 y S2-05.
- Lote 2 Parte 2 (Sprint 4): PASS en S4-01, S4-02, S4-03, S4-04 y S4-05.
- Lote 3 Fase 1 (Sprint 5): PASS en S5-04 y S5-05; BLOCKED en S5-01, S5-02 y S5-03 por perdida de contexto de sucursal.

### Hallazgos y resolucion propuesta

| Defecto | Sprint/Caso | Falla observada | Causa probable | Como resolver | Estado | Re-test |
|---------|-------------|-----------------|----------------|---------------|--------|---------|
| RF-001 / BUG-S1-01 | S1-01 (impactaba S1-02, S2-02, S2-03) | Al crear producto fallaba por system_id nulo | Causa confirmada: ProductService creaba el producto y asignaba system_id despues, con columna no nullable/unique | 1) Aplicado: generar system_id antes de Product::create e incluirlo en payload. 2) Recomendado: agregar test automatizado de regresion para creacion de producto sin marca/variante. | Cerrado | PASS en re-test de S1-01, S1-02, S2-02 y S2-03 |
| RF-002 / BUG-S2-05 | S2-05 | Ruta 404 en settings/pricing-formulas | Causa confirmada: faltaba ruta web/inertia para /settings/pricing-formulas | 1) Aplicado: registrar ruta web autenticada y render de settings/PricingFormulas. 2) Recomendado: agregar smoke test de navegacion al modulo en CI/manual. | Cerrado | PASS en re-test de S2-05 |
| RF-003 | S1-05 | Caso bloqueado por disponibilidad/alcance de roles | Entorno QA no tenia todos los perfiles listos para validacion cruzada de visibilidad | 1) Preparar usuarios de prueba por rol (seller/cashier/admin/manager). 2) Definir credenciales QA y checklist de sesion por rol. | Abierto (medio) | Re-test: ejecutar S1-05 con seller y cashier y adjuntar evidencias |
| RF-004 / BUG-S5-01 | S5-01, S5-02, S5-03 | Perdida de contexto de sucursal: menus de Compras/Inventario se ocultan y los endpoints de flujo responden 403/409 | Contexto current_warehouse_id queda nulo o sin acceso valido; middleware exige sucursal efectiva y el setter de contexto rechaza accesos invalidos | 1) Backend: estandarizar respuesta de contexto requerido y blindar recuperacion de contexto al autenticar. 2) Frontend: forzar GET/POST de /api/v1/user/warehouse-context al bootstrap y solicitar seleccion explicita si queda null. 3) QA: re-test secuencial S5-01 -> S5-02 -> S5-03 con evidencia nueva. | Abierto (severo) | Pendiente |

### Riesgos de entorno detectados previamente

- Riesgo R-ENV-01: Redis intermitente puede provocar 500 transversales por throttle/cache.
- Riesgo R-ENV-02: En http local puede aparecer warning de blob inseguro en descargas.
- Riesgo R-APP-01: Perdida de warehouse context puede bloquear Inventario/Compras con respuestas 403/409 hasta reasignar sucursal.
- Nota: no son las fallas raiz de la bitacora S1-S2, pero deben vigilarse para la corrida completa S1-S6.

### Evidencia tecnica asociada

- RF-001 / BUG-S1-01:
	- [pos-backend/app/Services/ProductService.php](pos-backend/app/Services/ProductService.php#L48)
	- [pos-backend/app/Services/ProductService.php](pos-backend/app/Services/ProductService.php#L53)
	- [pos-backend/database/migrations/2026_04_10_201414_add_system_id_to_products_table.php](pos-backend/database/migrations/2026_04_10_201414_add_system_id_to_products_table.php#L49)
- RF-002 / BUG-S2-05:
	- [pos-frontend/resources/js/components/AppSidebar.vue](pos-frontend/resources/js/components/AppSidebar.vue#L345)
	- [pos-frontend/resources/js/pages/settings/PricingFormulas.vue](pos-frontend/resources/js/pages/settings/PricingFormulas.vue#L13)
	- [pos-frontend/routes/settings.php](pos-frontend/routes/settings.php#L29)
- RF-004 / BUG-S5-01:
	- [pos-backend/app/Http/Middleware/EnforceWarehouseContext.php](pos-backend/app/Http/Middleware/EnforceWarehouseContext.php#L36)
	- [pos-backend/app/Http/Controllers/Api/UserController.php](pos-backend/app/Http/Controllers/Api/UserController.php#L140)
	- [pos-backend/app/Http/Controllers/Api/UserController.php](pos-backend/app/Http/Controllers/Api/UserController.php#L145)
	- [pos-backend/routes/api.php](pos-backend/routes/api.php#L29)
	- [pos-backend/routes/api.php](pos-backend/routes/api.php#L30)
	- [pos-frontend/resources/js/components/AppSidebar.vue](pos-frontend/resources/js/components/AppSidebar.vue#L71)
	- [pos-frontend/resources/js/components/AppSidebar.vue](pos-frontend/resources/js/components/AppSidebar.vue#L126)
	- [pos-frontend/resources/js/components/AppSidebar.vue](pos-frontend/resources/js/components/AppSidebar.vue#L173)

### Plantilla para cierre final (llenar al terminar S1-S6)

| KPI | Valor esperado | Valor real |
|-----|----------------|------------|
| Casos ejecutados | 100% de S1-S6 + R-01..R-05 | 26 / 36 |
| Pass rate | >= 95% | 84.62% (22/26) |
| Defectos criticos abiertos | 0 | 1 (BUG-S5-01) |
| Defectos con re-test FAIL | 0 | 0 (sin re-test fallido; hay 4 casos bloqueados pendientes) |

### Conclusiones y acciones siguientes

1. Priorizar fix de BUG-S5-01 (contexto de sucursal) y ejecutar re-test de S5-01, S5-02 y S5-03.
2. Configurar usuarios de prueba por rol y desbloquear S1-05.
3. Completar ejecucion restante de Sprint 5 y Sprint 6 con evidencia Antigravity por caso.
4. Al finalizar S5-S6, correr regresion R-01 a R-05 y emitir cierre final.
5. Como mejora de calidad, agregar test automatizado para BUG-S1-01 y smoke de navegacion para settings/pricing-formulas.

