<?php

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPaymentMethodForPosTest(
    string $code,
    string $name,
    bool $isActive,
    bool $isSystem,
    int $sortOrder
): void {
    PaymentMethod::create([
        'name' => $name,
        'code' => $code,
        'is_active' => $isActive,
        'is_system' => $isSystem,
        'requires_reference' => $code === 'transfer' || $code === 'ach',
        'requires_cash_count' => $code === 'cash',
        'sort_order' => $sortOrder,
    ]);
}

it('returns 200 or 404 for current pos session and never 500', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-POS',
        'name' => 'Warehouse POS',
    ]);
    $user = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $response = $this->actingAs($user)->getJson('/api/v1/pos-sessions/current');

    expect(in_array($response->status(), [200, 404], true))->toBeTrue();
});

it('closes a pos session with dynamic payment method reconciliation rows', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CLOSE',
        'name' => 'Warehouse Close',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    PaymentMethod::create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'is_active' => true,
        'is_system' => true,
        'requires_reference' => false,
        'requires_cash_count' => true,
        'sort_order' => 1,
    ]);
    PaymentMethod::create([
        'name' => 'Tarjeta',
        'code' => 'card',
        'is_active' => true,
        'is_system' => true,
        'requires_reference' => false,
        'requires_cash_count' => false,
        'sort_order' => 2,
    ]);
    PaymentMethod::create([
        'name' => 'Yappy',
        'code' => 'yappy',
        'is_active' => true,
        'is_system' => false,
        'requires_reference' => false,
        'requires_cash_count' => false,
        'sort_order' => 3,
    ]);
    PaymentMethod::create([
        'name' => 'ACH',
        'code' => 'ach',
        'is_active' => false,
        'is_system' => false,
        'requires_reference' => false,
        'requires_cash_count' => false,
        'sort_order' => 4,
    ]);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 200,
        'expected_amount' => 200,
        'status' => 'open',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-CLOSE-001',
        'user_id' => $cashier->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 400,
        'tax_amount' => 0,
        'discount_amount' => 10,
        'shipping_cost' => 5,
        'total' => 400,
        'payment_status' => 'paid',
        'payment_method' => 'multiple',
    ]);

    foreach ([
        ['method' => 'cash', 'amount' => 100],
        ['method' => 'cash', 'amount' => -5],
        ['method' => 'card', 'amount' => 50],
        ['method' => 'yappy', 'amount' => 75],
        ['method' => 'ach', 'amount' => 12.50],
    ] as $payment) {
        SalesOrderPayment::create([
            'sales_order_id' => $order->id,
            'amount' => $payment['amount'],
            'method' => $payment['method'],
            'paid_at' => now(),
            'created_by' => $cashier->id,
        ]);
    }

    CashMovement::create([
        'pos_session_id' => $session->id,
        'user_id' => $cashier->id,
        'type' => 'in',
        'amount' => 20,
        'reason' => 'Fondo adicional',
    ]);
    CashMovement::create([
        'pos_session_id' => $session->id,
        'user_id' => $cashier->id,
        'type' => 'out',
        'amount' => 15,
        'reason' => 'Retiro',
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 300,
        'closing_details' => [
            'cash_denominations' => ['100' => 3],
            'cash_extra' => 0,
            'payment_method_counts' => [
                'cash' => 300,
                'card' => 50,
                'yappy' => 75,
                'ach' => 12.50,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.expected_amount', '300.00')
        ->assertJsonPath('data.closing_amount', '300.00')
        ->assertJsonPath('data.closing_details.summary.expected_total', 437.5)
        ->assertJsonPath('data.closing_details.summary.declared_total', 437.5)
        ->assertJsonPath('data.closing_details.summary.total_difference', 0.0);

    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows->keys()->all())->toContain('cash', 'card', 'yappy', 'ach');
    expect($rows['cash']['start_adds'])->toBe(220.0);
    expect($rows['cash']['payments'])->toBe(100.0);
    expect($rows['cash']['withdrawals'])->toBe(-20.0);
    expect($rows['cash']['expected_total'])->toBe(300.0);
    expect($rows['cash']['is_countable'])->toBeTrue();
    expect($rows['cash']['is_informational'])->toBeFalse();
    expect($rows['ach']['expected_total'])->toBe(12.5);
    expect($rows['ach']['is_countable'])->toBeTrue();
});

it('excludes 100 percent credit sales from countable reconciliation totals', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CREDIT-ONLY',
        'name' => 'Warehouse Credit Only',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);
    createPaymentMethodForPosTest('credit', 'Crédito', true, true, 4);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-CREDIT-ONLY-001',
        'user_id' => $cashier->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 100,
        'method' => 'credit',
        'paid_at' => now(),
        'created_by' => $cashier->id,
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 0,
        'closing_details' => [
            'payment_method_counts' => [],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.expected_total', 0.0)
        ->assertJsonPath('data.closing_details.summary.declared_total', 0.0)
        ->assertJsonPath('data.closing_details.summary.total_difference', 0.0)
        ->assertJsonPath('data.closing_details.summary.accounts_receivable_generated', 100.0)
        ->assertJsonPath('data.closing_details.summary.store_credit_applied', 0.0);

    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows['credit']['expected_total'])->toBe(100.0);
    expect($rows['credit']['declared_total'])->toBe(0.0);
    expect($rows['credit']['difference'])->toBe(0.0);
    expect($rows['credit']['is_countable'])->toBeFalse();
    expect($rows['credit']['is_informational'])->toBeTrue();
});

it('reconciles mixed card and credit sale with only card as countable total', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CREDIT-MIX',
        'name' => 'Warehouse Credit Mix',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);
    createPaymentMethodForPosTest('card', 'Tarjeta', true, true, 2);
    createPaymentMethodForPosTest('credit', 'Crédito', true, true, 4);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-CREDIT-MIX-001',
        'user_id' => $cashier->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'partial',
        'payment_method' => 'multiple',
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 40,
        'method' => 'card',
        'paid_at' => now(),
        'created_by' => $cashier->id,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 60,
        'method' => 'credit',
        'paid_at' => now(),
        'created_by' => $cashier->id,
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 0,
        'closing_details' => [
            'payment_method_counts' => [
                'card' => 40,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.expected_total', 40.0)
        ->assertJsonPath('data.closing_details.summary.declared_total', 40.0)
        ->assertJsonPath('data.closing_details.summary.accounts_receivable_generated', 60.0);

    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows['card']['expected_total'])->toBe(40.0);
    expect($rows['card']['is_countable'])->toBeTrue();
    expect($rows['credit']['expected_total'])->toBe(60.0);
    expect($rows['credit']['is_countable'])->toBeFalse();
    expect($rows['credit']['is_informational'])->toBeTrue();
});

it('counts real cash receipt from accounts receivable while keeping original credit informational', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-CREDIT-RECEIPT',
        'name' => 'Warehouse Credit Receipt',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);
    createPaymentMethodForPosTest('credit', 'Crédito', true, true, 4);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-CREDIT-RECEIPT',
        'name' => 'Cliente CxC',
        'credit_limit' => 5000,
        'credit_days' => 30,
        'current_balance' => 100,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-CREDIT-RECEIPT-001',
        'user_id' => $cashier->id,
        'customer_id' => $customer->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'confirmed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 100,
        'method' => 'credit',
        'paid_at' => now()->subMinutes(30),
        'created_by' => $cashier->id,
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 30,
        'method' => 'cash',
        'paid_at' => now()->subMinutes(10)->toISOString(),
        'applications' => [
            [
                'document_id' => $order->id,
                'amount' => 30,
            ],
        ],
    ])->assertCreated();

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 30,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 30,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.expected_total', 30.0)
        ->assertJsonPath('data.closing_details.summary.declared_total', 30.0)
        ->assertJsonPath('data.closing_details.summary.accounts_receivable_generated', 100.0);

    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows['cash']['expected_total'])->toBe(30.0);
    expect($rows['credit']['expected_total'])->toBe(100.0);
    expect($rows['credit']['is_countable'])->toBeFalse();
});

it('keeps store credit as informational and out of reconciled totals', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-STORE-CREDIT',
        'name' => 'Warehouse Store Credit',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);
    createPaymentMethodForPosTest('store_credit', 'Saldo a Favor', true, true, 5);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-STORE-CREDIT-001',
        'user_id' => $cashier->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 25,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 25,
        'payment_status' => 'pending',
        'payment_method' => 'store_credit',
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 25,
        'method' => 'store_credit',
        'paid_at' => now(),
        'created_by' => $cashier->id,
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 0,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 0,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.expected_total', 0.0)
        ->assertJsonPath('data.closing_details.summary.declared_total', 0.0)
        ->assertJsonPath('data.closing_details.summary.store_credit_applied', 25.0)
        ->assertJsonPath('data.closing_details.summary.accounts_receivable_generated', 0.0);

    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows['store_credit']['expected_total'])->toBe(25.0);
    expect($rows['store_credit']['is_countable'])->toBeFalse();
    expect($rows['store_credit']['is_informational'])->toBeTrue();
});

it('requires a note when closing totals have a difference', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-DIFF',
        'name' => 'Warehouse Difference',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    PaymentMethod::create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'is_active' => true,
        'is_system' => true,
        'requires_reference' => false,
        'requires_cash_count' => true,
        'sort_order' => 1,
    ]);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'user_id' => $cashier->id,
        'closing_amount' => 90,
        'closing_details' => [
            'cash_denominations' => ['50' => 1, '20' => 2],
            'cash_extra' => 0,
            'payment_method_counts' => [
                'cash' => 90,
            ],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('notes');
});

// ---------------------------------------------------------------------------
// Multi-warehouse isolation tests
// ---------------------------------------------------------------------------

it('current returns only the session belonging to the effective warehouse', function () {
    $warehouseA = Warehouse::create(['code' => 'WA-CURR', 'name' => 'Warehouse A Current']);
    $warehouseB = Warehouse::create(['code' => 'WB-CURR', 'name' => 'Warehouse B Current']);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouseB->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

    $sessionA = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouseA->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $sessionB = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouseB->id,
        'opened_at' => now()->subMinutes(30),
        'opening_amount' => 200,
        'expected_amount' => 200,
        'status' => 'open',
    ]);

    $response = $this->actingAs($cashier)->getJson('/api/v1/pos-sessions/current');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($sessionB->id);
    expect($response->json('data.warehouse_id'))->toBe($warehouseB->id);
});

it('open allows a session in warehouse B even when warehouse A already has an open session', function () {
    $warehouseA = Warehouse::create(['code' => 'WA-OPEN', 'name' => 'Warehouse A Open']);
    $warehouseB = Warehouse::create(['code' => 'WB-OPEN', 'name' => 'Warehouse B Open']);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouseB->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

    // Pre-existing open session in warehouse A
    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouseA->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    // Open a session in warehouse B (effective warehouse for this cashier)
    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/open', [
        'opening_amount' => 50,
    ]);

    $response->assertCreated();
    expect($response->json('data.warehouse_id'))->toBe($warehouseB->id);

    // Warehouse A session must remain open
    expect(
        PosSession::where('warehouse_id', $warehouseA->id)->where('status', 'open')->exists()
    )->toBeTrue();
});

it('close only closes the session of the effective warehouse and leaves the other branch open', function () {
    $warehouseA = Warehouse::create(['code' => 'WA-CLOSE', 'name' => 'Warehouse A Close']);
    $warehouseB = Warehouse::create(['code' => 'WB-CLOSE', 'name' => 'Warehouse B Close']);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouseB->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

    $sessionA = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouseA->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $sessionB = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouseB->id,
        'opened_at' => now()->subMinutes(30),
        'opening_amount' => 200,
        'expected_amount' => 200,
        'status' => 'open',
    ]);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'closing_amount' => 200,
    ]);

    $response->assertOk();

    // Session B (effective warehouse) must be closed
    expect($sessionB->fresh()->status)->toBe('closed');

    // Session A (other branch) must remain open
    expect($sessionA->fresh()->status)->toBe('open');
});

it('open returns 422 when the user has no effective warehouse context', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => null,
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/pos-sessions/open', [
        'opening_amount' => 100,
    ]);

    // Admin with no current_warehouse_id and no warehouse_id in the request:
    // The middleware cannot resolve an effective warehouse, so it passes through
    // without injecting warehouse_id. OpenPosSessionRequest then fails validation
    // with a 422 — no fallback to an arbitrary branch is allowed.
    $response->assertUnprocessable()
        ->assertJsonValidationErrors('warehouse_id');
});
