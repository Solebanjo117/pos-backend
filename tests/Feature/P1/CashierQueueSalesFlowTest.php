<?php

use App\Models\Brand;
use App\Models\CashierQueueEntry;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function openQueueSession(User $user, Warehouse $warehouse): PosSession
{
    return PosSession::create([
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);
}

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-QFLOW', 'name' => 'Caja flujo']);
    $this->cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->cashier->warehouses()->attach($this->warehouse->id);
    $this->secondCashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->secondCashier->warehouses()->attach($this->warehouse->id);
    $this->seller = User::factory()->create([
        'role' => 'seller',
        'name' => 'Vendedor Uno',
        'is_active' => true,
    ]);
    $this->customer = Customer::create([
        'code' => 'CLI-QF',
        'name' => 'Cliente Cola',
        'status' => 'active',
        'credit_limit' => 500,
    ]);
    $this->product = Product::create(['name' => 'Producto Cola', 'type' => 'simple', 'status' => 'active', 'sku_base' => 'COLA-1']);
    $brand = Brand::create(['name' => 'Marca Cola', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'COLA-V1',
        'name' => 'Variante Cola',
    ]);
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 10,
        'quantity_reserved' => 0,
    ]);
    $this->quote = SalesQuote::create([
        'user_id' => $this->seller->id,
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent_to_cashier',
        'sent_to_cashier_at' => now(),
        'subtotal' => 90,
        'tax_amount' => 6.3,
        'total' => 96.3,
    ]);
    $this->quoteItem = $this->quote->items()->create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount_percent' => 10,
        'tax_percent' => 7,
    ]);
    $this->entry = app(CashierQueueService::class)->enqueueSale($this->quote->fresh());
    $this->actingAs($this->cashier);
});

it('queues a quote keeping reference, amount and customer without creating sale, payments or receivables', function (): void {
    expect($this->entry->entry_type)->toBe(CashierQueueEntry::TYPE_SALE)
        ->and($this->entry->status)->toBe(CashierQueueEntry::STATUS_PENDING)
        ->and($this->entry->source_id)->toBe($this->quote->id)
        ->and((float) $this->entry->estimated_amount)->toBe(96.3);

    $this->assertDatabaseCount('sales_orders', 0);
    $this->assertDatabaseCount('sales_order_payments', 0);
    $this->assertDatabaseCount('customer_transactions', 0);
});

it('exposes sale entries as cotizacion por atender with the quote seller', function (): void {
    $this->getJson('/api/v1/cashier-queue')
        ->assertOk()
        ->assertJsonPath('data.0.entry_type', CashierQueueEntry::TYPE_SALE)
        ->assertJsonPath('data.0.source.description', 'Cotizacion por atender')
        ->assertJsonPath('data.0.source.seller_name', 'Vendedor Uno')
        ->assertJsonPath('data.0.source.party', 'Cliente Cola')
        ->assertJsonPath('data.0.source.document_number', $this->quote->fresh()->quote_number);
});

it('returns the invoice summary for a cashier with items, totals, customer and seller', function (): void {
    $response = $this->getJson("/api/v1/sales-quotes/{$this->quote->id}/summary")
        ->assertOk()
        ->assertJsonPath('data.id', $this->quote->id)
        ->assertJsonPath('data.customer_name', 'Cliente Cola')
        ->assertJsonPath('data.seller_name', 'Vendedor Uno')
        ->assertJsonPath('data.total', 96.3)
        ->assertJsonPath('data.items.0.product_name', 'Producto Cola')
        ->assertJsonPath('data.items.0.sku', 'COLA-V1')
        ->assertJsonPath('data.items.0.quantity', 1);

    expect((float) $response->json('data.items.0.unit_price'))->toBe(100.0);
});

it('open at cashier without session returns the quote payload and keeps the queue intact', function (): void {
    $this->postJson("/api/v1/sales-quotes/{$this->quote->id}/open-at-cashier")
        ->assertUnprocessable()
        ->assertJsonPath('requires_session', true)
        ->assertJsonPath('data.id', $this->quote->id)
        ->assertJsonPath('data.items.0.id', $this->quoteItem->id);

    expect($this->quote->fresh()->status)->toBe('sent_to_cashier')
        ->and($this->entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_PENDING);
    $this->assertDatabaseCount('sales_orders', 0);
});

it('open at cashier with session returns quote with items and seller', function (): void {
    openQueueSession($this->cashier, $this->warehouse);

    $this->postJson("/api/v1/sales-quotes/{$this->quote->id}/open-at-cashier")
        ->assertOk()
        ->assertJsonPath('data.id', $this->quote->id)
        ->assertJsonPath('data.user.id', $this->seller->id)
        ->assertJsonPath('data.items.0.id', $this->quoteItem->id);
});

it('locks an opened queue entry to the cashier attending it', function (): void {
    openQueueSession($this->cashier, $this->warehouse);
    openQueueSession($this->secondCashier, $this->warehouse);

    $this->postJson("/api/v1/cashier-queue/{$this->entry->id}/open")->assertOk();

    $this->actingAs($this->secondCashier)
        ->postJson("/api/v1/cashier-queue/{$this->entry->id}/open")
        ->assertUnprocessable();
});

it('removes quote from queue without deleting quote or creating financial records and allows re-enqueue', function (): void {
    $this->deleteJson("/api/v1/sales-quotes/{$this->quote->id}/cashier-queue")
        ->assertOk();

    expect($this->quote->fresh()->status)->toBe('sent')
        ->and($this->quote->fresh()->sent_to_cashier_at)->toBeNull()
        ->and($this->entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_CANCELLED)
        ->and($this->quote->items()->count())->toBe(1);

    $this->assertDatabaseCount('sales_orders', 0);
    $this->assertDatabaseCount('customer_transactions', 0);

    $this->actingAs($this->seller)
        ->postJson("/api/v1/sales-quotes/{$this->quote->id}/send-to-cashier")
        ->assertOk();

    $revived = CashierQueueEntry::query()->where('source_id', $this->quote->id)->firstOrFail();
    expect($revived->status)->toBe(CashierQueueEntry::STATUS_PENDING)
        ->and($revived->cancelled_at)->toBeNull()
        ->and($this->quote->fresh()->status)->toBe('sent_to_cashier');
});

it('checkout from pos creates a single sale linked to the quote and completes the entry', function (): void {
    openQueueSession($this->cashier, $this->warehouse);

    $response = $this->postJson('/api/v1/sales-orders', [
        'source_quote_id' => $this->quote->id,
        'status' => 'confirmed',
        'payments' => [['method' => 'cash', 'amount' => 96.30]],
        'items' => [[
            'source_quote_item_id' => $this->quoteItem->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 10,
            'tax_percent' => 7,
        ]],
    ])->assertCreated();

    $orderId = $response->json('data.id');
    $order = SalesOrder::findOrFail($orderId);

    expect(SalesOrder::count())->toBe(1)
        ->and($this->quote->fresh()->status)->toBe('collected')
        ->and($this->quote->fresh()->converted_order_id)->toBe($orderId)
        ->and($this->entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_COMPLETED)
        ->and((float) $this->entry->fresh()->final_amount)->toBe((float) $order->total);

    $this->assertDatabaseCount('customer_transactions', 0);
    expect(SalesOrderPayment::count())->toBe(1);
});

it('creates receivable only at checkout for the unpaid remainder', function (): void {
    openQueueSession($this->cashier, $this->warehouse);

    $response = $this->postJson('/api/v1/sales-orders', [
        'source_quote_id' => $this->quote->id,
        'status' => 'confirmed',
        'payments' => [['method' => 'cash', 'amount' => 50]],
        'items' => [[
            'source_quote_item_id' => $this->quoteItem->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 10,
            'tax_percent' => 7,
        ]],
    ])->assertCreated();

    $order = SalesOrder::findOrFail($response->json('data.id'));
    $expectedReceivable = round((float) $order->total - 50, 2);

    expect((int) $order->customer_id)->toBe($this->customer->id);

    $transaction = CustomerTransaction::query()->where('customer_id', $this->customer->id)->firstOrFail();
    expect((float) $transaction->amount)->toBe($expectedReceivable)
        ->and($transaction->type)->toBe('sale');
});

it('prevents two cashiers from creating two sales with the same quote', function (): void {
    openQueueSession($this->cashier, $this->warehouse);
    openQueueSession($this->secondCashier, $this->warehouse);

    $payload = [
        'source_quote_id' => $this->quote->id,
        'status' => 'confirmed',
        'payments' => [['method' => 'cash', 'amount' => 96.30]],
        'items' => [[
            'source_quote_item_id' => $this->quoteItem->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 10,
            'tax_percent' => 7,
        ]],
    ];

    $first = $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();
    $orderId = $first->json('data.id');

    $this->actingAs($this->secondCashier)
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $orderId);

    expect(SalesOrder::count())->toBe(1);
});
