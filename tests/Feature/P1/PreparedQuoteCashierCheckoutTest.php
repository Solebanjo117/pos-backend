<?php

use App\Models\Brand;
use App\Models\CashierQueueEntry;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function preparedQuoteCheckoutContext(bool $prepare = true, ?Customer $customer = null): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-PREP-'.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Bodega preparada',
    ]);
    $seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $seller->warehouses()->attach($warehouse->id);
    $cashier->warehouses()->attach($warehouse->id);

    $product = Product::create([
        'name' => 'Producto preparado',
        'type' => 'simple',
        'status' => 'active',
        'sku_base' => 'PREP-'.fake()->unique()->numberBetween(1000, 9999),
    ]);
    $brand = Brand::create(['name' => 'Marca preparada '.fake()->unique()->numberBetween(1000, 9999)]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'PREP-V-'.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Variante preparada',
    ]);
    $inventory = ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
    ]);
    $lot = InventoryLot::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'source_type' => 'opening',
        'source_id' => 1,
        'received_quantity' => 5,
        'quantity_available' => 5,
        'unit_cost_base' => 50,
        'landed_unit_cost' => 50,
        'received_at' => now()->subDay(),
    ]);

    $quote = SalesQuote::create([
        'customer_id' => $customer?->id,
        'user_id' => $seller->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'sent',
    ]);
    $quoteItem = $quote->items()->create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 1,
        'unit_price' => 100,
        'discount_percent' => 0,
        'tax_percent' => 7,
    ]);

    test()->actingAs($seller)
        ->postJson("/api/v1/sales-quotes/{$quote->id}/send-to-deposit")
        ->assertCreated();
    $quote->refresh();
    $order = SalesOrder::findOrFail($quote->converted_order_id);

    if ($prepare) {
        test()->postJson("/api/v1/sales-orders/{$order->id}/mark-prepared")->assertOk();
    }
    test()->postJson("/api/v1/sales-quotes/{$quote->id}/send-to-cashier")->assertOk();

    $session = PosSession::create([
        'user_id' => $cashier->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesQuote::class)
        ->where('source_id', $quote->id)
        ->firstOrFail();

    return compact(
        'warehouse',
        'seller',
        'cashier',
        'product',
        'variant',
        'inventory',
        'lot',
        'quote',
        'quoteItem',
        'order',
        'session',
        'entry',
    );
}

function preparedQuotePaymentPayload(array $context, string $key, array $payments): array
{
    return [
        'idempotency_key' => $key,
        'source_quote_id' => $context['quote']->id,
        'status' => 'confirmed',
        'warehouse_id' => $context['warehouse']->id,
        'payment_method' => 'multiple',
        'payments' => $payments,
        'items' => [[
            'source_quote_item_id' => $context['quoteItem']->id,
            'product_id' => $context['product']->id,
            'variant_id' => $context['variant']->id,
            'warehouse_id' => $context['warehouse']->id,
            'quantity' => 99,
            'unit_price' => 1,
            'discount_percent' => 99,
            'tax_percent' => 7,
        ]],
    ];
}

it('opens and charges the prepared warehouse order without creating a second sale', function (): void {
    $context = preparedQuoteCheckoutContext();
    $key = (string) Str::uuid();
    $payload = preparedQuotePaymentPayload($context, $key, [['method' => 'cash', 'amount' => 107]]);

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$context['entry']->id}/open")
        ->assertOk();
    $this->postJson("/api/v1/sales-quotes/{$context['quote']->id}/open-at-cashier")
        ->assertOk()
        ->assertJsonPath('data.converted_order_id', $context['order']->id);

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $context['order']->id);

    $order = $context['order']->fresh();
    expect(SalesOrder::query()->count())->toBe(1)
        ->and($order->status)->toBe('completed')
        ->and($order->payment_status)->toBe('paid')
        ->and($order->pos_session_id)->toBe($context['session']->id)
        ->and($order->idempotency_key)->toBe($key)
        ->and(SalesOrderPayment::query()->where('sales_order_id', $order->id)->count())->toBe(1)
        ->and($context['quote']->fresh()->status)->toBe('collected')
        ->and($context['entry']->fresh()->status)->toBe(CashierQueueEntry::STATUS_COMPLETED)
        ->and((int) $context['inventory']->fresh()->quantity_available)->toBe(4)
        ->and((int) $context['lot']->fresh()->quantity_available)->toBe(4)
        ->and(InventoryLotAllocation::query()->where('sales_order_item_id', $order->items()->value('id'))->count())->toBe(1);

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $order->id);
    expect(SalesOrderPayment::query()->where('sales_order_id', $order->id)->count())->toBe(1)
        ->and((int) $context['inventory']->fresh()->quantity_available)->toBe(4);

    $changed = $payload;
    $changed['payments'][0]['amount'] = 106;
    $this->postJson('/api/v1/sales-orders', $changed)->assertConflict();
});

it('creates receivable only for the unpaid remainder of the prepared order', function (): void {
    $customer = Customer::create([
        'code' => 'CLI-PREP-'.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Cliente crédito preparado',
        'status' => 'active',
        'credit_limit' => 500,
        'current_balance' => 0,
    ]);
    $context = preparedQuoteCheckoutContext(false, $customer);
    $payload = preparedQuotePaymentPayload($context, (string) Str::uuid(), [
        ['method' => 'cash', 'amount' => 50],
        ['method' => 'credit', 'amount' => 57],
    ]);

    $this->actingAs($context['cashier'])
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $context['order']->id);

    $transaction = CustomerTransaction::query()
        ->where('customer_id', $customer->id)
        ->where('reference_id', $context['order']->id)
        ->firstOrFail();
    expect((float) $transaction->amount)->toBe(57.0)
        ->and($context['order']->fresh()->payment_status)->toBe('partial')
        ->and(SalesOrderPayment::query()->where('sales_order_id', $context['order']->id)->count())->toBe(2);
});

it('deposits a bank payment into the selected active financial account', function (): void {
    $context = preparedQuoteCheckoutContext();
    $account = FinancialAccount::create([
        'name' => 'Banco cobro preparado',
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 100,
        'current_balance' => 100,
        'ledger_account_id' => LedgerAccount::query()->where('code', '1110')->firstOrFail()->id,
        'created_by' => $context['cashier']->id,
    ]);
    $payload = preparedQuotePaymentPayload($context, (string) Str::uuid(), [[
        'method' => 'transfer',
        'amount' => 107,
        'reference_number' => 'ACH-PREP-1',
        'financial_account_id' => $account->id,
    ]]);

    $this->actingAs($context['cashier'])
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $context['order']->id);

    expect((float) $account->fresh()->current_balance)->toBe(207.0)
        ->and(FinancialAccountMovement::query()->where('financial_account_id', $account->id)->count())->toBe(1)
        ->and(SalesOrderPayment::query()->value('financial_account_id'))->toBe($account->id);
});

it('opens and charges a linked order before warehouse preparation', function (): void {
    $context = preparedQuoteCheckoutContext(false);
    $payload = preparedQuotePaymentPayload($context, (string) Str::uuid(), [
        ['method' => 'cash', 'amount' => 107],
    ]);

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/sales-quotes/{$context['quote']->id}/open-at-cashier")
        ->assertOk()
        ->assertJsonPath('data.converted_order_id', $context['order']->id);

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $context['order']->id);

    expect($context['order']->fresh()->status)->toBe('completed')
        ->and($context['order']->fresh()->prepared_at)->toBeNull()
        ->and(SalesOrderPayment::query()->count())->toBe(1)
        ->and($context['quote']->fresh()->status)->toBe('collected')
        ->and($context['entry']->fresh()->status)->toBe(CashierQueueEntry::STATUS_COMPLETED);

    $this->actingAs($context['seller'])
        ->getJson('/api/v1/sales-orders/pending-at-warehouse')
        ->assertOk()
        ->assertJsonPath('data.0.id', $context['order']->id);
    $this->postJson("/api/v1/sales-orders/{$context['order']->id}/mark-prepared")
        ->assertOk();
    expect($context['order']->fresh()->prepared_at)->not->toBeNull();
});

it('does not consume stock reserved by another order and reports the shortage', function (): void {
    $context = preparedQuoteCheckoutContext(false);
    $context['inventory']->forceFill([
        'quantity_available' => 0,
        'quantity_reserved' => 5,
    ])->save();
    $payload = preparedQuotePaymentPayload($context, (string) Str::uuid(), [
        ['method' => 'cash', 'amount' => 107],
    ]);

    $this->actingAs($context['cashier'])
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('data.id', $context['order']->id)
        ->assertJsonPath('warnings.0.type', 'stock_shortage')
        ->assertJsonPath('warnings.0.requested_quantity', 1)
        ->assertJsonPath('warnings.0.missing_quantity', 1);

    expect($context['order']->fresh()->status)->toBe('completed')
        ->and((int) $context['inventory']->fresh()->quantity_available)->toBe(0)
        ->and((int) $context['inventory']->fresh()->quantity_reserved)->toBe(5)
        ->and((int) $context['lot']->fresh()->quantity_available)->toBe(5)
        ->and(InventoryLotAllocation::query()->where('sales_order_item_id', $context['order']->items()->value('id'))->count())->toBe(0)
        ->and($context['quote']->fresh()->status)->toBe('collected')
        ->and($context['entry']->fresh()->status)->toBe(CashierQueueEntry::STATUS_COMPLETED);

    $activity = Activity::query()
        ->where('subject_type', SalesQuote::class)
        ->where('subject_id', $context['quote']->id)
        ->where('description', 'cashier_collected')
        ->latest('id')
        ->firstOrFail();
    $warnings = $activity->properties->get('stock_warnings');
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['type'])->toBe('stock_shortage')
        ->and($warnings[0]['requested_quantity'])->toBe(1)
        ->and($warnings[0]['missing_quantity'])->toBe(1);
});

it('requires an idempotency UUID when cashier charges a queued quote', function (): void {
    $context = preparedQuoteCheckoutContext();
    $payload = preparedQuotePaymentPayload($context, (string) Str::uuid(), [
        ['method' => 'cash', 'amount' => 107],
    ]);
    unset($payload['idempotency_key']);

    $this->actingAs($context['cashier'])
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    expect($context['order']->fresh()->status)->toBe('pending')
        ->and(SalesOrderPayment::query()->count())->toBe(0);
});

it('does not expose the prepared quote to a cashier working in another warehouse', function (): void {
    $context = preparedQuoteCheckoutContext();
    $otherWarehouse = Warehouse::create([
        'code' => 'W-OTHER-'.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Otra bodega',
    ]);

    $this->actingAs($context['cashier'])
        ->withHeader('X-Warehouse-Id', (string) $otherWarehouse->id)
        ->postJson("/api/v1/sales-quotes/{$context['quote']->id}/open-at-cashier")
        ->assertForbidden()
        ->assertJsonPath('message', 'La cotizacion pertenece a otra sucursal.');

    expect($context['order']->fresh()->status)->toBe('pending')
        ->and(SalesOrderPayment::query()->count())->toBe(0);
});

it('rolls back payment quote queue and inventory when prepared checkout fails', function (): void {
    $context = preparedQuoteCheckoutContext();
    $payload = preparedQuotePaymentPayload(
        $context,
        (string) Str::uuid(),
        [['method' => 'cash', 'amount' => 107]],
    );

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$context['entry']->id}/open")
        ->assertOk();
    $this->mock(InventoryFifoService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('currentFifoPricing')->andReturn(['active_lot_id' => null]);
        $mock->shouldReceive('consumeStock')->once()->andThrow(new RuntimeException('Fallo FIFO controlado'));
    });

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertServerError();

    expect($context['order']->fresh()->status)->toBe('pending')
        ->and($context['order']->fresh()->idempotency_key)->toBeNull()
        ->and(SalesOrderPayment::query()->count())->toBe(0)
        ->and($context['quote']->fresh()->status)->toBe('sent_to_cashier')
        ->and($context['entry']->fresh()->status)->toBe(CashierQueueEntry::STATUS_OPENED)
        ->and((int) $context['inventory']->fresh()->quantity_available)->toBe(5)
        ->and((int) $context['lot']->fresh()->quantity_available)->toBe(5);
});
