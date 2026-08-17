<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialDailySnapshot;
use App\Models\InventoryLot;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinancialSnapshotService;
use App\Services\OperationalLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dailySummaryContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-DS-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Daily Summary',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
        'can_manage_financial_adjustments' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    $brand = Brand::create(['name' => 'Marca DS', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'DS-1',
        'name' => 'Producto Daily Summary',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'DS-1-A',
        'status' => 'active',
        'is_default' => true,
    ]);

    return [$admin, $warehouse, $product, $variant];
}

it('returns real cash sales, supplier deferred payments and FIFO inventory warnings in the daily summary', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    // Credit purchase of 200 on 2026-05-01 raises inventory + accounts payable.
    $supplier = Supplier::create([
        'name' => 'Proveedor DS',
        'email' => 'proveedor-ds@example.test',
    ]);
    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-05-01',
        'subtotal' => 200,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 200,
    ]);
    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'amount' => 50,
        'method' => 'transfer',
        'paid_at' => '2026-05-03 11:00:00',
        'user_id' => $admin->id,
    ]);
    InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'purchase_order',
        'source_id' => $purchaseOrder->id,
        'received_quantity' => 10,
        'quantity_available' => 7,
        'unit_cost_base' => 20,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 20,
        'received_at' => '2026-05-01 09:00:00',
    ]);

    // A cash sale of 100 paid same day, with COGS 60.
    $order = SalesOrder::create([
        'order_number' => 'SO-DS-001',
        'user_id' => $admin->id,
        'date' => '2026-05-02',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 60,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-05-02 10:00:00',
        'created_by' => $admin->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/reports/daily-summary?date_from=2026-05-01&date_to=2026-05-03&warehouse_id='.$warehouse->id)
        ->assertOk();

    $rows = collect($response->json('rows'))->keyBy('date');

    // Inventory comes from FIFO lots. Historical rows are marked as approximate
    // because lot availability is the current source of truth.
    expect((float) $rows['2026-05-01']['closing_inventory'])->toBe(140.0)
        ->and((float) $rows['2026-05-01']['accounts_payable'])->toBe(200.0)
        ->and((float) $rows['2026-05-02']['closing_inventory'])->toBe(140.0);
    expect($response->json('warnings'))->toContain('inventory_historical_value_uses_current_fifo_lot_availability');

    // Sales recognized as real cash sale + COGS on the sale day.
    expect((float) $rows['2026-05-02']['cash_sales'])->toBe(100.0)
        ->and((float) $rows['2026-05-02']['cogs_cash_sales'])->toBe(60.0)
        ->and((float) $rows['2026-05-02']['net_profit'])->toBe(40.0);
    expect((float) $rows['2026-05-03']['supplier_bank_payments'])->toBe(50.0)
        ->and((float) $rows['2026-05-03']['closing_bank'])->toBe(-50.0)
        ->and((float) $rows['2026-05-03']['accounts_payable'])->toBe(150.0);

    expect((float) $response->json('totals.cash_sales'))->toBe(100.0)
        ->and((float) $response->json('totals.supplier_bank_payments'))->toBe(50.0)
        ->and((float) $response->json('totals.net_profit'))->toBe(40.0);
});

it('reuses cached snapshots for past days but keeps today fresh', function (): void {
    [, $warehouse] = dailySummaryContext();

    $service = app(FinancialSnapshotService::class);
    $pastDate = now()->subDays(3)->toDateString();

    $first = $service->computeDay($pastDate, $warehouse->id);
    $firstComputedAt = $first->computed_at;

    // Recomputing the same closed past day must reuse the cached snapshot.
    $second = $service->computeDay($pastDate, $warehouse->id);
    expect($second->computed_at?->equalTo($firstComputedAt))->toBeTrue();
    expect(FinancialDailySnapshot::query()
        ->where('warehouse_id', $warehouse->id)
        ->whereDate('date', $pastDate)
        ->count())->toBe(1);

    // Today must always be (re)computed.
    $todayFirst = $service->computeDay(now()->toDateString(), $warehouse->id);
    $todaySecond = $service->computeDay(now()->toDateString(), $warehouse->id);
    expect($todaySecond->computed_at)->not->toBeNull()
        ->and($todaySecond->computed_at?->greaterThanOrEqualTo($todayFirst->computed_at))->toBeTrue();
    expect(FinancialDailySnapshot::query()
        ->where('warehouse_id', $warehouse->id)
        ->whereDate('date', now()->toDateString())
        ->count())->toBe(1);
});

it('returns financial drilldown rows and marks formal accounting reports available after operational posting', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();
    $customer = Customer::create([
        'code' => 'C-DRILLDOWN',
        'name' => 'Cliente Drilldown',
        'current_balance' => 0,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-DS-DRILL',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-04',
        'status' => 'completed',
        'subtotal' => 120,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 120,
        'payment_status' => 'paid',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 120,
        'unit_cost' => 70,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 120,
        'total' => 120,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 120,
        'method' => 'card',
        'paid_at' => '2026-05-04 12:00:00',
        'created_by' => $admin->id,
    ]);

    $drilldown = $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=sales&date_from=2026-05-04&date_to=2026-05-04&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('metric', 'sales')
        ->assertJsonPath('total', 120)
        ->assertJsonPath('rows.0.document', 'SO-DS-DRILL')
        ->assertJsonPath('rows.0.paid', 120)
        ->assertJsonPath('rows.0.document_node.type', 'sales_order')
        ->assertJsonPath('rows.0.document_node.id', $order->id)
        ->assertJsonPath('rows.0.party_node.type', 'customer')
        ->assertJsonPath('rows.0.user_node.type', 'user');

    expect(collect($drilldown->json('columns'))->pluck('key')->all())
        ->toContain('display_datetime')
        ->not->toContain('occurred_at', 'business_datetime')
        ->and($drilldown->json('rows.0'))->toHaveKeys([
            'occurred_at',
            'business_datetime',
            'datetime_source',
            'display_datetime',
        ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-advanced-status')
        ->assertOk()
        ->assertJsonPath('ledger.is_available', true)
        ->assertJsonPath('reports.5.status', 'available');
});

it('keeps inventory trace fields internal and paginates drilldowns with supported page sizes', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    foreach (range(1, 31) as $index) {
        InventoryLot::create([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'source_type' => 'opening_balance',
            'source_id' => $index,
            'received_quantity' => 1,
            'quantity_available' => 1,
            'unit_cost_base' => 1,
            'tax_per_unit' => 0,
            'misc_per_unit' => 0,
            'landed_unit_cost' => 1,
            'received_at' => '2026-05-04 09:00:00',
        ]);
    }

    foreach ([10, 25, 50, 100] as $perPage) {
        $response = $this->actingAs($admin)
            ->getJson('/api/v1/reports/financial-drilldown?metric=inventory&date_from=2026-05-04&date_to=2026-05-04&warehouse_id='.$warehouse->id.'&per_page='.$perPage)
            ->assertOk()
            ->assertJsonPath('meta.per_page', $perPage)
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.last_page', (int) ceil(31 / $perPage));

        expect($response->json('rows'))->toHaveCount(min(31, $perPage));
    }

    $firstPage = $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=inventory&date_from=2026-05-04&date_to=2026-05-04&warehouse_id='.$warehouse->id.'&per_page=10&page=1')
        ->assertOk();
    $secondPage = $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=inventory&date_from=2026-05-04&date_to=2026-05-04&warehouse_id='.$warehouse->id.'&per_page=10&page=2')
        ->assertOk();

    $columns = collect($firstPage->json('columns'))->keyBy('key');
    $firstRow = $firstPage->json('rows.0');
    $firstIds = collect($firstPage->json('rows'))->pluck('id');
    $secondIds = collect($secondPage->json('rows'))->pluck('id');

    expect($columns->keys()->all())
        ->not->toContain('warehouse', 'user')
        ->and($columns['display_datetime']['label'])->toBe('Fecha y hora')
        ->and($columns->keys()->all())->not->toContain('occurred_at', 'business_datetime')
        ->and(array_key_exists('warehouse', $firstRow))->toBeTrue()
        ->and(array_key_exists('user', $firstRow))->toBeTrue()
        ->and($firstIds->intersect($secondIds))->toHaveCount(0);
});

it('maps yappy payments and bank adjustments into Banco', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    PaymentMethod::updateOrCreate(
        ['code' => 'yappy'],
        [
            'name' => 'Yappy',
            'is_active' => true,
            'is_system' => true,
            'requires_reference' => true,
            'requires_cash_count' => false,
            'sort_order' => 10,
        ],
    );

    $order = SalesOrder::create([
        'order_number' => 'SO-DS-YAPPY',
        'user_id' => $admin->id,
        'date' => '2026-05-05',
        'status' => 'completed',
        'subtotal' => 90,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 90,
        'payment_status' => 'paid',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 90,
        'unit_cost' => 40,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 90,
        'total' => 90,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 90,
        'method' => 'yappy',
        'paid_at' => '2026-05-05 12:00:00',
        'created_by' => $admin->id,
    ]);

    $bankLedger = LedgerAccount::firstOrCreate(
        ['code' => '1110'],
        ['name' => 'Banco', 'type' => 'asset', 'is_active' => true],
    );
    $counterparty = LedgerAccount::create([
        'code' => '3998',
        'name' => 'Contrapartida de prueba',
        'type' => 'equity',
        'is_active' => true,
    ]);
    $bankAccount = FinancialAccount::create([
        'name' => 'Banco Daily Summary',
        'type' => 'bank',
        'institution_name' => 'Banco Nacional',
        'masked_number' => '•••• 2525',
        'currency' => 'USD',
        'opening_balance' => 0,
        'current_balance' => 0,
        'ledger_account_id' => $bankLedger->id,
        'is_active' => true,
        'is_legacy' => false,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->postJson('/api/v1/reports/adjustments', [
            'warehouse_id' => $warehouse->id,
            'type' => 'bank',
            'direction' => 'increase',
            'amount' => 25,
            'reason' => 'Cuadre banco',
            'business_datetime' => '2026-05-05T12:30:00-05:00',
            'idempotency_key' => (string) Str::uuid(),
            'financial_account_id' => $bankAccount->id,
            'counterparty_ledger_account_id' => $counterparty->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'bank');

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/reports/daily-summary?date_from=2026-05-05&date_to=2026-05-05&warehouse_id='.$warehouse->id)
        ->assertOk();

    expect((float) $response->json('rows.0.bank_sales'))->toBe(90.0)
        ->and((float) $response->json('rows.0.bank_adjustments'))->toBe(25.0)
        ->and((float) $response->json('rows.0.closing_bank'))->toBe(115.0)
        ->and((float) $response->json('totals.bank'))->toBe(115.0);

    $bankDrilldown = $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=bank&date_from=2026-05-05&date_to=2026-05-05&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('total', 115);

    expect($bankDrilldown->json('warnings'))
        ->toContain('bank_is_operational_payment_bucket');
});

it('reports daily generated receivables and payables with matching drilldowns', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    $supplier = Supplier::create([
        'name' => 'Proveedor credito diario',
        'email' => 'credito-diario@example.test',
    ]);
    PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-05-06',
        'subtotal' => 120,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 120,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-CREDIT-GENERATED',
        'user_id' => $admin->id,
        'date' => '2026-05-06',
        'status' => 'completed',
        'subtotal' => 90,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 90,
        'payment_status' => 'pending',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 90,
        'unit_cost' => 35,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 90,
        'total' => 90,
    ]);

    $summary = $this->actingAs($admin)
        ->getJson('/api/v1/reports/daily-summary?date_from=2026-05-06&date_to=2026-05-06&warehouse_id='.$warehouse->id)
        ->assertOk();

    expect((float) $summary->json('rows.0.receivables_generated'))->toBe(90.0)
        ->and((float) $summary->json('rows.0.payables_generated'))->toBe(120.0)
        ->and((float) $summary->json('totals.receivables_generated'))->toBe(90.0)
        ->and((float) $summary->json('totals.payables_generated'))->toBe(120.0);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=receivables_generated&date_from=2026-05-06&date_to=2026-05-06&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('total', 90)
        ->assertJsonPath('rows.0.document', 'SO-CREDIT-GENERATED');

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-drilldown?metric=payables_generated&date_from=2026-05-06&date_to=2026-05-06&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('total', 120);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-reconciliation?date_from=2026-05-06&date_to=2026-05-06&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('passed', true)
        ->assertJsonPath('max_difference', 0)
        ->assertJsonPath('equations.passed', true)
        ->assertJsonCount(9, 'metrics');
});

it('places receivables in assets and reconciles assets with liabilities and equity', function (): void {
    [$admin, $warehouse, $product, $variant] = dailySummaryContext();

    $creditCustomer = Customer::create([
        'code' => 'CLI-BS-CREDIT',
        'name' => 'Cliente por cobrar',
        'status' => 'active',
        'current_balance' => 50,
    ]);
    Customer::create([
        'code' => 'CLI-BS-FAVOR',
        'name' => 'Cliente saldo a favor',
        'status' => 'active',
        'current_balance' => -10,
    ]);

    $paidOrder = SalesOrder::create([
        'order_number' => 'SO-BS-CASH',
        'user_id' => $admin->id,
        'date' => '2026-05-07',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'paid',
    ]);
    $paidItem = SalesOrderItem::create([
        'sales_order_id' => $paidOrder->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 20,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-05-07 09:00:00',
        'created_by' => $admin->id,
    ]);

    $creditOrder = SalesOrder::create([
        'order_number' => 'SO-BS-CREDIT',
        'customer_id' => $creditCustomer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-07',
        'status' => 'completed',
        'subtotal' => 50,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 50,
        'payment_status' => 'pending',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $creditOrder->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 50,
        'unit_cost' => 10,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 50,
        'total' => 50,
    ]);

    $supplier = Supplier::create(['name' => 'Proveedor Balance Sheet']);
    $balancePurchase = PurchaseOrder::create([
        'po_number' => 'PO-BS-001',
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-05-07',
        'subtotal' => 40,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 40,
    ]);
    PurchaseOrderItem::create([
        'purchase_order_id' => $balancePurchase->id,
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity_ordered' => 4,
        'quantity_received' => 4,
        'unit_cost' => 10,
        'subtotal' => 40,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'misc_cost' => 0,
    ]);
    app(OperationalLedgerService::class)->syncPurchaseOrder($balancePurchase->fresh());
    InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'opening_balance',
        'received_quantity' => 3,
        'quantity_available' => 3,
        'unit_cost_base' => 10,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 10,
        'received_at' => '2026-05-07 08:00:00',
    ]);
    SalesReturn::create([
        'return_number' => 'DEV-BS-001',
        'sales_order_id' => $paidOrder->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'approved',
        'settlement_status' => 'pending',
        'total_amount' => 7,
        'money_refund_amount' => 5,
        'store_credit_amount' => 2,
        'receivable_applied_amount' => 0,
        'refund_method' => 'cash',
        'approved_by' => $admin->id,
        'approved_at' => '2026-05-07 12:00:00',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/reports/balance-sheet?date_from=2026-05-07&date_to=2026-05-07&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('assets.accounts_receivable', 50)
        ->assertJsonPath('liabilities.accounts_payable', 40)
        ->assertJsonPath('liabilities.customer_credits', 2)
        ->assertJsonPath('liabilities.pending_refunds', 5)
        ->assertJsonPath('totals.reconciliation_difference', 0);

    expect((float) $response->json('totals.total_assets'))
        ->toBe((float) $response->json('totals.liabilities_and_equity'));
});
