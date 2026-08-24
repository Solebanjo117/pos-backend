<?php

use App\Models\Brand;
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
