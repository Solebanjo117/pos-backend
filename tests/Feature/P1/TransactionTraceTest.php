<?php

use App\Models\Brand;
use App\Models\CashierQueueEntry;
use App\Models\Customer;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseBeneficiary;
use App\Models\OperationalExpenseCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesCreditNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function transactionTraceFixture(string $role = 'admin'): array
{
    $warehouse = Warehouse::create([
        'code' => 'WH-TRACE-'.uniqid(),
        'name' => 'Almacen Trazabilidad',
    ]);
    $user = User::factory()->create([
        'role' => $role,
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $user->warehouses()->attach($warehouse->id);
    $customer = Customer::create([
        'code' => 'C-TRACE-'.uniqid(),
        'name' => 'Cliente Trazabilidad',
        'current_balance' => 0,
        'credit_limit' => 500,
        'credit_days' => 30,
    ]);
    $product = Product::create([
        'sku_base' => 'SKU-TRACE-'.uniqid(),
        'name' => 'Producto Trazabilidad',
        'status' => 'active',
    ]);
    $order = SalesOrder::create([
        'order_number' => 'TRACE-'.uniqid(),
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'status' => 'completed',
        'subtotal' => 21.40,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_cost' => 0,
        'total' => 21.40,
        'payment_status' => 'paid',
        'payment_method' => 'cash',
    ]);
    SalesOrderItem::create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'seller_id' => $user->id,
        'quantity' => 1,
        'unit_price' => 21.40,
        'unit_cost' => 10,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_percent' => 0,
        'tax_amount' => 0,
        'subtotal' => 21.40,
        'total' => 21.40,
    ]);
    $payment = SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => 21.40,
        'method' => 'cash',
        'paid_at' => now(),
        'created_by' => $user->id,
    ]);
    $movement = InventoryMovement::create([
        'movement_type' => 'out',
        'reason' => 'sale',
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'quantity_before' => 5,
        'quantity_after' => 4,
        'reference_type' => 'sales_order',
        'reference_id' => $order->id,
        'user_id' => $user->id,
    ]);

    return compact('warehouse', 'user', 'customer', 'product', 'order', 'payment', 'movement');
}

it('returns a normalized bidirectional sales trace', function (): void {
    $fixture = transactionTraceFixture();

    $response = $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/sales_order/'.$fixture['order']->id)
        ->assertOk()
        ->assertJsonPath('data.root.type', 'sales_order')
        ->assertJsonFragment(['type' => 'sales_order_payment', 'relation' => 'payment'])
        ->assertJsonFragment(['type' => 'inventory_movement', 'relation' => 'inventory_movement']);

    expect(collect($response->json('data.timeline'))->pluck('occurred_at')->filter()->values()->all())
        ->toBe(collect($response->json('data.timeline'))->pluck('occurred_at')->filter()->sort()->values()->all());

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/inventory_movement/'.$fixture['movement']->id)
        ->assertOk()
        ->assertJsonFragment(['type' => 'sales_order', 'relation' => 'source_document']);
});

it('does not expose restricted related inventory data to cashier', function (): void {
    $fixture = transactionTraceFixture('cashier');

    $response = $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/sales_order/'.$fixture['order']->id)
        ->assertOk();

    $inventoryNode = collect($response->json('data.relationships'))
        ->firstWhere('type', 'inventory_movement');

    expect($inventoryNode)
        ->not->toBeNull()
        ->and($inventoryNode['restricted'])->toBeTrue()
        ->and($inventoryNode['can_view'])->toBeFalse()
        ->and($inventoryNode['href'])->toBeNull()
        ->and($inventoryNode['amount'])->toBeNull()
        ->and($inventoryNode['number'])->toBeNull();

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/inventory_movement/'.$fixture['movement']->id)
        ->assertForbidden();
});

it('uses stable movement groups and exact document references without time heuristics', function (): void {
    $fixture = transactionTraceFixture();
    $group = (string) \Illuminate\Support\Str::uuid();
    $fixture['movement']->update(['movement_group_uuid' => $group]);
    $related = InventoryMovement::create([
        'movement_type' => 'in',
        'reason' => 'transfer',
        'product_id' => $fixture['product']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'quantity' => 1,
        'movement_group_uuid' => $group,
        'reference_type' => 'transfer',
        'reference_id' => 999,
        'user_id' => $fixture['user']->id,
        'created_at' => now()->addMinutes(10),
    ]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/inventory-movements/'.$fixture['movement']->id.'/related')
        ->assertOk()
        ->assertJsonFragment(['id' => $related->id, 'movement_group_uuid' => $group]);
});

it('enforces warehouse access on trace roots', function (): void {
    $fixture = transactionTraceFixture('manager');
    $otherWarehouse = Warehouse::create(['code' => 'WH-OTHER-'.uniqid(), 'name' => 'Otro almacen']);
    $fixture['movement']->update(['warehouse_id' => $otherWarehouse->id]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/inventory_movement/'.$fixture['movement']->id)
        ->assertForbidden();
});

it('traces a return through its credit note and cashier queue in both directions', function (): void {
    $fixture = transactionTraceFixture();
    $return = SalesReturn::create([
        'return_number' => 'RET-TRACE-'.uniqid(),
        'sales_order_id' => $fixture['order']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'customer_id' => $fixture['customer']->id,
        'user_id' => $fixture['user']->id,
        'status' => 'approved',
        'settlement_status' => 'pending',
        'reason' => 'Prueba de trazabilidad',
        'total_amount' => 21.40,
        'money_refund_amount' => 21.40,
        'approved_by' => $fixture['user']->id,
        'approved_at' => now(),
    ]);
    $creditNote = SalesCreditNote::create([
        'credit_note_number' => 'NC-TRACE-'.uniqid(),
        'sales_return_id' => $return->id,
        'sales_order_id' => $fixture['order']->id,
        'customer_id' => $fixture['customer']->id,
        'total_amount' => 21.40,
        'refund_amount' => 21.40,
        'status' => 'issued',
        'issued_by' => $fixture['user']->id,
        'issued_at' => now(),
    ]);
    $queue = CashierQueueEntry::create([
        'entry_type' => CashierQueueEntry::TYPE_RETURN,
        'source_type' => SalesReturn::class,
        'source_id' => $return->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'status' => CashierQueueEntry::STATUS_PENDING,
        'estimated_amount' => 21.40,
    ]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/sales_return/'.$return->id)
        ->assertOk()
        ->assertJsonFragment(['type' => 'sales_order', 'relation' => 'source_invoice'])
        ->assertJsonFragment(['type' => 'sales_credit_note', 'id' => $creditNote->id])
        ->assertJsonFragment(['type' => 'cashier_queue_entry', 'id' => $queue->id]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/cashier_queue_entry/'.$queue->id)
        ->assertOk()
        ->assertJsonFragment(['type' => 'sales_return', 'relation' => 'source']);
});

it('exposes expense beneficiary and category as navigable existing records', function (): void {
    $fixture = transactionTraceFixture();
    $beneficiary = OperationalExpenseBeneficiary::create([
        'name' => 'Beneficiario Trazabilidad',
        'type' => 'individual',
    ]);
    $category = OperationalExpenseCategory::create([
        'name' => 'Servicios trazables',
        'slug' => 'servicios-trazables-'.uniqid(),
        'expense_kind' => 'operational',
        'affects_cash_flow' => true,
        'affects_income_statement' => true,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $expense = OperationalExpense::create([
        'warehouse_id' => $fixture['warehouse']->id,
        'created_by' => $fixture['user']->id,
        'expense_date' => now()->toDateString(),
        'category' => $category->slug,
        'category_id' => $category->id,
        'beneficiary_id' => $beneficiary->id,
        'description' => 'Gasto trazable',
        'amount' => 10,
        'estimated_amount' => 10,
        'status' => 'pending',
    ]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/operational_expense/'.$expense->id)
        ->assertOk()
        ->assertJsonFragment([
            'type' => 'operational_expense_beneficiary',
            'id' => $beneficiary->id,
            'relation' => 'beneficiary',
        ])
        ->assertJsonFragment([
            'type' => 'operational_expense_category',
            'id' => $category->id,
            'relation' => 'category',
        ]);
});

it('paginates a complete timeline without changing its total', function (): void {
    $fixture = transactionTraceFixture();

    foreach (range(1, 30) as $index) {
        SalesOrderPayment::create([
            'sales_order_id' => $fixture['order']->id,
            'amount' => 1,
            'method' => 'cash',
            'reference_number' => 'TRACE-PAGE-'.$index,
            'paid_at' => now()->addSeconds($index),
            'created_by' => $fixture['user']->id,
        ]);
    }

    $first = $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/sales_order/'.$fixture['order']->id.'?page=1&per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data.timeline');
    $second = $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/sales_order/'.$fixture['order']->id.'?page=2&per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data.timeline');

    expect($first->json('data.meta.total'))
        ->toBe($second->json('data.meta.total'))
        ->and($first->json('data.meta.last_page'))->toBeGreaterThan(1)
        ->and(collect($first->json('data.timeline'))->pluck('id')->intersect(
            collect($second->json('data.timeline'))->pluck('id')
        ))->toBeEmpty();
});

it('returns navigation context and traces product variant lot and warehouse records', function (): void {
    $fixture = transactionTraceFixture();
    $brand = Brand::create(['name' => 'Marca Trazabilidad']);
    $variant = ProductVariant::create([
        'product_id' => $fixture['product']->id,
        'brand_id' => $brand->id,
        'sku' => 'VAR-TRACE-'.uniqid(),
        'status' => 'active',
        'is_default' => true,
    ]);
    $lot = InventoryLot::create([
        'product_id' => $fixture['product']->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'source_type' => 'manual_inbound',
        'received_quantity' => 5,
        'quantity_available' => 5,
        'unit_cost_base' => 10,
        'landed_unit_cost' => 10,
        'received_at' => now(),
    ]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/product/'.$fixture['product']->id)
        ->assertOk()
        ->assertJsonPath('data.context.type', 'product')
        ->assertJsonPath('data.breadcrumbs.0.id', $fixture['product']->id)
        ->assertJsonFragment(['type' => 'product_variant', 'id' => $variant->id]);

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/inventory_lot/'.$lot->id)
        ->assertOk()
        ->assertJsonFragment(['type' => 'product', 'id' => $fixture['product']->id])
        ->assertJsonFragment(['type' => 'warehouse', 'id' => $fixture['warehouse']->id]);
});

it('keeps user activity restricted to administrators', function (): void {
    $fixture = transactionTraceFixture('manager');

    $this->actingAs($fixture['user'])
        ->getJson('/api/v1/transaction-traces/user/'.$fixture['user']->id)
        ->assertForbidden();

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin)
        ->getJson('/api/v1/transaction-traces/user/'.$fixture['user']->id)
        ->assertOk()
        ->assertJsonPath('data.root.type', 'user');
});
