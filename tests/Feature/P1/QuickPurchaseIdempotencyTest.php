<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function quickPurchaseFixture(): array
{
    $warehouse = Warehouse::create(['code' => 'W-QP-IDEM', 'name' => 'Quick Purchase']);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);
    $supplier = Supplier::create(['name' => 'Proveedor Quick Purchase', 'status' => 'active']);
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['is_default' => true]);

    return compact('warehouse', 'admin', 'supplier', 'product', 'variant');
}

function quickPurchasePayload(array $fixture, string $key, int $quantity = 2): array
{
    return [
        'idempotency_key' => $key,
        'supplier_id' => $fixture['supplier']->id,
        'warehouse_id' => $fixture['warehouse']->id,
        'items' => [[
            'variant_id' => $fixture['variant']->id,
            'quantity_ordered' => $quantity,
            'unit_cost' => 5.25,
            'notes' => 'Abastecimiento desde POS',
        ]],
    ];
}

it('replays an identical quick receipt without duplicating purchase or inventory', function (): void {
    $fixture = quickPurchaseFixture();
    $key = (string) Str::uuid();
    $payload = quickPurchasePayload($fixture, $key);

    $first = $this->actingAs($fixture['admin'])
        ->withHeader('X-Warehouse-Id', (string) $fixture['warehouse']->id)
        ->postJson('/api/v1/purchase-orders/quick-receive', $payload)
        ->assertCreated();

    $second = $this->actingAs($fixture['admin'])
        ->withHeader('X-Warehouse-Id', (string) $fixture['warehouse']->id)
        ->postJson('/api/v1/purchase-orders/quick-receive', $payload)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(PurchaseOrder::query()->where('idempotency_key', $key)->count())->toBe(1);
    $this->assertDatabaseCount('purchase_order_items', 1);
    $this->assertDatabaseHas('product_inventory', [
        'warehouse_id' => $fixture['warehouse']->id,
        'variant_id' => $fixture['variant']->id,
        'quantity_available' => 2,
    ]);
});

it('returns conflict when a quick receipt key is reused with different data', function (): void {
    $fixture = quickPurchaseFixture();
    $key = (string) Str::uuid();

    $this->actingAs($fixture['admin'])
        ->withHeader('X-Warehouse-Id', (string) $fixture['warehouse']->id)
        ->postJson('/api/v1/purchase-orders/quick-receive', quickPurchasePayload($fixture, $key))
        ->assertCreated();

    $this->actingAs($fixture['admin'])
        ->withHeader('X-Warehouse-Id', (string) $fixture['warehouse']->id)
        ->postJson('/api/v1/purchase-orders/quick-receive', quickPurchasePayload($fixture, $key, 3))
        ->assertConflict()
        ->assertJsonPath('message', 'La clave de idempotencia ya fue utilizada con datos diferentes.');

    $this->assertDatabaseCount('purchase_orders', 1);
    $this->assertDatabaseCount('purchase_order_items', 1);
});

it('requires an idempotency key for the persistent quick receipt', function (): void {
    $fixture = quickPurchaseFixture();
    $payload = quickPurchasePayload($fixture, (string) Str::uuid());
    unset($payload['idempotency_key']);

    $this->actingAs($fixture['admin'])
        ->withHeader('X-Warehouse-Id', (string) $fixture['warehouse']->id)
        ->postJson('/api/v1/purchase-orders/quick-receive', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});
