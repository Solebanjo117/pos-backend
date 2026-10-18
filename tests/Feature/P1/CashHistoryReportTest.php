<?php

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseCashEntry;
use App\Services\AccountLedgerService;
use App\Services\WarehouseCashService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function historySale($test, float $amount, string $effective = '2026-06-20 17:00:00', bool $legacy = true, array $attributes = []): SalesOrderPayment
{
    $create = fn () => SalesOrderPayment::create([...['sales_order_id' => $test->order->id, 'method' => 'cash', 'amount' => $amount, 'warehouse_id' => $test->warehouse->id, 'pos_session_id' => $test->session->id, 'paid_at' => $effective, 'business_datetime' => $effective, 'created_by' => $test->user->id], ...$attributes]);

    return $legacy ? SalesOrderPayment::withoutEvents($create) : $create();
}

function historyReport($test, array $filters = [])
{
    return $test->getJson('/api/v1/reports/cash-position?'.http_build_query([...['warehouse_id' => $test->warehouse->id, 'date_from' => '2026-01-01', 'date_to' => '2026-10-04', 'mode' => 'current'], ...$filters]))->assertOk();
}

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-05 17:00:00', 'UTC'));
    $this->warehouse = Warehouse::create(['code' => 'HISTORY', 'name' => 'Sucursal historial']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $this->session = PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 0, 'opened_at' => now()]);
    $this->order = SalesOrder::create(['user_id' => $this->user->id, 'pos_session_id' => $this->session->id, 'status' => 'completed', 'date' => '2026-06-20', 'total' => 100]);
    PaymentMethod::updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash']);
});

it('shows fifteen pre-ledger cash receipts without inventing physical balances or writing ledger entries', function (): void {
    $payments = collect(range(1, 15))->map(fn ($i) => historySale($this, $i));
    $before = $payments->map(fn ($p) => $p->fresh()->getRawOriginal())->all();
    $report = historyReport($this)->assertJsonPath('meta.total', 15)->assertJsonPath('summary.initialized', false)
        ->assertJsonPath('summary.incoming', 120)->assertJsonPath('summary.closing_balance', null)->assertJsonPath('summary.opening_balance', null)
        ->assertJsonPath('history_summary.untracked_records', 15)->assertJsonPath('summary.balance_complete', false);
    foreach ($report->json('data') as $row) {
        expect($row['balance'])->toBeNull()->and($row['included_in_cash_balance'])->toBeFalse()->and($row['origin'])->toBe('sales')
            ->and($row['document_url'])->toBe('/sales-orders/'.$this->order->id);
    }
    expect(WarehouseCashEntry::count())->toBe(0)->and($payments->map(fn ($p) => $p->fresh()->getRawOriginal())->all())->toBe($before);
});

it('honors the selected history range in current mode and explains receipts outside it', function (): void {
    historySale($this, 70);
    historySale($this, 30, '2026-09-20 17:00:00');
    historyReport($this, ['date_from' => '2026-09-01'])->assertJsonPath('meta.total', 1)->assertJsonPath('summary.incoming', 30)->assertJsonPath('history_summary.outside_range_records', 1);
    historyReport($this, ['date_from' => '2026-10-01'])->assertJsonPath('meta.total', 0)->assertJsonPath('summary.incoming', 0)->assertJsonPath('history_summary.outside_range_records', 2);
});

it('caps history at the effective current instant even when the selected range extends into the future', function (): void {
    historySale($this, 10, '2026-10-05 17:00:00');
    historySale($this, 20, '2026-10-05 17:00:01');
    $report = historyReport($this, ['date_from' => '2026-10-05', 'date_to' => '2026-10-06'])->assertJsonPath('meta.total', 1)->assertJsonPath('summary.incoming', 10);
    expect($report->json('history_summary.effective_to'))->toBe($report->json('summary.effective_as_of'));
});

it('keeps physical cash current while filtering history and counts modern documents only once', function (): void {
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 100, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    historySale($this, 20, '2026-09-20 17:00:00', false);
    historySale($this, 30, '2026-10-03 17:00:00', false);
    historyReport($this, ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'])->assertJsonPath('meta.total', 1)
        ->assertJsonPath('summary.incoming', 20)->assertJsonPath('summary.closing_balance', 150)->assertJsonPath('data.0.balance', 120)
        ->assertJsonPath('data.0.origin', 'ledger')->assertJsonPath('history_summary.untracked_records', 0);
    historyReport($this, ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'mode' => 'period'])->assertJsonPath('summary.closing_balance', 120);
    expect(WarehouseCashEntry::count())->toBe(2);
});

it('retains history before reconciliation without using it again in the reconciled balance', function (): void {
    historySale($this, 80);
    historySale($this, 10, '2026-06-21 17:00:00', false);
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 50, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    historySale($this, 20, '2026-09-20 17:00:00', false);
    $report = historyReport($this)->assertJsonPath('meta.total', 3)->assertJsonPath('summary.incoming', 110)
        ->assertJsonPath('summary.opening_balance', null)->assertJsonPath('summary.closing_balance', 70);
    expect($report->json('data.0.balance'))->toBeNull()->and($report->json('data.1.balance'))->toBeNull()
        ->and((float) $report->json('data.2.balance'))->toBe(70.0);
});

it('marks balances unknown when original documents after the reconciled cutoff lack ledger trace', function (): void {
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 50, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    historySale($this, 20, '2026-09-20 17:00:00');
    historyReport($this, ['date_from' => '2026-09-01'])->assertJsonPath('summary.closing_balance', null)->assertJsonPath('summary.opening_balance', null)->assertJsonPath('summary.balance_complete', false);
    expect(WarehouseCashEntry::count())->toBe(0);
});

it('shows one full legacy customer receipt rather than its applications and linked cash movement', function (): void {
    $customer = Customer::create(['code' => 'HIST-C', 'name' => 'Cliente descartable', 'credit_limit' => 1000, 'current_balance' => 30]);
    $movement = CashMovement::withoutEvents(fn () => CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => 'in', 'amount' => 40, 'balance_before' => 0, 'balance_after' => 40, 'reason' => 'Recibo', 'business_datetime' => '2026-06-20 17:00:00']));
    $receipt = AccountPaymentBatch::withoutEvents(fn () => AccountPaymentBatch::create(['account_type' => 'customer', 'account_id' => $customer->id, 'direction' => 'incoming', 'batch_number' => 'HIST-REC-1', 'amount' => 40, 'method' => 'cash', 'pos_session_id' => $this->session->id, 'cash_movement_id' => $movement->id, 'paid_at' => '2026-06-20 17:00:00', 'business_datetime' => '2026-06-20 17:00:00', 'created_by' => $this->user->id]));
    historySale($this, 30, '2026-06-21 17:00:00', true, ['account_payment_batch_id' => $receipt->id]);
    historyReport($this)->assertJsonPath('meta.total', 1)->assertJsonPath('summary.incoming', 40)->assertJsonPath('data.0.origin', 'receipts');
    expect(WarehouseCashEntry::count())->toBe(0);
});

it('does not duplicate a modern customer receipt represented in the cash ledger', function (): void {
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 0, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    $customer = Customer::create(['code' => 'HIST-MODERN', 'name' => 'Cliente descartable', 'credit_limit' => 1000, 'current_balance' => 30]);
    $this->order->update(['customer_id' => $customer->id]);
    app(AccountLedgerService::class)->recordCustomerReceipt($customer, ['amount' => 40, 'method' => 'cash', 'paid_at' => '2026-10-03 17:00:00', 'applications' => [['document_id' => $this->order->id, 'amount' => 30]]]);
    historyReport($this)->assertJsonPath('meta.total', 1)->assertJsonPath('summary.incoming', 40)->assertJsonPath('summary.closing_balance', 40)->assertJsonPath('history_summary.untracked_records', 0);
});

it('resolves legacy sales only from a unique document warehouse and never assigns ambiguous items', function (): void {
    $this->order->update(['pos_session_id' => null]);
    $product = Product::factory()->create();
    $item = ['sales_order_id' => $this->order->id, 'product_id' => $product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1, 'unit_price' => 10, 'subtotal' => 10, 'total' => 10];
    SalesOrderItem::withoutEvents(fn () => SalesOrderItem::create($item));
    historySale($this, 10, '2026-06-20 17:00:00', true, ['warehouse_id' => null, 'pos_session_id' => null]);
    historyReport($this)->assertJsonPath('meta.total', 1);
    $other = Warehouse::create(['code' => 'HIST-OTHER', 'name' => 'Otra sucursal']);
    SalesOrderItem::withoutEvents(fn () => SalesOrderItem::create([...$item, 'warehouse_id' => $other->id]));
    $report = historyReport($this)->assertJsonPath('meta.total', 0);
    expect(implode(' ', $report->json('warnings')))->toContain('sin sucursal identificable');
});

it('uses regional effective days rather than document creation dates', function (): void {
    historySale($this, 10, '2026-06-20 04:59:59');
    historySale($this, 20, '2026-06-20 05:00:00');
    historySale($this, 30, '2026-06-21 04:59:59');
    historySale($this, 40, '2026-06-21 05:00:00');
    historyReport($this, ['date_from' => '2026-06-20', 'date_to' => '2026-06-20'])->assertJsonPath('meta.total', 2)->assertJsonPath('summary.incoming', 50);
});

it('filters detail without changing totals or running balances and paginates duplicate source ids safely', function (): void {
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 10, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    historySale($this, 10, '2026-09-20 17:00:00', false, ['reference_number' => 'MATCH']);
    historySale($this, 20, '2026-09-21 17:00:00', false, ['reference_number' => 'OTHER']);
    historyReport($this, ['date_from' => '2026-09-01', 'search' => 'OTHER'])->assertJsonPath('meta.total', 1)->assertJsonPath('summary.incoming', 30)->assertJsonPath('summary.closing_balance', 40)->assertJsonPath('data.0.balance', 40);
    $a = historyReport($this, ['per_page' => 1])->json('data.0.row_key');
    $b = historyReport($this, ['per_page' => 1, 'page' => 2])->json('data.0.row_key');
    expect($a)->not->toBe($b);
});

it('keeps distinct row keys when a legacy payment and a modern ledger entry have the same numeric id', function (): void {
    $legacy = historySale($this, 10);
    $legacy->forceFill(['created_at' => '2026-06-20 17:00:00'])->saveQuietly();
    app(WarehouseCashService::class)->reconcile($this->warehouse->id, ['amount' => 10, 'cutoff_at' => '2026-09-01 05:00:00', 'reason' => 'Conteo descartable'], $this->user);
    historySale($this, 20, '2026-09-20 17:00:00', false);
    $rows = historyReport($this)->assertJsonPath('meta.total', 2)->json('data');
    expect($rows[0]['origin'])->toBe('sales')->and($rows[1]['origin'])->toBe('ledger')
        ->and($rows[0]['id'])->toBe($rows[1]['id'])->and($rows[0]['row_key'])->not->toBe($rows[1]['row_key']);
});

it('enforces manager branch context and rejects cashier access and invalid date ranges', function (): void {
    historySale($this, 20);
    $other = Warehouse::create(['code' => 'HIST-SCOPED', 'name' => 'Otra sucursal']);
    $this->user->update(['role' => 'manager']);
    historyReport($this, ['warehouse_id' => $other->id])->assertJsonPath('summary.warehouse_id', $this->warehouse->id)->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/reports/cash-position?date_from=2026-10-04&date_to=2026-01-01&mode=current')->assertUnprocessable();
    $this->user->update(['role' => 'cashier']);
    $this->getJson('/api/v1/reports/cash-position')->assertForbidden();
});

it('retains legacy expenses and funding and deduplicates linked refund documents', function (): void {
    $return = SalesReturn::withoutEvents(fn () => SalesReturn::create(['return_number' => 'HIST-RETURN-1', 'sales_order_id' => $this->order->id, 'warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'completed', 'reason' => 'Prueba', 'total_amount' => 5]));
    historySale($this, -5, '2026-06-20 17:00:00', true, ['sales_return_id' => $return->id]);
    foreach ([['out', 'expense', 10, null, null], ['in', 'funding', 20, null, null], ['out', 'sales_return_refund', 5, 'sales_return', $return->id]] as [$type, $subtype, $amount, $sourceType, $sourceId]) {
        CashMovement::withoutEvents(fn () => CashMovement::create(['pos_session_id' => $this->session->id, 'user_id' => $this->user->id, 'type' => $type, 'subtype' => $subtype, 'amount' => $amount, 'balance_before' => 50, 'balance_after' => $type === 'out' ? 50 - $amount : 50 + $amount, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => 'Histórico descartable', 'business_datetime' => '2026-06-20 17:00:00']));
    }
    historyReport($this)->assertJsonPath('meta.total', 3)->assertJsonPath('summary.incoming', 20)->assertJsonPath('summary.outgoing', 15)->assertJsonPath('summary.closing_balance', null);
});
