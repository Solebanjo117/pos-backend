<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function customerSalesHistoryContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-CSH-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacen Clientes',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'CL-HIST',
        'name' => 'Cliente Historial',
        'credit_limit' => 1000,
        'current_balance' => 75,
    ]);

    $otherCustomer = Customer::create([
        'code' => 'CL-OTHER',
        'name' => 'Cliente Ajeno',
    ]);

    $brand = Brand::create(['name' => 'Marca Historial', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'HIST-001',
        'name' => 'Filtro Cabina Historial',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'HIST-001-A',
        'status' => 'active',
        'is_default' => true,
    ]);

    return compact('warehouse', 'user', 'customer', 'otherCustomer', 'product', 'variant');
}

function createCustomerHistoryOrder(
    Customer $customer,
    User $user,
    Warehouse $warehouse,
    string $number,
    string $date,
    string $paymentStatus,
    float $total,
): SalesOrder {
    return SalesOrder::create([
        'order_number' => $number,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => $date,
        'status' => $paymentStatus === 'paid' ? 'completed' : 'confirmed',
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => $total,
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentStatus === 'pending' ? 'credit' : 'cash',
    ]);
}

it('lists customer sales history with items payments summaries filters and pagination', function () {
    [
        'warehouse' => $warehouse,
        'user' => $user,
        'customer' => $customer,
        'otherCustomer' => $otherCustomer,
        'product' => $product,
        'variant' => $variant,
    ] = customerSalesHistoryContext();

    $paidOrder = createCustomerHistoryOrder($customer, $user, $warehouse, 'SO-HIST-003', '2026-05-20', 'paid', 150);
    SalesOrderItem::create([
        'sales_order_id' => $paidOrder->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 25,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 100,
        'total' => 100,
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $paidOrder->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => 50,
        'unit_cost' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 50,
        'total' => 50,
        'is_custom_line' => true,
        'custom_description' => 'Mano de obra',
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 100,
        'method' => 'cash',
        'notes' => 'Caja',
        'paid_at' => '2026-05-20 09:00:00',
        'created_by' => $user->id,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 50,
        'method' => 'card',
        'notes' => 'Visa',
        'paid_at' => '2026-05-20 09:05:00',
        'created_by' => $user->id,
    ]);

    $partialOrder = createCustomerHistoryOrder($customer, $user, $warehouse, 'SO-HIST-002', '2026-05-10', 'partial', 100);
    SalesOrderPayment::create([
        'sales_order_id' => $partialOrder->id,
        'amount' => 25,
        'method' => 'transfer',
        'paid_at' => '2026-05-10 10:00:00',
        'created_by' => $user->id,
    ]);

    createCustomerHistoryOrder($customer, $user, $warehouse, 'SO-HIST-001', '2026-04-01', 'pending', 80);
    createCustomerHistoryOrder($otherCustomer, $user, $warehouse, 'SO-OTHER-001', '2026-05-25', 'paid', 999);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/customers/{$customer->id}/sales-history?per_page=2");

    $response->assertOk()
        ->assertJsonPath('data.0.id', $paidOrder->id)
        ->assertJsonPath('data.0.document_type', 'sales_order')
        ->assertJsonPath('data.0.document_number', 'SO-HIST-003')
        ->assertJsonPath('data.0.order_number', 'SO-HIST-003')
        ->assertJsonPath('data.0.amount_paid', 150)
        ->assertJsonPath('data.0.amount_due', 0)
        ->assertJsonPath('data.0.payment_summary.0.method', 'cash')
        ->assertJsonPath('data.0.payment_summary.0.amount', 100)
        ->assertJsonPath('data.0.payment_summary.1.method', 'card')
        ->assertJsonPath('data.0.items.0.product_name', 'Filtro Cabina Historial')
        ->assertJsonPath('data.0.items.0.product_sku', 'HIST-001')
        ->assertJsonPath('data.0.items.1.product_name', 'Mano de obra')
        ->assertJsonPath('data.0.payments.0.creator_name', $user->name)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    expect(collect($response->json('data'))->pluck('order_number'))->not->toContain('SO-OTHER-001');

    $this->actingAs($user)
        ->getJson("/api/v1/customers/{$customer->id}/sales-history?payment_status=partial&date_from=2026-05-01&date_to=2026-05-31&search=HIST-002")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $partialOrder->id)
        ->assertJsonPath('data.0.amount_paid', 25)
        ->assertJsonPath('data.0.amount_due', 75)
        ->assertJsonPath('data.0.payment_summary.0.method', 'transfer');
});

it('validates sales history filters', function () {
    [
        'user' => $user,
        'customer' => $customer,
    ] = customerSalesHistoryContext();

    $this->actingAs($user)
        ->getJson("/api/v1/customers/{$customer->id}/sales-history?status=archived&payment_status=paid,voided")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'payment_status']);
});
