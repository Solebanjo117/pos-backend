# HermeX POS — Patrones de Código

Documentación de los patrones reales implementados en el proyecto.

---

## 1. Laravel — Estructura de Controladores

### Patrón: Controlador delgado con Service

Los controladores **solo** coordinan la request/response. La lógica de negocio vive en Services.

**Ejemplo real:** `app/Http/Controllers/Api/SalesOrderController.php`

```php
class SalesOrderController extends Controller
{
    protected SalesOrderService $salesOrderService;

    public function __construct(SalesOrderService $salesOrderService)
    {
        $this->salesOrderService = $salesOrderService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'search', 'status', 'payment_status',
                'customer_id', 'warehouse_id'
            ]);

            $orders = $this->salesOrderService->getAll($filters);

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener órdenes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(PosCheckoutRequest $request): JsonResponse
    {
        // Validación adicional específica del controlador
        $validated = $request->validate([...]);

        // Lógica de negocio delegada al Service
        $order = $this->salesOrderService->create($validated);

        return response()->json($order, 201);
    }
}
```

**✅ Correcto:**
- Inyección de dependencias en constructor
- Try-catch con mensajes claros en español
- Delegación de lógica al Service
- Solo validación y respuesta HTTP en el controlador

**❌ Incorrecto:**
- Lógica de negocio directamente en el controlador
- Queries Eloquent complejas en el controlador
- Transacciones DB en el controlador

---

## 2. Laravel — Services

### Patrón: Service con métodos de negocio

Los Services contienen toda la lógica de negocio, transacciones, y queries complejas.

**Ejemplo real:** `app/Services/SalesOrderService.php`

```php
class SalesOrderService
{
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = SalesOrder::with(['customer', 'user', 'items.product']);

        // Apply filters usando scopes del modelo
        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ilike', "%{$search}%")
                  ->orWhereHas('customer', function ($q) use ($search) {
                      $q->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        return $query->orderBy('created_at', 'desc')
                     ->paginate($filters['per_page'] ?? 20);
    }

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            // Validación de stock
            $allowNegative = Setting::get('allow_negative_inventory', false);
            if (!$allowNegative) {
                $this->checkStockAvailability($data['items']);
            }

            // Crear orden
            $order = SalesOrder::create([...]);

            // Agregar items
            foreach ($data['items'] as $itemData) {
                $this->addItem($order->id, $itemData);
            }

            // Procesar pagos
            if (isset($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    $order->addPayment(...);
                }
            }

            // Auto-confirmar si status no es draft
            if (in_array($data['status'], ['confirmed', 'processing'])) {
                $order->confirm();
            }

            return $order->refresh();
        });
    }
}
```

**✅ Correcto:**
- Usar `DB::transaction()` para operaciones multi-paso
- Aplicar scopes del modelo para queries complejas
- Eager loading con `with()` para relaciones
- Mensajes de error en español

**❌ Incorrecto:**
- Lógica de negocio en el modelo
- Sin transacciones para operaciones críticas
- N+1 queries por falta de eager loading

---

## 3. Laravel — Form Requests

### Patrón: Validación centralizada

**Ejemplo real:** `app/Http/Requests/PosCheckoutRequest.php`

```php
class PosCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Validar roles permitidos
        $user = $this->user();
        return $user && in_array($user->role, ['admin', 'manager', 'cashier', 'seller']);
    }

    public function rules(): array
    {
        return [
            'customer_id'       => 'nullable|exists:customers,id',
            'status'            => 'nullable|in:draft,pending,confirmed',
            'payments'          => 'nullable|array',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
            'payments.*.method' => 'required_with:payments|in:cash,card,transfer,credit,store_credit',
            'items'             => 'nullable|array',
            'items.*.product_id'    => 'required|exists:products,id',
            'items.*.variant_id'    => 'nullable|exists:product_variants,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.unit_price'    => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'El cliente seleccionado no existe.',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1.',
        ];
    }
}
```

**✅ Correcto:**
- Validación de autorización en `authorize()`
- Reglas claras y específicas
- Mensajes personalizados en español
- Validación anidada para arrays

**❌ Incorrecto:**
- Dejar `authorize()` siempre true sin validar roles
- Validar en el controlador en lugar del Form Request
- Mensajes en inglés

---

## 4. Laravel — Rutas API

### Patrón: Agrupación modular

**Ejemplo real:** `routes/api.php`

```php
// Patrón principal: middleware + prefix + archivos modulares
Route::middleware(['web', 'auth', 'throttle:120,1'])
    ->prefix('v1')
    ->group(function () {

    // Públicas a todos autenticados
    require __DIR__ . '/api_dashboard.php';
    require __DIR__ . '/api_products.php';
    require __DIR__ . '/api_customers.php';

    // POS & Sales: seller, cashier, manager, admin
    require __DIR__ . '/api_sales_orders.php';
    require __DIR__ . '/api_pos_sessions.php';

    // Purchasing: buyer, manager, admin
    require __DIR__ . '/api_purchase_orders.php';
    require __DIR__ . '/api_suppliers.php';

    // Admin only
    require __DIR__ . '/api_users.php';
    require __DIR__ . '/api_backup.php';
});
```

**Archivo individual:** `routes/api_sales_orders.php`

```php
use App\Http\Controllers\Api\SalesOrderController;

Route::middleware(['role:admin,manager,cashier,seller'])->group(function () {
    Route::get('/sales-orders', [SalesOrderController::class, 'index']);
    Route::post('/sales-orders', [SalesOrderController::class, 'store']);
    Route::get('/sales-orders/{id}', [SalesOrderController::class, 'show']);
    Route::put('/sales-orders/{id}', [SalesOrderController::class, 'update']);
    Route::delete('/sales-orders/{id}', [SalesOrderController::class, 'destroy']);
});
```

**✅ Correcto:**
- Prefijo `/v1` para versionado
- Throttle global `120,1` (120 requests/minuto)
- Middleware `auth` + `web` para Sanctum
- Archivos separados por área funcional
- Middleware `role:` para control de acceso

**❌ Incorrecto:**
- Rutas sin middleware de autenticación
- Sin throttling (permite ataques)
- Todas las rutas en un solo archivo

---

## 5. Laravel — Seeders Idempotentes

### Patrón: updateOrCreate / firstOrCreate

Los seeders deben poder ejecutarse múltiples veces sin duplicar datos.

**Ejemplo real:** `database/seeders/DemoDataSeeder.php`

```php
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ updateOrCreate: actualiza si existe, crea si no
        $admin = User::updateOrCreate(
            ['email' => 'admin@hermes.com'],  // Criterio de búsqueda
            [
                'name' => 'Admin Principal',
                'username' => 'admin',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true
            ]
        );

        // ✅ firstOrCreate: crea solo si no existe
        $customerData = [
            ['name' => 'Taller AutoMax', 'email' => 'compras@automax.com'],
            ['name' => 'Juan García', 'email' => 'juang@gmail.com'],
        ];

        foreach ($customerData as $cd) {
            Customer::firstOrCreate(
                ['email' => $cd['email']],  // Criterio
                array_merge($cd, [
                    'code' => Customer::generateCode(),
                    'status' => 'active',
                    'current_balance' => 0
                ])
            );
        }

        $this->command->info('✅ Datos creados/actualizados');
    }
}
```

**✅ Correcto:**
- `updateOrCreate()` para datos que pueden cambiar
- `firstOrCreate()` para datos "create once"
- Criterios únicos (email, code, etc.)
- Mensajes informativos con emojis

**❌ Incorrecto:**
- `create()` directo (duplica en cada run)
- Sin criterios de búsqueda
- Sin feedback en consola

---

## 6. Eloquent — Relaciones

### Patrón: Relaciones en el modelo

**Ejemplo real:** `app/Models/SalesOrder.php`

```php
class SalesOrder extends Model
{
    // ── Relaciones ──
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesOrderPayment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ──
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    // ── Accessors ──
    protected function amountPaid(): Attribute
    {
        return Attribute::make(
            get: fn () => (float) $this->payments->sum('amount'),
        );
    }

    protected function amountDue(): Attribute
    {
        return Attribute::make(
            get: fn () => max(0, (float) $this->total - $this->amount_paid),
        );
    }
}
```

**✅ Correcto:**
- Type hints en relaciones (`BelongsTo`, `HasMany`)
- Scopes para queries reutilizables
- Accessors para propiedades calculadas
- Nombres descriptivos

**❌ Incorrecto:**
- Queries directas en vez de scopes
- Cálculos repetidos en vez de accessors
- Sin type hints

---

## 7. Eloquent — $fillable

### Regla de oro: NUNCA usar $guarded = []

**Ejemplo real:** `app/Models/Customer.php`

```php
class Customer extends Model
{
    // ✅ Lista explícita de campos asignables en masa
    protected $fillable = [
        'code',
        'name',
        'type',
        'email',
        'phone',
        'tax_id',
        'credit_limit',
        'credit_days',
        'current_balance',
        'city',
        'country',
        'status',
    ];

    // ✅ Campos que se deben ocultar en JSON
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    // ✅ Campos calculados que se agregan al JSON
    protected $appends = [
        'store_credit',
        'has_store_credit',
    ];
}
```

**✅ Correcto:**
- `$fillable` con lista explícita
- `$hidden` para campos sensibles
- `$appends` para propiedades calculadas

**❌ Incorrecto:**
- `protected $guarded = []` (permite mass assignment de cualquier campo)
- Sin `$fillable` definido

---

## 8. Eloquent — Métodos de negocio en el modelo

**¿Cuándo poner lógica en el modelo vs Service?**

**EN EL MODELO:** Operaciones que modifican **solo** ese registro.

**Ejemplo real:** `app/Models/SalesOrder.php`

```php
class SalesOrder extends Model
{
    // ✅ Método que modifica solo este SalesOrder
    public function confirm(): bool
    {
        if ($this->status !== 'draft' && $this->status !== 'pending') {
            return false;
        }

        $this->status = 'confirmed';

        // Cargar deuda al cliente
        if ($this->customer_id) {
            $customer = Customer::find($this->customer_id);
            $storeCreditUsed = (float) $this->payments()
                ->where('method', 'store_credit')
                ->sum('amount');
            $amountToBill = max(0, (float) $this->total - $storeCreditUsed);

            if ($amountToBill > 0) {
                $customer->current_balance += $amountToBill;
                $customer->save();
                // Crear transacción...
            }
        }

        $this->save();
        return true;
    }

    // ✅ Método que agrega un pago a esta orden
    public function addPayment(float $amount, string $method): SalesOrderPayment
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'method' => $method,
            'paid_at' => now(),
        ]);

        // Recalcular payment_status
        $paid = (float) $this->payments()->sum('amount');
        if ($paid >= (float) $this->total) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }
        $this->save();

        return $payment;
    }
}
```

**EN EL SERVICE:** Operaciones que involucran **múltiples modelos** o lógica compleja.

```php
class SalesOrderService
{
    // ✅ Crea orden + items + pagos + ajuste de inventario
    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $order = SalesOrder::create([...]);

            foreach ($data['items'] as $item) {
                $this->addItem($order->id, $item); // Afecta inventario
            }

            if ($data['payments']) {
                foreach ($data['payments'] as $payment) {
                    $order->addPayment(...); // Afecta customer balance
                }
            }

            if ($data['status'] === 'confirmed') {
                $order->confirm();
            }

            return $order->refresh();
        });
    }
}
```

---

## 9. Tests — Estructura con Pest

### Patrón: Archivos por área con helper functions

**Ejemplo real:** `tests/Feature/P2/CustomerBalanceTest.php`

```php
use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helper function ──
function setupCustomerBalanceTest(): array
{
    $warehouse = Warehouse::create([...]);
    $user = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
    ]);
    $customer = Customer::create([...]);
    $product = Product::create([...]);

    return compact('warehouse', 'user', 'customer', 'product');
}

// ── Tests ──
it('venta a credito puro solo genera cargo al balance', function () {
    $data = setupCustomerBalanceTest();
    extract($data);

    $this->actingAs($user)
        ->postJson('/api/v1/sales-orders', [
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'payments' => [],
            'items' => [...]
        ])
        ->assertStatus(201);

    $customer->refresh();
    expect($customer->current_balance)->toBe(100.0);

    $transactions = CustomerTransaction::where('customer_id', $customer->id)->get();
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->type)->toBe('sale');
});
```

**✅ Correcto:**
- `uses(RefreshDatabase::class)` al inicio
- Helper functions para setup reutilizable
- Tests descriptivos en español
- `expect()` syntax de Pest
- Assertions claras del estado final

**❌ Incorrecto:**
- Duplicar setup en cada test
- Tests sin contexto claro
- Sin `RefreshDatabase`

### Organización por niveles

```
tests/Feature/
├── P0/  → Tests críticos (integrity, security, roles)
├── P1/  → Tests de validación y edge cases
├── P2/  → Tests de features completas
└── P3/  → Tests de rendimiento (opcional)
```

---

## 10. Vue 3 + Inertia — Layouts

### Patrón: defineOptions con layout

**Ejemplo real:** `resources/js/pages/Customers/Show.vue`

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

// ✅ Define el layout para esta página
defineOptions({ layout: AppLayout })

const props = defineProps<{
  customerId: number
}>()

const customer = ref(null)

onMounted(async () => {
  // Cargar datos...
})
</script>

<template>
  <Head title="Cliente - {{ customer?.name }}" />

  <div class="p-6">
    <h1>{{ customer?.name }}</h1>
    <!-- contenido -->
  </div>
</template>
```

**✅ Correcto:**
- `defineOptions({ layout: AppLayout })` al inicio del script
- `<Head>` de Inertia para el título
- Props tipadas con TypeScript
- `onMounted` para carga de datos

**❌ Incorrecto:**
- Sin `defineOptions` (no tendrá layout)
- Importar layout pero no usarlo

---

## 11. Vue 3 + Inertia — Patrón Wrapper

### Usar cuando: Una página renderiza otra como hijo con props

**Ejemplo real:** `resources/js/pages/SalesQuotes/Editor.vue`

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PosIndex from '@/pages/POS/Index.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
  quoteId?: number
}>()
</script>

<template>
  <!-- Wrapper mínimo que pasa props al componente hijo -->
  <PosIndex :quote-mode="true" :quote-id="props.quoteId" />
</template>
```

**Uso:**
El editor de cotizaciones reutiliza toda la UI del POS pero en modo "cotización".

**✅ Correcto:**
- Template mínimo (solo renderiza el hijo)
- Props bien tipadas
- Layout aplicado en el wrapper

**❌ Incorrecto:**
- Lógica duplicada (copiar/pegar el componente hijo)
- Props sin tipar

---

## 12. Vue 3 — Composables

### Patrón: Lógica reutilizable con estado reactivo

**Ejemplo real:** `resources/js/composables/useCustomers.ts`

```ts
import { ref, computed } from 'vue'
import { CustomersApiService } from '@/services/customers'
import type { Customer } from '@/types/customer'

export function useCustomers() {
  const customers = ref<Customer[]>([])
  const currentCustomer = ref<Customer | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const balanceSummary = computed(() => {
    if (!currentCustomer.value) return null
    return {
      creditLimit: currentCustomer.value.credit_limit,
      currentBalance: currentCustomer.value.current_balance,
      availableCredit: Math.max(
        0,
        currentCustomer.value.credit_limit -
        Math.max(0, currentCustomer.value.current_balance)
      ),
    }
  })

  const fetchCustomers = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await CustomersApiService.getAll()
      customers.value = response.data
    } catch (err) {
      error.value = 'Error al cargar clientes'
      console.error(err)
    } finally {
      loading.value = false
    }
  }

  const fetchCustomer = async (id: number) => {
    loading.value = true
    try {
      const response = await CustomersApiService.getById(id)
      currentCustomer.value = response.data
    } catch (err) {
      error.value = 'Cliente no encontrado'
    } finally {
      loading.value = false
    }
  }

  return {
    customers,
    currentCustomer,
    loading,
    error,
    balanceSummary,
    fetchCustomers,
    fetchCustomer,
  }
}
```

**✅ Correcto:**
- Estado reactivo con `ref()` y `computed()`
- Tipos TypeScript en parámetros y returns
- Manejo de loading y error
- Return explícito de todo lo público

**❌ Incorrecto:**
- Sin types (TypeScript sin tipar)
- Sin manejo de errores
- Lógica directamente en el componente

---

## 13. Vue 3 — TypeScript en componentes

### Patrón: Props tipadas + interfaces importadas

**Ejemplo real:** `resources/js/components/POS/PaymentMethods.vue`

```vue
<script setup lang="ts">
import { computed } from 'vue'

// ✅ Interface local para props
interface PaymentType {
  id: string
  label: string
}

const PAYMENT_TYPES: PaymentType[] = [
  { id: 'visa', label: 'Visa' },
  { id: 'mastercard', label: 'Mastercard' },
  { id: 'clave', label: 'Clave' },
]

// ✅ Props tipadas
const props = defineProps<{
  payments: Record<string, number>
}>()

// ✅ Emits tipados
const emit = defineEmits<{
  (e: 'paymentChange', id: string, value: number): void
}>()

const total = computed(() =>
  PAYMENT_TYPES.reduce((acc, { id }) => acc + (props.payments[id] || 0), 0)
)

const handleChange = (id: string, rawValue: string) => {
  const val = parseFloat(rawValue)
  emit('paymentChange', id, isNaN(val) ? 0 : val)
}
</script>
```

**Dónde van los tipos:**
- Interfaces locales: Dentro del componente
- Tipos globales: `resources/js/types/` (ej: `customer.ts`, `product.ts`)

**✅ Correcto:**
- Props con `defineProps<{ ... }>()`
- Emits con `defineEmits<{ ... }>()`
- Interfaces locales cuando solo se usan en ese componente
- Importar tipos compartidos desde `/types`

**❌ Incorrecto:**
- Props sin tipar
- Usar `any`
- Duplicar interfaces en múltiples archivos

---

## 14. Vue 3 — Llamadas a API

### Patrón: Axios client centralizado + Services

**NO usar `Inertia.post()` para llamadas API.** Inertia es solo para navegación entre páginas.

**Ejemplo real:** `resources/js/services/api/client.ts`

```ts
import axios from 'axios'

const apiClient = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Interceptor para CSRF token
apiClient.interceptors.request.use((config) => {
  const token = document.head?.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  if (token) {
    config.headers['X-CSRF-TOKEN'] = token
  }
  return config
})

export default apiClient
```

**Service layer:** `resources/js/services/customers.ts`

```ts
import apiClient from './api/client'

export const CustomersApiService = {
  getAll: () => apiClient.get('/customers'),
  getById: (id: number) => apiClient.get(`/customers/${id}`),
  create: (data: any) => apiClient.post('/customers', data),
  update: (id: number, data: any) => apiClient.put(`/customers/${id}`, data),
  delete: (id: number) => apiClient.delete(`/customers/${id}`),
}
```

**Uso en componente:**

```ts
import { CustomersApiService } from '@/services/customers'

const loadCustomers = async () => {
  try {
    const response = await CustomersApiService.getAll()
    customers.value = response.data
  } catch (error) {
    console.error('Error:', error)
  }
}
```

**✅ Correcto:**
- `apiClient` centralizado para todas las llamadas
- Services con métodos tipados
- CSRF token automático
- `withCredentials: true` para Sanctum

**❌ Incorrecto:**
- `Inertia.post()` para llamadas API (solo para navegación)
- `fetch()` directo sin interceptors
- Sin manejo de CSRF

---

## 15. Convenciones de Nombres

### Archivos y carpetas

**Laravel:**
```
app/Models/SalesOrder.php          → PascalCase, singular
app/Services/SalesOrderService.php → PascalCase, sufijo Service
app/Http/Controllers/Api/SalesOrderController.php → PascalCase, sufijo Controller
routes/api_sales_orders.php        → snake_case, plural
database/migrations/2024_01_01_create_sales_orders_table.php → snake_case, plural
```

**Vue:**
```
resources/js/pages/POS/Index.vue              → PascalCase
resources/js/components/POS/PaymentMethods.vue → PascalCase
resources/js/composables/useCustomers.ts       → camelCase, prefijo use
resources/js/services/customers.ts             → camelCase, sin prefijo
resources/js/types/customer.ts                 → camelCase, singular
```

### Variables y funciones

**PHP:**
```php
$salesOrder      // camelCase
$customer_id     // snake_case para DB columns
getById()        // camelCase
```

**TypeScript:**
```ts
const salesOrder   // camelCase
const fetchCustomers // camelCase
interface Customer // PascalCase
type PaymentMethod // PascalCase
```

---

## 16. Git Commits

### Patrón: Conventional Commits

```bash
feat(pos): add store credit payment method
fix(billing): correct balance calculation for partial payments
refactor(api): extract sales logic to SalesOrderService
test(p2): add CustomerBalance test suite
docs: update PATTERNS.md with Service pattern

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

**Prefijos:**
- `feat`: Nueva funcionalidad
- `fix`: Corrección de bug
- `refactor`: Cambio que no añade funcionalidad ni corrige bug
- `test`: Añadir o modificar tests
- `docs`: Cambios en documentación
- `chore`: Mantenimiento (deps, config)

**Scope:** Área afectada (pos, billing, api, frontend, etc.)

---

## Resumen de patrones clave

1. **Controladores delgados** → Lógica en Services
2. **Services para lógica de negocio** → Transacciones, queries complejas
3. **Form Requests** → Validación centralizada
4. **Rutas modulares** → Archivos separados por área
5. **Seeders idempotentes** → updateOrCreate / firstOrCreate
6. **Relaciones en modelos** → Con scopes y accessors
7. **$fillable explícito** → NUNCA $guarded = []
8. **Tests con Pest** → Helper functions + expect()
9. **Layouts con defineOptions** → Vue + Inertia
10. **Composables** → Lógica reutilizable
11. **Axios centralizado** → NO Inertia.post() para API
12. **Types TypeScript** → Props e interfaces tipadas

---

## 17. Patrón: De hardcodeado a configurable (Métodos de Pago)

### Contexto

Los métodos de pago estaban hardcodeados en múltiples lugares:
- Validaciones: `'in:cash,card,transfer,credit'`
- Presentación: `match($method) { 'cash' => 'Efectivo', ... }`
- Lógica: arrays hardcodeados `['cash', 'card', 'transfer']`

**Problema:** Agregar "Yappy" o "Nequi" requería modificar 20+ archivos.

### Solución: Patrón Configurable

**Backend:**

1. **Modelo con datos de configuración**

```php
// app/Models/PaymentMethod.php
class PaymentMethod extends Model
{
    protected $fillable = [
        'name',        // Display: "Efectivo", "Yappy"
        'code',        // Internal: "cash", "yappy"
        'is_active',   // Activar/desactivar
        'is_system',   // Sistema = no archivable
        'requires_reference',
        'requires_cash_count',
        'sort_order',
    ];

    // Scopes para queries comunes
    public function scopeActive($query) {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query) {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Helper estático para formatear pagos históricos
    public static function getNameByCode(string $code): string
    {
        static $cache = [];
        if (!isset($cache[$code])) {
            $method = static::where('code', $code)->first();
            $cache[$code] = $method?->name ?? ucfirst($code);
        }
        return $cache[$code];
    }

    // Generar código desde nombre
    public static function generateCode(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
    }
}
```

2. **Controller CRUD + Reorder**

```php
// app/Http/Controllers/Api/PaymentMethodController.php
class PaymentMethodController extends Controller
{
    // GET /payment-methods → solo activos (todos los roles)
    public function index(): JsonResponse
    {
        return response()->json(
            PaymentMethod::active()->ordered()->get()
        );
    }

    // GET /payment-methods/all → todos (admin)
    public function all(): JsonResponse
    {
        return response()->json(
            PaymentMethod::ordered()->get()
        );
    }

    // POST /payment-methods → crear (admin)
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'requires_reference' => 'boolean',
            'requires_cash_count' => 'boolean',
        ]);

        $code = PaymentMethod::generateCode($validated['name']);

        if (PaymentMethod::where('code', $code)->exists()) {
            return response()->json([
                'message' => 'Ya existe un método con ese código.',
            ], 422);
        }

        $maxOrder = PaymentMethod::max('sort_order') ?? 0;

        $method = PaymentMethod::create([
            'name' => $validated['name'],
            'code' => $code,
            'is_active' => true,
            'is_system' => false,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($method, 201);
    }

    // PUT /payment-methods/{id} → actualizar (admin)
    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        // Métodos sistema: solo permitir cambiar sort_order
        if ($paymentMethod->is_system && $request->has('is_active')) {
            return response()->json([
                'message' => 'Métodos de sistema no pueden desactivarse.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'is_active' => 'sometimes|boolean',
            'requires_reference' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($paymentMethod->is_system) {
            $validated = array_intersect_key($validated, ['sort_order' => true]);
        }

        $paymentMethod->update($validated);
        return response()->json($paymentMethod);
    }

    // DELETE /payment-methods/{id} → archivar (admin)
    public function destroy(PaymentMethod $paymentMethod)
    {
        if ($paymentMethod->is_system) {
            return response()->json([
                'message' => 'Métodos de sistema no pueden eliminarse.',
            ], 403);
        }

        // Soft delete = toggle is_active
        $paymentMethod->update(['is_active' => false]);
        return response()->json(['message' => 'Método archivado.']);
    }

    // POST /payment-methods/reorder → reordenar (admin)
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:payment_methods,id',
        ]);

        foreach ($validated['order'] as $index => $id) {
            PaymentMethod::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }
}
```

3. **Seeder con métodos sistema**

```php
// database/seeders/PaymentMethodSeeder.php
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        // Métodos sistema (no archivables)
        $systemMethods = [
            ['name' => 'Efectivo', 'code' => 'cash', 'requires_cash_count' => true, 'sort_order' => 1],
            ['name' => 'Tarjeta', 'code' => 'card', 'sort_order' => 2],
            ['name' => 'Transferencia', 'code' => 'transfer', 'requires_reference' => true, 'sort_order' => 3],
            ['name' => 'Crédito', 'code' => 'credit', 'sort_order' => 4],
            ['name' => 'Saldo a Favor', 'code' => 'store_credit', 'sort_order' => 5],
        ];

        foreach ($systemMethods as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                array_merge($method, ['is_active' => true, 'is_system' => true])
            );
        }
    }
}
```

**Frontend:**

1. **Service layer**

```ts
// resources/js/services/paymentMethods.ts
import apiClient from './api/client'
import type { PaymentMethod } from '@/types/payment-method'

export const paymentMethodsService = {
    async getActive(): Promise<PaymentMethod[]> {
        const { data } = await apiClient.get('/payment-methods')
        return data
    },

    async getAll(): Promise<PaymentMethod[]> {
        const { data } = await apiClient.get('/payment-methods/all')
        return data
    },

    async create(methodData: CreatePaymentMethodData): Promise<PaymentMethod> {
        const { data } = await apiClient.post('/payment-methods', methodData)
        return data
    },

    async update(id: number, methodData: UpdatePaymentMethodData): Promise<PaymentMethod> {
        const { data } = await apiClient.put(`/payment-methods/${id}`, methodData)
        return data
    },

    async archive(id: number): Promise<void> {
        await apiClient.delete(`/payment-methods/${id}`)
    },

    async reorder(order: number[]): Promise<void> {
        await apiClient.post('/payment-methods/reorder', { order })
    },
}
```

2. **Composable con helpers semánticos**

```ts
// resources/js/composables/usePaymentMethods.ts
import { ref, computed } from 'vue'
import { paymentMethodsService } from '@/services/paymentMethods'
import type { PaymentMethod } from '@/types/payment-method'

export function usePaymentMethods() {
    const methods = ref<PaymentMethod[]>([])
    const loading = ref(false)

    const fetchMethods = async () => {
        loading.value = true
        methods.value = await paymentMethodsService.getActive()
        loading.value = false
    }

    // Helpers semánticos (la lógica especial sigue por código)
    const isCreditMethod = (code: string) => code === 'credit'
    const isStoreCreditMethod = (code: string) => code === 'store_credit'
    const isSpecialMethod = (code: string) =>
        isCreditMethod(code) || isStoreCreditMethod(code)

    // Obtener nombre para mostrar
    const getMethodName = (code: string): string => {
        const method = methods.value.find(m => m.code === code)
        return method?.name ?? code
    }

    // Métodos estándar vs especiales
    const standardMethods = computed(() =>
        methods.value.filter(m => !isSpecialMethod(m.code))
    )

    const specialMethods = computed(() =>
        methods.value.filter(m => isSpecialMethod(m.code))
    )

    return {
        methods,
        loading,
        fetchMethods,
        isCreditMethod,
        isStoreCreditMethod,
        isSpecialMethod,
        getMethodName,
        standardMethods,
        specialMethods,
    }
}
```

3. **Uso en componentes (reemplaza hardcoded)**

**ANTES:**
```vue
<script setup>
const paymentMethods = ['efectivo', 'tarjeta', 'transferencia']
</script>

<template>
  <button v-for="method in paymentMethods">{{ method }}</button>
</template>
```

**DESPUÉS:**
```vue
<script setup>
import { usePaymentMethods } from '@/composables/usePaymentMethods'

const { methods, fetchMethods } = usePaymentMethods()

onMounted(fetchMethods)
</script>

<template>
  <button v-for="method in methods" :key="method.code">
    {{ method.name }}
  </button>
</template>
```

4. **UI de administración (Settings/Store.vue)**

```vue
<script setup>
const {
    allMethods: paymentMethods,
    fetchAllMethods,
    createMethod,
    updateMethod,
    archiveMethod,
    reorderMethods
} = usePaymentMethods()

onMounted(fetchAllMethods)

const handleAddMethod = async () => {
    await createMethod({ name: newMethodName.value })
    await fetchAllMethods()
}

const handleToggleActive = async (method) => {
    if (method.is_system) return
    await updateMethod(method.id, { is_active: !method.is_active })
}

const moveMethodUp = async (index) => {
    const order = paymentMethods.value.map(m => m.id)
    ;[order[index], order[index - 1]] = [order[index - 1], order[index]]
    await reorderMethods(order)
    await fetchAllMethods()
}
</script>

<template>
  <section>
    <h2>Métodos de Pago</h2>
    <button @click="showAddMethodModal = true">+ Agregar</button>

    <div v-for="(method, index) in paymentMethods" :key="method.id">
      <!-- Reorder buttons -->
      <button @click="moveMethodUp(index)">▲</button>
      <button @click="moveMethodDown(index)">▼</button>

      <!-- Info -->
      <span>{{ method.name }}</span>
      <span v-if="method.is_system">Sistema</span>

      <!-- Toggle activo (disabled para sistema) -->
      <input
        type="checkbox"
        :checked="method.is_active"
        :disabled="method.is_system"
        @change="handleToggleActive(method)"
      />

      <!-- Archivar (solo custom) -->
      <button v-if="!method.is_system" @click="archiveMethod(method.id)">
        Archivar
      </button>
    </div>
  </section>
</template>
```

### Precauciones críticas

1. **Compatibilidad histórica:** Los códigos en `order_payments.method` son datos ya existentes.
   - El seeder debe usar los códigos exactos de la BD (verificar con `SELECT DISTINCT method`)
   - NUNCA cambiar códigos de métodos sistema

2. **Lógica especial persiste:** `credit` y `store_credit` tienen lógica en `addPayment()` y `confirm()`
   - Esa lógica opera por `code`, no por nombre visible
   - La configurabilidad es solo visual y de disponibilidad

3. **Fetch timing:** En modales de cobro, fetchear al abrir (datos frescos), no al montar el componente

4. **Cierre de caja dinámico:**
```php
// ANTES (hardcodeado)
$summary = ['cash' => $cashTotal, 'card' => $cardTotal];

// DESPUÉS (dinámico)
$activeMethods = PaymentMethod::active()->pluck('code');
$summary = OrderPayment::whereBetween('created_at', [$open, $close])
    ->whereIn('method', $activeMethods)
    ->groupBy('method')
    ->selectRaw('method, SUM(amount) as total')
    ->get()
    ->keyBy('method');

// Incluir métodos con total $0
$result = $activeMethods->map(fn($code) => [
    'code' => $code,
    'name' => PaymentMethod::where('code', $code)->value('name'),
    'total' => $summary[$code]->total ?? 0,
]);
```

5. **is_system=true:** Efectivo, tarjeta, transferencia, crédito, store_credit
   - No se pueden archivar
   - Toggle is_active deshabilitado en UI
   - Retornar 403 si se intenta modificar/eliminar

### Beneficios del patrón

✅ **DRY:** Un solo lugar para agregar métodos (Settings > Métodos de Pago)
✅ **No requiere code:** Admin agrega "Yappy", aparece en POS, tickets, cierre
✅ **Histórico preservado:** Métodos archivados siguen mostrando nombre en órdenes viejas
✅ **Flexible:** Ordenar, activar/desactivar según necesidad del negocio
✅ **Type-safe:** Frontend usa tipos TypeScript, backend valida en controller

### Cuándo aplicar este patrón

Aplicar cuando:
- Listas hardcodeadas aparecen en múltiples archivos
- El admin debe poder modificar opciones sin desplegar código
- Necesitas preservar datos históricos con sus etiquetas

NO aplicar cuando:
- La lista es verdaderamente estática (ej: roles de usuario definidos en lógica)
- Cambios requieren lógica de código (no solo presentación)
- Performance crítica (el overhead de DB query importa)

---

**Última actualización:** 2026-03-26
