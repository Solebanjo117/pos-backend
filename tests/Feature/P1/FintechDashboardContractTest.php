<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fintechDashboardContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'FD-001',
        'name' => 'Sucursal Fintech',
    ]);
    $otherWarehouse = Warehouse::create([
        'code' => 'FD-002',
        'name' => 'Sucursal Externa',
    ]);
    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $user->warehouses()->attach([$warehouse->id, $otherWarehouse->id]);
    $customer = Customer::create([
        'code' => 'FD-C001',
        'name' => 'Cliente Financiero',
        'credit_days' => 15,
        'current_balance' => 200,
    ]);
    $secondCustomer = Customer::create([
        'code' => 'FD-C002',
        'name' => 'Cliente Contado',
        'current_balance' => 0,
    ]);
    $brand = Brand::create(['name' => 'Marca Dashboard', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'FD-P001',
        'name' => 'Producto Dashboard',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'FD-P001-V',
        'status' => 'active',
        'is_default' => true,
    ]);

    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 2,
        'quantity_reserved' => 0,
        'reorder_point' => 5,
        'reorder_quantity' => 10,
    ]);
    InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'test',
        'source_id' => 1,
        'batch_number' => 'FD-LOT',
        'received_quantity' => 2,
        'quantity_available' => 2,
        'unit_cost_base' => 20,
        'tax_per_unit' => 0,
        'misc_per_unit' => 0,
        'landed_unit_cost' => 20,
        'sale_price' => 50,
        'received_at' => now(),
    ]);

    return compact('warehouse', 'otherWarehouse', 'user', 'customer', 'secondCustomer', 'product', 'variant');
}

function createFintechDashboardOrder(
    Customer $customer,
    User $user,
    Warehouse $warehouse,
    Product $product,
    ?ProductVariant $variant,
    string $number,
    string $date,
    float $total,
    float $unitCost,
    string $paymentStatus,
    string $status = 'completed',
): SalesOrder {
    $order = SalesOrder::create([
        'order_number' => $number,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => $date,
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
        'status' => $status,
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => $total,
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentStatus === 'pending' ? 'credit' : 'cash',
    ]);

    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant?->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => $total,
        'unit_cost' => $unitCost,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => $total,
        'total' => $total,
    ]);

    return $order;
}

it('returns fintech financial dashboard data without breaking the legacy dashboard contract', function (): void {
    [
        'warehouse' => $warehouse,
        'otherWarehouse' => $otherWarehouse,
        'user' => $user,
        'customer' => $customer,
        'secondCustomer' => $secondCustomer,
        'product' => $product,
        'variant' => $variant,
    ] = fintechDashboardContext();

    $creditOrder = createFintechDashboardOrder($customer, $user, $warehouse, $product, $variant, 'FD-001', '2026-05-10', 300, 120, 'partial');
    SalesOrderPayment::create([
        'sales_order_id' => $creditOrder->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-05-10 11:00:00',
        'created_by' => $user->id,
    ]);
    $paidOrder = createFintechDashboardOrder($secondCustomer, $user, $warehouse, $product, $variant, 'FD-002', '2026-05-11', 200, 80, 'paid');
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 200,
        'method' => 'card',
        'paid_at' => '2026-05-11 12:00:00',
        'created_by' => $user->id,
    ]);
    $previousOrder = createFintechDashboardOrder($customer, $user, $warehouse, $product, $variant, 'FD-PREV', '2026-04-10', 100, 40, 'paid');
    SalesOrderPayment::create([
        'sales_order_id' => $previousOrder->id,
        'amount' => 100,
        'method' => 'cash',
        'paid_at' => '2026-04-10 12:00:00',
        'created_by' => $user->id,
    ]);
    createFintechDashboardOrder($customer, $user, $otherWarehouse, $product, $variant, 'FD-OTHER', '2026-05-12', 900, 10, 'paid');
    createFintechDashboardOrder($customer, $user, $warehouse, $product, $variant, 'FD-CAN', '2026-05-12', 999, 10, 'paid', 'cancelled');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard?period=custom&date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id.'&compare=true');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'stats',
                'top_products',
                'low_stock_products',
                'inventory_summary',
                'sales_chart',
                'profit_by_brand',
                'sales_by_category',
                'top_cashiers',
                'recent_activity',
                'financial' => [
                    'period',
                    'kpis',
                    'payment_methods',
                    'top_clients',
                    'latest_transactions',
                    'alerts',
                    'report_links',
                ],
            ],
        ])
        ->assertJsonPath('data.financial.period.key', 'custom')
        ->assertJsonPath('data.financial.period.date_from', '2026-05-01')
        ->assertJsonPath('data.financial.period.date_to', '2026-05-31')
        ->assertJsonPath('data.financial.period.previous_date_from', '2026-04-01')
        ->assertJsonPath('data.financial.period.previous_date_to', '2026-04-30')
        ->assertJsonPath('data.financial.kpis.0.key', 'total_revenue')
        ->assertJsonPath('data.financial.kpis.0.value', 500)
        ->assertJsonPath('data.financial.kpis.0.route', '/reports/financial')
        ->assertJsonPath('data.financial.kpis.0.comparison.previous_value', 100)
        ->assertJsonPath('data.financial.kpis.0.comparison.delta_percentage', 400)
        ->assertJsonPath('data.financial.kpis.1.key', 'accounts_receivable')
        ->assertJsonPath('data.financial.kpis.1.value', 200)
        ->assertJsonPath('data.financial.kpis.1.route', '/ar')
        ->assertJsonPath('data.financial.kpis.2.key', 'net_profit')
        ->assertJsonPath('data.financial.kpis.2.value', 300)
        ->assertJsonPath('data.financial.kpis.3.key', 'cogs_expenses')
        ->assertJsonPath('data.financial.kpis.3.value', 200)
        ->assertJsonPath('data.financial.kpis.4.key', 'cash_collected')
        ->assertJsonPath('data.financial.kpis.4.value', 300)
        ->assertJsonPath('data.financial.kpis.5.key', 'inventory_value')
        ->assertJsonPath('data.financial.kpis.5.value', 40)
        ->assertJsonPath('data.financial.payment_methods.0.method', 'card')
        ->assertJsonPath('data.financial.payment_methods.0.amount', 200)
        ->assertJsonPath('data.financial.payment_methods.0.percentage', 66.67)
        ->assertJsonPath('data.financial.payment_methods.1.method', 'cash')
        ->assertJsonPath('data.financial.top_clients.0.customer_id', $customer->id)
        ->assertJsonPath('data.financial.top_clients.0.total_revenue', 300)
        ->assertJsonPath('data.financial.top_clients.0.pending_amount', 200)
        ->assertJsonPath('data.financial.latest_transactions.0.reference', 'FD-002')
        ->assertJsonPath('data.financial.latest_transactions.0.route', '/sales-orders/'.$paidOrder->id)
        ->assertJsonPath('data.financial.alerts.0.type', 'low_stock')
        ->assertJsonPath('data.financial.report_links.income_statement', '/reports/financial')
        ->assertJsonPath('data.financial.report_links.accounts_receivable', '/ar');
});
