<?php

use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create(['code' => 'BAL', 'name' => 'Conciliación']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $this->session = PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 100, 'opened_at' => now()]);
});

it('uses reconciled cash without duplicating openings, purchases or refund movements', function () {
    $this->postJson('/api/v1/cash/reconciliation', ['amount' => 100, 'cutoff_at' => now()->subMinute()->toISOString(), 'reason' => 'Conteo físico'])->assertCreated();
    $order = SalesOrder::create(['user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'draft', 'date' => now(), 'total' => 30]);
    SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => 30, 'method' => 'cash', 'created_by' => $this->user->id, 'paid_at' => now()]);
    CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => 'out', 'amount' => 10, 'reason' => 'Compra']);
    CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => 'out', 'subtype' => 'sales_return_refund', 'amount' => 5, 'reason' => 'Devolución']);
    SalesOrderPayment::create(['sales_order_id' => $order->id, 'amount' => -5, 'method' => 'cash', 'created_by' => $this->user->id, 'paid_at' => now()]);
    $this->session->update(['status' => 'closed', 'closed_at' => now()]);
    PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 115, 'opened_at' => now()]);
    $this->getJson('/api/v1/cash/availability')->assertOk()->assertJsonPath('data.balance', 115);
    $this->getJson('/api/v1/cash/payment-summary')->assertOk()->assertJsonPath('data.0.incoming', 30)->assertJsonPath('data.0.outgoing', 5)->assertJsonPath('data.0.net', 25);
    $this->getJson('/api/v1/cash/payment-movements')->assertOk()->assertJsonPath('total', 2);
});

it('blocks uninitialized or insufficient cash transfers and replays without another debit', function () {
    $account = FinancialAccount::create(['name' => 'Banco', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'current_balance' => 0, 'ledger_account_id' => LedgerAccount::where('code', '1110')->firstOrFail()->id, 'is_active' => true, 'is_legacy' => false]);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'from_endpoint_type' => 'warehouse_cash', 'from_endpoint_id' => $this->warehouse->id, 'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $account->id, 'amount' => 40];
    $this->postJson('/api/v1/financial-transfers', $payload)->assertUnprocessable();
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 100, 'cutoff_at' => now()->subMinute(), 'reason' => 'Conteo'], $this->user);
    $response = $this->postJson('/api/v1/financial-transfers', $payload)->assertCreated();
    $this->postJson('/api/v1/financial-transfers', $payload)->assertOk();
    expect(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(60.0)
        ->and((float) $account->fresh()->current_balance)->toBe(40.0)
        ->and(app(\App\Services\PosSessionReconciliationService::class)->build($this->session)['summary']['expected_cash'])->toBe(60.0)
        ->and(CashMovement::count())->toBe(1);
    $this->postJson('/api/v1/financial-transfers', [...$payload, 'idempotency_key' => (string) Str::uuid(), 'amount' => 61])->assertUnprocessable();
    $this->postJson('/api/v1/financial-transfers/'.$response->json('data.id').'/reverse', ['reason' => 'Corrección'])->assertSuccessful();
    expect(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(100.0)
        ->and(app(\App\Services\PosSessionReconciliationService::class)->build($this->session)['summary']['expected_cash'])->toBe(100.0);
});

it('counts a receipt with a custom reference once in accumulated cash and the session', function () {
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 100, 'cutoff_at' => now()->subMinute(), 'reason' => 'Conteo'], $this->user);
    $customer = \App\Models\Customer::create(['code' => 'CASH-REC', 'name' => 'Cliente', 'credit_limit' => 1000, 'current_balance' => 30]);
    $order = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'confirmed', 'date' => now(), 'total' => 30]);
    app(\App\Services\AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 40, 'method' => 'cash', 'reference_number' => 'REFERENCIA-CLIENTE', 'applications' => [['document_id' => $order->id, 'amount' => 30]]]);
    expect(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(140.0)
        ->and(app(\App\Services\PosSessionReconciliationService::class)->build($this->session)['summary']['expected_cash'])->toBe(140.0);
});

it('keeps payments after reconciliation in the same second and refuses overwriting the baseline', function () {
    $this->freezeTime();
    CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => 'in', 'amount' => 10, 'reason' => 'Anterior']);
    $payload = ['amount' => 110, 'cutoff_at' => now()->toISOString(), 'reason' => 'Conteo'];
    $this->postJson('/api/v1/cash/reconciliation', $payload)->assertCreated();
    CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => 'in', 'amount' => 5, 'reason' => 'Posterior']);
    $this->getJson('/api/v1/cash/availability')->assertOk()->assertJsonPath('data.balance', 115);
    $this->postJson('/api/v1/cash/reconciliation', $payload)->assertUnprocessable();
});
