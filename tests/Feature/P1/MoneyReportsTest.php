<?php

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesLineReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create(['code' => 'REPORT3', 'name' => 'Reportes']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
});

function reportOrder($test, string $date = '2026-08-10'): array
{
    $order = SalesOrder::create(['user_id' => $test->user->id, 'date' => $date, 'status' => 'confirmed', 'total' => 100]);
    $item = SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => \App\Models\Product::factory()->create()->id, 'warehouse_id' => $test->warehouse->id, 'quantity' => 2, 'unit_price' => 50, 'unit_cost' => 20, 'is_custom_line' => true, 'custom_description' => 'Artículo histórico']);
    $order->refresh()->calculateTotals();

    return [$order, $item];
}

it('reports approved returns in their own period without requiring a money refund', function () {
    [$order, $item] = reportOrder($this);
    $return = SalesReturn::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'approved', 'settlement_status' => 'pending', 'total_amount' => 50, 'approved_at' => '2026-09-10 12:00:00', 'reason' => 'Devolución', 'refund_method' => 'cash']);
    SalesReturnItem::create(['sales_return_id' => $return->id, 'sales_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 1, 'unit_price' => 50, 'subtotal' => 50, 'reporting_unit_cost' => 20]);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('summary.net_sales', 100)->assertJsonPath('summary.profit', 60)->assertJsonPath('data.0.occurred_at', '2026-08-10T05:00:00.000000Z');
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonPath('summary.net_sales', -50)->assertJsonPath('summary.cost', -20)->assertJsonPath('data.0.kind', 'return');
    $this->getJson('/api/v1/cash/payment-movements')->assertOk()->assertJsonPath('total', 0);
});

it('allocates header discounts in cents and retains totals across pages and groups', function () {
    [$order, $item] = reportOrder($this);
    $order->update(['discount_amount' => 10]);
    $order->refresh()->calculateTotals();
    $response = $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31&per_page=1')->assertOk()->assertJsonPath('summary.net_sales', 90)->assertJsonPath('summary.discounts', 10)->assertJsonPath('summary.profit', 50);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31&group_by=brand')->assertOk()->assertJsonPath('summary', $response->json('summary'));
});

it('keeps amendments in their effective month and does not use today product costs', function () {
    [$order, $item] = reportOrder($this);
    $service = app(SalesLineReportService::class);
    $before = $service->snapshot($order->id);
    $this->travelTo(\Carbon\Carbon::parse('2026-09-15 12:00:00'));
    $item->update(['quantity' => 3, 'unit_cost' => 22]);
    $order->refresh()->calculateTotals();
    $service->recordAmendment($order, $before, (string) \Illuminate\Support\Str::uuid());
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('summary.net_sales', 100)->assertJsonPath('summary.cost', 40);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonPath('summary.net_sales', 50)->assertJsonPath('summary.cost', 26)->assertJsonPath('data.0.kind', 'amendment');
});

it('marks missing cost and blocks cashiers from profitability', function () {
    [, $item] = reportOrder($this);
    $item->update(['unit_cost' => 0]);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('summary.profit', null)->assertJsonPath('summary.incomplete', true);
    $this->user->update(['role' => 'cashier']);
    $this->getJson('/api/v1/reports/sales-lines')->assertForbidden();
});

it('keeps the cash history and deposit separate with the required 1500 balance example', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-15 12:00:00'));
    $session = \App\Models\PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 500, 'opened_at' => now()]);
    app(\App\Services\WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 500, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo inicial'], $this->user);
    [$order] = reportOrder($this);
    $order->update(['pos_session_id' => $session->id]);
    \App\Models\SalesOrderPayment::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'pos_session_id' => $session->id, 'amount' => 5000, 'method' => 'cash', 'created_by' => $this->user->id, 'paid_at' => now()]);
    \App\Models\CashMovement::create(['pos_session_id' => $session->id, 'user_id' => $this->user->id, 'type' => 'out', 'subtype' => 'expense', 'amount' => 1000, 'reason' => 'Gastos']);
    $account = \App\Models\FinancialAccount::create(['name' => 'Banco reporte', 'type' => 'bank', 'currency' => 'USD', 'current_balance' => 0, 'opening_balance' => 0, 'ledger_account_id' => \App\Models\LedgerAccount::where('code', '1110')->firstOrFail()->id, 'is_active' => true]);
    $payload = ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'from_endpoint_type' => 'warehouse_cash', 'from_endpoint_id' => $this->warehouse->id, 'to_endpoint_type' => 'financial_account', 'to_endpoint_id' => $account->id, 'amount' => 3000, 'reference' => 'DEP-3000'];
    $transfer = $this->postJson('/api/v1/financial-transfers', $payload)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/financial-transfers', $payload)->assertOk();
    $report = $this->getJson('/api/v1/reports/cash-position?mode=period&date_from=2026-09-01&date_to=2026-09-30&per_page=1')->assertOk()->assertJsonPath('summary.opening_balance', 500)->assertJsonPath('summary.incoming', 5000)->assertJsonPath('summary.outgoing', 4000)->assertJsonPath('summary.closing_balance', 1500)->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/reports/cash-position?mode=period&date_from=2026-09-01&date_to=2026-09-30&kind=transfer')->assertOk()->assertJsonPath('summary', $report->json('summary'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_url', '/finance/transfers/'.$transfer)->assertJsonPath('data.0.balance', 1500);
    $this->getJson('/api/v1/cash/payment-summary?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonPath('data.0.incoming', fn ($value) => (float) $value === 5000.0);
    $this->getJson('/api/v1/financial-accounts/'.$account->id.'/movements?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonPath('data.0.document_url', '/finance/transfers/'.$transfer)->assertJsonPath('summary.incoming', 3000);
    $this->postJson('/api/v1/financial-transfers/'.$transfer.'/reverse', ['reason' => 'Corrección'])->assertSuccessful();
    $this->getJson('/api/v1/reports/cash-position?mode=current')->assertOk()->assertJsonPath('summary.closing_balance', 4500)->assertJsonPath('meta.total', 4);
    expect((float) $account->fresh()->current_balance)->toBe(0.0);
});

it('honors the reconciliation watermark and the business timezone without inventing older balances', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-15 12:00:00'));
    $service = app(\App\Services\WarehouseCashService::class);
    $service->record($this->warehouse->id, 'test', 1, 10);
    $service->reconcile($this->warehouse->id, ['amount' => 100, 'cutoff_at' => now(), 'reason' => 'Conteo'], $this->user);
    $service->record($this->warehouse->id, 'test', 2, 5);
    $this->getJson('/api/v1/reports/cash-position?mode=current')->assertOk()->assertJsonPath('summary.closing_balance', 105)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/reports/cash-position?mode=as_of&as_of=2026-09-14')->assertOk()->assertJsonPath('summary.closing_balance', null)->assertJsonPath('summary.historical_available', false);
    $this->travelTo(\Carbon\Carbon::parse('2026-09-17 12:00:00'));
    $service->record($this->warehouse->id, 'test', 3, 20, occurredAt: '2026-09-16 04:59:59');
    $service->record($this->warehouse->id, 'test', 4, 30, occurredAt: '2026-09-16 05:00:00');
    $this->getJson('/api/v1/reports/cash-position?mode=as_of&as_of=2026-09-15')->assertOk()->assertJsonPath('summary.closing_balance', 125);
    $this->getJson('/api/v1/reports/cash-position?mode=period&date_from=2026-09-16&date_to=2026-09-16')->assertOk()->assertJsonPath('summary.opening_balance', 125)->assertJsonPath('summary.closing_balance', 155);
});

it('exports the complete filtered report and rejects unauthorized account history', function () {
    [$order, $item] = reportOrder($this);
    $response = $this->get('/api/v1/reports/sales-lines/export/csv?date_from=2026-08-01&date_to=2026-08-31&per_page=1')->assertOk();
    $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
    expect($contents)->toContain($order->order_number)->toContain('RESUMEN COMPLETO')->toContain('net_sales');
    $this->get('/api/v1/reports/sales-lines/export/xlsx?date_from=2026-08-01&date_to=2026-08-31')->assertOk();
    $this->get('/api/v1/reports/payment-methods/export/csv')->assertOk();
    $this->get('/api/v1/reports/cash-position/export/csv')->assertOk();
    $this->getJson('/api/v1/financial-accounts/report?per_page=1')->assertOk()->assertJsonStructure(['data', 'total', 'totals_by_currency']);
    $this->getJson('/api/v1/reports/sales-line-options?field=product_id')->assertOk()->assertJsonPath('data.0.id', $item->product_id);
    $this->user->update(['role' => 'manager']);
    $this->getJson('/api/v1/financial-accounts/report')->assertForbidden();
});

it('distributes mixed collections and later installments by method without treating credit as money', function () {
    [$order] = reportOrder($this);
    $this->travelTo(\Carbon\Carbon::parse('2026-09-10 12:00:00'));
    $payment = fn ($method, $amount, $returnId = null) => \App\Models\SalesOrderPayment::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'method' => $method, 'amount' => $amount, 'sales_return_id' => $returnId, 'created_by' => $this->user->id, 'paid_at' => now()]);
    $payment('cash', 40);
    $payment('yappy', 60);
    $payment('store_credit', 20);
    $return = SalesReturn::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'approved', 'total_amount' => 10, 'refund_method' => 'cash', 'settlement_status' => 'settled', 'reason' => 'Parcial', 'approved_at' => now()]);
    $payment('cash', -10, $return->id);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00'));
    $payment('cash', 5);
    \App\Models\PaymentMethod::where('code', 'yappy')->update(['is_active' => false]);
    $summary = collect($this->getJson('/api/v1/cash/payment-summary?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('data'))->keyBy('method');
    expect($summary->keys()->sort()->values()->all())->toBe(['cash', 'yappy'])
        ->and((float) $summary['cash']['incoming'])->toBe(40.0)->and((float) $summary['cash']['outgoing'])->toBe(10.0)->and((float) $summary['cash']['net'])->toBe(30.0)
        ->and((float) $summary['yappy']['net'])->toBe(60.0);
    $this->getJson('/api/v1/cash/payment-movements?date_from=2026-09-01&date_to=2026-09-30&method=yappy&pending_account=1')->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/v1/cash/payment-summary?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->assertJsonPath('data.0.incoming', fn ($value) => (float) $value === 5.0);
});

it('balances discount and return tax cents across lines and excludes drafts and other warehouses', function () {
    [$order, $first] = reportOrder($this);
    $first->update(['quantity' => 1, 'unit_price' => 3.33, 'unit_cost' => 1]);
    $items = collect([$first]);
    for ($index = 0; $index < 2; $index++) {
        $items->push(SalesOrderItem::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'product_id' => $first->product_id, 'quantity' => 1, 'unit_price' => 3.33, 'unit_cost' => 1]));
    }
    $order->update(['discount_amount' => 0.01, 'shipping_cost' => 5]);
    $order->refresh()->calculateTotals();
    $report = $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('summary.discounts', 0.01)->assertJsonPath('summary.net_sales', 9.98)->json();
    expect(round(collect($report['data'])->sum('discounts'), 2))->toBe(0.01);
    $return = SalesReturn::create(['sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'approved', 'total_amount' => 10.01, 'refund_method' => 'cash', 'reason' => 'Impuesto con centavos', 'approved_at' => '2026-09-10 12:00:00']);
    foreach ($items as $item) {
        SalesReturnItem::create(['sales_return_id' => $return->id, 'sales_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => 1, 'unit_price' => 3.33, 'subtotal' => 3.33, 'reporting_unit_cost' => 1]);
    }
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->assertJsonPath('summary.tax', -0.02);
    $order->update(['status' => 'draft']);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('summary.profit', 0)->assertJsonPath('summary.incomplete', false);
    $order->update(['status' => 'confirmed']);
    $other = Warehouse::create(['code' => 'OTHER-REPORT', 'name' => 'Otra']);
    $this->user->update(['role' => 'manager', 'current_warehouse_id' => $other->id]);
    $this->user->warehouses()->attach($other);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31&warehouse_id='.$this->warehouse->id)->assertOk()->assertJsonPath('meta.total', 0);
});

it('does not deduct a header discount twice when it was already applied to lines', function () {
    [$order, $item] = reportOrder($this);
    $item->update(['discount_amount' => 10]);
    $order->updateQuietly(['total' => 90, 'discount_amount' => 10]);
    $this->getJson('/api/v1/reports/sales-lines?date_from=2026-08-01&date_to=2026-08-31')->assertOk()->assertJsonPath('summary.discounts', 10)->assertJsonPath('summary.net_sales', 90)->assertJsonPath('summary.profit', 50);
});
