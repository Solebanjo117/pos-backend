<?php

use App\Models\Brand;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-IDEMP', 'name' => 'Idempotencia']);
    $this->seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->seller->warehouses()->attach($this->warehouse->id);
    $this->product = Product::create(['name' => 'Producto Idemp', 'type' => 'simple', 'status' => 'active', 'sku_base' => 'IDEMP-1']);
    $brand = Brand::create(['name' => 'Marca Idemp', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'IDEMP-V1',
    ]);
    ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 50,
        'quantity_reserved' => 0,
    ]);
    $this->actingAs($this->seller);
});

function quotePayload(string $key, int $warehouseId, int $productId, int $variantId): array
{
    return [
        'idempotency_key' => $key,
        'warehouse_id' => $warehouseId,
        'items' => [[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_percent' => 7,
        ]],
    ];
}

it('does not duplicate a quote when the same idempotency key is posted twice', function (): void {
    $key = (string) Str::uuid();
    $payload = quotePayload($key, $this->warehouse->id, $this->product->id, $this->variant->id);

    $first = $this->postJson('/api/v1/sales-quotes', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/sales-quotes', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SalesQuote::count())->toBe(1);
});

it('does not duplicate a POS quote when the same idempotency key is posted twice', function (): void {
    $key = (string) Str::uuid();
    $payload = quotePayload($key, $this->warehouse->id, $this->product->id, $this->variant->id);

    $first = $this->postJson('/api/v1/sales-quotes/from-pos', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/sales-quotes/from-pos', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SalesQuote::count())->toBe(1);
});

it('creates two quotes when no idempotency key is provided (unchanged behaviour)', function (): void {
    $payload = quotePayload('', $this->warehouse->id, $this->product->id, $this->variant->id);
    unset($payload['idempotency_key']);

    $this->postJson('/api/v1/sales-quotes', $payload)->assertCreated();
    $this->postJson('/api/v1/sales-quotes', $payload)->assertCreated();

    expect(SalesQuote::count())->toBe(2);
});

it('does not duplicate a sale when the same idempotency key is posted twice', function (): void {
    $key = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $key,
        'status' => 'draft',
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_percent' => 7,
        ]],
    ];

    $first = $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(SalesOrder::count())->toBe(1);
});

it('rejects reuse of a sale idempotency key with a different checkout', function (): void {
    $key = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $key,
        'status' => 'draft',
        'warehouse_id' => $this->warehouse->id,
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_percent' => 0,
        ]],
    ];

    $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();
    $this->postJson('/api/v1/sales-orders', [
        ...$payload,
        'notes' => 'Payload diferente',
    ])->assertConflict();

    expect(SalesOrder::count())->toBe(1);
});

it('requires and updates the selected destination account for a bank checkout', function (): void {
    PaymentMethod::create([
        'name' => 'Transferencia',
        'code' => 'transfer',
        'is_active' => true,
        'is_system' => true,
        'liquidity_bucket' => 'bank',
        'available_in_sales' => true,
    ]);
    $account = FinancialAccount::create([
        'name' => 'Banco POS',
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 10,
        'current_balance' => 10,
        'ledger_account_id' => LedgerAccount::query()->where('code', '1110')->firstOrFail()->id,
        'created_by' => $this->seller->id,
    ]);
    $payload = [
        'idempotency_key' => (string) Str::uuid(),
        'status' => 'confirmed',
        'warehouse_id' => $this->warehouse->id,
        'payments' => [[
            'method' => 'transfer',
            'amount' => 100,
        ]],
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_percent' => 0,
        ]],
    ];

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payments.0.financial_account_id');

    $payload['idempotency_key'] = (string) Str::uuid();
    $payload['payments'][0]['financial_account_id'] = $account->id;
    $this->postJson('/api/v1/sales-orders', $payload)->assertCreated();

    expect((float) $account->fresh()->current_balance)->toBe(110.0)
        ->and(FinancialAccountMovement::query()->where('financial_account_id', $account->id)->count())->toBe(1);
});

it('rejects a declared credit amount that differs from the actual unpaid balance', function (): void {
    $customer = \App\Models\Customer::create([
        'code' => 'CLI-IDEMP-CREDIT',
        'name' => 'Cliente crédito',
        'credit_limit' => 500,
        'current_balance' => 0,
    ]);
    \App\Models\PosSession::create([
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'opened_at' => now(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);
    $payload = [
        'idempotency_key' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'status' => 'confirmed',
        'warehouse_id' => $this->warehouse->id,
        'payments' => [
            ['method' => 'cash', 'amount' => 50],
            ['method' => 'credit', 'amount' => 10],
        ],
        'items' => [[
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_percent' => 0,
        ]],
    ];

    $this->postJson('/api/v1/sales-orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payments');

    expect(SalesOrder::count())->toBe(0);
});
