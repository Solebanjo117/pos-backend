<?php

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function accountsPayableContext(float $total = 120.00): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-AP-'.fake()->unique()->randomNumber(4),
        'name' => 'Warehouse AP',
    ]);

    $buyer = User::factory()->create([
        'role' => 'admin',
        'current_warehouse_id' => $warehouse->id,
        'is_active' => true,
    ]);
    $buyer->warehouses()->attach($warehouse->id);

    $supplier = Supplier::create([
        'name' => 'Proveedor Credito '.fake()->unique()->randomNumber(4),
        'email' => 'proveedor-ap-'.fake()->unique()->randomNumber(4).'@example.test',
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'user_id' => $buyer->id,
        'status' => 'received',
        'payment_status' => 'pending',
        'order_date' => now()->toDateString(),
        'subtotal' => $total,
        'tax_amount' => 0,
        'shipping_cost' => 0,
        'total' => $total,
    ]);

    return compact('warehouse', 'buyer', 'supplier', 'purchaseOrder');
}

function createApPaymentMethod(string $code, string $name, int $sortOrder): void
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

it('lists payable documents with invoice fallback and capabilities', function () {
    ['buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();

    $this->actingAs($buyer)
        ->getJson('/api/v1/accounts-payable/documents?status=open')
        ->assertOk()
        ->assertJsonPath('data.0.id', $purchaseOrder->id)
        ->assertJsonPath('data.0.party.id', $supplier->id)
        ->assertJsonPath('data.0.document_number', $purchaseOrder->po_number)
        ->assertJsonPath('data.0.total', 120)
        ->assertJsonPath('data.0.paid', 0)
        ->assertJsonPath('data.0.balance', 120)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.capabilities.can_register_payment', true)
        ->assertJsonPath('summary.balance', 120)
        ->assertJsonPath('meta.total', 1);
});

it('filters payable documents and keeps seller access forbidden', function () {
    ['buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();
    $seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);

    $this->actingAs($buyer)
        ->getJson('/api/v1/accounts-payable/documents?document_number='.urlencode($purchaseOrder->po_number).'&search='.urlencode($supplier->name))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($seller)
        ->getJson('/api/v1/accounts-payable/documents')
        ->assertForbidden();
});

it('records a bank payment from the selected financial account without requiring an open cash session', function () {
    ['buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();
    createApPaymentMethod('transfer', 'Transferencia', 1);
    $account = FinancialAccount::create([
        'name' => 'Banco pagos',
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 200,
        'current_balance' => 200,
        'ledger_account_id' => LedgerAccount::query()->where('code', '1110')->firstOrFail()->id,
        'created_by' => $buyer->id,
    ]);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 30,
        'method' => 'transfer',
        'financial_account_id' => $account->id,
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 30],
        ],
    ])->assertCreated();

    expect((float) $account->fresh()->current_balance)->toBe(170.0)
        ->and(AccountPaymentBatch::firstOrFail()->financial_account_id)->toBe($account->id)
        ->and(FinancialAccountMovement::query()->where('financial_account_id', $account->id)->count())->toBe(1);
});

it('records partial and full accounts payable payments from supplier account', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();
    createApPaymentMethod('transfer', 'Transferencia', 1);

    PosSession::create([
        'user_id' => $buyer->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($buyer)->getJson('/api/v1/accounts-payable')
        ->assertOk()
        ->assertJsonPath('data.0.id', $supplier->id)
        ->assertJsonPath('data.0.total_debt', 120);

    $this->actingAs($buyer)->getJson("/api/v1/accounts-payable/{$supplier->id}/statement")
        ->assertOk()
        ->assertJsonPath('data.summary.open_documents_count', 1)
        ->assertJsonPath('data.summary.total_open_balance', 120)
        ->assertJsonPath('data.summary.total_documents_amount', 120)
        ->assertJsonPath('data.summary.total_paid', 0);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 50,
        'method' => 'transfer',
        'reference_number' => 'ACH-50',
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 50],
        ],
    ])->assertCreated();

    expect($purchaseOrder->fresh()->payment_status)->toBe('partial')
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(70.0)
        ->and(PurchaseOrderPayment::firstOrFail()->pos_session_id)->not->toBeNull()
        ->and(AccountPaymentBatch::firstOrFail()->pos_session_id)->not->toBeNull();

    $this->actingAs($buyer)->getJson("/api/v1/accounts-payable/{$supplier->id}/statement")
        ->assertOk()
        ->assertJsonPath('data.summary.open_documents_count', 1)
        ->assertJsonPath('data.summary.total_open_balance', 70)
        ->assertJsonPath('data.summary.total_documents_amount', 120)
        ->assertJsonPath('data.summary.total_paid', 50);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 70,
        'method' => 'transfer',
        'reference_number' => 'ACH-70',
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 70],
        ],
    ])->assertCreated();

    expect($purchaseOrder->fresh()->payment_status)->toBe('paid')
        ->and((float) $purchaseOrder->fresh()->amount_due)->toBe(0.0);

    $this->actingAs($buyer)->getJson('/api/v1/accounts-payable')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects non real accounts payable payment methods', function (string $method) {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();

    PosSession::create([
        'user_id' => $buyer->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 10,
        'method' => $method,
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 10],
        ],
    ])->assertUnprocessable();

    expect(PurchaseOrderPayment::count())->toBe(0)
        ->and($purchaseOrder->fresh()->payment_status)->toBe('pending');
})->with(['credit', 'store_credit', 'supplier_credit']);

it('requires an open cash session before recording a cash accounts payable payment', function () {
    ['buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 10,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 10],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('pos_session_id');
});

it('records accounts payable cash payment as cash drawer withdrawal', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();
    createApPaymentMethod('cash', 'Efectivo', 1);

    PosSession::create([
        'user_id' => $buyer->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 120,
        'expected_amount' => 120,
        'status' => 'open',
    ]);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 40,
        'method' => 'cash',
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 40],
        ],
    ])->assertCreated();

    $movement = CashMovement::firstOrFail();

    expect($movement->type)->toBe('out')
        ->and((float) $movement->amount)->toBe(40.0)
        ->and(AccountPaymentBatch::firstOrFail()->cash_movement_id)->toBe($movement->id);

    $response = $this->actingAs($buyer)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouse->id,
        'closing_amount' => 80,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 80,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.closing_details.summary.cash_movements_out', 40.0)
        ->assertJsonPath('data.closing_details.summary.expected_cash', 80.0);
});

it('records accounts payable transfer payment as method withdrawal without cash movement', function () {
    ['warehouse' => $warehouse, 'buyer' => $buyer, 'supplier' => $supplier, 'purchaseOrder' => $purchaseOrder] = accountsPayableContext();
    createApPaymentMethod('cash', 'Efectivo', 1);
    createApPaymentMethod('transfer', 'Transferencia', 2);

    PosSession::create([
        'user_id' => $buyer->id,
        'warehouse_id' => $warehouse->id,
        'opened_at' => now()->subHour(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'status' => 'open',
    ]);

    $this->actingAs($buyer)->postJson("/api/v1/accounts-payable/{$supplier->id}/payments", [
        'amount' => 40,
        'method' => 'transfer',
        'reference_number' => 'ACH-40',
        'applications' => [
            ['document_id' => $purchaseOrder->id, 'amount' => 40],
        ],
    ])->assertCreated();

    expect(CashMovement::count())->toBe(0);

    $response = $this->actingAs($buyer)->postJson('/api/v1/pos-sessions/close', [
        'warehouse_id' => $warehouse->id,
        'closing_amount' => 0,
        'closing_details' => [
            'payment_method_counts' => [
                'cash' => 0,
            ],
        ],
        'notes' => 'Salida por transferencia',
    ]);
    $rows = collect($response->json('data.closing_details.reconciliation_rows'))->keyBy('code');

    $response->assertOk();
    expect($rows['transfer']['withdrawals'])->toBe(-40.0)
        ->and($rows['transfer']['expected_total'])->toBe(-40.0);
});
