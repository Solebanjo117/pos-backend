<?php

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryFifoService;
use App\Services\PurchaseOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function purchaseReceiptContext(string $suffix = ''): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-RCV-'.$suffix.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Warehouse Receipt '.$suffix,
    ]);
    $buyer = User::factory()->create([
        'role' => 'buyer',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $buyer->warehouses()->attach($warehouse->id);
    $supplier = Supplier::create([
        'name' => 'Proveedor Receipt '.$suffix.fake()->unique()->numberBetween(1000, 9999),
    ]);
    $product = Product::create([
        'sku_base' => 'RCV-'.$suffix.fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Producto Receipt '.$suffix,
        'status' => 'active',
    ]);
    $order = PurchaseOrder::create([
        'po_number' => 'PO-RCV-'.$suffix.fake()->unique()->numberBetween(1000, 9999),
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'sent',
        'order_date' => now()->toDateString(),
        'subtotal' => 50,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => 50,
    ]);
    $item = PurchaseOrderItem::create([
        'purchase_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity_ordered' => 10,
        'quantity_received' => 0,
        'unit_cost' => 5,
        'subtotal' => 50,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'misc_cost' => 0,
        'units_per_package' => 1,
        'status' => 'pending',
    ]);

    return compact('warehouse', 'buyer', 'supplier', 'product', 'order', 'item');
}

function receivePurchaseOrderPayload(PurchaseOrderItem $item, string $key, int $quantity = 5): array
{
    return [
        'idempotency_key' => $key,
        'items' => [[
            'item_id' => $item->id,
            'quantity_received' => $quantity,
        ]],
    ];
}

it('replays an identical partial receipt without duplicating stock or fifo lots', function () {
    $context = purchaseReceiptContext('REPLAY');
    $key = (string) Str::uuid();
    $payload = receivePurchaseOrderPayload($context['item'], $key);
    $url = '/api/v1/purchase-orders/'.$context['order']->id.'/receive';

    $this->actingAs($context['buyer'])->postJson($url, $payload)->assertOk();
    $this->actingAs($context['buyer'])->postJson($url, $payload)->assertOk();

    expect((int) $context['item']->fresh()->quantity_received)->toBe(5)
        ->and(PurchaseOrderReceipt::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(InventoryLot::query()->where('source_id', $context['item']->id)->count())->toBe(1)
        ->and((int) ProductInventory::query()
            ->where('product_id', $context['product']->id)
            ->where('warehouse_id', $context['warehouse']->id)
            ->value('quantity_available'))->toBe(5);
});

it('returns conflict when the receipt key is reused with different data', function () {
    $context = purchaseReceiptContext('CONFLICT');
    $key = (string) Str::uuid();
    $url = '/api/v1/purchase-orders/'.$context['order']->id.'/receive';

    $this->actingAs($context['buyer'])
        ->postJson($url, receivePurchaseOrderPayload($context['item'], $key, 5))
        ->assertOk();

    $this->actingAs($context['buyer'])
        ->postJson($url, receivePurchaseOrderPayload($context['item'], $key, 4))
        ->assertStatus(409)
        ->assertJsonPath('message', 'La clave de idempotencia ya fue utilizada con datos diferentes.');

    expect((int) $context['item']->fresh()->quantity_received)->toBe(5)
        ->and(InventoryLot::query()->where('source_id', $context['item']->id)->count())->toBe(1);
});

it('allows a later partial receipt when it uses a new key', function () {
    $context = purchaseReceiptContext('PARTIAL');
    $url = '/api/v1/purchase-orders/'.$context['order']->id.'/receive';

    $this->actingAs($context['buyer'])
        ->postJson($url, receivePurchaseOrderPayload($context['item'], (string) Str::uuid(), 5))
        ->assertOk();
    $this->actingAs($context['buyer'])
        ->postJson($url, receivePurchaseOrderPayload($context['item'], (string) Str::uuid(), 5))
        ->assertOk();

    expect((int) $context['item']->fresh()->quantity_received)->toBe(10)
        ->and(PurchaseOrderReceipt::query()->where('purchase_order_id', $context['order']->id)->count())->toBe(2)
        ->and(InventoryLot::query()->where('source_id', $context['item']->id)->count())->toBe(2);
});

it('rejects duplicate or foreign purchase lines before changing inventory', function () {
    $context = purchaseReceiptContext('OWNER');
    $foreign = purchaseReceiptContext('FOREIGN');
    $url = '/api/v1/purchase-orders/'.$context['order']->id.'/receive';
    $duplicateLine = receivePurchaseOrderPayload($context['item'], (string) Str::uuid());
    $duplicateLine['items'][] = $duplicateLine['items'][0];

    $this->actingAs($context['buyer'])
        ->postJson($url, $duplicateLine)
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.1.item_id');

    $this->actingAs($context['buyer'])
        ->postJson($url, receivePurchaseOrderPayload($foreign['item'], (string) Str::uuid()))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.item_id');

    expect((int) $context['item']->fresh()->quantity_received)->toBe(0)
        ->and((int) $foreign['item']->fresh()->quantity_received)->toBe(0)
        ->and(PurchaseOrderReceipt::query()->count())->toBe(0)
        ->and(InventoryLot::query()->count())->toBe(0);
});

it('rolls back the document and receipt record when fifo reception fails', function () {
    $context = purchaseReceiptContext('ROLLBACK');
    $this->mock(InventoryFifoService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('receiveStock')->once()->andThrow(new RuntimeException('Fallo FIFO controlado'));
    });

    $this->actingAs($context['buyer'])
        ->postJson(
            '/api/v1/purchase-orders/'.$context['order']->id.'/receive',
            receivePurchaseOrderPayload($context['item'], (string) Str::uuid())
        )
        ->assertStatus(422)
        ->assertJsonPath('message', 'Fallo FIFO controlado');

    expect((int) $context['item']->fresh()->quantity_received)->toBe(0)
        ->and($context['order']->fresh()->status)->toBe('sent')
        ->and(PurchaseOrderReceipt::query()->count())->toBe(0)
        ->and(InventoryLot::query()->count())->toBe(0);
});

it('returns a clear not found response for a missing purchase order', function () {
    $context = purchaseReceiptContext('MISSING');

    $this->actingAs($context['buyer'])
        ->postJson(
            '/api/v1/purchase-orders/999999/receive',
            receivePurchaseOrderPayload($context['item'], (string) Str::uuid())
        )
        ->assertNotFound()
        ->assertJsonPath('message', 'La orden de compra no existe.');
});

it('returns a recoverable server error without exposing database details', function () {
    $context = purchaseReceiptContext('DATABASE');
    $this->mock(PurchaseOrderService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('receiveItems')->once()->andThrow(new QueryException(
            'pgsql',
            'select confidential_details',
            [],
            new RuntimeException('database unavailable')
        ));
    });

    $response = $this->actingAs($context['buyer'])
        ->postJson(
            '/api/v1/purchase-orders/'.$context['order']->id.'/receive',
            receivePurchaseOrderPayload($context['item'], (string) Str::uuid())
        );

    $response->assertStatus(500)
        ->assertJsonPath('message', 'No se pudo completar la recepción por un error interno.');
    expect($response->getContent())->not->toContain('confidential_details');
});
