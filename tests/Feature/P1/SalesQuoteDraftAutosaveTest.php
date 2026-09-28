<?php

use App\Models\Brand;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'W-DRAFT', 'name' => 'Sucursal borradores']);
    $this->otherWarehouse = Warehouse::create(['code' => 'W-OTHER', 'name' => 'Otra sucursal']);
    $this->seller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->warehouse->id,
        'is_active' => true,
    ]);
    $this->seller->warehouses()->attach($this->warehouse->id);
    $this->actingAs($this->seller);

    $this->product = Product::create([
        'name' => 'Producto borrador',
        'type' => 'simple',
        'status' => 'active',
        'sku_base' => 'DRAFT-1',
    ]);
    $brand = Brand::create(['name' => 'Marca borrador', 'status' => 'active']);
    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'brand_id' => $brand->id,
        'sku' => 'DRAFT-V1',
    ]);
    $this->inventory = ProductInventory::create([
        'product_id' => $this->product->id,
        'variant_id' => $this->variant->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity_available' => 1,
        'quantity_reserved' => 0,
    ]);
});

function draftPayload(int $warehouseId, int $productId, int $variantId): array
{
    return [
        'expected_revision' => 0,
        'customer_id' => null,
        'customer_vehicle_id' => null,
        'warehouse_id' => $warehouseId,
        'notes' => 'Entregar por la tarde',
        'internal_notes' => 'Llamar antes',
        'items' => [[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => 3,
            'unit_price' => 100,
            'discount_percent' => 10,
            'discount_amount' => 15,
            'tax_percent' => 7,
            'notes' => 'Empaque separado',
        ]],
    ];
}

it('autosaves a public quote without charging, opening cash or changing stock', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);

    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.draft_revision', 1)
        ->assertJsonPath('data.customer_id', null)
        ->assertJsonPath('data.notes', 'Entregar por la tarde')
        ->assertJsonPath('data.internal_notes', 'Llamar antes');

    $quote = SalesQuote::with('items')->firstOrFail();
    expect($quote->idempotency_key)->toBe($uuid)
        ->and($quote->items)->toHaveCount(1)
        ->and((float) $quote->items->first()->discount_amount)->toBe(15.0)
        ->and($quote->items->first()->notes)->toBe('Empaque separado')
        ->and(SalesOrder::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and((int) $this->inventory->fresh()->quantity_available)->toBe(1)
        ->and((int) $this->inventory->fresh()->quantity_reserved)->toBe(0);
});

it('replays a lost response without duplicate quote or revision and rejects changed content', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $first = $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertCreated();

    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.draft_revision', 1);

    $payload['notes'] = 'Contenido diferente con la misma versión';
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertConflict()
        ->assertJsonPath('data.draft_revision', 1);

    expect(SalesQuote::count())->toBe(1)
        ->and(SalesQuote::firstOrFail()->items()->count())->toBe(1);
});

it('updates a sent quote without a linked order and preserves vehicle and fixed discount', function (): void {
    $customer = Customer::create(['code' => 'DRAFT-C', 'name' => 'Cliente borrador']);
    $make = VehicleMake::create(['name' => 'Honda']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Civic', 'year_from' => 2012, 'year_to' => 2026]);
    $engine = VehicleEngine::create(['make_id' => $make->id, 'model_id' => $model->id, 'engine_code' => 'R18']);
    $engine->models()->syncWithoutDetaching([$model->id]);
    $vehicle = CustomerVehicle::create([
        'customer_id' => $customer->id,
        'vehicle_model_id' => $model->id,
        'vehicle_engine_id' => $engine->id,
        'status' => 'active',
    ]);
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['customer_id'] = $customer->id;
    $payload['customer_vehicle_id'] = $vehicle->id;
    $payload['vehicle_context'] = [
        'make_id' => $make->id,
        'make_name' => 'Nombre obsoleto',
        'model_id' => $model->id,
        'model_name' => 'Nombre obsoleto',
        'engine_id' => $engine->id,
        'engine_name' => 'Nombre obsoleto',
        'year' => 2020,
    ];
    $first = $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertCreated();
    $quote = SalesQuote::findOrFail($first->json('data.id'));
    $quote->update(['status' => 'sent']);

    $payload['quote_id'] = $quote->id;
    $payload['expected_revision'] = 1;
    $payload['notes'] = 'Nueva entrega';
    $payload['items'][0]['quantity'] = 4;
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.draft_revision', 2)
        ->assertJsonPath('data.customer_vehicle_id', $vehicle->id)
        ->assertJsonPath('data.vehicle_context.make_name', 'Honda')
        ->assertJsonPath('data.vehicle_context.model_name', 'Civic')
        ->assertJsonPath('data.vehicle_context.engine_id', $engine->id)
        ->assertJsonPath('data.vehicle_context.year', 2020)
        ->assertJsonPath('data.notes', 'Nueva entrega');

    expect((float) $quote->fresh()->items()->firstOrFail()->discount_amount)->toBe(15.0);

    $payload['notes'] = 'Cambio atrasado';
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertConflict()
        ->assertJsonPath('data.draft_revision', 2);
});

it('rejects a catalog engine outside the selected model', function (): void {
    $make = VehicleMake::create(['name' => 'Ford']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Focus', 'year_from' => 2010, 'year_to' => 2020]);
    $otherModel = VehicleModel::create(['make_id' => $make->id, 'name' => 'Fiesta', 'year_from' => 2010, 'year_to' => 2020]);
    $engine = VehicleEngine::create(['make_id' => $make->id, 'model_id' => $otherModel->id, 'engine_code' => 'OTHER']);
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['vehicle_context'] = ['make_id' => $make->id, 'model_id' => $model->id, 'engine_id' => $engine->id];

    $this->putJson('/api/v1/sales-quotes/drafts/'.Str::uuid(), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_context.engine_id']);
    expect(SalesQuote::count())->toBe(0);
});

it('rejects autosave after a quote enters a fulfillment flow', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $created = $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertCreated();
    $quote = SalesQuote::findOrFail($created->json('data.id'));
    $quote->update(['status' => 'sent_to_cashier']);

    $payload['quote_id'] = $quote->id;
    $payload['expected_revision'] = 1;
    $payload['notes'] = 'No debe persistirse';
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertConflict();

    expect($quote->fresh()->notes)->toBe('Entregar por la tarde')
        ->and($quote->draft_revision)->toBe(1);
});

it('does not let a seller write a draft in another warehouse', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->otherWarehouse->id, $this->product->id, $this->variant->id);

    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertUnprocessable();

    expect(SalesQuote::count())->toBe(0);
});

it('does not expose a quote from another warehouse in a conflict response', function (): void {
    $foreignSeller = User::factory()->create([
        'role' => 'seller',
        'current_warehouse_id' => $this->otherWarehouse->id,
        'is_active' => true,
    ]);
    $foreignSeller->warehouses()->attach($this->otherWarehouse->id);
    $quote = SalesQuote::create([
        'user_id' => $foreignSeller->id,
        'warehouse_id' => $this->otherWarehouse->id,
        'status' => 'draft',
    ]);
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['quote_id'] = $quote->id;

    $this->putJson('/api/v1/sales-quotes/drafts/'.Str::uuid(), $payload)
        ->assertForbidden()
        ->assertDontSee($quote->quote_number);
});

it('binds a stable key to a legacy quote and rejects a different key thereafter', function (): void {
    $quote = SalesQuote::create([
        'user_id' => $this->seller->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'sent',
    ]);
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['quote_id'] = $quote->id;
    $uuid = (string) Str::uuid();

    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)
        ->assertOk()
        ->assertJsonPath('data.draft_revision', 1);
    expect($quote->fresh()->idempotency_key)->toBe($uuid);

    $payload['expected_revision'] = 1;
    $this->putJson('/api/v1/sales-quotes/drafts/'.Str::uuid(), $payload)
        ->assertForbidden();
    expect($quote->fresh()->draft_revision)->toBe(1);
});

it('rejects stale autosave after a manual edit increments the revision', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $created = $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertCreated();
    $quoteId = $created->json('data.id');

    $this->putJson("/api/v1/sales-quotes/{$quoteId}", ['notes' => 'Cambio manual'])
        ->assertOk()
        ->assertJsonPath('data.draft_revision', 2);

    $payload['quote_id'] = $quoteId;
    $payload['expected_revision'] = 1;
    $payload['notes'] = 'Cambio en pestaña anterior';
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertConflict();
    expect(SalesQuote::findOrFail($quoteId)->notes)->toBe('Cambio manual');
});

it('blocks a linked order even if its quote status returns to sent', function (): void {
    $uuid = (string) Str::uuid();
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $created = $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertCreated();
    $quote = SalesQuote::findOrFail($created->json('data.id'));
    $order = SalesOrder::create(['user_id' => $this->seller->id, 'date' => now(), 'status' => 'pending']);
    $quote->update(['status' => 'sent', 'converted_order_id' => $order->id]);

    $payload['quote_id'] = $quote->id;
    $payload['expected_revision'] = 1;
    $payload['notes'] = 'No debe guardarse';
    $this->putJson("/api/v1/sales-quotes/drafts/{$uuid}", $payload)->assertConflict();
    $this->putJson("/api/v1/sales-quotes/{$quote->id}", ['notes' => 'Tampoco manual'])->assertUnprocessable();
    $admin = User::factory()->create(['role' => 'admin', 'current_warehouse_id' => $this->warehouse->id, 'is_active' => true]);
    $admin->warehouses()->attach($this->warehouse->id);
    $this->actingAs($admin);
    $this->deleteJson("/api/v1/sales-quotes/{$quote->id}")->assertUnprocessable();
    expect($quote->fresh()->notes)->toBe('Entregar por la tarde');
});

it('returns validation errors for malformed items before custom lookups', function (): void {
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['items'] = [['product_id' => ['invalid'], 'warehouse_id' => $this->warehouse->id]];
    $this->putJson('/api/v1/sales-quotes/drafts/'.Str::uuid(), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_id']);
});

it('preserves an existing seller without treating it as reassignment', function (): void {
    $otherSeller = User::factory()->create(['role' => 'seller', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $otherSeller->warehouses()->attach($this->warehouse->id);
    $quote = SalesQuote::create(['user_id' => $otherSeller->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'draft']);
    $payload = draftPayload($this->warehouse->id, $this->product->id, $this->variant->id);
    $payload['quote_id'] = $quote->id;
    $payload['seller_id'] = $otherSeller->id;
    $this->putJson('/api/v1/sales-quotes/drafts/'.Str::uuid(), $payload)->assertOk()
        ->assertJsonPath('data.user_id', $otherSeller->id);
});
