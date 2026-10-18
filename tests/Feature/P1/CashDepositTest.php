<?php

use App\Models\CashDepositAllocation;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialTransfer;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountLedgerService;
use App\Services\WarehouseCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-04 17:00:00', 'UTC'));
    $this->warehouse = Warehouse::create(['code' => 'DEPOSIT', 'name' => 'Sucursal depósitos']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $this->session = PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 0, 'opened_at' => now()]);
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 0, 'cutoff_at' => '2026-10-01 05:00:00', 'reason' => 'Base descartable'], $this->user);
    PaymentMethod::updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash', 'available_in_customer_receipts' => true]);
    $this->bank = FinancialAccount::create(['name' => 'Banco de pruebas', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => true, 'is_legacy' => false, 'ledger_account_id' => LedgerAccount::where('code', '1110')->firstOrFail()->id]);
    $this->order = SalesOrder::create(['user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'confirmed', 'date' => '2026-10-02', 'total' => 100]);
    $this->payment = SalesOrderPayment::create(['sales_order_id' => $this->order->id, 'warehouse_id' => $this->warehouse->id, 'pos_session_id' => $this->session->id, 'method' => 'cash', 'amount' => 100, 'paid_at' => '2026-10-02 17:00:00', 'business_datetime' => '2026-10-02 17:00:00', 'created_by' => $this->user->id]);
    $this->payload = ['warehouse_id' => $this->warehouse->id, 'financial_account_id' => $this->bank->id, 'idempotency_key' => (string) Str::uuid(),
        'date_from' => '2026-10-01', 'date_to' => '2026-10-04', 'amount' => 40, 'allocations' => [['scope' => 'sales', 'id' => $this->payment->id, 'amount' => 40]]];
});

it('deposits a partial collection once without altering its original amount or cashier balance', function (): void {
    $original = $this->payment->getAttributes();
    $response = $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertOk()->assertJsonPath('replayed', true);
    expect($this->payment->fresh()->getAttributes())->toBe($original)
        ->and(CashDepositAllocation::count())->toBe(1)->and(FinancialTransfer::count())->toBe(1)
        ->and((float) $this->bank->fresh()->current_balance)->toBe(40.0)
        ->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(60.0)
        ->and(JournalEntry::where('source_type', 'financial_transfer')->where('source_id', $response->json('data.id'))->count())->toBe(1);
    $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04')->assertOk()
        ->assertJsonPath('summary.collected', 100)->assertJsonPath('summary.deposited', 40)->assertJsonPath('summary.remaining', 60);
});

it('shares remnants across overlapping ranges and rejects double allocation atomically', function (): void {
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    $payload = [...$this->payload, 'idempotency_key' => (string) Str::uuid(), 'date_from' => '2026-10-02', 'amount' => 70,
        'allocations' => [['scope' => 'sales', 'id' => $this->payment->id, 'amount' => 70]]];
    $this->postJson('/api/v1/cash/deposits', $payload)->assertUnprocessable();
    $payload['amount'] = $payload['allocations'][0]['amount'] = 60;
    $this->postJson('/api/v1/cash/deposits', $payload)->assertCreated();
    expect(CashDepositAllocation::sum('amount'))->toEqual(100)->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(0.0);
});

it('rejects conflicting idempotency and duplicate source rows', function (): void {
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'reference' => 'Changed'])->assertConflict();
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'idempotency_key' => (string) Str::uuid(), 'amount' => 80, 'allocations' => [$this->payload['allocations'][0], $this->payload['allocations'][0]]])->assertUnprocessable();
    expect(CashDepositAllocation::count())->toBe(1)->and(FinancialAccountMovement::count())->toBe(1);
});

it('rejects spent cash even when collection remnants are enough', function (): void {
    app(WarehouseCashService::class)->record($this->warehouse->id, 'test_expense', 1, -80);
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertUnprocessable();
    expect(CashDepositAllocation::count())->toBe(0)->and(FinancialTransfer::count())->toBe(0)->and((float) $this->bank->fresh()->current_balance)->toBe(0.0);
});

it('releases allocations through a reversal while retaining history and permits a new deposit', function (): void {
    $id = $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/financial-transfers/'.$id.'/reverse', ['reason' => 'Corrección depósito'])->assertCreated();
    expect(CashDepositAllocation::firstOrFail()->released_at)->not->toBeNull()
        ->and(CashDepositAllocation::firstOrFail()->released_by_transfer_id)->not->toBeNull()
        ->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(100.0)
        ->and((float) $this->bank->fresh()->current_balance)->toBe(0.0);
    $this->postJson('/api/v1/financial-transfers/'.$id.'/reverse', ['reason' => 'Doble reversa'])->assertUnprocessable();
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertOk()->assertJsonPath('data.status', 'reversed');
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    expect(CashDepositAllocation::count())->toBe(2)->and(CashDepositAllocation::whereNull('released_at')->sum('amount'))->toEqual(40);
});

it('rejects sources outside branch range method or effective date and invalid precision', function (): void {
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'date_from' => '2026-10-03'])->assertUnprocessable();
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'business_datetime' => '2026-10-01 17:00:00'])->assertUnprocessable();
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'business_datetime' => '2026-10-05 17:00:00'])->assertUnprocessable();
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'amount' => 40.001])->assertUnprocessable();
    $other = Warehouse::create(['code' => 'OTHER', 'name' => 'Otra sucursal']);
    $this->user->warehouses()->attach($other);
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'warehouse_id' => $other->id])->assertUnprocessable();
    $this->bank->update(['currency' => 'EUR']);
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertUnprocessable();
    expect(FinancialTransfer::count())->toBe(0)->and(CashDepositAllocation::count())->toBe(0);
});

it('deposits the full customer receipt including its advance and excludes duplicate applications', function (): void {
    $customer = Customer::create(['code' => 'DEP-C', 'name' => 'Cliente depósito', 'credit_limit' => 1000, 'current_balance' => 100]);
    $creditOrder = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'confirmed', 'date' => '2026-10-02', 'total' => 100]);
    app(AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 120, 'method' => 'cash', 'applications' => [['document_id' => $creditOrder->id, 'amount' => 100]]]);
    $sources = $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04')->assertOk()->json('data');
    expect($sources)->toHaveCount(2);
    $receipt = collect($sources)->firstWhere('scope', 'receipts');
    $payload = [...$this->payload, 'amount' => 120, 'allocations' => [['scope' => 'receipts', 'id' => $receipt['id'], 'amount' => 120]]];
    $this->postJson('/api/v1/cash/deposits', $payload)->assertCreated();
    expect(CashDepositAllocation::count())->toBe(1)->and((float) $this->bank->fresh()->current_balance)->toBe(120.0);
});

it('restricts deposits to managers and admins and permits branch cash independent of an admin cashier session', function (): void {
    $this->session->update(['status' => 'closed']);
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    $cashier = User::factory()->create(['role' => 'cashier', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->actingAs($cashier)->postJson('/api/v1/cash/deposits', [...$this->payload, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04')->assertForbidden();
});

it('records the full bank receipt once and keeps applications out of bank liquidity', function (): void {
    PaymentMethod::updateOrCreate(['code' => 'yappy'], ['name' => 'Yappy', 'liquidity_bucket' => 'bank', 'is_active' => true, 'available_in_customer_receipts' => true]);
    $customer = Customer::create(['code' => 'DEP-BANK-C', 'name' => 'Cliente banco', 'credit_limit' => 1000, 'current_balance' => 30]);
    $order = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'status' => 'confirmed', 'date' => '2026-10-02', 'total' => 30]);
    $result = app(AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 40, 'method' => 'yappy', 'financial_account_id' => $this->bank->id, 'applications' => [['document_id' => $order->id, 'amount' => 30]]]);
    expect((float) $this->bank->fresh()->current_balance)->toBe(40.0)
        ->and(FinancialAccountMovement::count())->toBe(1)->and((int) $result['batch']->warehouse_id)->toBe($this->warehouse->id);
    $summary = collect($this->getJson('/api/v1/cash/payment-summary')->assertOk()->json('data'));
    expect((float) $summary->firstWhere('method', 'yappy')['incoming'])->toBe(40.0);
    $snapshot = app(\App\Services\FinancialSnapshotService::class)->computeDay('2026-10-04', $this->warehouse->id, true);
    expect((float) $snapshot->closing_bank)->toBe(40.0);
    $bankLedger = \App\Models\JournalLine::query()->whereHas('ledgerAccount', fn ($query) => $query->where('report_group', 'bank'))->selectRaw('SUM(debit - credit) as amount')->first();
    expect((float) $bankLedger->amount)->toBe(40.0);
});

it('leaves allocations active when a bank without funds prevents reversal', function (): void {
    $id = $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated()->json('data.id');
    app(\App\Services\FinancialAccountMovementService::class)->record($this->bank->fresh(), 'out', 40, ['source_type' => 'test_spending', 'source_id' => 1]);
    $this->postJson('/api/v1/financial-transfers/'.$id.'/reverse', ['reason' => 'Sin fondos'])->assertUnprocessable();
    expect(CashDepositAllocation::firstOrFail()->released_at)->toBeNull()->and(FinancialTransfer::count())->toBe(1)
        ->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(60.0);
});

it('prevents deleting a receipt source while an assigned deposit is active', function (): void {
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    expect(fn () => $this->payment->delete())->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(SalesOrderPayment::find($this->payment->id))->not->toBeNull()
        ->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(60.0);
});

it('rejects a backdated deposit that would overdraw cash at an intervening date', function (): void {
    app(WarehouseCashService::class)->record($this->warehouse->id, 'test_spending', 1, -80, occurredAt: '2026-10-03 17:00:00');
    app(WarehouseCashService::class)->record($this->warehouse->id, 'test_funding', 1, 80, occurredAt: '2026-10-04 12:00:00');
    expect(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(100.0);
    $payload = [...$this->payload, 'amount' => 90, 'business_datetime' => '2026-10-02 18:00:00', 'allocations' => [['scope' => 'sales', 'id' => $this->payment->id, 'amount' => 90]]];
    $this->postJson('/api/v1/cash/deposits', $payload)->assertUnprocessable();
    expect(FinancialTransfer::count())->toBe(0)->and(CashDepositAllocation::count())->toBe(0);
});

it('allows an authorized manager to deposit branch cash without opening a personal cashier session', function (): void {
    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $manager->warehouses()->attach($this->warehouse);
    $this->actingAs($manager)->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated();
    expect(CashDepositAllocation::count())->toBe(1)->and(app(WarehouseCashService::class)->availability($this->warehouse->id)['balance'])->toBe(60.0);
});

it('blocks ambiguous historical collections on the server and permits collections after an unassigned deposit', function (string $endpoint): void {
    $this->session->update(['opening_amount' => 100]);
    $legacyId = $this->postJson('/api/v1/financial-transfers', ['idempotency_key' => (string) Str::uuid(),
        'from_endpoint_type' => $endpoint, 'from_endpoint_id' => $endpoint === 'warehouse_cash' ? $this->warehouse->id : $this->session->id,
        'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $this->bank->id, 'amount' => 40,
        'business_datetime' => '2026-10-03 17:00:00'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04')->assertOk()
        ->assertJsonPath('data.0.requires_reconciliation', true)->assertJsonPath('summary.blocked_remaining', 100)
        ->assertJsonPath('summary.available_for_deposit', 0)->assertJsonCount(1, 'warnings');
    $this->postJson('/api/v1/cash/deposits', $this->payload)->assertUnprocessable();
    expect(FinancialTransfer::count())->toBe(1)->and(CashDepositAllocation::count())->toBe(0);
    $newPayment = SalesOrderPayment::create(['sales_order_id' => $this->order->id, 'warehouse_id' => $this->warehouse->id,
        'pos_session_id' => $this->session->id, 'method' => 'cash', 'amount' => 30, 'paid_at' => now(), 'business_datetime' => now(), 'created_by' => $this->user->id]);
    $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.0.requires_reconciliation', false)->assertJsonPath('summary.available_for_deposit', 30);
    $this->postJson('/api/v1/cash/deposits', [...$this->payload, 'amount' => 10, 'allocations' => [['scope' => 'sales', 'id' => $newPayment->id, 'amount' => 10]]])->assertCreated();
    $this->postJson('/api/v1/financial-transfers/'.$legacyId.'/reverse', ['reason' => 'Depósito de prueba anulado'])->assertCreated();
    $this->getJson('/api/v1/cash/deposit-sources?date_from=2026-10-01&date_to=2026-10-04')->assertOk()
        ->assertJsonPath('data.0.requires_reconciliation', false)->assertJsonPath('summary.blocked_remaining', 0);
})->with(['warehouse_cash', 'pos_session']);

it('does not permit a manager to deposit another assigned branch or reverse a deposit', function (): void {
    $id = $this->postJson('/api/v1/cash/deposits', $this->payload)->assertCreated()->json('data.id');
    $other = Warehouse::create(['code' => 'DEP-MANAGER-OTHER', 'name' => 'Otra sucursal asignada']);
    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true, 'current_warehouse_id' => $other->id]);
    $manager->warehouses()->attach([$other->id, $this->warehouse->id]);
    $this->actingAs($manager)->postJson('/api/v1/cash/deposits', [...$this->payload, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson('/api/v1/financial-transfers/'.$id.'/reverse', ['reason' => 'No autorizado'])->assertForbidden();
    expect(FinancialTransfer::count())->toBe(1)->and((float) $this->bank->fresh()->current_balance)->toBe(40.0);
});
