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

function customerProfileContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-CP-'.fake()->unique()->randomNumber(4),
        'name' => 'Sucursal Perfil Cliente',
    ]);
    $user = User::factory()->create([
        'role' => 'manager',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);
    $customer = Customer::create([
        'code' => 'CL-PROFILE',
        'type' => 'business',
        'name' => 'Cliente Perfil',
        'credit_limit' => 1000,
        'credit_days' => 15,
        'current_balance' => 250,
    ]);
    $mechanic = Customer::create([
        'code' => 'MEC-PROFILE',
        'type' => 'business',
        'name' => 'Mecanico Perfil',
    ]);
    $brand = Brand::create(['name' => 'Marca Perfil', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'PROF-001',
        'name' => 'Filtro Perfil',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'PROF-001-A',
        'status' => 'active',
        'is_default' => true,
    ]);

    return compact('warehouse', 'user', 'customer', 'mechanic', 'product', 'variant');
}

function createCustomerProfileOrder(
    Customer $customer,
    User $user,
    Warehouse $warehouse,
    Product $product,
    ProductVariant $variant,
    string $number,
    string $date,
    float $total,
    string $paymentStatus,
    ?Customer $mechanic = null,
    string $status = 'confirmed',
    int $quantity = 1,
): SalesOrder {
    $order = SalesOrder::create([
        'order_number' => $number,
        'customer_id' => $customer->id,
        'referred_by_customer_id' => $mechanic?->id,
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
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => $quantity,
        'unit_price' => $total / $quantity,
        'unit_cost' => 10,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => $total,
        'total' => $total,
    ]);

    return $order;
}

it('returns customer profile summary without breaking customer show contract', function (): void {
    [
        'warehouse' => $warehouse,
        'user' => $user,
        'customer' => $customer,
        'mechanic' => $mechanic,
        'product' => $product,
        'variant' => $variant,
    ] = customerProfileContext();

    $paidOrder = createCustomerProfileOrder($customer, $user, $warehouse, $product, $variant, 'SO-CP-001', '2026-05-20', 300, 'paid', $mechanic, 'completed', 3);
    SalesOrderPayment::create([
        'sales_order_id' => $paidOrder->id,
        'amount' => 300,
        'method' => 'cash',
        'paid_at' => '2026-05-20 09:00:00',
        'created_by' => $user->id,
    ]);

    $partialOrder = createCustomerProfileOrder($customer, $user, $warehouse, $product, $variant, 'SO-CP-002', '2026-05-25', 200, 'partial', $mechanic, 'confirmed', 2);
    SalesOrderPayment::create([
        'sales_order_id' => $partialOrder->id,
        'amount' => 75,
        'method' => 'card',
        'paid_at' => '2026-05-26 11:00:00',
        'created_by' => $user->id,
    ]);

    createCustomerProfileOrder($customer, $user, $warehouse, $product, $variant, 'SO-CP-CAN', '2026-05-27', 999, 'paid', $mechanic, 'cancelled');

    $this->actingAs($user)
        ->getJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('customer.id', $customer->id)
        ->assertJsonPath('balance_summary.current_balance', '250.00')
        ->assertJsonPath('profile_summary.total_purchased', 500)
        ->assertJsonPath('profile_summary.invoices_count', 2)
        ->assertJsonPath('profile_summary.average_ticket', 250)
        ->assertJsonPath('profile_summary.last_purchase_date', '2026-05-25')
        ->assertJsonPath('profile_summary.total_paid', 375)
        ->assertJsonPath('profile_summary.pending_amount', 125)
        ->assertJsonPath('profile_summary.credit_used_percent', 25)
        ->assertJsonPath('profile_summary.credit_status', 'pending')
        ->assertJsonPath('profile_summary.top_items.0.name', 'Filtro Perfil')
        ->assertJsonPath('profile_summary.top_items.0.quantity', 5)
        ->assertJsonPath('profile_summary.recent_payments.0.order_number', 'SO-CP-002')
        ->assertJsonPath('profile_summary.recent_payments.0.amount', 75)
        ->assertJsonPath('profile_summary.mechanic.customer_id', $mechanic->id)
        ->assertJsonPath('profile_summary.mechanic.orders_count', 2)
        ->assertJsonPath('profile_summary.mechanic.last_order_date', '2026-05-25');
});
