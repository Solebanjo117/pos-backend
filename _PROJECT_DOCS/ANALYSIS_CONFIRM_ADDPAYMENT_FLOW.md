# ANÁLISIS — Flujo de confirm() y addPayment()

## Fecha
2026-03-22

## Propósito
Mapear el flujo completo de `SalesOrder::confirm()` y `SalesOrder::addPayment()` para entender cuándo y por qué se ejecutan ambos en una venta del POS.

---

## 1. DÓNDE SE LLAMA `confirm()`

### Ubicación Principal
**SalesOrderService.php** línea 180:
```php
// Auto-confirm if status is not draft
if (in_array($requestedStatus, ['confirmed', 'processing', 'completed'])) {
    $order->confirm();
} else {
    $order->update(['status' => $requestedStatus]);
}
```

### Endpoint Manual
**SalesOrderController.php** línea 354:
```php
public function confirm(int $id): JsonResponse
{
    try {
        $order = $this->salesOrderService->confirmOrder($id);
        return response()->json([
            'message' => 'Orden confirmada exitosamente',
            'data' => $order,
        ]);
    }
    // ...
}
```
Ruta: `POST /api/sales-orders/{id}/confirm`

---

## 2. DÓNDE SE LLAMA `addPayment()`

### Ubicación en el Servicio
**SalesOrderService.php** líneas 148-176:
```php
// Handle payments from POS (multiple or single)
if (isset($data['payments']) && is_array($data['payments'])) {
    foreach ($data['payments'] as $payment) {
        if (isset($payment['amount']) && (float)$payment['amount'] > 0) {
            $order->addPayment(
                amount: (float)$payment['amount'],
                method: $payment['method'] ?? 'cash',
                notes: $payment['notes'] ?? 'Pago POS'
            );
        }
    }
} elseif (isset($data['initial_payment']) && isset($data['payment_method'])) {
    // Legacy support for single payment
    if ((float)$data['initial_payment'] > 0) {
        $order->addPayment(
            amount: (float)$data['initial_payment'],
            method: $data['payment_method'],
            notes: 'Pago inicial POS'
        );
    }
} else {
    // Legacy support if no initial payment was passed but payment_status is 'paid'
    if (($data['payment_status'] ?? null) === 'paid') {
        $order->addPayment(
            amount: $order->total,
            method: $data['payment_method'] ?? 'cash',
            notes: 'Pago completo POS'
        );
    }
}
```

---

## 3. ORDEN DE EJECUCIÓN EN UNA VENTA DEL POS

Cuando el frontend envía `POST /sales-orders` con `payments[]` y sin status explícito:

```
SalesOrderController::store(PosCheckoutRequest)
  └─> SalesOrderService::create(array $data)
       │
       ├─ 1. Crea SalesOrder con status='draft' (línea 106)
       ├─ 2. Agrega items con addItem() (líneas 121-125)
       ├─ 3. Reserva stock SI es draft/pending (líneas 129-145)
       │
       ├─ 4. 🔴 LLAMA addPayment() para cada pago (líneas 148-157)
       │      └─> SalesOrder::addPayment($amount, $method, $notes)
       │           ├─ Crea registro en sales_order_payments
       │           ├─ Recalcula payment_status (paid/partial/pending)
       │           └─ REDUCE balance del cliente:
       │              customer->current_balance -= $amount
       │              (ejemplo: balance 0 - 100 = -100)
       │           └─ Crea CustomerTransaction type='payment'
       │
       └─ 5. 🔴 LLAMA confirm() si status != 'draft' (línea 180)
              └─> SalesOrder::confirm()
                   ├─ Valida stock (líneas 203-220)
                   ├─ Crea InventoryMovement type='out' (líneas 223-235)
                   ├─ Descuenta de quantity_available (líneas 238-255)
                   ├─ Cambia status='confirmed' (línea 258)
                   │
                   └─ CARGA total al balance del cliente (líneas 261-281):
                      customer->current_balance += $order->total
                      (ejemplo: balance -100 + 100 = 0)
                      Crea CustomerTransaction type='sale'
```

---

## 4. EL PROBLEMA

### Código de confirm()
**SalesOrder.php** líneas 261-281:
```php
// Charge debt to customer if applicable
if ($this->customer_id) {
    $customer = \App\Models\Customer::find($this->customer_id);
    if ($customer) {
        $balanceBefore = (float) $customer->current_balance;
        $customer->current_balance = $balanceBefore + (float) $this->total;  // ← SIEMPRE CARGA EL TOTAL
        $customer->save();

        \App\Models\CustomerTransaction::create([
            'customer_id'      => $customer->id,
            'type'             => 'sale',                    // ← TIPO 'sale'
            'reference_type'   => 'sales_order',
            'reference_id'     => $this->id,
            'amount'           => (float) $this->total,      // ← TOTAL COMPLETO
            'balance_before'   => $balanceBefore,
            'balance_after'    => $customer->current_balance,
            'description'      => "Cargo por venta {$this->order_number}",
            'transaction_date' => now(),
            'created_by'       => auth()->id(),
        ]);
    }
}
```

### Código de addPayment()
**SalesOrder.php** líneas 333-366:
```php
// Reduce customer debt (or apply store_credit)
if ($this->customer_id) {
    $customer = \App\Models\Customer::find($this->customer_id);
    if ($customer) {
        $balanceBefore = (float) $customer->current_balance;

        // For store_credit, we INCREASE the balance (use up the credit)
        // For other methods, we DECREASE the balance (reduce debt)
        if ($method === 'store_credit') {
            // Store credit: balance is negative, add amount to bring it closer to 0
            $customer->current_balance = $balanceBefore + $amount;
            $transactionType = 'store_credit_used';
            $description = "Saldo a favor aplicado a venta {$this->order_number}";
        } else {
            $customer->current_balance = $balanceBefore - $amount;  // ← REDUCE DEUDA
            $transactionType = 'payment';
            $description = "Abono a venta {$this->order_number}";
        }
        $customer->save();

        \App\Models\CustomerTransaction::create([
            'customer_id'      => $customer->id,
            'type'             => $transactionType,
            'reference_type'   => 'sales_order_payment',
            'reference_id'     => $payment->id,
            'amount'           => $amount,
            'balance_before'   => $balanceBefore,
            'balance_after'    => $customer->current_balance,
            'description'      => $description,
            'transaction_date' => $paidAt ?? now(),
            'created_by'       => auth()->id(),
        ]);
    }
}
```

---

## 5. ESCENARIOS DE PRUEBA

### ✅ Escenario 1: VENTA A CRÉDITO PURO (SIN PAGOS)
```
Cliente inicial: balance=0, credit_limit=500
Venta: $100, payments=[]

EJECUCIÓN:
1. addPayment() NO se llama (array vacío)
2. confirm() ejecuta:
   - balance = 0 + 100 = 100
   - Crea CustomerTransaction: type='sale', amount=100

RESULTADO FINAL:
- balance = 100
- Transacciones:
  └─ SALE: +$100 "Cargo por venta ORD-20260322-0001"

✓ CORRECTO
```

### ✅ Escenario 2: VENTA CON PAGO PARCIAL
```
Cliente inicial: balance=0
Venta: $100
Pagos: $30 efectivo, resto a crédito

EJECUCIÓN:
1. addPayment($30, 'cash'):
   - balance = 0 - 30 = -30
   - payment_status = 'partial'
   - Crea CustomerTransaction: type='payment', amount=30

2. confirm():
   - balance = -30 + 100 = 70
   - Crea CustomerTransaction: type='sale', amount=100

RESULTADO FINAL:
- balance = 70 (debe $70)
- Transacciones (orden cronológico):
  └─ PAYMENT: -$30 "Abono a venta ORD-20260322-0001"
  └─ SALE: +$100 "Cargo por venta ORD-20260322-0001"

✓ CORRECTO MATEMÁTICAMENTE
⚠️ ORDEN CONFUSO (pago antes que cargo)
```

### ⚠️ Escenario 3: VENTA CON PAGO COMPLETO
```
Cliente inicial: balance=0
Venta: $100
Pagos: $100 efectivo

EJECUCIÓN:
1. addPayment($100, 'cash'):
   - balance = 0 - 100 = -100
   - payment_status = 'paid'
   - Crea CustomerTransaction: type='payment', amount=100

2. confirm():
   - balance = -100 + 100 = 0
   - Crea CustomerTransaction: type='sale', amount=100

RESULTADO FINAL:
- balance = 0 (nada pendiente)
- Transacciones (orden cronológico):
  └─ PAYMENT: -$100 "Abono a venta ORD-20260322-0001"
  └─ SALE: +$100 "Cargo por venta ORD-20260322-0001"

✓ CORRECTO MATEMÁTICAMENTE
❌ CONFUSO PARA EL USUARIO:
   - Parece que pagó $100 y luego se le cargó $100
   - Balance queda en 0, pero pasó por -100 temporalmente
   - Las transacciones sugieren que primero pagó deuda inexistente
```

### ✅ Escenario 4: VENTA CON STORE_CREDIT
```
Cliente inicial: balance=-50 (tiene $50 de saldo a favor)
Venta: $30
Pagos: $30 store_credit

EJECUCIÓN:
1. addPayment($30, 'store_credit'):
   - balance = -50 + 30 = -20
   - payment_status = 'paid'
   - Crea CustomerTransaction: type='store_credit_used', amount=30

2. confirm():
   - balance = -20 + 30 = 10
   - Crea CustomerTransaction: type='sale', amount=30

RESULTADO FINAL:
- balance = 10 (debe $10)
- Transacciones:
  └─ STORE_CREDIT_USED: +$30 "Saldo a favor aplicado..."
  └─ SALE: +$30 "Cargo por venta..."

⚠️ INCORRECTO: Debería quedar en -20 (todavía tiene $20 a favor)
❌ PROBLEMA: confirm() suma el total SIN verificar si ya se pagó con store_credit
```

---

## 6. ¿POR QUÉ SE EJECUTAN AMBOS?

**CONDICIÓN EN SALESORDERSERVICE.PHP**:

```php
// Línea 150
if (isset($payment['amount']) && (float)$payment['amount'] > 0) {
    $order->addPayment(...);  // ← Solo se llama si amount > 0
}

// Línea 180
if (in_array($requestedStatus, ['confirmed', 'processing', 'completed'])) {
    $order->confirm();  // ← Siempre se llama si no es draft
}
```

**Por defecto**, el frontend del POS envía ventas sin especificar `status`, por lo que:
- `$requestedStatus = $data['status'] ?? 'draft'` → **'draft'**

Pero en el código original, parece que había lógica para auto-confirmar.

**VERIFICACIÓN**: ¿Cuál es el status efectivo que se está enviando desde el frontend?

---

## 7. ANÁLISIS DEL PROBLEMA REAL

### Problema Identificado
**En el Escenario 4 (store_credit)**, la lógica es incorrecta porque:

1. `addPayment('store_credit', $30)`:
   - Balance: -50 + 30 = -20 ✓ CORRECTO (consume saldo a favor)

2. `confirm()`:
   - Balance: -20 + 30 = 10 ❌ INCORRECTO
   - **confirm() NO DEBERÍA cargar el total si ya se pagó completamente**

### La Lógica Actual en confirm()
```php
// SalesOrder.php líneas 261-281
// Charge debt to customer if applicable
if ($this->customer_id) {
    $customer = \App\Models\Customer::find($this->customer_id);
    if ($customer) {
        $balanceBefore = (float) $customer->current_balance;
        $customer->current_balance = $balanceBefore + (float) $this->total;  // ← NO CONSIDERA payment_status
        $customer->save();
        // ...
    }
}
```

**PROBLEMA**: `confirm()` siempre carga el total completo al balance, sin importar si `payment_status = 'paid'`.

---

## 8. ORDEN CRONOLÓGICO DE TRANSACCIONES

### Orden Actual (Confuso)
```
1. PAYMENT: -$100 → balance = -100
2. SALE:    +$100 → balance = 0
```

### Orden Lógico Esperado
```
1. SALE:    +$100 → balance = 100  (cargo por venta)
2. PAYMENT: -$100 → balance = 0    (abono del cliente)
```

### Por Qué el Orden Actual es Confuso
- El cliente ve primero un "Abono" de $100 cuando aún no había deuda
- Luego ve el "Cargo" por la venta
- En la UI de historial de transacciones, aparece como si hubiera pagado antes de deber

---

## 9. CONCLUSIÓN

### ✅ Funciona Matemáticamente
El balance final es correcto en todos los escenarios excepto cuando se usa `store_credit`.

### ❌ Problemas Identificados

1. **CRÍTICO - store_credit**: En el escenario 4, cuando se paga con saldo a favor:
   - addPayment() incrementa balance correctamente (consume saldo a favor)
   - confirm() luego suma el total completo SIN considerar que ya se pagó
   - **RESULTADO**: El cliente termina debiendo cuando debería quedar con saldo a favor

2. **UX CONFUSA - Orden de transacciones**: El "Pago" aparece ANTES que el "Cargo" en el historial, lo cual es contraintuitivo.

3. **DUPLICACIÓN CONCEPTUAL**: Para ventas con pago completo, se crean 2 transacciones que se cancelan entre sí, cuando debería ser solo 1 transacción neta.

---

## 10. SOLUCIONES PROPUESTAS

### Opción A: confirm() debe considerar payment_status
```php
// En SalesOrder.php confirm()
if ($this->customer_id) {
    $customer = \App\Models\Customer::find($this->customer_id);
    if ($customer) {
        // Solo cargar el monto PENDIENTE, no el total
        $amountToCharge = $this->amount_due; // total - amount_paid

        if ($amountToCharge > 0) {
            $balanceBefore = (float) $customer->current_balance;
            $customer->current_balance = $balanceBefore + $amountToCharge;
            $customer->save();

            \App\Models\CustomerTransaction::create([
                'customer_id' => $customer->id,
                'type' => 'sale',
                'amount' => $amountToCharge,  // ← Solo lo que falta por pagar
                // ...
            ]);
        }
    }
}
```

### Opción B: Invertir el orden (confirm primero, luego addPayment)
```php
// En SalesOrderService.php create()

// 1. Confirmar PRIMERO (carga deuda completa)
if (in_array($requestedStatus, ['confirmed', 'processing', 'completed'])) {
    $order->confirm();
}

// 2. LUEGO registrar pagos (reduce deuda)
if (isset($data['payments']) && is_array($data['payments'])) {
    foreach ($data['payments'] as $payment) {
        if (isset($payment['amount']) && (float)$payment['amount'] > 0) {
            $order->addPayment(...);
        }
    }
}
```

---

## 11. RECOMENDACIÓN

**OPCIÓN A** es más limpia porque:
- No requiere cambiar el orden de ejecución
- confirm() solo carga lo que realmente queda pendiente
- Elimina transacciones redundantes cuando payment_status='paid'
- Funciona correctamente con store_credit

**Cambio mínimo requerido**:
```php
// SalesOrder.php línea 261 - reemplazar:
$customer->current_balance = $balanceBefore + (float) $this->total;

// Por:
$amountToBill = max(0, (float)$this->total - (float)$this->amount_paid);
if ($amountToBill > 0) {
    $customer->current_balance = $balanceBefore + $amountToBill;
}
```

---

## ARCHIVOS INVOLUCRADOS

1. `pos-backend/app/Models/SalesOrder.php`
   - Método `confirm()` (líneas 194-291)
   - Método `addPayment()` (líneas 312-374)

2. `pos-backend/app/Services/SalesOrderService.php`
   - Método `create()` (líneas 77-198)
   - Orden de ejecución: addPayment → confirm

3. `pos-backend/app/Http/Controllers/Api/SalesOrderController.php`
   - Método `store()` (líneas 76-267)
   - Valida y delega a SalesOrderService

4. `pos-backend/app/Http/Controllers/Api/PosController.php`
   - Solo maneja settings del POS
   - NO participa en el flujo de ventas

---

## PRÓXIMOS PASOS

1. Decidir entre Opción A u Opción B
2. Implementar fix en `SalesOrder.php`
3. Crear tests P2 para:
   - Venta con pago completo (balance debe quedar en 0)
   - Venta con pago parcial (balance debe ser total - pago)
   - Venta con store_credit (balance debe reflejar saldo consumido)
4. Verificar que no se rompan los tests P0 existentes
