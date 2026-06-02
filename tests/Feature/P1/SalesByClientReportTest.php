<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salesByClientContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-SBC-'.fake()->unique()->randomNumber(4),
        'name' => 'Sucursal Ventas Cliente',
    ]);
    $otherWarehouse = Warehouse::create([
        'code' => 'W-SBC-O-'.fake()->unique()->randomNumber(4),
        'name' => 'Sucursal Externa',
    ]);
    $user = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach([$warehouse->id, $otherWarehouse->id]);
    $customer = Customer::create([
        'code' => 'CL-SBC',
        'name' => 'Taller Cliente Principal',
        'credit_limit' => 2000,
        'credit_days' => 5,
    ]);
    $secondCustomer = Customer::create([
        'code' => 'CL-SBC-2',
        'name' => 'Auto Repuestos Secundario',
        'credit_days' => 30,
    ]);
    $referrer = Customer::create([
        'code' => 'REF-SBC',
        'name' => 'Mecanico Rodriguez',
    ]);
    $product = Product::create([
        'sku_base' => 'SBC-001',
        'name' => 'Filtro Ventas Cliente',
        'status' => 'active',
    ]);

    return compact('warehouse', 'otherWarehouse', 'user', 'customer', 'secondCustomer', 'referrer', 'product');
}

function createSalesByClientOrder(
    Customer $customer,
    User $user,
    Warehouse $warehouse,
    Product $product,
    string $number,
    string $date,
    float $total,
    string $paymentStatus,
    ?Customer $referrer = null,
    string $status = 'confirmed',
): SalesOrder {
    $order = SalesOrder::create([
        'order_number' => $number,
        'customer_id' => $customer->id,
        'referred_by_customer_id' => $referrer?->id,
        'user_id' => $user->id,
        'date' => $date,
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
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => $total,
        'unit_cost' => $total / 2,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => $total,
        'total' => $total,
    ]);

    return $order;
}

it('aggregates sales by client with real invoices payments referrers and top clients', function (): void {
    [
        'warehouse' => $warehouse,
        'otherWarehouse' => $otherWarehouse,
        'user' => $user,
        'customer' => $customer,
        'secondCustomer' => $secondCustomer,
        'referrer' => $referrer,
        'product' => $product,
    ] = salesByClientContext();

    $paidOrder = createSalesByClientOrder($customer, $user, $warehouse, $product, 'SO-SBC-001', '2026-05-20', 300, 'paid', $referrer, 'completed');
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 300,
        'method' => 'cash',
        'paid_at' => '2026-05-20 10:00:00',
        'created_by' => $user->id,
    ]);

    $partialOrder = createSalesByClientOrder($customer, $user, $warehouse, $product, 'SO-SBC-002', '2026-05-21', 200, 'partial', $referrer);
    SalesOrderPayment::create([
        'sales_order_id' => $partialOrder->id,
        'amount' => 80,
        'method' => 'card',
        'paid_at' => '2026-05-22 11:00:00',
        'created_by' => $user->id,
    ]);

    $secondCustomerOrder = createSalesByClientOrder($secondCustomer, $user, $warehouse, $product, 'SO-SBC-003', '2026-05-23', 150, 'paid', null, 'completed');
    SalesOrderPayment::create([
        'sales_order_id' => $secondCustomerOrder->id,
        'amount' => 150,
        'method' => 'cash',
        'paid_at' => '2026-05-23 12:00:00',
        'created_by' => $user->id,
    ]);
    createSalesByClientOrder($secondCustomer, $user, $otherWarehouse, $product, 'SO-SBC-004', '2026-05-23', 900, 'paid', null, 'completed');
    createSalesByClientOrder($customer, $user, $warehouse, $product, 'SO-SBC-CAN', '2026-05-24', 999, 'paid', $referrer, 'cancelled');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/reports/sales-by-client?date_from=2026-05-01&date_to=2026-05-31&warehouse_id='.$warehouse->id.'&per_page=1');

    $response->assertOk()
        ->assertJsonPath('summary.total_revenue', 650)
        ->assertJsonPath('summary.orders_count', 3)
        ->assertJsonPath('summary.active_customers', 2)
        ->assertJsonPath('summary.total_paid', 530)
        ->assertJsonPath('summary.pending_amount', 120)
        ->assertJsonPath('data.0.customer_id', $customer->id)
        ->assertJsonPath('data.0.referred_by_customer_id', $referrer->id)
        ->assertJsonPath('data.0.orders_count', 2)
        ->assertJsonPath('data.0.total_revenue', 500)
        ->assertJsonPath('data.0.total_paid', 380)
        ->assertJsonPath('data.0.pending_amount', 120)
        ->assertJsonPath('data.0.dominant_payment_method', 'cash')
        ->assertJsonPath('data.0.status', 'vencido')
        ->assertJsonPath('data.0.details.invoices.0.order_number', 'SO-SBC-002')
        ->assertJsonPath('data.0.details.items.0.name', 'Filtro Ventas Cliente')
        ->assertJsonPath('by_referrer.0.customer_id', $referrer->id)
        ->assertJsonPath('by_referrer.0.total_revenue', 500)
        ->assertJsonPath('top_clients.0.customer_id', $customer->id)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);

    expect(collect($response->json('data.0.details.invoices'))->pluck('order_number'))
        ->not->toContain('SO-SBC-CAN');
});

it('filters searches sorts and exports sales by client report', function (): void {
    [
        'warehouse' => $warehouse,
        'user' => $user,
        'customer' => $customer,
        'secondCustomer' => $secondCustomer,
        'referrer' => $referrer,
        'product' => $product,
    ] = salesByClientContext();

    createSalesByClientOrder($customer, $user, $warehouse, $product, 'SO-SBC-F1', '2026-05-10', 100, 'paid', $referrer, 'completed');
    createSalesByClientOrder($secondCustomer, $user, $warehouse, $product, 'SO-SBC-F2', '2026-05-11', 300, 'paid', null, 'completed');

    $this->actingAs($user)
        ->getJson('/api/v1/reports/sales-by-client?date_from=2026-05-01&date_to=2026-05-31&customer_id='.$customer->id.'&referred_by_customer_id='.$referrer->id.'&search=Principal&sort_by=customer_name&sort_direction=asc')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.customer_id', $customer->id)
        ->assertJsonPath('summary.total_revenue', 100);

    $this->actingAs($user)
        ->get('/api/v1/reports/sales-by-client/export/pdf?date_from=2026-05-01&date_to=2026-05-31')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($user)
        ->get('/api/v1/reports/sales-by-client/export/excel?date_from=2026-05-01&date_to=2026-05-31')
        ->assertOk();
});

it('validates sales by client report filters', function (): void {
    ['user' => $user] = salesByClientContext();

    $this->actingAs($user)
        ->getJson('/api/v1/reports/sales-by-client?date_from=2026-05-31&date_to=2026-05-01&payment_status=paid,voided&sort_by=bad')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_to', 'payment_status', 'sort_by']);
});
