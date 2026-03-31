# LESSONS_LEARNED.md
Bugs resueltos con causa raíz documentada. El agente debe leer este archivo
antes de debuggear o al trabajar en áreas relacionadas.

---

## LL-001 — Inertia layout no se propaga en componentes wrapper

**Área:** Frontend / Inertia / Vue 3
**Archivos afectados:** `SalesQuotes/Editor.vue`, `Returns/ReturnEditor.vue`

**Síntoma**
El sidebar desaparecía en `/quotes/create` y `/quotes/{id}/edit` pero
funcionaba correctamente en `/pos`.

**Causa raíz**
Inertia solo lee `defineOptions({ layout })` del componente de página que
renderiza directamente. Si una página wrapper no declara su propio layout,
Inertia no hereda el layout del componente hijo que renderiza internamente.

**Fix**
Agregar en el componente raíz que Inertia renderiza directamente:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
defineOptions({ layout: AppLayout })
</script>
```

**Regla derivada**
Todo componente que Inertia renderiza como página (registrado en `web.php`)
DEBE declarar su propio layout, aunque internamente use otro componente
que ya tenga layout definido. El layout nunca se hereda del hijo.

**Patrón de riesgo**
```
web.php → Editor.vue (sin layout ❌) → <PosIndex layout=AppLayout />
```
```
web.php → Editor.vue (con layout ✅) → <PosIndex />
```

---

## LL-002 — `confirm()` factura el total completo ignorando pagos registrados

**Área:** Backend / SalesOrder / Balance de cliente
**Archivos afectados:** `app/Models/SalesOrder.php`, `app/Services/SalesOrderService.php`

**Síntoma**
- Venta pagada al 100% generaba 2 transacciones que se cancelaban entre sí
- Venta con store_credit terminaba con balance incorrecto en el cliente

**Causa raíz**
`confirm()` siempre sumaba `$this->total` completo al `current_balance` del
cliente, sin considerar pagos ya registrados (efectivo, tarjeta, store_credit).

**Fix**
```php
$amountPaid = $this->payments()->sum('amount');
$amountToBill = max(0, (float)$this->total - (float)$amountPaid);

if ($amountToBill > 0) {
    $customer->current_balance += $amountToBill;
    $customer->save();
    // crear transacción solo con $amountToBill
}
// Si $amountToBill === 0: venta ya saldada, no crear transacción de deuda
```

**Regla derivada**
Nunca asumir que `$this->total` representa lo que el cliente debe. Siempre
calcular `total - pagos_registrados` antes de modificar el balance.

---

## LL-003 — Atributos de variante se borraban silenciosamente al editar producto

**Área:** Backend + Frontend / Productos / Variantes
**Archivos afectados:** `Products/Edit.vue`, controlador de productos

**Síntoma**
Al guardar un producto desde Edit.vue, los atributos de variante
desaparecían sin error visible.

**Causa raíz**
`confirm()` llamaba `assignVariantAttributes([])` cuando el usuario no había
tocado los atributos — el frontend enviaba un array vacío por defecto,
borrando los atributos existentes.

**Fix**
- Backend: solo llamar `assignVariantAttributes()` si el array recibido no está vacío
- Frontend: no enviar el campo `attributes` en el payload si el usuario no lo modificó

**Regla derivada**
Para operaciones destructivas (borrar, reemplazar colecciones), validar
explícitamente que el payload tiene intención real de modificar antes de ejecutar.
Nunca interpretar ausencia de dato como "borrar todo".

---

## LL-004 — Seeder con roles corruptos por registros duplicados

**Área:** Backend / Seeders / Roles
**Archivos afectados:** `database/seeders/DemoDataSeeder.php`

**Síntoma**
Usuarios en producción tenían roles incorrectos después de re-ejecutar seeders.

**Causa raíz**
Seeders usaban `create()` en lugar de `firstOrCreate()` / `updateOrCreate()`,
generando duplicados o fallando silenciosamente en entornos con datos previos.

**Fix**
Usar `updateOrCreate()` en todos los seeders para que sean idempotentes:
```php
Role::updateOrCreate(['name' => 'seller'], ['guard_name' => 'web']);
```

**Regla derivada**
Todos los seeders del proyecto deben ser idempotentes. Nunca usar `create()`
en seeders — siempre `firstOrCreate()` o `updateOrCreate()`.

---

## LL-005 — nginx interceptaba OPTIONS antes de que Laravel procesara CORS

**Área:** Infraestructura / nginx / CORS
**Archivos afectados:** configuración nginx de `api.hermexpos.app`

**Síntoma**
Requests del frontend eran bloqueados por CORS a pesar de tener
`config/cors.php` correctamente configurado en Laravel.

**Causa raíz**
nginx tenía un bloque que respondía a requests OPTIONS directamente,
antes de que llegaran a Laravel. Laravel nunca procesaba esos preflight
requests y por lo tanto nunca enviaba los headers CORS correctos.

**Fix**
Eliminar el interceptor OPTIONS de nginx. Dejar que todos los requests
(incluyendo OPTIONS) pasen a Laravel, que es quien maneja CORS.

**Regla derivada**
En este stack, CORS es responsabilidad exclusiva de Laravel (`HandleCors`
en el grupo `api`). nginx no debe interceptar ni responder OPTIONS.

---

## LL-006 — Vendedor redirigido a /pos al enviar cotización a caja

**Área:** Frontend / Flujo cotización → caja
**Archivos afectados:** componente de transición de estados en cotizaciones

**Síntoma**
Al hacer "Enviar a caja", el seller era redirigido al POS en lugar de
quedarse en la lista de cotizaciones.

**Causa raíz**
`handleTransition()` llamaba `openQuoteInPOS()` después de cambiar el estado,
lo que navegaba al seller al POS — pantalla que no le corresponde.

**Fix**
Eliminar la llamada a `openQuoteInPOS()` en `handleTransition()`.
La lista se refresca sola; el seller no debe ser redirigido.

**Regla derivada**
Las transiciones de estado no deben asumir navegación. Separar
"cambiar estado" de "navegar a siguiente pantalla" — el cashier
navega por su cuenta desde su propia vista.

---

## LL-007 — USERNAME no se guardaba al editar usuario

**Área:** Backend / Modelo User
**Archivos afectados:** `app/Models/User.php`

**Síntoma**
Al editar un usuario, el campo `username` no se actualizaba en la base de datos.

**Causa raíz**
El campo `username` no estaba incluido en el array `$fillable` de `User.php`.
Laravel bloqueaba la asignación masiva silenciosamente.

**Fix**
Agregar `'username'` al array `$fillable` en `User.php`.

**Regla derivada**
Al agregar nuevos campos a cualquier modelo, verificar siempre `$fillable`.
Los errores de mass assignment no lanzan excepción por defecto — fallan
silenciosamente, lo que los hace difíciles de detectar.
## LL-008 — clearCart() en returnMode destruía venta activa del cajero

**Área:** Frontend / POS / usePOS composable
**Archivos afectados:** Pos/Index.vue, usePOS.ts

**Síntoma**
Al procesar una devolución, el cajero perdía los productos que tenía
en su carrito de venta activo.

**Causa raíz**
loadOrderForReturn() llamaba clearCart() para limpiar antes de cargar
los items de la devolución — borrando la sesión activa del cajero.

**Fix**
Aprovechar el sistema multi-cart existente:
1. Guardar activeSessionId en sessionStorage antes de entrar a returnMode
2. Crear nueva sesión temporal "Devolución en Curso"
3. En onUnmounted: destruir sesión temporal y restaurar sessionStorage

**Regla derivada**
Nunca llamar clearCart() al inicializar un modo alternativo del POS.
Crear una sesión paralela y destruirla al salir — el estado del cajero
es intocable.
## LL-009 — Wrapper de Inertia sin layout hereda el error de LL-001

**Área:** Frontend / Inertia / Vue 3
**Archivos afectados:** EditOrderEditor.vue

**Regla derivada**
Todo componente wrapper que Inertia renderiza como página DEBE declarar
su propio layout. Patrón obligatorio para cualquier *Editor.vue nuevo:

import AppLayout from '@/layouts/AppLayout.vue'
defineOptions({ layout: AppLayout })

Aplica a: ReturnEditor.vue, EditOrderEditor.vue, y cualquier wrapper futuro.

## LL-010 — Reducción de cantidades en editMode debe delegarse a returns

**Área:** Backend + Frontend / SalesOrder edit flow

**Regla derivada**
editMode solo permite aumentar cantidades o agregar productos nuevos.
Cualquier reducción pertenece al flujo de devoluciones (/returns/{id}).
Validar en frontend (input min = originalQty) Y en backend (rechazar
diff negativo con 422).

## LL-011 — Supply List es entidad separada de PurchaseOrder

**Área:** Backend / Órdenes de Compra
**Archivos afectados:** SupplyListController.php, SupplyListItem.php

**Regla derivada**
La lista de abastecimiento es intención de compra temporal, no una OC.
Nunca mezclar modelos. El flujo es:
  supply_list_items → convert() → PurchaseOrder(s) agrupadas por supplier_id
Items sin proveedor crean OC con supplier_id=null (permitido por schema).

## LL-012 — Precio de venta editable en OC actualiza ProductPricing, no Product

**Área:** Backend / PurchaseOrderService
**Archivos afectados:** PurchaseOrderService.php

**Regla derivada**
El precio de venta vive en product_pricing.base_price, no en products.price.
Al actualizar precio desde OC, siempre ir por $item->variant->pricing
o $item->product->pricing. Solo actualizar si sale_price > 0 Y es
diferente al valor actual — evitar escrituras innecesarias.

## LL-013 — Auto-cálculo de precio por margen de categoría solo en Create, no Edit

**Área:** Frontend / Products/Create.vue
**Archivos afectados:** Products/Create.vue, Categories/Create.vue, Categories/Edit.vue

**Regla derivada**
El auto-cálculo de base_price por default_margin_percent aplica SOLO
en Create.vue (producto nuevo). Edit.vue usa edición por API campo a campo.
El flag priceManuallyEdited previene sobreescribir el precio si el usuario
lo tocó. Reset solo via botón explícito "↺ Usar margen de categoría",
nunca automáticamente al detectar valor 0.

## LL-014 — Herencia de atributos del producto padre a variantes nuevas (R27)

**Área:** Backend / ProductVariantController
**Archivos afectados:** app/Http/Controllers/Api/ProductVariantController.php

**Síntoma**
Al crear una variante nueva, el usuario debe rellenar manualmente todos
los atributos aunque sean idénticos al producto padre.

**Causa raíz**
El controlador de variantes no copiaba automáticamente los atributos del
producto padre cuando no se proveían atributos en el request.

**Fix**
En `ProductVariantController::store()`, después de crear la variante,
si no se proveen atributos (`!empty($validated['attributes'])`), se
heredan automáticamente los atributos del producto padre usando
`->attributes->map()` y `assignVariantAttributes()`.

**Regla derivada**
Al crear una variante nueva SIN atributos especificados, SIEMPRE heredar
los atributos del producto padre. NO sobreescribir si la variante ya tiene
atributos propios (solo aplicar en creación, no en actualización).

## LL-015 — Stock mínimo cero: validaciones para prevenir inventario negativo (R28)

**Área:** Backend / Services de inventario
**Archivos afectados:**
- app/Services/SalesOrderService.php
- app/Services/PurchaseOrderService.php
- database/migrations/2026_03_25_000003_add_stock_non_negative_check.php

**Síntoma**
El sistema permitía que el inventario bajara a valores negativos sin validación.

**Causa raíz**
Varias operaciones de descuento de inventario no validaban si había stock
suficiente antes de decrementar `quantity_available`.

**Fix**
1. **SalesOrderService::create()** - Agregada validación antes de reservar stock
   en órdenes draft/pending. Se valida todo el batch de items ANTES de reservar.
2. **PurchaseOrderService::cancel()** - Agregada validación antes de revertir
   recepción de OC, con mensaje descriptivo si hay inconsistencia de inventario.
3. **Migración opcional** - Creada migración que agrega CHECK constraint
   `stock_non_negative` solo si no hay registros con stock negativo existente.
   Si detecta stock negativo, lanza excepción con ejemplos para que el usuario
   corrija manualmente.
4. **Frontend** - Verificado que inputs de inventario tienen `min="0"` y
   badge rojo para productos sin stock ya existe en ProductCard.vue.

**Regla derivada**
- SIEMPRE validar stock disponible ANTES de decrementar inventory.
- Para operaciones batch (múltiples items), validar TODO primero, ejecutar después.
- El constraint de BD es opcional - solo aplicar si no hay datos negativos existentes.
- Al cancelar operaciones de entrada (OC), validar que haya stock suficiente
  para revertir la operación, o reportar inconsistencia de inventario.
- Servicios que ya validan: InventoryTransferService, InventoryMovementService,
  WorkOrderController (no requieren modificación).

## LL-016 — Métodos de pago hardcodeados en 20+ archivos bloquean agregar nuevos métodos

**Área:** Backend + Frontend / Métodos de pago
**Archivos afectados:** 20+ archivos (controllers, requests, models, views, componentes)

**Síntoma**
Para agregar "Yappy" como método de pago, había que modificar:
- Validaciones en Form Requests: `'in:cash,card,transfer,credit'`
- Match statements en modelos: `match($method) { 'cash' => 'Efectivo', ... }`
- Arrays hardcodeados en vistas de ticket y cierre de caja
- Componentes Vue con listas hardcodeadas
- Migraciones con ENUMs que requerían nueva migración para cada método

**Causa raíz**
Los métodos de pago eran una lista estática distribuida en múltiples capas
de la aplicación. Cada nuevo método requería cambios en backend (validación,
presentación) y frontend (componentes, tipos).

**Fix**
Implementar el patrón "De hardcodeado a configurable" (ver PATTERNS.md #17):

1. **Backend:**
   - Migración `payment_methods` con campos: name, code, is_active, is_system, sort_order
   - Modelo `PaymentMethod` con scopes (active, system, ordered)
   - Seeder idempotente con métodos sistema (cash, card, transfer, credit, store_credit)
   - Controller CRUD + reorder endpoint
   - Helper estático `PaymentMethod::getNameByCode()` para formatear históricos

2. **Frontend:**
   - Service layer `paymentMethodsService.ts`
   - Composable `usePaymentMethods()` con helpers semánticos
   - UI de administración en Settings/Store.vue (agregar, activar/desactivar, reordenar)
   - Reemplazar arrays hardcodeados por `methods.value.filter(...)` en todos los componentes

3. **Compatibilidad:**
   - Códigos en `order_payments.method` son datos históricos — nunca cambiarlos
   - Lógica especial de `credit` y `store_credit` sigue operando por código, no por nombre
   - Métodos archivados siguen mostrando nombre en órdenes antiguas vía `getNameByCode()`

**Regla derivada**
Cuando una lista hardcodeada aparece en 3+ archivos Y el admin debe poder
modificarla sin desplegar código, aplicar el patrón configurable:
- Crear tabla de configuración
- Seedear valores actuales como registros
- Helper estático para compatibilidad histórica
- UI de administración con CRUD + reorder
- Métodos "sistema" protegidos (is_system=true, no archivables)

**Archivos cambiados:**
- Backend: 12 archivos (SalesOrderController, PosCheckoutRequest, SalesOrderPayment, migraciones, etc.)
- Frontend: 8 archivos (PaymentMethods.vue, AddPaymentModal.vue, CloseSessionModal.vue, Settings/Store.vue, etc.)

**Resultado:**
Agregar "Yappy" ahora es: Settings > Métodos de Pago > + Agregar > "Yappy".
Aparece automáticamente en POS, tickets, cierre de caja, históricos.
## LL-017 — removeFromCashierQueue retrocede a 'sent', no a 'draft'

**Área:** Backend / SalesQuoteController
**Archivos afectados:** SalesQuoteController.php

**Regla derivada**
Al quitar una cotización de la cola de caja, el status correcto es
'sent' (estado anterior al envío a caja), no 'draft'.
Solo se limpia sent_to_cashier_at. El historial de estados queda intacto.

## LL-018 — Cola de Bodega es independiente de SupplyList

**Área:** Backend + Frontend / Warehouse
**Archivos afectados:** useWarehouseQueue.ts, Warehouse/Queue.vue

**Regla derivada**
SupplyList (R29) = lista pre-compra para generar OC por proveedor.
Warehouse Queue (R35) = cola post-cobro para preparación física.
Son sistemas completamente independientes. No mezclar modelos ni endpoints.
Warehouse Queue vive en sales_orders (sent_to_warehouse_at, prepared_at, prepared_by).
## LL-019 — Stock negativo controlado por setting, no por constraint de BD

**Área:** Backend / Inventario
**Archivos afectados:** SalesOrderService.php, InventoryTransferService.php,
                        InventoryMovementService.php

**Regla derivada**
La protección de stock mínimo cero vive en código (allow_negative_inventory
de Settings), NO en constraint de BD. Esto permite que el admin habilite
negativos si su negocio lo requiere.
La validación debe aplicarse en TODOS los puntos de descuento de inventario:
- SalesOrderService@confirm
- InventoryTransferService
- InventoryMovementService
Si se agrega un nuevo punto de descuento en el futuro, agregar el mismo
check en ese método.

## LL-020 — returnMode usa cantidades negativas intencionalmente

**Área:** Frontend / POS
**Archivos afectados:** ProductTable.vue, Pos/Index.vue

**Regla derivada**
Los inputs de cantidad en el POS NO deben tener min="0" cuando
returnMode=true — las devoluciones requieren cantidades negativas.
Solo aplicar min="0" en formularios de ajuste manual de inventario
fuera del POS (Dashboard, Movimientos, Waste, Lotes).
## LL-021 — OC Global Diaria: supplier_id vive en el item, no en la cabecera

**Área:** Backend / PurchaseOrder / PurchaseOrderItem
**Archivos afectados:** PurchaseOrderItem.php, PurchaseOrder.php,
                        PurchaseOrderService.php, api_purchase_orders.php

**Regla derivada**
A partir de 2026-03-27, el modelo de OC cambió de "1 OC por Proveedor"
a "1 OC por Día" (bucket diario). Implicaciones:

- supplier_id en purchase_orders → nullable (retrocompatibilidad)
- supplier_id en purchase_order_items → obligatorio para items nuevos
- OC diaria tiene status='open' y display_name="OC del [fecha]"
- Quick Purchase = siempre contado, sin CxP, stock sube inmediatamente
- Filtro por proveedor busca en header Y en items

Para obtener la OC del día: getOrCreateDailyPurchaseOrder()
Para agregar desde POS: addItemToDailyPO() o quickPurchase() v2
Para modo recepción batch: batchAddItemsToDailyPO()

**OC históricas:** supplier_id de la cabecera fue migrado a todos
sus items existentes. effective_supplier accessor maneja ambos casos.

## LL-022 — Quick Purchase v2 no crea OC individual, añade a OC diaria

**Área:** Backend + Frontend / POS / QuickPurchaseModal
**Archivos afectados:** QuickPurchaseModal.vue, PurchaseOrderService.php

**Regla derivada**
QuickPurchaseModal sigue siendo la misma UI, pero el endpoint
cambió de crear una OC nueva a POST /purchase-orders/quick-purchase
que usa getOrCreateDailyPurchaseOrder() internamente.
El stock sube inmediatamente al procesar — no espera cierre de la OC.