<?php

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\OperationalExpense;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function operationalExpenseAdminContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-GO-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Gastos',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    return [$admin, $warehouse];
}

it('creates and filters operational expenses', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'rent',
        'description' => 'Alquiler del local',
        'amount' => 850.50,
        'payment_method' => 'transfer',
        'reference_number' => 'REC-001',
        'warehouse_id' => $warehouse->id,
        'notes' => 'Pago mensual',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.category', 'rent')
        ->assertJsonPath('data.category_label', 'Alquiler')
        ->assertJsonPath('data.warehouse.id', $warehouse->id)
        ->assertJsonPath('data.pos_session_id', $session->id)
        ->assertJsonPath('data.cash_movement_id', null);

    $this->assertDatabaseHas('operational_expenses', [
        'category' => 'rent',
        'reference_number' => 'REC-001',
        'warehouse_id' => $warehouse->id,
        'pos_session_id' => $session->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/operational-expenses?date_from=2026-05-01&date_to=2026-05-31&category=rent&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_amount', 850.50)
        ->assertJsonCount(1, 'data');
});

it('registers cash operational expenses as cash drawer withdrawals', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'electricity',
        'description' => 'Luz del local',
        'amount' => 45.25,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.cash_movement.pos_session_id', $session->id)
        ->assertJsonPath('data.pos_session_id', $session->id);

    $expense = OperationalExpense::firstOrFail();
    $movement = CashMovement::firstOrFail();

    expect($expense->cash_movement_id)->toBe($movement->id)
        ->and($expense->pos_session_id)->toBe($session->id)
        ->and($movement->type)->toBe('out')
        ->and((float) $movement->amount)->toBe(45.25)
        ->and($movement->reason)->toBe('Gasto operativo: Luz del local');

    $this->actingAs($admin)
        ->getJson('/api/v1/pos-sessions/current?warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.cash_movements_out', 45.25)
        ->assertJsonPath('summary.expected_cash', 54.75);
});

it('registers non cash operational expenses as method withdrawals in the open session', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'software',
        'description' => 'Programa mensual',
        'amount' => 32.75,
        'payment_method' => 'transfer',
        'reference_number' => 'ACH-1',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated()
        ->assertJsonPath('data.pos_session_id', $session->id)
        ->assertJsonPath('data.cash_movement_id', null);

    expect(CashMovement::count())->toBe(0);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/pos-sessions/current?warehouse_id='.$warehouse->id)
        ->assertOk();

    $transferRow = collect($response->json('summary.reconciliation_rows'))->firstWhere('code', 'transfer');

    expect($transferRow)->not->toBeNull()
        ->and((float) $transferRow['withdrawals'])->toBe(-32.75)
        ->and((float) $transferRow['expected_total'])->toBe(-32.75);

    $this->actingAs($admin)
        ->getJson('/api/v1/pos-sessions/'.$session->id)
        ->assertOk()
        ->assertJsonPath('operational_expenses.0.category', 'software')
        ->assertJsonPath('operational_expenses.0.pos_session_id', $session->id);
});

it('rejects operational expenses without an open cash session in the warehouse', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'software',
        'description' => 'Programa mensual',
        'amount' => 32.75,
        'payment_method' => 'transfer',
        'warehouse_id' => $warehouse->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');
});

it('syncs and deletes cash movements while the operational expense session is open', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'maintenance',
        'description' => 'Mantenimiento',
        'amount' => 20,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated();

    $expense = OperationalExpense::firstOrFail();

    $this->actingAs($admin)->putJson("/api/v1/operational-expenses/{$expense->id}", [
        'amount' => 25,
        'description' => 'Mantenimiento local',
    ])->assertOk();

    expect((float) CashMovement::firstOrFail()->amount)->toBe(25.0)
        ->and(CashMovement::firstOrFail()->reason)->toBe('Gasto operativo: Mantenimiento local');

    $this->actingAs($admin)->deleteJson("/api/v1/operational-expenses/{$expense->id}")
        ->assertOk();

    expect(OperationalExpense::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);
});

it('rejects operational expense changes after the linked cash session is closed', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'maintenance',
        'description' => 'Mantenimiento',
        'amount' => 20,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated();

    $expense = OperationalExpense::firstOrFail();
    $session->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $this->actingAs($admin)->putJson("/api/v1/operational-expenses/{$expense->id}", [
        'amount' => 30,
    ])->assertUnprocessable();

    $this->actingAs($admin)->deleteJson("/api/v1/operational-expenses/{$expense->id}")
        ->assertUnprocessable();

    expect(OperationalExpense::count())->toBe(1)
        ->and(CashMovement::count())->toBe(1);
});

it('rejects non cash operational expense changes after the linked session is closed', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson('/api/v1/operational-expenses', [
        'expense_date' => '2026-05-10',
        'category' => 'software',
        'description' => 'Programa mensual',
        'amount' => 20,
        'payment_method' => 'transfer',
        'warehouse_id' => $warehouse->id,
    ])->assertCreated();

    $expense = OperationalExpense::firstOrFail();
    $session->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $this->actingAs($admin)->putJson("/api/v1/operational-expenses/{$expense->id}", [
        'amount' => 30,
    ])->assertUnprocessable();

    $this->actingAs($admin)->deleteJson("/api/v1/operational-expenses/{$expense->id}")
        ->assertUnprocessable();

    expect(OperationalExpense::count())->toBe(1)
        ->and(CashMovement::count())->toBe(0);
});

it('validates operational expense payloads', function () {
    [$admin] = operationalExpenseAdminContext();

    $this->actingAs($admin)
        ->postJson('/api/v1/operational-expenses', [
            'expense_date' => 'not-a-date',
            'category' => 'invalid',
            'amount' => -1,
            'payment_method' => 'bitcoin',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expense_date', 'category', 'amount', 'payment_method']);
});

it('includes global and warehouse operational expenses in financial net profit', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();
    $otherWarehouse = Warehouse::create([
        'code' => 'W-OTHER',
        'name' => 'Otro Almacen',
    ]);

    $product = Product::create([
        'sku_base' => 'FIN-GO-1',
        'name' => 'Producto Reporte',
        'status' => 'active',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-GO-001',
        'user_id' => $admin->id,
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
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 20,
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
        'paid_at' => '2026-05-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'rent',
        'description' => 'Gasto global',
        'amount' => 10,
        'payment_method' => 'transfer',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'maintenance',
        'description' => 'Gasto de almacen',
        'amount' => 5,
        'payment_method' => 'cash',
        'warehouse_id' => $warehouse->id,
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'software',
        'description' => 'Otro almacen',
        'amount' => 99,
        'payment_method' => 'transfer',
        'warehouse_id' => $otherWarehouse->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_revenue', 100)
        ->assertJsonPath('summary.total_cogs', 40)
        ->assertJsonPath('summary.total_operational_expenses', 15)
        ->assertJsonPath('summary.gross_profit', 60)
        ->assertJsonPath('summary.net_profit', 45)
        ->assertJsonPath('operational_expenses.by_category.0.category', 'maintenance')
        ->assertJsonPath('operational_expenses.timeline.0.amount', 15);
});

it('includes open customer and supplier credit balances in financial report without changing net profit', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();
    $otherWarehouse = Warehouse::create([
        'code' => 'W-CREDIT-OTHER',
        'name' => 'Otro Almacen Credito',
    ]);

    $product = Product::create([
        'sku_base' => 'FIN-CREDIT-1',
        'name' => 'Producto Credito',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'code' => 'CLI-CREDIT-1',
        'name' => 'Cliente Credito',
        'email' => 'cliente-credito@example.test',
    ]);

    $otherCustomer = Customer::create([
        'code' => 'CLI-CREDIT-2',
        'name' => 'Cliente Otro Credito',
        'email' => 'cliente-otro-credito@example.test',
    ]);

    $supplier = Supplier::create([
        'name' => 'Proveedor Credito Reporte',
        'email' => 'proveedor-credito-reporte@example.test',
    ]);

    $otherSupplier = Supplier::create([
        'name' => 'Proveedor Otro Credito',
        'email' => 'proveedor-otro-credito@example.test',
    ]);

    $creditSale = SalesOrder::create([
        'order_number' => 'SO-CREDIT-001',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-12',
        'status' => 'completed',
        'subtotal' => 150,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 150,
        'payment_status' => 'partial',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $creditSale->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 150,
        'unit_cost' => 80,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 150,
        'total' => 150,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $creditSale->id,
        'amount' => 40,
        'method' => 'cash',
        'paid_at' => '2026-05-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    $otherCreditSale = SalesOrder::create([
        'order_number' => 'SO-CREDIT-OTHER',
        'customer_id' => $otherCustomer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-12',
        'status' => 'completed',
        'subtotal' => 999,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 999,
        'payment_status' => 'pending',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $otherCreditSale->id,
        'product_id' => $product->id,
        'warehouse_id' => $otherWarehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 999,
        'unit_cost' => 500,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 999,
        'total' => 999,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'partial',
        'order_date' => '2026-05-12',
        'subtotal' => 200,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 200,
    ]);

    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'amount' => 75,
        'method' => 'transfer',
        'reference_number' => 'ACH-75',
        'paid_at' => '2026-05-12 11:00:00',
    ]);

    PurchaseOrder::create([
        'supplier_id' => $otherSupplier->id,
        'warehouse_id' => $otherWarehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-05-12',
        'subtotal' => 888,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 888,
    ]);

    $outOfRangeSale = SalesOrder::create([
        'order_number' => 'SO-CREDIT-OLD',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-04-20',
        'status' => 'completed',
        'subtotal' => 700,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 700,
        'payment_status' => 'pending',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $outOfRangeSale->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 700,
        'unit_cost' => 300,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 700,
        'total' => 700,
    ]);

    $paidSale = SalesOrder::create([
        'order_number' => 'SO-CREDIT-PAID',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-18',
        'status' => 'completed',
        'subtotal' => 0,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 0,
        'payment_status' => 'paid',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $paidSale->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 0,
        'unit_cost' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 0,
        'total' => 0,
    ]);

    PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => '2026-04-20',
        'subtotal' => 600,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 600,
    ]);

    PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'paid',
        'order_date' => '2026-05-18',
        'subtotal' => 400,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 400,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.net_profit', -40)
        ->assertJsonPath('credit_balances.accounts_receivable.total_open_balance', 110)
        ->assertJsonPath('credit_balances.accounts_receivable.open_documents_count', 1)
        ->assertJsonPath('credit_balances.accounts_receivable.accounts_count', 1)
        ->assertJsonPath('credit_balances.accounts_payable.total_open_balance', 125)
        ->assertJsonPath('credit_balances.accounts_payable.open_documents_count', 1)
        ->assertJsonPath('credit_balances.accounts_payable.accounts_count', 1);
});

it('generates an income statement with customer and supplier credit kept out of cash revenue', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();

    $product = Product::create([
        'sku_base' => 'INC-STMT-1',
        'name' => 'Producto Estado Resultados',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'code' => 'CLI-INC-STMT',
        'name' => 'Cliente Credito Resultados',
        'email' => 'cliente-resultados@example.test',
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-INC-STMT',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-12',
        'status' => 'completed',
        'subtotal' => 150,
        'tax_amount' => 7,
        'discount_amount' => 3,
        'shipping_cost' => 0,
        'total' => 150,
        'payment_status' => 'partial',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 150,
        'unit_cost' => 80,
        'discount_percent' => 0,
        'discount_amount' => 3,
        'tax_percent' => 7,
        'tax_amount' => 7,
        'subtotal' => 150,
        'total' => 150,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 40,
        'method' => 'cash',
        'paid_at' => '2026-05-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    OperationalExpense::create([
        'expense_date' => '2026-05-12',
        'category' => 'rent',
        'description' => 'Alquiler de prueba',
        'amount' => 10,
        'payment_method' => 'transfer',
        'warehouse_id' => $warehouse->id,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/income-statement?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('summary.total_revenue', 40)
        ->assertJsonPath('summary.total_cogs', 80)
        ->assertJsonPath('summary.total_operational_expenses', 10)
        ->assertJsonPath('summary.net_profit', -50)
        ->assertJsonPath('sections.0.key', 'income')
        ->assertJsonPath('sections.0.rows.0.key', 'cash-sales')
        ->assertJsonPath('sections.0.rows.0.amount', 40)
        ->assertJsonPath('sections.4.key', 'open-balances')
        ->assertJsonPath('sections.4.rows.0.key', 'accounts-receivable')
        ->assertJsonPath('sections.4.rows.0.amount', 117.5)
        ->assertJsonPath('sections.4.rows.1.key', 'accounts-payable')
        ->assertJsonPath('sections.4.rows.1.amount', 0);
});

it('generates a current operational financial position statement', function () {
    [$admin, $warehouse] = operationalExpenseAdminContext();
    $otherWarehouse = Warehouse::create([
        'code' => 'W-FP-OTHER',
        'name' => 'Otro Almacen Situacion',
    ]);

    $product = Product::create([
        'sku_base' => 'FIN-POS-1',
        'name' => 'Producto Situacion',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'code' => 'CLI-FIN-POS',
        'name' => 'Cliente Situacion',
        'email' => 'cliente-situacion@example.test',
    ]);

    $supplier = Supplier::create([
        'name' => 'Proveedor Situacion',
        'email' => 'proveedor-situacion@example.test',
    ]);

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => '2026-05-12 08:00:00',
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    CashMovement::create([
        'pos_session_id' => $session->id,
        'user_id' => $admin->id,
        'type' => 'in',
        'amount' => 25,
        'reason' => 'adjustment',
    ]);

    CashMovement::create([
        'pos_session_id' => $session->id,
        'user_id' => $admin->id,
        'type' => 'out',
        'amount' => 5,
        'reason' => 'adjustment',
    ]);

    InventoryLot::create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'opening_balance',
        'received_quantity' => 5,
        'quantity_available' => 5,
        'unit_cost_base' => 10,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 10,
        'received_at' => '2026-05-12 09:00:00',
    ]);

    InventoryLot::create([
        'product_id' => $product->id,
        'warehouse_id' => $otherWarehouse->id,
        'source_type' => 'opening_balance',
        'received_quantity' => 9,
        'quantity_available' => 9,
        'unit_cost_base' => 99,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 99,
        'received_at' => '2026-05-12 09:00:00',
    ]);

    $sale = SalesOrder::create([
        'order_number' => 'SO-FIN-POS',
        'customer_id' => $customer->id,
        'user_id' => $admin->id,
        'date' => '2026-05-12',
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 100,
        'payment_status' => 'partial',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $sale->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $admin->id,
        'quantity' => 1,
        'unit_price' => 100,
        'unit_cost' => 30,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $sale->id,
        'amount' => 40,
        'method' => 'cash',
        'paid_at' => '2026-05-12 10:00:00',
        'created_by' => $admin->id,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'partial',
        'order_date' => '2026-05-12',
        'subtotal' => 200,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 200,
    ]);

    PurchaseOrderPayment::create([
        'purchase_order_id' => $purchaseOrder->id,
        'amount' => 75,
        'method' => 'transfer',
        'paid_at' => '2026-05-12 11:00:00',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/reports/financial-position?warehouse_id='.$warehouse->id)
        ->assertOk()
        ->assertJsonPath('basis', 'current_operational_snapshot')
        ->assertJsonPath('summary.total_assets', 230)
        ->assertJsonPath('summary.total_liabilities', 125)
        ->assertJsonPath('summary.operational_equity', 105)
        ->assertJsonPath('sections.0.key', 'assets')
        ->assertJsonPath('sections.0.rows.0.key', 'operational-cash')
        ->assertJsonPath('sections.0.rows.0.amount', 120)
        ->assertJsonPath('sections.0.rows.1.key', 'accounts-receivable')
        ->assertJsonPath('sections.0.rows.1.amount', 60)
        ->assertJsonPath('sections.0.rows.2.key', 'inventory')
        ->assertJsonPath('sections.0.rows.2.amount', 50)
        ->assertJsonPath('sections.1.rows.0.key', 'accounts-payable')
        ->assertJsonPath('sections.1.rows.0.amount', 125);
});
