<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports sales order metrics in the operational summary contract', function () {
    $warehouse = Warehouse::create([
        'code' => 'W-STATS',
        'name' => 'Warehouse Statistics',
    ]);
    $seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);
    $customer = Customer::create([
        'code' => 'C-STATS',
        'name' => 'Cliente Estadísticas',
        'credit_limit' => 1000,
        'current_balance' => 120,
    ]);
    $product = Product::create([
        'sku_base' => 'STATS-001',
        'name' => 'Producto Estadísticas',
        'status' => 'active',
    ]);

    $createOrder = function (
        string $number,
        string $status,
        float $total,
        string $paymentStatus,
        string $paymentMethod = 'cash',
    ) use ($seller, $customer, $warehouse, $product): SalesOrder {
        $order = SalesOrder::create([
            'order_number' => $number,
            'customer_id' => $customer->id,
            'user_id' => $seller->id,
            'date' => now()->toDateString(),
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => $total,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
            'total' => $total,
        ]);

        return $order->fresh();
    };

    $createOrder('SO-DRAFT', 'draft', 20, 'pending');
    $createOrder('SO-CREDIT', 'pending', 80, 'pending', 'credit');
    $partial = $createOrder('SO-PARTIAL', 'confirmed', 100, 'partial');
    $completed = $createOrder('SO-PAID', 'completed', 50, 'paid');

    SalesOrderPayment::create([
        'sales_order_id' => $partial->id,
        'amount' => 60,
        'method' => 'cash',
        'paid_at' => now(),
        'created_by' => $seller->id,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $completed->id,
        'amount' => 50,
        'method' => 'card',
        'paid_at' => now(),
        'created_by' => $seller->id,
    ]);

    expect(SalesOrder::query()->count())->toBe(4)
        ->and(SalesOrder::query()->byDateRange(now()->toDateString(), now()->toDateString())->count())->toBe(4)
        ->and(SalesOrder::query()->whereHas('items', fn ($query) => $query->where('warehouse_id', $warehouse->id))->count())->toBe(4);

    $this->actingAs($seller)
        ->getJson('/api/v1/sales-orders/statistics?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('total_orders', 4)
        ->assertJsonPath('draft_orders', 1)
        ->assertJsonPath('pending_orders', 1)
        ->assertJsonPath('completed_orders', 1)
        ->assertJsonPath('total_sales', 250)
        ->assertJsonPath('collected_sales', 110)
        ->assertJsonPath('credit_receivable', 120);
});
