<?php

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function accountsReceivableContext(float $total = 100.00): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-AR-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse AR',
    ]);

    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'AR-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente Credito',
        'credit_limit' => 5000,
        'current_balance' => $total,
        'credit_days' => 30,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-AR-'.fake()->unique()->randomNumber(5),
        'customer_id' => $customer->id,
        'user_id' => $cashier->id,
        'pos_session_id' => null,
        'date' => now()->toDateString(),
        'status' => 'confirmed',
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => $total,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
    ]);

    return compact('warehouse', 'cashier', 'customer', 'order');
}

function createArPaymentMethod(string $code, string $name, int $sortOrder): void
{
    PaymentMethod::create([
        'name' => $name,
        'code' => $code,
        'is_active' => true,
        'is_system' => true,
        'requires_reference' => $code === 'transfer',
        'requires_cash_count' => $code === 'cash',
        'sort_order' => $sortOrder,
    ]);
}

it('lists credit customer debt and removes it from pending list after full receipt', function () {
    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $customer, 'order' => $order] = accountsReceivableContext();
    createArPaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->getJson('/api/v1/accounts-receivable')
        ->assertOk()
        ->assertJsonPath('data.0.id', $customer->id)
        ->assertJsonPath('data.0.current_balance', 100)
        ->assertJsonPath('data.0.total_billed', 100)
        ->assertJsonPath('data.0.total_paid', 0)
        ->assertJsonPath('data.0.status', 'Corriente')
        ->assertJsonPath('summary.total_receivable', 100)
        ->assertJsonPath('summary.active_customers', 1)
        ->assertJsonPath('summary.open_documents_count', 1)
        ->assertJsonPath('summary.credits_granted', 100)
        ->assertJsonPath('summary.aging_buckets.current.amount', 100);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 100,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $order->id, 'amount' => 100],
        ],
    ])->assertCreated();

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and((float) $order->fresh()->amount_due)->toBe(0.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(0.0)
        ->and(AccountPaymentBatch::firstOrFail()->pos_session_id)->not->toBeNull();

    $this->actingAs($cashier)->getJson('/api/v1/accounts-receivable')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('records partial receivable receipt and keeps remaining document open', function () {
    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $customer, 'order' => $order] = accountsReceivableContext();
    createArPaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 40,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $order->id, 'amount' => 40],
        ],
    ])->assertCreated();

    $statement = $this->actingAs($cashier)->getJson("/api/v1/accounts-receivable/{$customer->id}/statement");

    expect($order->fresh()->payment_status)->toBe('partial')
        ->and((float) $order->fresh()->amount_due)->toBe(60.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(60.0);

    $statement->assertOk()
        ->assertJsonPath('data.documents.0.id', $order->id)
        ->assertJsonPath('data.documents.0.amount_due', 60)
        ->assertJsonPath('data.documents.0.days_overdue', 0)
        ->assertJsonPath('data.documents.0.status_label', 'Corriente')
        ->assertJsonPath('data.summary.total_open_balance', 60)
        ->assertJsonPath('data.documents.0.payment_summary.0.method', 'cash')
        ->assertJsonPath('data.documents.0.payment_summary.0.amount', 40);
});

it('exposes receivable dashboard aging and collection metrics for the full filtered set', function () {
    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $currentCustomer, 'order' => $currentOrder] = accountsReceivableContext(150);
    createArPaymentMethod('cash', 'Efectivo', 1);

    $overdueCustomer = Customer::create([
        'code' => 'AR-OLD-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente Vencido',
        'credit_limit' => 2000,
        'current_balance' => 240,
        'credit_days' => 15,
    ]);

    SalesOrder::create([
        'order_number' => 'SO-OLD-'.fake()->unique()->randomNumber(5),
        'customer_id' => $overdueCustomer->id,
        'user_id' => $cashier->id,
        'date' => now()->subDays(150)->toDateString(),
        'status' => 'confirmed',
        'subtotal' => 240,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 240,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
    ]);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$currentCustomer->id}/receipts", [
        'amount' => 50,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $currentOrder->id, 'amount' => 50],
        ],
    ])->assertCreated();

    $response = $this->actingAs($cashier)->getJson('/api/v1/accounts-receivable?per_page=1');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.total_receivable', 340)
        ->assertJsonPath('summary.active_customers', 2)
        ->assertJsonPath('summary.open_documents_count', 2)
        ->assertJsonPath('summary.collections_received', 50)
        ->assertJsonPath('summary.aging_buckets.90_plus.amount', 240)
        ->assertJsonPath('summary.over_90_count', 1);
});

it('exposes design ready receivable rows summary trend and recent movements', function () {
    Carbon::setTestNow('2026-06-03 10:00:00');
    CarbonImmutable::setTestNow('2026-06-03 10:00:00');

    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $currentCustomer, 'order' => $currentOrder] = accountsReceivableContext(150);
    createArPaymentMethod('cash', 'Efectivo', 1);

    $overdueCustomer = Customer::create([
        'code' => 'AR-RISK-'.fake()->unique()->randomNumber(5),
        'name' => 'Cliente Riesgo Alto',
        'credit_limit' => 500,
        'current_balance' => 240,
        'credit_days' => 15,
    ]);

    SalesOrder::create([
        'order_number' => 'SO-RISK-001',
        'customer_id' => $overdueCustomer->id,
        'user_id' => $cashier->id,
        'date' => now()->subDays(150)->toDateString(),
        'status' => 'confirmed',
        'subtotal' => 240,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 240,
        'payment_status' => 'pending',
        'payment_method' => 'credit',
    ]);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$currentCustomer->id}/receipts", [
        'amount' => 50,
        'method' => 'cash',
        'paid_at' => '2026-06-03 12:00:00',
        'reference_number' => 'RC-READY-001',
        'applications' => [
            ['document_id' => $currentOrder->id, 'amount' => 50],
        ],
    ])->assertCreated();

    $response = $this->actingAs($cashier)->getJson('/api/v1/accounts-receivable?per_page=50');

    $response->assertOk()
        ->assertJsonPath('summary.aging_segments.0.key', 'current')
        ->assertJsonPath('summary.aging_segments.4.key', '90_plus')
        ->assertJsonPath('summary.portfolio_metrics.dso_days', 23)
        ->assertJsonPath('summary.portfolio_metrics.collection_efficiency_percent', 33.33)
        ->assertJsonPath('summary.monthly_trend.5.month', 'Jun')
        ->assertJsonPath('summary.monthly_trend.5.credits', 150)
        ->assertJsonPath('summary.monthly_trend.5.collections', 50)
        ->assertJsonPath('summary.recent_movements.0.type', 'payment')
        ->assertJsonPath('summary.recent_movements.0.reference_number', 'RC-READY-001')
        ->assertJsonPath('summary.recent_movements.1.type', 'credit')
        ->assertJsonPath('summary.recent_movements.1.document_number', $currentOrder->order_number);

    $rows = collect($response->json('data'));
    $currentRow = $rows->firstWhere('id', $currentCustomer->id);
    $overdueRow = $rows->firstWhere('id', $overdueCustomer->id);

    expect($currentRow)
        ->toHaveKey('initials', 'CC')
        ->and($currentRow)->toHaveKey('credit_used_percent', 2.0)
        ->and($currentRow)->toHaveKey('max_days_overdue', 0)
        ->and($currentRow)->toHaveKey('reliability_score', 100)
        ->and($currentRow)->toHaveKey('can_register_payment', true)
        ->and($currentRow)->toHaveKey('last_payment_days', 0)
        ->and($overdueRow)->toHaveKey('initials', 'CR')
        ->and($overdueRow)->toHaveKey('credit_used_percent', 48.0)
        ->and($overdueRow)->toHaveKey('max_days_overdue', 135)
        ->and($overdueRow)->toHaveKey('reliability_score', 25);

    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('rejects credit as accounts receivable receipt method', function () {
    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $customer, 'order' => $order] = accountsReceivableContext();

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 10,
        'method' => 'credit',
        'applications' => [
            ['document_id' => $order->id, 'amount' => 10],
        ],
    ])->assertUnprocessable();

    expect($order->fresh()->payment_status)->toBe('pending');
});

it('requires an open cash session before recording real receivable receipt', function () {
    ['cashier' => $cashier, 'customer' => $customer, 'order' => $order] = accountsReceivableContext();

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 10,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $order->id, 'amount' => 10],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('pos_session_id');
});

it('includes receivable cash receipt in pos session closing', function () {
    ['warehouse' => $warehouse, 'cashier' => $cashier, 'customer' => $customer, 'order' => $order] = accountsReceivableContext();
    createArPaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 20,
        'expected_amount' => 20,
        'status' => 'open',
    ]);

    $this->actingAs($cashier)->postJson("/api/v1/accounts-receivable/{$customer->id}/receipts", [
        'amount' => 30,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $order->id, 'amount' => 30],
        ],
    ])->assertCreated();

    expect(CashMovement::count())->toBe(0);

    $response = $this->actingAs($cashier)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouse->id,
        'closing_amount' => 50,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 50,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.expected_cash', 50.0)
        ->assertJsonPath('data.closing_details.summary.cash_sales', 30.0);
});
