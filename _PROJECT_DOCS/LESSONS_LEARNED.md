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