<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('pos.single_warehouse_mode', false);
    $this->warehouse = Warehouse::create(['code' => 'TX-01', 'name' => 'Sucursal Uno']);
    $this->otherWarehouse = Warehouse::create(['code' => 'TX-02', 'name' => 'Sucursal Dos']);
    $this->seller = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
        'current_warehouse_id' => $this->warehouse->id,
    ]);
    $this->seller->warehouses()->attach($this->warehouse->id);
    $this->customer = Customer::create(['code' => 'C-TX', 'name' => 'Cliente Integrado']);
    $this->product = Product::create(['sku_base' => 'TX-P', 'name' => 'Producto', 'status' => 'active']);
});

function transactionOrder(User $user, Customer $customer, Product $product, Warehouse $warehouse, string $number, string $status = 'pending'): SalesOrder
{
    $order = SalesOrder::create([
        'order_number' => $number,
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'date' => now()->toDateString(),
        'status' => $status,
        'payment_status' => 'pending',
        'subtotal' => 100,
        'total' => 100,
    ]);
    $order->items()->create([
        'product_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'unit_price' => 100,
        'tax_percent' => 0,
    ]);

    return $order->fresh();
}

it('paginates one row per transaction and preserves the linked quote document', function (): void {
    $order = transactionOrder($this->seller, $this->customer, $this->product, $this->warehouse, 'OV-TX-1');
    $order->items()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1,
        'unit_price' => 50,
        'tax_percent' => 0,
    ]);
    $linked = SalesQuote::create([
        'quote_number' => 'COT-TX-1',
        'customer_id' => $this->customer->id,
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_deposit',
        'converted_order_id' => $order->id,
        'total' => 150,
    ]);
    $free = SalesQuote::create([
        'quote_number' => 'COT-TX-2',
        'customer_id' => $this->customer->id,
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
        'total' => 60,
    ]);
    transactionOrder($this->seller, $this->customer, $this->product, $this->otherWarehouse, 'OV-TX-2');

    $response = $this->actingAs($this->seller)->getJson('/api/v1/transactions?per_page=1');
    $response->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('metrics.orders', 1)
        ->assertJsonPath('metrics.quotes', 1);

    $all = $this->getJson('/api/v1/transactions?per_page=10');
    $all->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['key' => 'order:'.$order->id, 'number' => $order->order_number])
        ->assertJsonFragment(['key' => 'quote:'.$free->id, 'number' => $free->quote_number])
        ->assertJsonFragment(['id' => $linked->id, 'number' => $linked->quote_number, 'status' => 'sent_to_deposit']);
    expect(collect($all->json('data'))->pluck('key'))->not->toContain('quote:'.$linked->id);
    expect((float) $all->json('metrics.order_amount'))->toBe((float) $order->fresh()->total);
    expect((float) $all->json('metrics.quote_amount'))->toBe(60.0);
    $orderRow = collect($all->json('data'))->firstWhere('key', 'order:'.$order->id);
    expect($orderRow['seller_name'])->toBe($this->seller->name)
        ->and((float) $orderRow['amount_due'])->toBe((float) $order->fresh()->total);
});

it('finds linked documents and applies typed statuses and warehouse access', function (): void {
    $order = transactionOrder($this->seller, $this->customer, $this->product, $this->warehouse, 'OV-SEARCH');
    SalesQuote::create([
        'quote_number' => 'COT-SEARCH',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_cashier',
        'converted_order_id' => $order->id,
    ]);
    SalesQuote::create([
        'quote_number' => 'COT-FREE',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'rejected',
    ]);

    $this->actingAs($this->seller)
        ->getJson('/api/v1/transactions?search=COT-SEARCH')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.key', 'order:'.$order->id)
        ->assertJsonPath('data.0.linked_quote.status', 'sent_to_cashier');
    $this->getJson('/api/v1/transactions?type=quote&status=quote:rejected')
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.number', 'COT-FREE');
    $this->getJson('/api/v1/transactions?type=order&status=quote:rejected')
        ->assertOk()->assertJsonPath('total', 0);
    $this->getJson('/api/v1/transactions?warehouse_id='.$this->otherWarehouse->id)
        ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    $this->seller->warehouses()->attach($this->otherWarehouse->id);
    $this->getJson('/api/v1/transactions?warehouse_id='.$this->otherWarehouse->id)
        ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    $this->getJson('/api/v1/transactions?per_page=101')->assertUnprocessable();
});

it('keeps list and action permissions aligned with current routes', function (): void {
    $quote = SalesQuote::create([
        'quote_number' => 'COT-ROLE',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
    ]);
    $buyer = User::factory()->create([
        'role' => 'buyer', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id,
    ]);
    $buyer->warehouses()->attach($this->warehouse->id);
    $cashier = User::factory()->create([
        'role' => 'cashier', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id,
    ]);

    $this->actingAs($buyer)->getJson('/api/v1/transactions')
        ->assertOk()->assertJsonPath('data.0.key', 'quote:'.$quote->id)
        ->assertJsonPath('data.0.actions', ['view']);
    $this->actingAs($cashier)->getJson('/api/v1/transactions')->assertForbidden();
});

it('uses quote workflow for a linked pending order and limits quote actions to real permissions', function (): void {
    $order = transactionOrder($this->seller, $this->customer, $this->product, $this->warehouse, 'OV-WORKFLOW');
    SalesQuote::create([
        'quote_number' => 'COT-WORKFLOW',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_deposit',
        'converted_order_id' => $order->id,
    ]);
    SalesQuote::create([
        'quote_number' => 'COT-CASHIER',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_cashier',
    ]);

    $response = $this->actingAs($this->seller)->getJson('/api/v1/transactions');
    $response->assertOk()->assertJsonPath('total', 2);
    $rows = collect($response->json('data'))->keyBy('key');
    expect($rows['order:'.$order->id]['actions'])->toContain('continue_quote')
        ->not->toContain('edit')
        ->and($rows['quote:'.SalesQuote::query()->where('quote_number', 'COT-CASHIER')->value('id')]['actions'])
        ->not->toContain('edit');
});

it('requires a valid active warehouse even for an admin', function (): void {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => null]);

    $this->actingAs($admin)->getJson('/api/v1/transactions')
        ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    $this->getJson('/api/v1/transactions?warehouse_id='.$this->warehouse->id)
        ->assertOk()->assertJsonPath('total', 0);
});

it('does not infer an unselected warehouse for a non-admin', function (): void {
    $this->seller->update(['current_warehouse_id' => null]);

    $this->actingAs($this->seller)->getJson('/api/v1/transactions')
        ->assertStatus(409);
});

it('filters converted quotes through their linked order without a second row', function (): void {
    $order = transactionOrder($this->seller, $this->customer, $this->product, $this->warehouse, 'OV-CONVERTED', 'completed');
    SalesQuote::create([
        'quote_number' => 'COT-CONVERTED',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'converted',
        'converted_order_id' => $order->id,
        'total' => 90,
    ]);

    $this->actingAs($this->seller)
        ->getJson('/api/v1/transactions?type=quote&status=quote:converted')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.key', 'order:'.$order->id)
        ->assertJsonPath('metrics.order_amount', '100');
});

it('shows quote conversion actions only when the quote contains items', function (): void {
    $empty = SalesQuote::create([
        'quote_number' => 'COT-EMPTY',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
    ]);
    $filled = SalesQuote::create([
        'quote_number' => 'COT-FILLED',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'draft',
    ]);
    $filled->items()->create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1,
        'unit_price' => 25,
        'tax_percent' => 0,
    ]);

    $rows = collect($this->actingAs($this->seller)->getJson('/api/v1/transactions')->assertOk()->json('data'))->keyBy('key');
    expect($rows['quote:'.$empty->id]['actions'])->not->toContain('convert', 'send_to_deposit')
        ->and($rows['quote:'.$filled->id]['actions'])->toContain('convert', 'send_to_deposit');
});

it('does not offer cancellation for a pending order still controlled by its quote', function (): void {
    $order = transactionOrder($this->seller, $this->customer, $this->product, $this->warehouse, 'OV-LINKED');
    SalesQuote::create([
        'quote_number' => 'COT-LINKED',
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_deposit',
        'converted_order_id' => $order->id,
    ]);

    $actions = $this->actingAs($this->seller)->getJson('/api/v1/transactions')->assertOk()->json('data.0.actions');
    expect($actions)->toContain('continue_quote')->not->toContain('cancel', 'edit');
});

it('supports an upper date bound without requiring a lower bound', function (): void {
    $this->actingAs($this->seller)->getJson('/api/v1/transactions?date_to='.now()->toDateString())
        ->assertOk();
    $this->getJson('/api/v1/transactions?date_from=2026-09-20&date_to=2026-09-19')
        ->assertUnprocessable()->assertJsonValidationErrors('date_to');
});
