<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerPostingService;
use App\Services\OperationalLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function balanceSheetUser(): array
{
    $warehouse = Warehouse::create(['code' => 'BS-'.fake()->unique()->numerify('####'), 'name' => 'Almacén Balance']);
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach($warehouse->id);

    return [$user, $warehouse];
}

function postBalanceEntry(array $data): JournalEntry
{
    return app(LedgerPostingService::class)->postManualEntry($data);
}

function balanceSheetProduct(): Product
{
    return Product::create([
        'sku_base' => 'BS-'.fake()->unique()->numerify('#####'),
        'name' => 'Producto Balance Sheet',
        'status' => 'active',
    ]);
}

it('builds the consolidated balance from posted ledger accounts at one cutoff', function (): void {
    [$user, $warehouse] = balanceSheetUser();

    postBalanceEntry([
        'posting_key' => 'test-opening',
        'source_type' => 'accounting_opening_balance',
        'occurred_at' => '2025-12-31 23:00:00',
        'created_by' => $user->id,
        'lines' => [
            ['account_code' => '1100', 'account_name' => 'Efectivo', 'debit' => 1000, 'credit' => 0],
            ['account_code' => '1300', 'account_name' => 'Inventario', 'debit' => 200, 'credit' => 0],
            ['account_code' => '3100', 'account_name' => 'Resultados acumulados', 'debit' => 0, 'credit' => 1200],
        ],
    ]);
    postBalanceEntry([
        'posting_key' => 'test-sale',
        'source_type' => 'sales_order_recognition',
        'source_id' => 101,
        'warehouse_id' => $warehouse->id,
        'occurred_at' => '2026-02-10 10:00:00',
        'created_by' => $user->id,
        'lines' => [
            ['account_code' => '1200', 'account_name' => 'CxC', 'debit' => 107, 'credit' => 0],
            ['account_code' => '4000', 'account_name' => 'Ventas', 'debit' => 0, 'credit' => 100],
            ['account_code' => '2200', 'account_name' => 'ITBMS', 'debit' => 0, 'credit' => 7],
            ['account_code' => '5100', 'account_name' => 'Costo', 'debit' => 60, 'credit' => 0],
            ['account_code' => '1300', 'account_name' => 'Inventario', 'debit' => 0, 'credit' => 60],
        ],
    ]);
    postBalanceEntry([
        'posting_key' => 'test-receipt',
        'source_type' => 'sales_order_payment',
        'source_id' => 201,
        'warehouse_id' => $warehouse->id,
        'occurred_at' => '2026-02-11 10:00:00',
        'created_by' => $user->id,
        'lines' => [
            ['account_code' => '1100', 'account_name' => 'Efectivo', 'debit' => 107, 'credit' => 0],
            ['account_code' => '1200', 'account_name' => 'CxC', 'debit' => 0, 'credit' => 107],
        ],
    ]);
    postBalanceEntry([
        'posting_key' => 'test-expense',
        'source_type' => 'operational_expense',
        'source_id' => 301,
        'warehouse_id' => $warehouse->id,
        'occurred_at' => '2026-02-12 10:00:00',
        'created_by' => $user->id,
        'lines' => [
            ['account_code' => '5000', 'account_name' => 'Gastos', 'debit' => 20, 'credit' => 0],
            ['account_code' => '2100', 'account_name' => 'CxP', 'debit' => 0, 'credit' => 20],
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/balance-sheet?as_of=2026-02-28&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('scope', 'consolidated')
        ->assertJsonPath('as_of', '2026-02-28')
        ->assertJsonPath('period_start', '2026-01-01')
        ->assertJsonPath('assets.cash', 1107)
        ->assertJsonPath('assets.inventory', 140)
        ->assertJsonPath('assets.accounts_receivable', 0)
        ->assertJsonPath('liabilities.accounts_payable', 20)
        ->assertJsonPath('equity.retained_earnings', 1200)
        ->assertJsonPath('equity.period_profit', 20)
        ->assertJsonPath('totals.total_assets', 1247)
        ->assertJsonPath('totals.total_liabilities', 27)
        ->assertJsonPath('totals.liabilities_and_equity', 1247)
        ->assertJsonPath('totals.reconciliation_difference', 0)
        ->assertJsonPath('warehouse_id', null);

    expect($response->json('warnings'))->toContain('El Balance Sheet oficial es consolidado; el almacén seleccionado no modifica sus totales.');
});

it('exposes a real difference and never creates retained earnings as a plug', function (): void {
    [$user] = balanceSheetUser();
    $cash = LedgerAccount::query()->where('system_key', 'cash')->firstOrFail();
    $entry = JournalEntry::create([
        'source_type' => 'corrupt_test_entry',
        'posting_key' => 'corrupt-test-entry',
        'occurred_at' => '2026-03-01 10:00:00',
        'status' => 'posted',
        'memo' => 'Asiento incompleto de prueba',
        'created_by' => $user->id,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id,
        'ledger_account_id' => $cash->id,
        'debit' => 50,
        'credit' => 0,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/balance-sheet?as_of=2026-03-01')
        ->assertOk()
        ->assertJsonPath('status', 'unbalanced')
        ->assertJsonPath('assets.cash', 50)
        ->assertJsonPath('equity.retained_earnings', 0)
        ->assertJsonPath('totals.reconciliation_difference', 50)
        ->assertJsonPath('reconciliation.trial_balance.difference', 50);
});

it('returns ledger-backed balance drilldowns at the requested cutoff', function (): void {
    [$user] = balanceSheetUser();

    postBalanceEntry([
        'posting_key' => 'test-cash-drilldown',
        'source_type' => 'accounting_opening_balance',
        'occurred_at' => '2026-03-15 09:00:00',
        'created_by' => $user->id,
        'lines' => [
            ['account_code' => '1100', 'account_name' => 'Efectivo', 'debit' => 75, 'credit' => 0],
            ['account_code' => '3100', 'account_name' => 'Resultados acumulados', 'debit' => 0, 'credit' => 75],
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/financial-drilldown?metric=balance_cash&date_from=2026-03-31&date_to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('metric', 'balance_cash')
        ->assertJsonPath('rows.0.account', '1100 Efectivo')
        ->assertJsonPath('rows.0.debit', 75)
        ->assertJsonPath('meta.total', 1);
});

it('posts sales and receipts once and keeps their ids when resynchronized', function (): void {
    [$user, $warehouse] = balanceSheetUser();
    $product = balanceSheetProduct();
    $order = SalesOrder::create([
        'order_number' => 'SO-LEDGER-001',
        'user_id' => $user->id,
        'date' => '2026-04-10',
        'status' => 'draft',
        'subtotal' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'payment_status' => 'pending',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 60,
        'discount_percent' => 0,
        'tax_percent' => 0,
        'is_custom_line' => true,
        'custom_description' => 'Servicio contable',
    ]);
    $order->update(['status' => 'completed']);
    $order->addPayment(100, 'cash', 'Cobro de prueba', '2026-04-10 11:00:00');

    $service = app(OperationalLedgerService::class);
    $service->syncSalesOrder($order->fresh());
    $order->payments->each(fn ($payment) => $service->syncSalesPayment($payment));
    $count = JournalEntry::query()->count();
    $service->syncSalesOrder($order->fresh());
    $order->payments->each(fn ($payment) => $service->syncSalesPayment($payment));

    expect(JournalEntry::query()->count())->toBe($count)
        ->and(JournalEntry::query()->where('source_type', 'sales_order_recognition')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'sales_order_payment')->count())->toBe(1);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/balance-sheet?as_of=2026-04-10')
        ->assertOk()
        ->assertJsonPath('assets.cash', 100)
        ->assertJsonPath('assets.accounts_receivable', 0)
        ->assertJsonPath('equity.period_profit', 40);
});

it('reconciles receivable and payable control accounts with their operational documents', function (): void {
    [$user, $warehouse] = balanceSheetUser();
    $product = balanceSheetProduct();
    $sale = SalesOrder::create([
        'order_number' => 'SO-CONTROL-001',
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'status' => 'draft',
        'subtotal' => 100,
        'tax_amount' => 0,
        'total' => 100,
        'payment_status' => 'pending',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $sale->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 60,
        'subtotal' => 100,
        'total' => 100,
        'is_custom_line' => true,
        'custom_description' => 'Control CxC',
    ]);
    $sale->update(['status' => 'completed']);

    $supplier = Supplier::create(['name' => 'Proveedor control']);
    $purchase = PurchaseOrder::create([
        'po_number' => 'PO-CONTROL-001',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $user->id,
        'status' => 'draft',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => 80,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 80,
    ]);
    PurchaseOrderItem::create([
        'purchase_order_id' => $purchase->id,
        'product_id' => $product->id,
        'quantity_ordered' => 1,
        'quantity_received' => 1,
        'unit_cost' => 80,
        'subtotal' => 80,
        'tax_amount' => 0,
    ]);
    $purchase->update(['status' => 'received']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/balance-sheet?as_of='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('assets.accounts_receivable', 100)
        ->assertJsonPath('liabilities.accounts_payable', 80);

    $controls = collect($response->json('reconciliation.control_accounts'))->keyBy('key');
    expect($controls->get('accounts_receivable'))
        ->toMatchArray(['ledger_balance' => 100, 'subledger_balance' => 100, 'difference' => 0, 'verified' => true])
        ->and($controls->get('accounts_payable'))
        ->toMatchArray(['ledger_balance' => 80, 'subledger_balance' => 80, 'difference' => 0, 'verified' => true]);
});

it('keeps backfill idempotent and dry-run free of writes', function (): void {
    [$user, $warehouse] = balanceSheetUser();
    $product = balanceSheetProduct();
    SalesOrder::withoutEvents(function () use ($user, $warehouse, $product): void {
        $order = SalesOrder::create([
            'order_number' => 'SO-BACKFILL-001',
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'status' => 'completed',
            'subtotal' => 25,
            'tax_amount' => 0,
            'total' => 25,
            'payment_status' => 'pending',
        ]);
        SalesOrderItem::withoutEvents(fn () => SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'unit_price' => 25,
            'unit_cost' => 10,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_percent' => 0,
            'tax_amount' => 0,
            'subtotal' => 25,
            'total' => 25,
            'is_custom_line' => true,
            'custom_description' => 'Backfill',
        ]));
    });

    $this->artisan('accounting:reconcile-balance-sheet', ['--as-of' => '2026-05-31', '--dry-run' => true])->assertSuccessful();
    expect(JournalEntry::query()->count())->toBe(0);

    $service = app(OperationalLedgerService::class);
    $service->backfill(\Illuminate\Support\Carbon::parse('2026-05-31'));
    $count = JournalEntry::query()->count();
    $service->backfill(\Illuminate\Support\Carbon::parse('2026-05-31'));

    expect($count)->toBeGreaterThan(0)->and(JournalEntry::query()->count())->toBe($count);
});
