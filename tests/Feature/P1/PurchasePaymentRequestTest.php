<?php

use App\Models\CashMovement;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchasePaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->warehouse = Warehouse::create(['code' => 'PPR', 'name' => 'Compras']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $supplier = Supplier::create(['name' => 'Proveedor']);
    $this->order = PurchaseOrder::create(['supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'received', 'payment_status' => 'pending', 'order_date' => now(), 'subtotal' => 100, 'total' => 100]);
    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true, 'liquidity_bucket' => 'cash', 'available_in_purchase_orders' => true]);
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], ['name' => 'Yappy', 'is_active' => true, 'liquidity_bucket' => 'bank', 'requires_financial_account' => false, 'available_in_purchase_orders' => true]);
});

it('requests a partial payment without moving money then settles once with combined methods', function () {
    $payload = ['amount' => 40, 'idempotency_key' => (string) Str::uuid()];
    $url = '/api/v1/purchase-orders/'.$this->order->id.'/payment-requests';
    $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertCreated();
    expect(PurchasePaymentRequest::count())->toBe(1)->and(CashMovement::count())->toBe(0)->and((float) $this->order->fresh()->amount_due)->toBe(100.0);
    $this->postJson($url, [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    PosSession::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'open', 'opening_amount' => 100, 'opened_at' => now()]);
    $entry = PurchasePaymentRequest::first()->queueEntry;
    $payments = ['payments' => [['method' => 'cash', 'amount' => 10], ['method' => 'yappy', 'amount' => 30]]];
    $this->postJson('/api/v1/cashier-queue/'.$entry->id.'/complete', $payments)->assertOk();
    $this->postJson('/api/v1/cashier-queue/'.$entry->id.'/complete', $payments)->assertOk();
    $this->postJson('/api/v1/cashier-queue/'.$entry->id.'/complete', ['payments' => [['method' => 'cash', 'amount' => 40]]])->assertConflict();
    expect((float) $this->order->fresh()->amount_due)->toBe(60.0)->and($this->order->payments()->count())->toBe(2)->and((float) CashMovement::sum('amount'))->toBe(10.0);
});

it('revalidates debt before settling a pending request', function () {
    $response = $this->postJson('/api/v1/purchase-orders/'.$this->order->id.'/payment-requests', ['amount' => 80, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $this->order->update(['total' => 50]);
    $this->postJson('/api/v1/cashier-queue/'.$response->json('data.queue_entry.id').'/complete', ['payments' => [['method' => 'cash', 'amount' => 80]]])->assertUnprocessable();
    expect($this->order->payments()->count())->toBe(0);
});

it('cancels an authorized purchase request before allowing another and rejects cross warehouse requests', function () {
    $url = '/api/v1/purchase-orders/'.$this->order->id.'/payment-requests';
    $payload = ['amount' => 25, 'idempotency_key' => (string) Str::uuid()];
    $response = $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, [...$payload, 'amount' => 30])->assertConflict();
    $this->postJson($url.'/'.$response->json('data.id').'/cancel', ['reason' => 'Corrección'])->assertOk();
    $this->postJson($url, [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $other = Warehouse::create(['code' => 'OTHER-PO', 'name' => 'Otra']);
    $this->postJson($url, [...$payload, 'warehouse_id' => $other->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    expect($this->order->payments()->count())->toBe(0);
});
