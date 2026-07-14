<?php

use App\Models\Brand;
use App\Models\CashMovement;
use App\Models\CashierQueueEntry;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cancellationWorkflowContext(?Customer $customer = null): array
{
    $warehouse = Warehouse::create(['code' => 'W-CANCEL', 'name' => 'Caja anulaciones']);
    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);
    $cashier->warehouses()->attach($warehouse->id);

    PaymentMethod::query()->updateOrCreate(['code' => 'cash'], [
        'name' => 'Efectivo',
        'is_active' => true,
        'is_system' => true,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_CASH,
        'available_in_sales' => true,
        'available_in_returns' => true,
        'sort_order' => 1,
    ]);

    $brand = Brand::create(['name' => 'Marca anulacion', 'status' => 'active']);
    $product = Product::create([
        'sku_base' => 'CANCEL-1',
        'name' => 'Producto anulable',
        'status' => 'active',
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'brand_id' => $brand->id,
        'sku' => 'CANCEL-1-A',
        'status' => 'active',
        'is_default' => true,
    ]);
    ProductInventory::create([
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity_available' => 5,
        'quantity_reserved' => 0,
    ]);

    auth()->login($admin);
    $order = app(SalesOrderService::class)->create([
        'user_id' => $admin->id,
        'customer_id' => $customer?->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'confirmed',
        'payment_method' => $customer ? 'credit' : 'cash',
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'unit_price' => 10,
            'tax_percent' => 7,
        ]],
    ])->fresh(['items']);

    return compact('warehouse', 'admin', 'cashier', 'product', 'variant', 'order');
}

it('annuls a paid invoice through a return and settles its cash refund idempotently', function (): void {
    $context = cancellationWorkflowContext();
    $order = $context['order'];

    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => $order->total,
        'method' => 'cash',
        'paid_at' => now(),
        'created_by' => $context['admin']->id,
    ]);
    $order->update(['payment_status' => 'paid']);

    $response = $this->actingAs($context['admin'])
        ->postJson("/api/v1/sales-orders/{$order->id}/cancel", [
            'reason' => 'Factura duplicada en caja',
            'refund_method' => 'cash',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Factura duplicada en caja');

    $return = SalesReturn::findOrFail($response->json('data.cancellation_return_id'));
    expect($return->status)->toBe('approved')
        ->and($return->settlement_status)->toBe('pending')
        ->and(SalesReturn::count())->toBe(1)
        ->and((int) ProductInventory::firstOrFail()->quantity_available)->toBe(5);

    $inventoryReturn = InventoryMovement::query()
        ->where('reference_type', 'sales_return')
        ->where('reference_id', $return->id)
        ->firstOrFail();
    expect($inventoryReturn->quantity_before)->toBe(3)
        ->and($inventoryReturn->quantity_after)->toBe(5)
        ->and($inventoryReturn->user_id)->toBe($context['admin']->id);

    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();
    PosSession::create([
        'user_id' => $context['cashier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'status' => 'open',
    ]);

    $payload = ['payment_method' => 'cash', 'reference_number' => 'ANU-001'];
    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", $payload)
        ->assertOk();

    expect(CashMovement::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->count())->toBe(1)
        ->and(SalesOrderPayment::query()->where('sales_return_id', $return->id)->where('amount', '<', 0)->count())->toBe(1)
        ->and($entry->fresh()->status)->toBe(CashierQueueEntry::STATUS_COMPLETED);

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", $payload)
        ->assertOk();
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/sales-orders/{$order->id}/cancel", [
            'reason' => 'Reintento de anulacion',
        ])
        ->assertOk();

    expect(CashMovement::query()->where('source_type', 'sales_return')->where('source_id', $return->id)->count())->toBe(1)
        ->and(SalesOrderPayment::query()->where('sales_return_id', $return->id)->count())->toBe(1)
        ->and(SalesReturn::count())->toBe(1)
        ->and((int) ProductInventory::firstOrFail()->quantity_available)->toBe(5);
});

it('annuls a credit invoice by reducing receivables without moving cash or bank', function (): void {
    $customer = Customer::create([
        'code' => 'CLI-CANCEL',
        'name' => 'Cliente credito anulacion',
        'status' => 'active',
        'current_balance' => 0,
    ]);
    $context = cancellationWorkflowContext($customer);
    $order = $context['order'];
    $balanceBefore = (float) $customer->fresh()->current_balance;
    expect($balanceBefore)->toBeGreaterThan(0);

    $response = $this->actingAs($context['admin'])
        ->postJson("/api/v1/sales-orders/{$order->id}/cancel", [
            'reason' => 'Credito emitido por error',
            'refund_method' => 'receivable',
        ])
        ->assertOk();

    $return = SalesReturn::findOrFail($response->json('data.cancellation_return_id'));
    expect((float) $customer->fresh()->current_balance)->toBe($balanceBefore)
        ->and((float) $return->receivable_applied_amount)->toBe($balanceBefore)
        ->and((float) $return->money_refund_amount)->toBe(0.0);

    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();
    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete")
        ->assertOk();

    expect((float) $customer->fresh()->current_balance)->toBe(0.0)
        ->and(CashMovement::count())->toBe(0)
        ->and(SalesOrderPayment::query()->where('sales_return_id', $return->id)->count())->toBe(0)
        ->and(CustomerTransaction::query()->where('reference_type', 'sales_return')->where('reference_id', $return->id)->count())->toBe(1)
        ->and((float) $order->fresh()->total)->toBe((float) $order->total);
});

it('settles a paid cancellation with a configured bank method without touching cash', function (): void {
    $context = cancellationWorkflowContext();
    $order = $context['order'];
    PaymentMethod::query()->updateOrCreate(['code' => 'yappy'], [
        'name' => 'Yappy',
        'is_active' => true,
        'is_system' => false,
        'liquidity_bucket' => PaymentMethod::LIQUIDITY_BANK,
        'available_in_sales' => true,
        'available_in_returns' => true,
        'requires_reference' => true,
        'sort_order' => 5,
    ]);
    SalesOrderPayment::create([
        'sales_order_id' => $order->id,
        'amount' => $order->total,
        'method' => 'yappy',
        'paid_at' => now(),
        'created_by' => $context['admin']->id,
    ]);

    $response = $this->actingAs($context['admin'])
        ->postJson("/api/v1/sales-orders/{$order->id}/cancel", [
            'reason' => 'Reembolso bancario de prueba',
            'refund_method' => 'yappy',
        ])
        ->assertOk();
    $return = SalesReturn::findOrFail($response->json('data.cancellation_return_id'));
    $entry = CashierQueueEntry::query()
        ->where('source_type', SalesReturn::class)
        ->where('source_id', $return->id)
        ->firstOrFail();

    $this->actingAs($context['cashier'])
        ->postJson("/api/v1/cashier-queue/{$entry->id}/complete", [
            'payment_method' => 'yappy',
            'reference_number' => 'YAPPY-DEV-1',
        ])
        ->assertOk();

    expect(CashMovement::count())->toBe(0)
        ->and(SalesOrderPayment::query()
            ->where('sales_return_id', $return->id)
            ->where('method', 'yappy')
            ->where('amount', '<', 0)
            ->count())->toBe(1)
        ->and($return->fresh()->settlement_reference)->toBe('YAPPY-DEV-1');
});
