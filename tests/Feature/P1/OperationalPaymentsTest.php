<?php

use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\OperationalExpense;
use App\Models\OperationalPayment;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OperationalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::query()->create(['code' => 'OP', 'name' => 'Operaciones']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->actingAs($this->user);
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash', 'available_in_supplier_payments' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], ['name' => 'Yappy', 'is_active' => true, 'liquidity_bucket' => 'bank', 'requires_reference' => true, 'requires_financial_account' => false, 'available_in_supplier_payments' => true]);
});

it('persists split money once and assigns an optional bank account without another cash movement', function () {
    PosSession::query()->create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'opening_amount' => 100, 'status' => 'open', 'opened_at' => now()]);
    $expense = OperationalExpense::query()->create(['warehouse_id' => $this->warehouse->id, 'created_by' => $this->user->id, 'expense_date' => now(), 'category' => 'other', 'amount' => 30, 'status' => 'pending']);
    $service = app(OperationalPaymentService::class);
    $payments = $service->normalize([['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 20, 'reference_number' => 'Y-1']], 30, 'supplier_payments');
    $records = $service->record($expense, $payments, 'out', $this->user);
    expect(CashMovement::query()->sum('amount'))->toEqual(10)
        ->and(FinancialAccountMovement::query()->count())->toBe(0)
        ->and($records[1]->pending_account)->toBeTrue();
    $account = FinancialAccount::query()->create(['ledger_account_id' => \App\Models\LedgerAccount::query()->firstOrCreate(['code' => '1110'], ['name' => 'Bank', 'type' => 'asset'])->id, 'name' => 'Banco', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 100, 'current_balance' => 100, 'is_active' => true, 'is_legacy' => false]);
    $service->assignAccount($records[1], $account->id, $this->user, $this->warehouse->id);
    $service->assignAccount($records[1], $account->id, $this->user, $this->warehouse->id);
    expect((float) $account->fresh()->current_balance)->toBe(80.0)
        ->and(FinancialAccountMovement::query()->count())->toBe(1)
        ->and(CashMovement::query()->count())->toBe(1);
});

it('rejects totals, missing references and inactive methods', function (array $payments) {
    app(OperationalPaymentService::class)->normalize($payments, 30, 'supplier_payments');
})->with([
    [[['method' => 'cash', 'amount' => 29]]],
    [[['method' => 'yappy', 'amount' => 30]]],
    [[['method' => 'missing', 'amount' => 30]]],
])->throws(ValidationException::class);

it('validates default accounts when configuring methods', function () {
    $this->postJson('/api/v1/payment-methods', ['name' => 'Pago configurado', 'default_financial_account_id' => 999999])->assertUnprocessable();
    $this->postJson('/api/v1/payment-methods', ['name' => 'Pago configurable', 'requires_financial_account' => false])->assertCreated()->assertJsonPath('requires_financial_account', false);
});

it('settles a fixed expense through the real queue with cash and yappy instead of forcing cash', function () {
    $category = \App\Models\OperationalExpenseCategory::create(['name' => 'Servicios', 'slug' => 'services', 'is_active' => true, 'expense_kind' => 'operational', 'affects_cash_flow' => true, 'affects_income_statement' => true]);
    PosSession::create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'open', 'opening_amount' => 100, 'opened_at' => now()]);
    $response = $this->postJson('/api/v1/cash-operation-requests', ['operation_type' => 'expense', 'expense_category_id' => $category->id, 'amount' => 30, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
    $url = '/api/v1/cashier-queue/'.$response->json('data.queue_entry_id').'/complete';
    $payments = ['payments' => [['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 20, 'reference_number' => 'YAP']]];
    $this->postJson($url, $payments)->assertOk();
    $this->postJson($url, $payments)->assertOk();
    expect(OperationalPayment::count())->toBe(2)->and((float) CashMovement::sum('amount'))->toBe(10.0)->and(OperationalExpense::first()->payment_method)->toBe('mixed')->and(FinancialAccountMovement::count())->toBe(0);
    $this->getJson('/api/v1/cash/payment-movements?scope=operations&pending_account=1')->assertOk()->assertJsonPath('total', 1);
});

it('records mixed funding as one capital movement without adding its bank part to cash', function () {
    PaymentMethod::query()->whereIn('code', ['cash', 'yappy'])->update(['available_in_customer_receipts' => true]);
    $category = \App\Models\FundingCategory::create(['name' => 'Socio', 'slug' => 'partner', 'is_active' => true]);
    PosSession::create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'open', 'opening_amount' => 0, 'opened_at' => now()]);
    $response = $this->postJson('/api/v1/cash-operation-requests', ['operation_type' => 'funding', 'funding_category_id' => $category->id, 'amount' => 30, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
    $payments = ['payments' => [['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 20, 'reference_number' => 'YAP']]];
    $this->postJson('/api/v1/cashier-queue/'.$response->json('data.queue_entry_id').'/complete', $payments)->assertOk();
    expect(\App\Models\CapitalMovement::count())->toBe(1)->and((float) CashMovement::sum('amount'))->toBe(10.0)->and(FinancialAccountMovement::count())->toBe(0);
    $entry = \App\Models\JournalEntry::where('source_type', 'capital_movement')->where('status', 'posted')->with('lines.ledgerAccount')->firstOrFail();
    expect((float) $entry->lines->first(fn ($line) => $line->ledgerAccount->code === '1100')->debit)->toBe(10.0);
});

it('restricts pending account assignments by role and warehouse and preserves the recorded classification', function () {
    $expense = OperationalExpense::create(['warehouse_id' => $this->warehouse->id, 'created_by' => $this->user->id, 'expense_date' => now(), 'category' => 'other', 'amount' => 20, 'status' => 'pending']);
    $service = app(OperationalPaymentService::class);
    $payment = $service->record($expense, $service->normalize([['method' => 'yappy', 'amount' => 20, 'reference_number' => 'Y2']], 20, 'supplier_payments'), 'out', $this->user)->first();
    $account = FinancialAccount::create(['name' => 'Banco', 'type' => 'bank', 'currency' => 'USD', 'current_balance' => 50, 'opening_balance' => 50, 'ledger_account_id' => \App\Models\LedgerAccount::where('code', '1110')->firstOrFail()->id, 'is_active' => true, 'is_legacy' => false]);
    $cashier = User::factory()->create(['role' => 'cashier', 'current_warehouse_id' => $this->warehouse->id, 'is_active' => true]);
    $cashier->warehouses()->attach($this->warehouse);
    $url = '/api/v1/cash/payments/operations/'.$payment->id.'/account';
    $this->actingAs($cashier)->postJson($url, ['financial_account_id' => $account->id])->assertForbidden();
    $other = Warehouse::create(['code' => 'OTHER-OP', 'name' => 'Otra']);
    $this->actingAs($this->user)->postJson($url, ['financial_account_id' => $account->id, 'warehouse_id' => $other->id])->assertForbidden();
    PaymentMethod::where('code', 'yappy')->update(['liquidity_bucket' => 'cash']);
    $this->postJson($url, ['financial_account_id' => $account->id, 'warehouse_id' => $this->warehouse->id])->assertOk();
    expect($payment->fresh()->liquidity_bucket)->toBe('bank')->and((float) $account->fresh()->current_balance)->toBe(30.0);
});
