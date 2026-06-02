<?php

use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salesReturnOperationalContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'WH-RET-'.uniqid(),
        'name' => 'Sucursal Devoluciones',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'C-RET-'.uniqid(),
        'name' => 'Cliente Devoluciones',
        'current_balance' => 90,
    ]);

    $brand = Brand::create([
        'name' => 'Marca Retorno',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'RET-'.uniqid(),
        'name' => 'Producto Retornable',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'RET-VAR-'.uniqid(),
        'status' => 'active',
        'is_default' => true,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-RET-'.uniqid(),
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 100,
        'tax_amount' => 7,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 107,
        'payment_status' => 'paid',
        'payment_method' => 'cash',
    ]);

    $item = SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 20,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 7,
        'tax_amount' => 7,
        'subtotal' => 100,
        'total' => 107,
    ]);

    $return = SalesReturn::create([
        'sales_order_id' => $order->id,
        'warehouse_id' => $warehouse->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'approved',
        'reason' => 'Producto defectuoso',
        'total_amount' => 53.50,
        'refund_method' => 'cash',
        'refund_notes' => 'Reembolso en caja',
    ]);

    $returnItem = $return->items()->create([
        'sales_order_item_id' => $item->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 50,
        'subtotal' => 50,
        'user_id' => $user->id,
        'seller_id' => $user->id,
    ]);

    $refundPayment = SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => -53.50,
        'method' => 'cash',
        'notes' => "Reembolso por devolución {$return->return_number}",
        'paid_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    $customerTransaction = CustomerTransaction::create([
        'customer_id' => $customer->id,
        'type' => 'credit_note',
        'reference_type' => 'sales_return',
        'reference_id' => $return->id,
        'amount' => 53.50,
        'balance_before' => 90,
        'balance_after' => 36.50,
        'description' => "Devolución {$return->return_number}",
        'transaction_date' => now(),
        'created_by' => $user->id,
    ]);

    $movement = InventoryMovement::create([
        'movement_type' => 'in',
        'reason' => 'return',
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'reference_type' => 'sales_return',
        'reference_id' => $return->id,
        'notes' => 'Entrada por devolución',
        'user_id' => $user->id,
    ]);

    $otherReturn = SalesReturn::create([
        'sales_order_id' => $order->id,
        'warehouse_id' => $warehouse->id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'approved',
        'reason' => 'Otra devolución',
        'total_amount' => 10,
        'refund_method' => 'balance',
    ]);

    InventoryMovement::create([
        'movement_type' => 'in',
        'reason' => 'return',
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'reference_type' => 'sales_return',
        'reference_id' => $otherReturn->id,
        'notes' => 'Otra entrada',
        'user_id' => $user->id,
    ]);

    return compact('warehouse', 'user', 'customer', 'order', 'item', 'return', 'returnItem', 'refundPayment', 'customerTransaction', 'movement');
}

it('returns operational detail for a sales return without mixing other returns', function () {
    [
        'user' => $user,
        'customer' => $customer,
        'order' => $order,
        'item' => $item,
        'return' => $return,
        'refundPayment' => $refundPayment,
        'customerTransaction' => $customerTransaction,
        'movement' => $movement,
    ] = salesReturnOperationalContext();

    $response = $this->actingAs($user)->getJson("/api/v1/sales-returns/{$return->id}/operational-detail");

    $response->assertOk()
        ->assertJsonPath('data.return.id', $return->id)
        ->assertJsonPath('data.return.return_number', $return->return_number)
        ->assertJsonPath('data.return.status', 'approved')
        ->assertJsonPath('data.return.refund_method', 'cash')
        ->assertJsonPath('data.return.customer.id', $customer->id)
        ->assertJsonPath('data.sales_order.id', $order->id)
        ->assertJsonPath('data.sales_order.order_number', $order->order_number)
        ->assertJsonPath('data.items.0.sales_order_item_id', $item->id)
        ->assertJsonPath('data.items.0.quantity_sold', 2)
        ->assertJsonPath('data.items.0.quantity_returned_total', 1)
        ->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.items.0.brand_name', 'Marca Retorno')
        ->assertJsonPath('data.refund_context.method', 'cash')
        ->assertJsonPath('data.refund_context.refund_payment.id', $refundPayment->id)
        ->assertJsonPath('data.refund_context.customer_transaction.id', $customerTransaction->id)
        ->assertJsonPath('data.documents.0.type', 'sales_return')
        ->assertJsonPath('data.documents.0.id', $return->id)
        ->assertJsonPath('data.documents.1.type', 'sales_order')
        ->assertJsonPath('data.documents.1.id', $order->id)
        ->assertJsonPath('data.inventory_context.movements.0.id', $movement->id)
        ->assertJsonPath('data.ar_context.customer_transactions.0.id', $customerTransaction->id)
        ->assertJsonPath('data.workflow.can_approve', false)
        ->assertJsonPath('data.workflow.can_reject', false)
        ->assertJsonPath('data.workflow.actions.can_approve', false)
        ->assertJsonPath('data.workflow.actions.can_open_order', true)
        ->assertJsonPath('data.workflow.links.sales_order', "/sales-orders/{$order->id}");

    expect(collect($response->json('data.inventory_context.movements'))->pluck('reference_id')->unique()->all())
        ->toBe([$return->id]);

    expect(collect($response->json('data.timeline'))->pluck('type')->all())
        ->toContain('created', 'approved', 'refund_payment', 'customer_transaction', 'inventory_movement');

    $refundEvent = collect($response->json('data.timeline'))->firstWhere('type', 'refund_payment');
    expect($refundEvent['source'])->toBe('sales_order_payment')
        ->and($refundEvent['actor_name'])->toBe($user->name)
        ->and($refundEvent['href'])->toBe("/sales-orders/{$order->id}");

    $timelineDates = collect($response->json('data.timeline'))
        ->pluck('occurred_at')
        ->filter()
        ->values()
        ->all();

    expect($timelineDates)->toBe(collect($timelineDates)->sort()->values()->all());
});

it('approves balance returns without an open cash session and records customer credit context', function () {
    [
        'user' => $user,
        'customer' => $customer,
        'order' => $order,
        'item' => $item,
    ] = salesReturnOperationalContext();

    $pending = SalesReturn::create([
        'sales_order_id' => $order->id,
        'warehouse_id' => $item->warehouse_id,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'status' => 'pending',
        'reason' => 'Saldo a favor',
        'total_amount' => 20,
        'refund_method' => 'balance',
    ]);

    $pending->items()->create([
        'sales_order_item_id' => $item->id,
        'product_id' => $item->product_id,
        'variant_id' => $item->variant_id,
        'quantity' => 1,
        'unit_price' => 20,
        'subtotal' => 20,
        'user_id' => $user->id,
        'seller_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/sales-returns/{$pending->id}/approve");

    $response->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect(SalesOrderPayment::query()
        ->where('sales_order_id', $order->id)
        ->where('notes', 'like', "%{$pending->return_number}%")
        ->exists())->toBeFalse();

    $this->assertDatabaseHas('customer_transactions', [
        'customer_id' => $customer->id,
        'reference_type' => 'sales_return',
        'reference_id' => $pending->id,
        'type' => 'credit_note',
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'reference_type' => 'sales_return',
        'reference_id' => $pending->id,
        'movement_type' => 'in',
    ]);
});
