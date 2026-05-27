<?php

use App\Models\AccountPaymentBatch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\JournalEntry;
use App\Models\OperationalExpense;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function financialReportContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-FR-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Reportes',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    return [$admin, $warehouse];
}

function financialReportProduct(Warehouse $warehouse): array
{
    $category = Category::create(['name' => 'Frenos']);
    $brand = Brand::create(['name' => 'Brembo', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'FR-REPORT-1',
        'name' => 'Pastilla de freno reporte',
        'category_id' => $category->id,
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'FR-REPORT-1-BRE',
        'status' => 'active',
        'is_default' => true,
    ]);

    InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'opening_balance',
        'received_quantity' => 4,
        'quantity_available' => 4,
        'unit_cost_base' => 20,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 20,
        'received_at' => '2026-05-10 09:00:00',
    ]);

    return [$product, $variant, $brand, $category];
}

function financialReportSale(User $user, Warehouse $warehouse, Product $product, ?ProductVariant $variant = null): SalesOrder
{
    $customer = Customer::create([
        'code' => 'CLI-FR-1',
        'name' => 'Cliente Reportes',
        'email' => 'cliente-reportes@example.test',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-FR-001',
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => '2026-05-12',
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
        'variant_id' => $variant?->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
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
        'amount' => 40,
        'method' => 'cash',
        'paid_at' => '2026-05-13 10:00:00',
        'created_by' => $user->id,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 60,
        'method' => 'transfer',
        'paid_at' => '2026-05-20 10:00:00',
        'created_by' => $user->id,
    ]);

    return $order;
}

function financialReportPurchase(User $user, Warehouse $warehouse, Supplier $supplier): PurchaseOrder
{
    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $user->id,
        'status' => 'received',
        'payment_status' => 'paid',
        'order_date' => '2026-05-12',
        'subtotal' => 200,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 200,
    ]);

    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'amount' => 75,
        'method' => 'cash',
        'paid_at' => '2026-05-13 11:00:00',
        'user_id' => $user->id,
    ]);

    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'amount' => 125,
        'method' => 'transfer',
        'paid_at' => '2026-05-20 11:00:00',
        'user_id' => $user->id,
    ]);

    return $purchaseOrder;
}

it('reports cash and operational accrual income separately with comparison and breakdowns', function (): void {
    [$admin, $warehouse] = financialReportContext();
    [$product, $variant, $brand, $category] = financialReportProduct($warehouse);
    financialReportSale($admin, $warehouse, $product, $variant);

    OperationalExpense::create([
        'expense_date' => '2026-05-13',
        'category' => 'rent',
        'description' => 'Alquiler',
        'amount' => 10,
        'payment_method' => 'transfer',
        'warehouse_id' => $warehouse->id,
        'created_by' => $admin->id,
    ]);

    $previousOrder = SalesOrder::create([
        'order_number' => 'SO-FR-PREV',
        'user_id' => $admin->id,
        'date' => '2026-04-12',
        'status' => 'completed',
        'subtotal' => 20,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 20,
        'payment_status' => 'paid',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $previousOrder->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 20,
        'unit_cost' => 5,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 20,
        'total' => 20,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $previousOrder->id,
        'amount' => 20,
        'method' => 'cash',
        'paid_at' => '2026-04-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-04-12',
        'category' => 'software',
        'description' => 'Software previo',
        'amount' => 5,
        'payment_method' => 'transfer',
        'warehouse_id' => $warehouse->id,
        'created_by' => $admin->id,
    ]);

    $cash = $this->actingAs($admin)
        ->getJson('/api/v1/reports/income-statement?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id.'&basis=cash&compare=previous_period')
        ->assertOk()
        ->assertJsonPath('basis', 'cash')
        ->assertJsonPath('summary.total_revenue', 100)
        ->assertJsonPath('summary.total_cogs', 60)
        ->assertJsonPath('summary.net_profit', 30)
        ->assertJsonPath('comparison.previous.summary.total_revenue', 20)
        ->assertJsonPath('comparison.variance.total_revenue.amount', 80)
        ->assertJsonPath('breakdowns.by_brand.0.id', $brand->id)
        ->assertJsonPath('breakdowns.by_category.0.id', $category->id)
        ->assertJsonPath('breakdowns.by_warehouse.0.id', $warehouse->id);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/income-statement?date_from=2026-05-01&date_to=2026-05-13&warehouse_id='.$warehouse->id.'&basis=accrual_operational')
        ->assertOk()
        ->assertJsonPath('basis', 'accrual_operational')
        ->assertJsonPath('summary.total_revenue', 100)
        ->assertJsonPath('summary.total_cogs', 60)
        ->assertJsonPath('summary.net_profit', 30);

    expect($cash->json('credit_balances.accounts_receivable.aging_buckets.1_30.amount'))->toBe(0);
});

it('reports credit aging, document drilldowns, recent movements and historical financial position', function (): void {
    [$admin, $warehouse] = financialReportContext();
    [$product, $variant] = financialReportProduct($warehouse);
    $sale = financialReportSale($admin, $warehouse, $product, $variant);
    $supplier = Supplier::create([
        'name' => 'Proveedor Reportes',
        'email' => 'proveedor-reportes@example.test',
    ]);
    $purchaseOrder = financialReportPurchase($admin, $warehouse, $supplier);

    AccountPaymentBatch::create([
        'account_type' => 'customer',
        'account_id' => $sale->customer_id,
        'direction' => 'incoming',
        'batch_number' => 'RC-FR-001',
        'amount' => 40,
        'method' => 'cash',
        'paid_at' => '2026-05-13 10:00:00',
        'created_by' => $admin->id,
    ]);

    AccountPaymentBatch::create([
        'account_type' => 'supplier',
        'account_id' => $supplier->id,
        'direction' => 'outgoing',
        'batch_number' => 'PG-FR-001',
        'amount' => 75,
        'method' => 'cash',
        'paid_at' => '2026-05-13 11:00:00',
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/income-statement?date_from=2026-05-01&date_to=2026-05-13&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('credit_balances.accounts_receivable.total_open_balance', 60)
        ->assertJsonPath('credit_balances.accounts_receivable.aging_buckets.1_30.amount', 60)
        ->assertJsonPath('credit_balances.accounts_receivable.documents.0.document_number', $sale->order_number)
        ->assertJsonPath('credit_balances.accounts_receivable.recent_movements.0.batch_number', 'RC-FR-001')
        ->assertJsonPath('credit_balances.accounts_payable.total_open_balance', 125)
        ->assertJsonPath('credit_balances.accounts_payable.aging_buckets.1_30.amount', 125)
        ->assertJsonPath('credit_balances.accounts_payable.documents.0.document_number', $purchaseOrder->po_number)
        ->assertJsonPath('credit_balances.accounts_payable.recent_movements.0.batch_number', 'PG-FR-001');

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-position?warehouse_id='.$warehouse->id.'&date_to=2026-05-13&mode=as_of')
        ->assertOk()
        ->assertJsonPath('mode', 'as_of')
        ->assertJsonPath('summary.accounts_receivable', 60)
        ->assertJsonPath('summary.accounts_payable', 125)
        ->assertJsonPath('summary.inventory_value', 80)
        ->assertJsonPath('warnings', []);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-position?warehouse_id='.$warehouse->id.'&date_to=2026-05-21&mode=current_snapshot')
        ->assertOk()
        ->assertJsonPath('mode', 'current_snapshot')
        ->assertJsonPath('summary.accounts_receivable', 0)
        ->assertJsonPath('summary.accounts_payable', 0)
        ->assertJsonPath('warnings.0.code', 'current_snapshot');
});

it('creates balanced journal entries and exposes a trial balance report', function (): void {
    [$admin, $warehouse] = financialReportContext();

    $entry = app(LedgerPostingService::class)->postManualEntry([
        'source_type' => 'test_entry',
        'source_id' => 99,
        'warehouse_id' => $warehouse->id,
        'occurred_at' => '2026-05-13 12:00:00',
        'memo' => 'Asiento de prueba',
        'created_by' => $admin->id,
        'lines' => [
            ['account_code' => '1100', 'account_name' => 'Caja', 'debit' => 100, 'credit' => 0],
            ['account_code' => '4000', 'account_name' => 'Ingresos', 'debit' => 0, 'credit' => 100],
        ],
    ]);

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and((float) $entry->lines->sum('debit'))->toBe(100.0)
        ->and((float) $entry->lines->sum('credit'))->toBe(100.0)
        ->and($entry->source_type)->toBe('test_entry')
        ->and($entry->source_id)->toBe(99);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/ledger-trial-balance?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_debit', 100)
        ->assertJsonPath('summary.total_credit', 100)
        ->assertJsonPath('summary.is_balanced', true)
        ->assertJsonPath('accounts.0.account_code', '1100')
        ->assertJsonPath('accounts.1.account_code', '4000');
});
