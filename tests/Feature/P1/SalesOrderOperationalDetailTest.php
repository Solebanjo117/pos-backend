<?php

use App\Models\AccountLedgerEntry;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
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

function salesOrderOperationalContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'WH-SOD-'.uniqid(),
        'name' => 'Sucursal Ventas',
    ]);

    $seller = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);

    $customer = Customer::create([
        'code' => 'C-SOD-'.uniqid(),
        'name' => 'Cliente Operativo',
        'current_balance' => 70,
        'credit_limit' => 1000,
        'credit_days' => 30,
    ]);

    $brand = Brand::create([
        'name' => 'Marca Operativa',
        'status' => 'active',
    ]);

    $product = Product::create([
        'sku_base' => 'SKU-SOD-'.uniqid(),
        'name' => 'Filtro Operativo',
        'status' => 'active',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'VAR-SOD-'.uniqid(),
        'status' => 'active',
        'is_default' => true,
    ]);

    $order = SalesOrder::create([
        'order_number' => 'SO-SOD-'.uniqid(),
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 110,
        'tax_amount' => 10,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 120,
        'payment_status' => 'partial',
        'payment_method' => 'multiple',
        'audit_logs' => [
            ['action' => 'checkout_success', 'metadata' => ['total' => 120], 'timestamp' => now()->toISOString()],
        ],
    ]);

    $item = SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $seller->id,
        'quantity' => 2,
        'unit_price' => 50,
        'unit_cost' => 20,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 10,
        'tax_amount' => 10,
        'subtotal' => 100,
        'total' => 110,
    ]);

    $customItem = SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $seller->id,
        'quantity' => 1,
        'unit_price' => 10,
        'unit_cost' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 10,
        'total' => 10,
        'is_custom_line' => true,
        'custom_description' => 'Servicio miscelaneo',
        'added_after_sale' => true,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 50,
        'method' => 'cash',
        'notes' => 'Pago inicial',
        'paid_at' => now()->subDays(2),
        'created_by' => $seller->id,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 30,
        'method' => 'credit',
        'notes' => 'Credito cliente',
        'paid_at' => now()->subDays(2),
        'created_by' => $seller->id,
    ]);

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => -10,
        'method' => 'cash',
        'notes' => 'Reembolso por devolucion',
        'paid_at' => now()->subDay(),
        'created_by' => $seller->id,
    ]);

    $return = SalesReturn::create([
        'sales_order_id' => $order->id,
        'warehouse_id' => $warehouse->id,
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'status' => 'approved',
        'reason' => 'Producto devuelto',
        'total_amount' => 10,
        'refund_method' => 'cash',
        'refund_notes' => 'Caja',
    ]);

    $return->items()->create([
        'sales_order_item_id' => $item->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 10,
        'subtotal' => 10,
        'user_id' => $seller->id,
        'seller_id' => $seller->id,
    ]);

    $movement = InventoryMovement::create([
        'movement_type' => 'out',
        'reason' => 'sale',
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 2,
        'reference_type' => 'sale',
        'reference_id' => $order->id,
        'notes' => 'Venta operativa',
        'user_id' => $seller->id,
    ]);

    $lot = InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'purchase_order',
        'source_id' => 1,
        'batch_number' => 'LOT-SOD',
        'received_quantity' => 5,
        'quantity_available' => 3,
        'unit_cost_base' => 18,
        'tax_per_unit' => 1,
        'misc_per_unit' => 1,
        'landed_unit_cost' => 20,
        'received_at' => now()->subDays(10),
    ]);

    InventoryLotAllocation::create([
        'inventory_lot_id' => $lot->id,
        'inventory_movement_id' => $movement->id,
        'sales_order_item_id' => $item->id,
        'quantity' => 2,
        'unit_cost' => 20,
        'total_cost' => 40,
    ]);

    CustomerTransaction::create([
        'customer_id' => $customer->id,
        'type' => 'sale',
        'reference_type' => 'sales_order',
        'reference_id' => $order->id,
        'amount' => 70,
        'balance_before' => 0,
        'balance_after' => 70,
        'description' => 'Cargo por venta',
        'transaction_date' => now(),
        'created_by' => $seller->id,
    ]);

    AccountLedgerEntry::create([
        'account_type' => 'customer',
        'account_id' => $customer->id,
        'movement_type' => 'charge',
        'document_type' => 'sales_order',
        'document_id' => $order->id,
        'document_number' => $order->order_number,
        'debit' => 70,
        'credit' => 0,
        'balance_after' => 70,
        'occurred_at' => now(),
        'notes' => 'Cargo ledger',
        'created_by' => $seller->id,
    ]);

    $otherOrder = SalesOrder::create([
        'order_number' => 'SO-OTHER-'.uniqid(),
        'customer_id' => $customer->id,
        'user_id' => $seller->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 20,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 20,
        'payment_status' => 'paid',
    ]);

    InventoryMovement::create([
        'movement_type' => 'out',
        'reason' => 'sale',
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'reference_type' => 'sale',
        'reference_id' => $otherOrder->id,
        'notes' => 'Otra venta',
        'user_id' => $seller->id,
    ]);

    return compact('warehouse', 'seller', 'customer', 'order', 'item', 'customItem', 'movement');
}

it('returns operational detail for a sales order without mixing other orders', function () {
    ['seller' => $seller, 'order' => $order, 'item' => $item, 'customItem' => $customItem, 'movement' => $movement] = salesOrderOperationalContext();

    $response = $this->actingAs($seller)->getJson("/api/v1/sales-orders/{$order->id}/operational-detail");

    $response->assertOk()
        ->assertJsonPath('data.order.id', $order->id)
        ->assertJsonPath('data.order.order_number', $order->order_number)
        ->assertJsonPath('data.items.0.id', $item->id)
        ->assertJsonPath('data.items.0.brand_name', 'Marca Operativa')
        ->assertJsonPath('data.items.0.gross_profit', 60)
        ->assertJsonPath('data.items.1.id', $customItem->id)
        ->assertJsonPath('data.items.1.is_custom_line', true)
        ->assertJsonPath('data.payment_summary.0.method', 'cash')
        ->assertJsonPath('data.payment_summary.0.amount', 40)
        ->assertJsonPath('data.payment_summary.1.method', 'credit')
        ->assertJsonPath('data.payment_summary.1.amount', 30)
        ->assertJsonPath('data.ar_context.amount_paid', 40)
        ->assertJsonPath('data.ar_context.amount_due', 70)
        ->assertJsonPath('data.ar_context.customer_transactions.0.reference_type', 'sales_order')
        ->assertJsonPath('data.ar_context.ledger_entries.0.document_id', $order->id)
        ->assertJsonPath('data.returns.0.total_amount', 10)
        ->assertJsonPath('data.returns.0.items.0.sales_order_item_id', $item->id)
        ->assertJsonPath('data.inventory_context.movements.0.id', $movement->id)
        ->assertJsonPath('data.inventory_context.movements.0.lot_allocations.0.sales_order_item_id', $item->id)
        ->assertJsonPath('data.profit_summary.total_cost', 40)
        ->assertJsonPath('data.profit_summary.gross_profit', 70)
        ->assertJsonPath('data.audit.0.action', 'checkout_success');

    expect(collect($response->json('data.inventory_context.movements'))->pluck('reference_id')->unique()->all())
        ->toBe([$order->id]);
});
