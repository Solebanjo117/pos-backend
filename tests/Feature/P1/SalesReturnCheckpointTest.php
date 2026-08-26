<?php

use App\Models\Brand;
use App\Models\CashierQueueEntry;
use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\InventoryMovement;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesCreditNote;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function salesReturnCheckpointContext(): array
{
    $warehouse = Warehouse::create(['code' => 'W-RET-CP6', 'name' => 'Caja devoluciones CP6']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], [
        'name' => 'Efectivo',
        'is_active' => true,
        'is_system' => true,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_CASH,
        'available_in_sales' => true,
        'available_in_returns' => true,
        'sort_order' => 1,
    ]);

    $brand = Brand::create(['name' => 'Marca CP6', 'status' => 'active']);
    $product = Product::create(['sku_base' => 'RET-CP6', 'name' => 'Producto CP6', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'RET-CP6-A',
        'status' => 'active',
        'is_default' => true,
    ]);
    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
    ]);

    auth()->login($user);
    $order = app(SalesOrderService::class)->create([
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'unit_price' => 10,
            'tax_percent' => 0,
        ]],
    ])->fresh(['items']);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => $order->total,
        'method' => 'cash',
        'paid_at' => now(),
        'created_by' => $user->id,
    ]);
    $order->update(['payment_status' => 'paid']);

    return compact('warehouse', 'user', 'product', 'variant', 'order');
}

function salesReturnCheckpointPayload(array $context, string $key): array
{
    return [
        'idempotency_key' => $key,
        'approve_immediately' => true,
        'refund_method' => 'cash',
        'reason' => 'Prueba idempotente CP6',
        'items' => [[
            'sales_order_item_id' => $context['order']->items->first()->id,
            'quantity' => 1,
            'user_id' => $context['user']->id,
        ]],
    ];
}

function salesReturnCheckpointBankAccount(array $context, array $overrides = []): FinancialAccount
{
    $ledger = LedgerAccount::query()->firstOrCreate(
        ['code' => '1110'],
        [
            'name' => 'Bancos',
            'type' => 'asset',
            'report_group' => 'bank',
            'normal_balance' => 'debit',
            'is_active' => true,
        ],
    );

    return FinancialAccount::create([
        'name' => 'Banco devolución CP6 '.Str::random(5),
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 100,
        'current_balance' => 100,
        'allow_negative_balance' => false,
        'is_active' => true,
        'is_legacy' => false,
        'ledger_account_id' => $ledger->id,
        'created_by' => $context['user']->id,
        ...$overrides,
    ]);
}

it('creates and approves a return atomically and replays the same request without duplicates', function (): void {
    $context = salesReturnCheckpointContext();
    $key = (string) Str::uuid();
    $payload = salesReturnCheckpointPayload($context, $key);

    $first = $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved');

    $second = $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.status', 'approved');

    $return = SalesReturn::findOrFail($first->json('data.id'));
    expect(SalesReturn::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and($return->items()->count())->toBe(1)
        ->and(SalesCreditNote::query()->where('sales_return_id', $return->id)->count())->toBe(1)
        ->and(CashierQueueEntry::query()->where('source_type', SalesReturn::class)->where('source_id', $return->id)->count())->toBe(1)
        ->and(InventoryMovement::query()->where('reference_type', 'sales_return')->where('reference_id', $return->id)->count())->toBe(1);
});

it('rejects reuse of a return idempotency key with different data', function (): void {
    $context = salesReturnCheckpointContext();
    $key = (string) Str::uuid();
    $payload = salesReturnCheckpointPayload($context, $key);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated();

    $payload['reason'] = 'Otra devolución con la misma clave';
    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertConflict()
        ->assertJsonPath('message', 'Esta identificación ya fue usada para una devolución diferente.');

    expect(SalesReturn::count())->toBe(1);
});

it('rolls back return creation when immediate approval fails', function (): void {
    $context = salesReturnCheckpointContext();
    $payload = salesReturnCheckpointPayload($context, (string) Str::uuid());

    $inventory = Mockery::mock(InventoryFifoService::class);
    $inventory->shouldReceive('receiveStock')->once()->andThrow(new RuntimeException('Fallo FIFO simulado'));
    app()->instance(InventoryFifoService::class, $inventory);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertStatus(500)
        ->assertJsonPath('message', 'Error al crear la devolución.')
        ->assertJsonMissingPath('error');

    expect(SalesReturn::count())->toBe(0)
        ->and(SalesCreditNote::count())->toBe(0)
        ->and(CashierQueueEntry::query()->where('source_type', SalesReturn::class)->count())->toBe(0)
        ->and(InventoryMovement::query()->where('reference_type', 'sales_return')->count())->toBe(0);
});

it('does not settle a cash refund when the locked session has insufficient funds', function (): void {
    $context = salesReturnCheckpointContext();
    $payload = salesReturnCheckpointPayload($context, (string) Str::uuid());
    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated();

    $return = SalesReturn::findOrFail($response->json('data.id'));
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();
    PosSession::create([
        'user_id' => $context['user']->id,
        'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(),
        'opening_amount' => 1,
        'status' => 'open',
    ]);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", ['payment_method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El efectivo disponible en la sesión no alcanza para completar este reembolso.');

    expect(CashMovement::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->count())->toBe(0)
        ->and(SalesOrderPayment::query()->where('sales_return_id', $return->id)->count())->toBe(0)
        ->and($return->fresh()->settlement_status)->toBe('pending')
        ->and($entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_PENDING);
});

it('requires an active specific bank account and settles against exactly that account', function (): void {
    $context = salesReturnCheckpointContext();
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], [
        'name' => 'Yappy',
        'is_active' => true,
        'is_system' => false,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_BANK,
        'available_in_sales' => true,
        'available_in_returns' => true,
        'sort_order' => 5,
    ]);
    $selected = salesReturnCheckpointBankAccount($context);
    $other = salesReturnCheckpointBankAccount($context);
    $key = (string) Str::uuid();
    $payload = salesReturnCheckpointPayload($context, $key);
    $payload['refund_method'] = 'yappy';
    $payload['financial_account_id'] = $selected->id;

    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated()
        ->assertJsonPath('data.financial_account_id', $selected->id);
    $return = SalesReturn::findOrFail($response->json('data.id'));
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();

    $this->actingAs($context['user'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", [
            'payment_method' => 'yappy',
            'reference_number' => 'BANK-CP6',
        ])
        ->assertOk();

    $payment = SalesOrderPayment::query()->where('sales_return_id', $return->id)->firstOrFail();
    $movement = FinancialAccountMovement::query()
        ->where('source_type', $payment->getMorphClass())
        ->where('source_id', $payment->id)
        ->firstOrFail();
    expect($payment->financial_account_id)->toBe($selected->id)
        ->and($movement->financial_account_id)->toBe($selected->id)
        ->and($movement->direction)->toBe('out')
        ->and((float) $selected->fresh()->current_balance)->toBe(90.0)
        ->and((float) $other->fresh()->current_balance)->toBe(100.0);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $return->id);
    expect(FinancialAccountMovement::query()->where('financial_account_id', $selected->id)->count())->toBe(1);

    $payload['financial_account_id'] = $other->id;
    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertConflict();
});

it('rejects missing legacy inactive or non-bank accounts for bank refunds', function (array $overrides): void {
    $context = salesReturnCheckpointContext();
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], [
        'name' => 'Yappy',
        'is_active' => true,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_BANK,
        'available_in_returns' => true,
    ]);
    $payload = salesReturnCheckpointPayload($context, (string) Str::uuid());
    $payload['refund_method'] = 'yappy';
    if ($overrides !== []) {
        $payload['financial_account_id'] = salesReturnCheckpointBankAccount($context, $overrides)->id;
    }

    $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('financial_account_id');
    expect(SalesReturn::count())->toBe(0);
})->with([
    'sin cuenta' => [[]],
    'heredada' => [['is_legacy' => true]],
    'inactiva' => [['is_active' => false]],
    'tipo no bancario' => [['type' => 'other']],
]);

it('rejects settlement if the selected bank account becomes inactive', function (): void {
    $context = salesReturnCheckpointContext();
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], [
        'name' => 'Yappy',
        'is_active' => true,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_BANK,
        'available_in_returns' => true,
    ]);
    $account = salesReturnCheckpointBankAccount($context);
    $payload = salesReturnCheckpointPayload($context, (string) Str::uuid());
    $payload['refund_method'] = 'yappy';
    $payload['financial_account_id'] = $account->id;
    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/sales-orders/{$context['order']->id}/returns", $payload)
        ->assertCreated();
    $return = SalesReturn::findOrFail($response->json('data.id'));
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();
    $account->update(['is_active' => false]);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", [
            'payment_method' => 'yappy',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('financial_account_id');

    expect(SalesOrderPayment::query()->where('sales_return_id', $return->id)->count())->toBe(0)
        ->and(FinancialAccountMovement::query()->where('financial_account_id', $account->id)->count())->toBe(0)
        ->and($return->fresh()->settlement_status)->toBe('pending');
});
