<?php

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
        'idempotency_key' => (string) Str::uuid(),
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

    expect(PosSession::query()->where('user_id', $cashier->id)->firstOrFail()->status)->toBe('open');
});

it('validates the denomination payload used by the closing modal', function () {
    $warehouse = Warehouse::create(['code' => 'W-DENOMINATIONS', 'name' => 'Warehouse Denominations']);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);
    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'closing_amount' => 100,
        'closing_details' => [
            'denominations' => ['unit_100' => -1, 'extra' => 0],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('closing_details.denominations.unit_100');

    expect($session->fresh()->status)->toBe('open');
});

it('shows normalized documents and an ordered timeline for a pos session', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-SESSION-DETAIL',
        'name' => 'Warehouse Session Detail',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);
    createPaymentMethodForPosTest('card', 'Tarjeta', true, true, 2);
    createPaymentMethodForPosTest('credit', 'Credito', true, true, 3);
    createPaymentMethodForPosTest('store_credit', 'Saldo a favor', true, true, 4);

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->setTime(8, 0),
        'closed_at' => now()->setTime(16, 0),
        'opening_amount' => 100,
        'closing_amount' => 125,
        'expected_amount' => 125,
        'status' => 'closed',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-SESSION-DETAIL',
        'name' => 'Cliente Caja Detalle',
    ]);
    $supplier = Supplier::create([
        'name' => 'Proveedor Caja Detalle',
        'status' => 'active',
    ]);

    $sale = SalesOrder::create([
        'order_number' => 'SO-SESSION-001',
        'user_id' => $cashier->id,
        'customer_id' => $customer->id,
        'pos_session_id' => $session->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'created_at' => now()->setTime(9, 0),
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $sale->id,
        'amount' => 80,
        'method' => 'cash',
        'paid_at' => now()->setTime(9, 5),
        'created_by' => $cashier->id,
    ]);

    $receipt = AccountPaymentBatch::create([
        'account_type' => 'customer',
        'account_id' => $customer->id,
        'pos_session_id' => $session->id,
        'direction' => 'incoming',
        'batch_number' => 'REC-SESSION-001',
        'amount' => 25,
        'method' => 'cash',
        'paid_at' => now()->setTime(10, 0),
        'created_by' => $cashier->id,
    ]);
    $receipt->applications()->create([
        'document_type' => 'sales_order',
        'document_id' => $sale->id,
        'document_number' => $sale->order_number,
        'amount' => 25,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => 'PO-SESSION-001',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $cashier->id,
        'status' => 'received',
        'payment_status' => 'partial',
        'order_date' => now()->toDateString(),
        'subtotal' => 60,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 60,
    ]);

    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'pos_session_id' => $session->id,
        'user_id' => $cashier->id,
        'amount' => 30,
        'method' => 'card',
        'paid_at' => now()->setTime(11, 0),
    ]);

    OperationalExpense::create([
        'warehouse_id' => $warehouse->id,
        'created_by' => $cashier->id,
        'pos_session_id' => $session->id,
        'expense_date' => now()->toDateString(),
        'category' => 'maintenance',
        'description' => 'Servicio menor',
        'amount' => 12,
        'payment_method' => 'transfer',
        'created_at' => now()->setTime(12, 0),
    ]);

    CashMovement::create([
        'pos_session_id' => $session->id,
        'user_id' => $cashier->id,
        'type' => 'in',
        'amount' => 20,
        'reason' => 'Fondo adicional',
        'created_at' => now()->setTime(13, 0),
    ]);

    $response = $this->actingAs($cashier)->getJson("/api/v1/pos-sessions/{$session->id}");

    $response->assertOk()
        ->assertJsonPath('reconciliation_rows.0.code', 'cash');

    $documents = collect($response->json('documents'));
    $saleDocument = $documents->firstWhere('type', 'sale');
    $receiptDocument = $documents->firstWhere('type', 'ar_receipt');
    expect($documents->pluck('type')->all())->toContain('sale', 'ar_receipt', 'ap_payment', 'operational_expense', 'cash_movement');
    expect($saleDocument['href'])->toBe("/sales-orders/{$sale->id}");
    expect($saleDocument['actor_name'])->toBe($cashier->name);
    expect($saleDocument['customer_name'])->toBe($customer->name);
    expect($saleDocument['direction'])->toBe('in');
    expect($saleDocument['is_cash_counted'])->toBeTrue();
    expect($receiptDocument['href'])->toBe("/ar?customer_id={$customer->id}");
    expect($receiptDocument['actor_name'])->toBe($cashier->name);
    expect($receiptDocument['customer_name'])->toBe($customer->name);
    expect($receiptDocument['direction'])->toBe('in');
    expect($receiptDocument['is_cash_counted'])->toBeTrue();
    expect($receiptDocument['applications'])->toBeArray();
    expect($receiptDocument['applications'][0]['document_number'])->toBe($sale->order_number);
    expect($documents->firstWhere('type', 'ap_payment')['href'])->toBe("/accounts-payable?supplier_id={$supplier->id}");
    expect($documents->firstWhere('type', 'operational_expense')['is_cash_counted'])->toBeFalse();

    $timeline = collect($response->json('timeline'));
    expect($timeline->pluck('type')->all())->toContain('opening', 'sale', 'ar_receipt', 'ap_payment', 'operational_expense', 'cash_movement', 'closing');
    expect($timeline->pluck('occurred_at')->values()->all())->toBe($timeline->pluck('occurred_at')->sort()->values()->all());
});

it('filters open sessions by opened date and closed sessions by closed date', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-SESSION-FILTER',
        'name' => 'Warehouse Session Filter',
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $manager->warehouses()->attach($warehouse->id);

    $openSession = PosSession::create([
        'user_id' => $manager->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-05-10 09:00:00',
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $closedSession = PosSession::create([
        'user_id' => $manager->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-05-01 09:00:00',
        'closed_at' => '2026-05-10 18:00:00',
        'opening_amount' => 100,
        'closing_amount' => 120,
        'expected_amount' => 120,
        'status' => 'closed',
    ]);

    $closedOutsideRange = PosSession::create([
        'user_id' => $manager->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-05-10 09:00:00',
        'closed_at' => '2026-05-11 18:00:00',
        'opening_amount' => 100,
        'closing_amount' => 100,
        'expected_amount' => 100,
        'status' => 'closed',
    ]);

    $otherManager = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $otherManager->warehouses()->attach($warehouse->id);
    $openOutsideRange = PosSession::create([
        'user_id' => $otherManager->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-05-09 09:00:00',
        'opening_amount' => 100,
        'closing_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $mixedResponse = $this->actingAs($manager)->getJson('/api/v1/pos-sessions?date_from=2026-05-10&date_to=2026-05-10');
    $mixedResponse->assertOk();
    $mixedIds = collect($mixedResponse->json('data.data'))->pluck('id')->all();
    expect($mixedIds)->toHaveCount(2);
    expect($mixedIds)->toContain($openSession->id, $closedSession->id);
    expect($mixedIds)->not->toContain($closedOutsideRange->id);
    expect($mixedIds)->not->toContain($openOutsideRange->id);

    $openResponse = $this->actingAs($manager)->getJson('/api/v1/pos-sessions?status=open&date_from=2026-05-10&date_to=2026-05-10');
    $openResponse->assertOk();
    expect(collect($openResponse->json('data.data'))->pluck('id')->all())->toBe([$openSession->id]);

    $closedResponse = $this->actingAs($manager)->getJson('/api/v1/pos-sessions?status=closed&date_from=2026-05-10&date_to=2026-05-10');
    $closedResponse->assertOk();
    expect(collect($closedResponse->json('data.data'))->pluck('id')->all())->toBe([$closedSession->id]);
});

it('validates pos session index filters before querying', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-SESSION-FILTER-VALIDATION',
        'name' => 'Warehouse Session Filter Validation',
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $manager->warehouses()->attach($warehouse->id);

    $this->actingAs($manager)
        ->getJson('/api/v1/pos-sessions?status=archived&date_from=bad-date&per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'date_from', 'per_page']);
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

it('reconciles only payments belonging to the session being closed', function () {
    $warehouseA = Warehouse::create(['code' => 'WA-RECON', 'name' => 'Warehouse A Reconciliation']);
    $warehouseB = Warehouse::create(['code' => 'WB-RECON', 'name' => 'Warehouse B Reconciliation']);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouseA->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach([$warehouseA->id, $warehouseB->id]);
    createPaymentMethodForPosTest('cash', 'Efectivo', true, true, 1);

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
        'opened_at' => now()->subHour(),
        'opening_amount' => 200,
        'expected_amount' => 200,
        'status' => 'open',
    ]);

    foreach ([[$sessionA, 25, 'SO-RECON-A'], [$sessionB, 80, 'SO-RECON-B']] as [$session, $amount, $number]) {
        $order = SalesOrder::create([
            'order_number' => $number,
            'user_id' => $cashier->id,
            'pos_session_id' => $session->id,
            'date' => now()->toDateString(),
            'status' => 'completed',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => $amount,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
        ]);
        SalesOrderPayment::create([
            'sales_order_id' => $order->id,
            'amount' => $amount,
            'method' => 'cash',
            'paid_at' => now(),
            'created_by' => $cashier->id,
        ]);
    }

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouseA->id,
        'closing_amount' => 125,
        'closing_details' => ['payment_method_counts' => ['cash' => 125]],
    ])->assertOk()
        ->assertJsonPath('data.closing_details.summary.cash_sales', 25.0)
        ->assertJsonPath('data.closing_details.summary.expected_cash', 125.0)
        ->assertJsonPath('data.closing_details.summary.total_difference', 0.0);

    expect($sessionA->fresh()->status)->toBe('closed')
        ->and($sessionB->fresh()->status)->toBe('open');
});

it('returns an understandable conflict when a close request is repeated', function () {
    $warehouse = Warehouse::create(['code' => 'W-CLOSE-REPLAY', 'name' => 'Warehouse Close Replay']);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);
    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 50,
        'expected_amount' => 50,
        'status' => 'open',
    ]);
    $payload = ['closing_amount' => 50];

    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', $payload)->assertOk();
    $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', $payload)
        ->assertConflict()
        ->assertJsonPath('message', 'La caja ya fue cerrada o no existe una sesion abierta para esta sucursal.');

    expect($session->fresh()->status)->toBe('closed')
        ->and(PosSession::query()->where('user_id', $cashier->id)->count())->toBe(1);
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
