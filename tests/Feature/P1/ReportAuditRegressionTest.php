<?php

use App\Models\CapitalMovement;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FundingCategory;
use App\Models\LedgerAccount;
use App\Models\OperationalExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountLedgerService;
use App\Services\FinancialSnapshotService;
use App\Services\WarehouseCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function reportAuditBank(string $currency = 'USD', float $balance = 0): FinancialAccount
{
    return FinancialAccount::create(['name' => 'Audit '.$currency, 'type' => 'bank', 'currency' => $currency, 'opening_balance' => $balance, 'current_balance' => $balance, 'is_active' => true, 'is_legacy' => false, 'ledger_account_id' => LedgerAccount::where('code', '1110')->firstOrFail()->id]);
}

function reportAuditDeposit($test, FinancialAccount $bank, ?string $effective = null)
{
    return $test->postJson('/api/v1/financial-transfers', ['idempotency_key' => (string) Str::uuid(), 'from_endpoint_type' => 'warehouse_cash', 'from_endpoint_id' => $test->warehouse->id, 'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $bank->id, 'amount' => 30, 'business_datetime' => $effective ?? now()->toISOString()])->assertCreated();
}

beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-20 17:00:00', 'UTC'));
    $this->warehouse = Warehouse::create(['code' => 'AUDIT', 'name' => 'Sucursal auditoria']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $this->session = PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 100, 'opened_at' => now()]);
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 100, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Prueba descartable'], $this->user);
    PaymentMethod::updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash', 'available_in_supplier_payments' => true, 'available_in_customer_receipts' => true]);
    PaymentMethod::updateOrCreate(['code' => 'yappy'], ['name' => 'Yappy', 'is_active' => true, 'liquidity_bucket' => 'bank', 'requires_reference' => true, 'requires_financial_account' => false, 'available_in_supplier_payments' => true, 'available_in_customer_receipts' => true]);
});

it('audit expects a mixed expense to split snapshot cash 10 and bank 20', function () {
    $category = OperationalExpenseCategory::create(['name' => 'Servicios', 'slug' => 'audit_services', 'is_active' => true, 'expense_kind' => 'operational', 'affects_cash_flow' => true, 'affects_income_statement' => true]);
    $request = $this->postJson('/api/v1/cash-operation-requests', ['operation_type' => 'expense', 'expense_category_id' => $category->id, 'amount' => 30, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $this->postJson('/api/v1/cashier-queue/'.$request->json('data.queue_entry_id').'/complete', ['payments' => [['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 20, 'reference_number' => 'AUDIT']]])->assertOk();
    $snapshot = app(FinancialSnapshotService::class)->computeDay('2026-09-20', $this->warehouse->id, true);
    expect((float) $snapshot->cash_expenses)->toBe(10.0)->and((float) $snapshot->bank_expenses)->toBe(20.0);
});

it('audit expects mixed funding snapshot to add cash 10 and bank 20', function () {
    $category = FundingCategory::create(['name' => 'Socio', 'slug' => 'audit_partner', 'is_active' => true]);
    $request = $this->postJson('/api/v1/cash-operation-requests', ['operation_type' => 'funding', 'funding_category_id' => $category->id, 'amount' => 30, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $this->postJson('/api/v1/cashier-queue/'.$request->json('data.queue_entry_id').'/complete', ['payments' => [['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 20, 'reference_number' => 'AUDIT']]])->assertOk();
    $snapshot = app(FinancialSnapshotService::class)->computeDay('2026-09-20', $this->warehouse->id, true);
    $cash = (float) $snapshot->closing_cash - (float) $snapshot->opening_cash;
    $bank = (float) $snapshot->closing_bank - (float) $snapshot->opening_bank;
    expect($cash)->toBe(10.0)->and($bank)->toBe(20.0);
});

it('audit expects unused configured methods to remain visible with zero totals', function () {
    PaymentMethod::create(['code' => 'audit_dynamic', 'name' => 'Metodo dinamico', 'is_active' => true, 'liquidity_bucket' => 'bank', 'requires_financial_account' => false]);
    $summary = $this->getJson('/api/v1/cash/payment-summary?date_from=2026-09-20&date_to=2026-09-20')->assertOk()->json('data');
    $methods = array_column($summary, 'method');
    $options = $this->getJson('/api/v1/cash/payment-movements')->assertOk()->json('filter_options.methods');
    expect($methods)->toContain('audit_dynamic');
});

it('audit expects all cash in a partly unallocated customer receipt in collection totals', function () {
    $customer = Customer::create(['code' => 'AUDIT-C', 'name' => 'Cliente auditoria', 'credit_limit' => 1000, 'current_balance' => 30]);
    $order = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'confirmed', 'date' => now(), 'total' => 30]);
    app(AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 40, 'method' => 'cash', 'reference_number' => 'AUDIT-REC', 'applications' => [['document_id' => $order->id, 'amount' => 30]]]);
    $summary = collect($this->getJson('/api/v1/cash/payment-summary')->assertOk()->json('data'));
    $incoming = (float) $summary->sum('incoming');
    expect($incoming)->toBe(40.0);
});

it('audit expects a backdated deposit in cash position on its effective date', function () {
    $bank = reportAuditBank();
    reportAuditDeposit($this, $bank, '2026-09-10T17:00:00Z');
    $report = $this->getJson('/api/v1/reports/cash-position?mode=as_of&as_of=2026-09-10')->assertOk()->json('summary');
    expect((float) $report['closing_balance'])->toBe(70.0);
});

it('audit expects a bank deposit in account history on its effective date', function () {
    $bank = reportAuditBank();
    reportAuditDeposit($this, $bank, '2026-09-10T17:00:00Z');
    $report = $this->getJson('/api/v1/financial-accounts/'.$bank->id.'/movements?date_from=2026-09-10&date_to=2026-09-10')->assertOk();
    expect((float) $report->json('summary.incoming'))->toBe(30.0);
});

it('audit expects a transfer to move snapshot cash to bank without changing income', function () {
    CapitalMovement::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'movement_type' => 'in', 'amount' => 100, 'movement_date' => '2026-09-01', 'business_datetime' => '2026-09-01T17:00:00Z', 'reason' => 'Opening funding']);
    $bank = reportAuditBank();
    reportAuditDeposit($this, $bank);
    $snapshot = app(FinancialSnapshotService::class)->computeDay('2026-09-20', $this->warehouse->id, true);
    expect((float) $snapshot->closing_cash)->toBe(70.0)->and((float) $snapshot->closing_bank)->toBe(30.0);
});

it('audit expects a currency mismatch transfer to be rejected without an exchange rate', function () {
    $usd = reportAuditBank('USD', 100);
    $eur = reportAuditBank('EUR', 0);
    $response = $this->postJson('/api/v1/financial-transfers', ['idempotency_key' => (string) Str::uuid(), 'from_endpoint_type' => 'financial_account', 'from_endpoint_id' => $usd->id, 'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $eur->id, 'amount' => 10]);
    $response->assertUnprocessable();
});
it('audit expects a physical cash baseline to be reconciled against consolidated ledger cash', function () {
    $balance = app(\App\Services\BalanceSheetLedgerService::class)->generate(['as_of' => '2026-09-20']);
    $keys = array_column($balance['reconciliation']['control_accounts'], 'key');
    expect($balance['is_official'])->toBeFalse();
});

it('keeps expense recognition separate from the effective bank payment date', function (): void {
    $bank = reportAuditBank('USD', 100);
    $category = OperationalExpenseCategory::create(['name' => 'Servicios fechados', 'slug' => 'dated_services', 'is_active' => true, 'expense_kind' => 'operational', 'affects_cash_flow' => true, 'affects_income_statement' => true]);
    $expense = \App\Models\OperationalExpense::create(['warehouse_id' => $this->warehouse->id, 'category_id' => $category->id, 'category' => $category->slug, 'description' => 'Factura anterior', 'amount' => 30, 'expense_date' => '2026-09-05', 'business_datetime' => '2026-09-10 17:00:00', 'paid_at' => '2026-09-10 17:00:00', 'payment_method' => 'yappy', 'status' => 'pending', 'created_by' => $this->user->id, 'affects_cash_flow' => true, 'affects_income_statement' => true]);
    app(\App\Services\OperationalPaymentService::class)->record($expense, [['method' => 'yappy', 'liquidity_bucket' => 'bank', 'amount' => 30, 'financial_account_id' => $bank->id]], 'out', $this->user);
    $expense->update(['status' => 'paid']);
    $this->getJson('/api/v1/financial-accounts/'.$bank->id.'/movements?date_from=2026-09-10&date_to=2026-09-10')->assertOk()->assertJsonPath('summary.outgoing', 30);
    $economic = app(FinancialSnapshotService::class)->computeDay('2026-09-05', $this->warehouse->id, true);
    $payment = app(FinancialSnapshotService::class)->computeDay('2026-09-10', $this->warehouse->id, true);
    expect((float) $economic->expenses)->toBe(30.0)->and((float) $economic->bank_expenses)->toBe(0.0)
        ->and((float) $payment->expenses)->toBe(0.0)->and((float) $payment->bank_expenses)->toBe(30.0);
});

it('does not collect a bank advance again when it is applied on a later day', function (): void {
    $bank = reportAuditBank();
    $customer = Customer::create(['code' => 'ADVANCE-LATER', 'name' => 'Cliente anticipo', 'credit_limit' => 1000, 'current_balance' => 0]);
    $firstOrder = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'status' => 'confirmed', 'date' => '2026-09-10', 'total' => 20]);
    $receipt = app(AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 100, 'method' => 'yappy', 'financial_account_id' => $bank->id, 'paid_at' => '2026-09-10 17:00:00', 'applications' => [['document_id' => $firstOrder->id, 'amount' => 20]]])['batch'];
    $order = SalesOrder::create(['customer_id' => $customer->id, 'user_id' => $this->user->id, 'status' => 'confirmed', 'date' => '2026-09-19', 'total' => 80]);
    \App\Models\SalesOrderPayment::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'account_payment_batch_id' => $receipt->id, 'method' => 'yappy', 'amount' => 80, 'financial_account_id' => $bank->id, 'paid_at' => '2026-09-20 17:00:00', 'business_datetime' => '2026-09-20 17:00:00', 'created_by' => $this->user->id]);
    $first = app(FinancialSnapshotService::class)->computeDay('2026-09-10', null, true);
    $second = app(FinancialSnapshotService::class)->computeDay('2026-09-20', null, true);
    expect((float) $bank->fresh()->current_balance)->toBe(100.0)->and((float) $first->closing_bank)->toBe(100.0)
        ->and((float) $second->closing_bank)->toBe(100.0)->and((float) $second->closing_bank - (float) $second->opening_bank)->toBe(0.0);
});
