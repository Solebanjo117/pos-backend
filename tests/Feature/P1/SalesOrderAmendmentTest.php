<?php

use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PosSessionReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->warehouse = Warehouse::create(['code' => 'AMEND', 'name' => 'Ampliaciones']);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'current_warehouse_id' => $this->warehouse->id]);
    $this->user->warehouses()->attach($this->warehouse);
    $this->actingAs($this->user);
    $this->session = PosSession::create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'open', 'opening_amount' => 0, 'opened_at' => now()->subMinute()]);
    $this->customer = Customer::create(['code' => 'AMEND-C', 'name' => 'Cliente', 'credit_limit' => 1000, 'current_balance' => 0]);
    $this->order = SalesOrder::create(['customer_id' => $this->customer->id, 'user_id' => $this->user->id, 'date' => now(), 'status' => 'draft']);
    $this->product = \App\Models\Product::create(['sku_base' => 'AMEND-P', 'name' => 'Producto', 'status' => 'active']);
    $this->inventory = \App\Models\ProductInventory::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity_available' => 10, 'quantity_reserved' => 0]);
    $this->item = $this->order->items()->create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'is_custom_line' => false, 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 7.13, 'tax_percent' => 0]);
    $this->order->refresh()->update(['status' => 'completed', 'payment_status' => 'paid']);
    $this->order->addPayment(192.87, 'cash', isCheckout: true);
    $this->payload = [
        'warehouse_id' => $this->warehouse->id, 'idempotency_key' => (string) Str::uuid(), 'expected_total' => 192.87,
        'items' => [['order_item_id' => $this->item->id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'is_custom_line' => false, 'qty' => 3, 'price' => 100, 'discount_amount' => 7.13, 'tax_percent' => 0]],
        'payment' => [['method' => 'cash', 'amount' => 100]],
    ];
});

it('amplia conservando descuento exacto pagos historicos y caja actual sin duplicar', function (): void {
    $url = "/api/v1/sales-orders/{$this->order->id}/add-items";
    $this->patchJson($url, $this->payload)->assertOk()->assertJsonPath('difference', 100)->assertJsonPath('replayed', false);
    $this->patchJson($url, $this->payload)->assertOk()->assertJsonPath('replayed', true);
    expect((float) $this->item->fresh()->discount_amount)->toBe(7.13)
        ->and($this->item->fresh()->quantity)->toBe(3)
        ->and((float) $this->order->fresh()->total)->toBe(292.87)
        ->and($this->order->payments()->count())->toBe(2)
        ->and((float) $this->customer->fresh()->current_balance)->toBe(0.0)
        ->and($this->order->payments()->reorder()->latest('id')->first()->pos_session_id)->toBe($this->session->id);
    $rows = app(PosSessionReconciliationService::class)->build($this->session)['rows'];
    expect((float) collect($rows)->firstWhere('code', 'cash')['payments'])->toBe(100.0);
});

it('rechaza reutilizar la clave con contenido distinto y versiones viejas', function (): void {
    $url = "/api/v1/sales-orders/{$this->order->id}/add-items";
    $this->patchJson($url, $this->payload)->assertOk();
    $changed = $this->payload;
    $changed['items'][0]['qty'] = 4;
    $this->patchJson($url, $changed)->assertConflict();
    $changed['idempotency_key'] = (string) Str::uuid();
    $this->patchJson($url, $changed)->assertConflict();
    expect($this->order->payments()->count())->toBe(2);
});

it('rechaza reducciones cambio de precio descuento y cobros incorrectos atomicamente', function (string $field, mixed $value): void {
    if ($field === 'payment') {
        $this->payload['payment'][0]['amount'] = $value;
    } else {
        $this->payload['items'][0][$field] = $value;
    }
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertUnprocessable();
    expect($this->item->fresh()->quantity)->toBe(2)->and($this->order->payments()->count())->toBe(1);
})->with([['qty', 1], ['price', 99], ['discount_amount', 8], ['payment', 99]]);

it('carga solo el credito adicional sin alterar pagos previos', function (): void {
    $this->payload['payment'] = [['method' => 'cash', 'amount' => 40], ['method' => 'credit', 'amount' => 60]];
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertOk();
    expect((float) $this->customer->fresh()->current_balance)->toBe(60.0)->and($this->order->fresh()->payment_status)->toBe('partial');
});

it('bloquea caja cerrada sucursal ajena y rol cajero', function (): void {
    $this->session->update(['status' => 'closed']);
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertUnprocessable();
    $this->session->update(['status' => 'open']);
    $other = Warehouse::create(['code' => 'OTHER-A', 'name' => 'Otra']);
    $this->payload['warehouse_id'] = $other->id;
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertUnprocessable();
    $this->user->update(['role' => 'cashier']);
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertForbidden();
});

it('rechaza orden vinculada todavia en preparacion', function (): void {
    SalesQuote::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'sent_to_deposit', 'converted_order_id' => $this->order->id]);
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertUnprocessable();
});

it('conserva referencia y cuenta del pago bancario', function (): void {
    PaymentMethod::query()->updateOrCreate(['code' => 'transfer'], ['name' => 'Transferencia', 'is_active' => true, 'available_in_sales' => true, 'liquidity_bucket' => 'bank', 'requires_reference' => true]);
    $account = FinancialAccount::create(['ledger_account_id' => \App\Models\LedgerAccount::query()->where('code', '1110')->firstOrFail()->id, 'name' => 'Banco', 'type' => 'bank', 'currency' => 'USD', 'is_active' => true, 'is_legacy' => false, 'opening_balance' => 0, 'current_balance' => 0]);
    $this->payload['payment'] = [['method' => 'transfer', 'amount' => 100, 'reference_number' => 'REF-AMPL', 'financial_account_id' => $account->id]];
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertOk();
    $payment = $this->order->payments()->reorder()->latest('id')->first();
    expect($payment->reference_number)->toBe('REF-AMPL')->and($payment->financial_account_id)->toBe($account->id)
        ->and(FinancialAccountMovement::where('financial_account_id', $account->id)->count())->toBe(1);
});

it('revierte stock lineas y pagos si no alcanza inventario', function (): void {
    $this->payload['items'][0]['qty'] = 20;
    $this->payload['payment'][0]['amount'] = 1800;
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertUnprocessable();
    expect($this->item->fresh()->quantity)->toBe(2)
        ->and((int) $this->inventory->fresh()->quantity_available)->toBe(10)
        ->and($this->order->payments()->count())->toBe(1);
});

it('consume solo incremento una vez y admite nueva linea con descuento fijo', function (): void {
    $this->payload['items'][] = ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 2, 'price' => 30, 'discount_amount' => 3.27, 'tax_percent' => 0];
    $this->payload['payment'][0]['amount'] = 156.73;
    $url = "/api/v1/sales-orders/{$this->order->id}/add-items";
    $this->patchJson($url, $this->payload)->assertOk();
    $this->patchJson($url, $this->payload)->assertOk();
    expect((int) $this->inventory->fresh()->quantity_available)->toBe(7)
        ->and($this->order->items()->count())->toBe(2)
        ->and((float) $this->order->fresh()->total)->toBe(349.60);
});

it('no atribuye ampliaciones a la caja original cerrada', function (): void {
    $old = PosSession::create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'closed', 'opening_amount' => 0, 'opened_at' => now()->subDays(2), 'closed_at' => now()->subDay()]);
    $this->order->update(['pos_session_id' => $old->id]);
    $this->order->payments()->update(['paid_at' => now()->subDays(2)->addHour()]);
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertOk();
    $service = app(PosSessionReconciliationService::class);
    expect((float) collect($service->build($this->session)['rows'])->firstWhere('code', 'cash')['payments'])->toBe(100.0)
        ->and((float) collect($service->build($old)['rows'])->firstWhere('code', 'cash')['payments'])->toBe(192.87)
        ->and($this->order->fresh()->pos_session_id)->toBe($old->id);
});

it('protege edicion ordinaria de orden pendiente vinculada', function (): void {
    $this->order->update(['status' => 'pending']);
    SalesQuote::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'sent_to_deposit', 'converted_order_id' => $this->order->id]);
    $this->putJson("/api/v1/sales-orders/{$this->order->id}", ['notes' => 'No modificar'])->assertUnprocessable();
    expect($this->order->fresh()->notes)->not->toBe('No modificar');
});

it('consume saldo a favor sin duplicar la deuda del cliente', function (): void {
    $this->customer->update(['current_balance' => -40]);
    $this->payload['payment'] = [['method' => 'store_credit', 'amount' => 40], ['method' => 'cash', 'amount' => 60]];
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertOk();
    expect((float) $this->customer->fresh()->current_balance)->toBe(0.0)
        ->and($this->order->fresh()->payment_status)->toBe('paid');
});

it('mantiene pagada una venta ampliada despues de una devolucion liquidada', function (): void {
    $return = \App\Models\SalesReturn::create([
        'sales_order_id' => $this->order->id, 'warehouse_id' => $this->warehouse->id,
        'customer_id' => $this->customer->id, 'user_id' => $this->user->id,
        'status' => 'approved', 'total_amount' => 50, 'reason' => 'Devolución previa',
    ]);
    $this->order->payments()->create([
        'sales_return_id' => $return->id, 'method' => 'cash', 'amount' => -50,
        'paid_at' => now(), 'created_by' => $this->user->id,
    ]);
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)->assertOk();
    expect($this->order->fresh()->payment_status)->toBe('paid')
        ->and($this->order->fresh()->amount_due)->toBe(0.0);
});

it('impide cancelar por separado una orden vinculada en preparacion', function (): void {
    $this->order->update(['status' => 'pending']);
    SalesQuote::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'sent_to_deposit', 'converted_order_id' => $this->order->id]);
    expect(fn () => app(\App\Services\SalesOrderCancellationService::class)->cancel($this->order, ['reason' => 'Cancelar'], $this->user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($this->order->fresh()->status)->toBe('pending');
});

it('no mezcla un reembolso asignado explicitamente a otra caja', function (): void {
    $otherSession = PosSession::create(['user_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'closed', 'opening_amount' => 0, 'opened_at' => now()->subHour(), 'closed_at' => now()]);
    $return = \App\Models\SalesReturn::create([
        'sales_order_id' => $this->order->id, 'warehouse_id' => $this->warehouse->id,
        'customer_id' => $this->customer->id, 'user_id' => $this->user->id,
        'status' => 'approved', 'total_amount' => 20, 'reason' => 'Otra caja',
    ]);
    $this->order->payments()->create([
        'sales_return_id' => $return->id, 'pos_session_id' => $otherSession->id,
        'method' => 'cash', 'amount' => -20, 'paid_at' => now(), 'created_by' => $this->user->id,
    ]);
    $rows = app(PosSessionReconciliationService::class)->build($this->session)['rows'];
    expect((float) collect($rows)->firstWhere('code', 'cash')['withdrawals'])->toBe(0.0);
});

it('expone la cotizacion vinculada al abrir una transaccion en el editor', function (): void {
    $quote = SalesQuote::create(['warehouse_id' => $this->warehouse->id, 'user_id' => $this->user->id, 'status' => 'sent_to_deposit', 'converted_order_id' => $this->order->id]);
    $this->getJson("/api/v1/sales-orders/{$this->order->id}")->assertOk()
        ->assertJsonPath('source_quote.id', $quote->id)
        ->assertJsonPath('source_quote.status', 'sent_to_deposit');
});

it('bloquea las rutas antiguas para reducir lineas de ventas cobradas', function (): void {
    $this->item->update(['added_after_sale' => true]);
    $this->putJson("/api/v1/sales-orders/items/{$this->item->id}", ['quantity' => 1])->assertUnprocessable();
    $this->deleteJson("/api/v1/sales-orders/items/{$this->item->id}")->assertUnprocessable();
    expect($this->item->fresh()->quantity)->toBe(2)
        ->and($this->order->payments()->count())->toBe(1);
});

it('no permite asignar una ampliacion nueva a otro vendedor sin autorizacion', function (): void {
    $this->user->update(['role' => 'seller']);
    $another = User::factory()->create(['role' => 'seller', 'is_active' => true]);
    $this->payload['items'][] = ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 1, 'price' => 30, 'tax_percent' => 0, 'seller_id' => $another->id];
    $this->payload['payment'][0]['amount'] = 130;
    $this->patchJson("/api/v1/sales-orders/{$this->order->id}/add-items", $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['items.1.seller_id']);
    expect($this->order->items()->count())->toBe(1);
});
