<?php

use App\Models\AccountLedgerEntry;
use App\Models\AccountPaymentApplication;
use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function purchasePaymentClosingContext(float $total = 64.20): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-PO-CASH-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse PO Cash',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $admin->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor Caja',
        'email' => 'proveedor-caja-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $admin->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => $total,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => $total,
    ]);

    return compact('warehouse', 'admin', 'supplier', 'purchaseOrder');
}

function createPurchasePaymentMethod(string $code, string $name, int $sortOrder): void
{
    PaymentMethod::create([
        'name' => $name,
        'code' => $code,
        'is_active' => true,
        'is_system' => true,
        'requires_reference' => $code === 'transfer',
        'requires_cash_count' => $code === 'cash',
        'sort_order' => $sortOrder,
    ]);
}

it('seeds supplier credit as a system payment method without replacing customer credit', function () {
    $this->seed(PaymentMethodSeeder::class);

    $supplierCredit = PaymentMethod::query()->where('code', 'supplier_credit')->firstOrFail();
    $customerCredit = PaymentMethod::query()->where('code', 'credit')->firstOrFail();

    expect($supplierCredit->name)->toBe('Crédito proveedor')
        ->and($supplierCredit->is_active)->toBeTrue()
        ->and($supplierCredit->is_system)->toBeTrue()
        ->and($supplierCredit->requires_reference)->toBeFalse()
        ->and($supplierCredit->requires_cash_count)->toBeFalse()
        ->and($supplierCredit->sort_order)->toBeGreaterThan($customerCredit->sort_order)
        ->and($customerCredit->name)->toBe('Crédito');
});

it('registers cash purchase order payment as cash drawer withdrawal in closing', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'warehouse_id' => $warehouse->id,
        'amount' => 64.20,
        'method' => 'cash',
        'notes' => 'Pago en efectivo',
    ])->assertCreated();

    $payment = PurchaseOrderPayment::firstOrFail();
    $movement = CashMovement::firstOrFail();

    expect(AccountPaymentBatch::count())->toBe(1)
        ->and(AccountPaymentApplication::count())->toBe(1)
        ->and(AccountLedgerEntry::count())->toBe(1);

    expect($payment->pos_session_id)->toBe($session->id)
        ->and($payment->cash_movement_id)->toBe($movement->id)
        ->and($movement->pos_session_id)->toBe($session->id)
        ->and($movement->type)->toBe('out')
        ->and((float) $movement->amount)->toBe(64.20)
        ->and($movement->reason)->toBe('Pago OC '.$purchaseOrder->po_number);

    $response = $this->actingAs($admin)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouse->id,
        'closing_amount' => 35.80,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 35.80,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.cash_movements_out', 64.20)
        ->assertJsonPath('data.closing_details.summary.expected_cash', 35.80);

    expect((float) $response->json('data.closing_details.summary.total_difference'))->toBe(0.0);

    $detail = $this->actingAs($admin)->getJson("/api/v1/pos-sessions/{$session->id}");
    $detail->assertOk()
        ->assertJsonPath('purchase_payments.0.method', 'cash')
        ->assertJsonPath('purchase_payments.0.purchase_order.po_number', $purchaseOrder->po_number);
});

it('allows a partial cash purchase order payment and leaves the remaining balance payable', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext(100);
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 20,
        'method' => 'cash',
        'notes' => 'Abono parcial',
    ])->assertCreated();

    expect(PurchaseOrderPayment::count())->toBe(1)
        ->and(CashMovement::count())->toBe(1)
        ->and((float) PurchaseOrderPayment::firstOrFail()->amount)->toBe(20.0)
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(80.0)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('partial');
});

it('registers transfer purchase order payment as transfer withdrawal without cash movement', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);
    createPurchasePaymentMethod('transfer', 'Transferencia', 2);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 64.20,
        'method' => 'transfer',
        'reference_number' => 'ACH-123',
        'financial_account_id' => \App\Models\FinancialAccount::create(['name' => 'Banco compra', 'type' => 'bank', 'currency' => 'USD', 'opening_balance' => 100, 'current_balance' => 100, 'ledger_account_id' => \App\Models\LedgerAccount::where('code', '1110')->firstOrFail()->id, 'is_active' => true, 'is_legacy' => false])->id,
    ])->assertCreated();

    expect(CashMovement::count())->toBe(0);

    $response = $this->actingAs($admin)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouse->id,
        'closing_amount' => 0,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 0,
            ],
        ],
        'notes' => 'Cierre con pago de compra por transferencia',
    ]);

    $response->assertOk();
    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    expect($rows['transfer']['withdrawals'])->toBe(-64.20)
        ->and($rows['transfer']['expected_total'])->toBe(-64.20)
        ->and($rows['transfer']['is_countable'])->toBeTrue();
});

it('rejects real purchase order payment when there is no open session for the order warehouse', function () {
    ['admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 64.20,
        'method' => 'cash',
    ])->assertUnprocessable();

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);
});

it('rejects purchase order payment when the requested warehouse does not match the order warehouse', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    $otherWarehouse = Warehouse::create([
        'code' => 'W-PO-OTHER-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse Diferente',
    ]);
    $admin->warehouses()->attach($otherWarehouse->id);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'warehouse_id' => $otherWarehouse->id,
        'amount' => 20,
        'method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('warehouse_id');

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);
});

it('explains that the purchase order warehouse needs its own open cash session', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    $otherWarehouse = Warehouse::create([
        'code' => 'W-PO-WRONG-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse con Caja Abierta',
    ]);
    $admin->warehouses()->attach($otherWarehouse->id);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $otherWarehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'warehouse_id' => $warehouse->id,
        'amount' => 20,
        'method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.pos_session_id.0', "La OC pertenece a {$warehouse->name}. Abre o selecciona caja en esa sucursal antes de registrar el pago.");

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0);
});

it('keeps supplier credit purchase order as payable without immediate payment or cash movement', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 64.20,
        'method' => 'supplier_credit',
    ])->assertOk();

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0)
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(64.20)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('pending');
});

it('records only real purchase payments when the rest remains supplier credit', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext(100);
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 20,
        'method' => 'cash',
    ])->assertCreated();

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 80,
        'method' => 'supplier_credit',
    ])->assertOk();

    expect(PurchaseOrderPayment::count())->toBe(1)
        ->and(CashMovement::count())->toBe(1)
        ->and((float) PurchaseOrderPayment::firstOrFail()->amount)->toBe(20.0)
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(80.0)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('partial');
});

it('keeps credit purchase order as payable without immediate payment or cash movement', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 64.20,
        'method' => 'credit',
    ])->assertOk();

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0)
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(64.20)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('pending');
});

it('deleting a cash purchase order payment removes its automatic cash movement', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 20,
        'method' => 'cash',
    ])->assertCreated();

    $payment = PurchaseOrderPayment::firstOrFail();
    expect(CashMovement::count())->toBe(1);

    $this->actingAs($admin)->deleteJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments/{$payment->id}")
        ->assertOk();

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and(AccountPaymentBatch::count())->toBe(0)
        ->and(AccountPaymentApplication::count())->toBe(0)
        ->and(AccountLedgerEntry::count())->toBe(0)
        ->and(CashMovement::count())->toBe(0)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('pending');
});

it('rejects deleting a purchase order payment linked to a closed cash session', function () {
    ['warehouse' => $warehouse, 'admin' => $admin, 'purchaseOrder' => $purchaseOrder] = purchasePaymentClosingContext();
    createPurchasePaymentMethod('cash', 'Efectivo', 1);

    $session = PosSession::create([
        'user_id' => $admin->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 100,
        'expected_amount' => 100,
        'status' => 'open',
    ]);

    $this->actingAs($admin)->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments", [
        'amount' => 20,
        'method' => 'cash',
    ])->assertCreated();

    $session->update([
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    $payment = PurchaseOrderPayment::firstOrFail();

    $this->actingAs($admin)->deleteJson("/api/v1/purchase-orders/{$purchaseOrder->id}/payments/{$payment->id}")
        ->assertUnprocessable();

    expect(PurchaseOrderPayment::count())->toBe(1)
        ->and(AccountPaymentBatch::count())->toBe(1)
        ->and(AccountPaymentApplication::count())->toBe(1)
        ->and(AccountLedgerEntry::count())->toBe(1)
        ->and(CashMovement::count())->toBe(1);
});
