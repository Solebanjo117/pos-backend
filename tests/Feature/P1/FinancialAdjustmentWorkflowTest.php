<?php

use App\Models\AccountLedgerEntry;
use App\Models\Brand;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialAdjustment;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function financialAdjustmentContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-AJ-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacén Ajustes',
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'can_manage_financial_adjustments' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $admin->warehouses()->attach($warehouse->id);
    $counterparty = LedgerAccount::firstOrCreate(
        ['code' => '3999'],
        ['name' => 'Contrapartida de ajustes', 'type' => 'equity', 'is_active' => true],
    );

    return compact('warehouse', 'admin', 'counterparty');
}

function adjustmentPayload(string $type, LedgerAccount $counterparty, array $extra = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'type' => $type,
        'direction' => 'increase',
        'amount' => 25.50,
        'reason' => 'Corrección autorizada por auditoría',
        'reference' => 'AUD-2026-01',
        'notes' => 'Validación de ajuste',
        'business_datetime' => now('America/Panama')->format('Y-m-d H:i:s'),
        'counterparty_ledger_account_id' => $counterparty->id,
        ...$extra,
    ];
}

it('creates a cash adjustment with an operational movement and balanced journal', function (): void {
    $context = financialAdjustmentContext();
    $session = PosSession::create([
        'user_id' => $context['admin']->id,
        'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'status' => 'open',
    ]);

    $response = $this->actingAs($context['admin'])
        ->postJson('/api/v1/reports/adjustments', adjustmentPayload('cash', $context['counterparty'], [
            'pos_session_id' => $session->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.folio', 'AJ-'.now('America/Panama')->format('Y').'-000001')
        ->assertJsonPath('data.direction', 'increase');

    $adjustment = FinancialAdjustment::findOrFail($response->json('data.id'));
    $entry = JournalEntry::with('lines')->findOrFail($adjustment->journal_entry_id);

    expect(CashMovement::where('source_type', 'financial_adjustment')->where('source_id', $adjustment->id)->count())->toBe(1)
        ->and((float) $entry->lines->sum('debit'))->toBe(25.5)
        ->and((float) $entry->lines->sum('credit'))->toBe(25.5)
        ->and($adjustment->created_ip)->not->toBeNull();
});

it('requires and updates an exact configured bank account idempotently', function (): void {
    $context = financialAdjustmentContext();
    $bankLedger = LedgerAccount::firstOrCreate(['code' => '1110'], ['name' => 'Banco', 'type' => 'asset', 'is_active' => true]);
    $bank = FinancialAccount::create([
        'name' => 'Operativa principal',
        'type' => 'bank',
        'institution_name' => 'Banco General',
        'masked_number' => '•••• 4321',
        'currency' => 'USD',
        'opening_balance' => 100,
        'current_balance' => 100,
        'ledger_account_id' => $bankLedger->id,
        'is_active' => true,
        'is_legacy' => false,
        'created_by' => $context['admin']->id,
    ]);
    $payload = adjustmentPayload('bank', $context['counterparty'], ['financial_account_id' => $bank->id]);

    $created = $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', $payload)->assertCreated();
    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', $payload)->assertOk()->assertJsonPath('replayed', true);
    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', [...$payload, 'amount' => 99])->assertConflict();
    $this->actingAs($context['admin'])->getJson('/api/v1/reports/adjustments?type=bank')
        ->assertOk()
        ->assertJsonPath('data.0.id', $created->json('data.id'))
        ->assertJsonPath('data.0.capabilities.can_reverse', true);
    $this->actingAs($context['admin'])->getJson('/api/v1/reports/adjustments/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.financial_account.masked_number', '•••• 4321')
        ->assertJsonPath('data.journal_entry.id', fn ($value): bool => is_int($value));

    expect((float) $bank->fresh()->current_balance)->toBe(125.5)
        ->and(FinancialAccountMovement::where('source_type', 'financial_adjustment')->count())->toBe(1)
        ->and(FinancialAdjustment::count())->toBe(1);
});

it('creates a manual balanced entry and rejects equal accounts', function (): void {
    $context = financialAdjustmentContext();
    $debit = LedgerAccount::create(['code' => '1199', 'name' => 'Cuenta débito', 'type' => 'asset', 'is_active' => true]);
    $payload = adjustmentPayload('manual_correction', $context['counterparty'], [
        'affected_ledger_account_id' => $debit->id,
    ]);
    unset($payload['direction']);

    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', $payload)->assertCreated();
    $entry = JournalEntry::with('lines')->where('source_type', 'financial_adjustment')->firstOrFail();
    expect((float) $entry->lines->sum('debit'))->toBe(25.5)
        ->and((float) $entry->lines->sum('credit'))->toBe(25.5);

    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', [
        ...$payload,
        'idempotency_key' => (string) Str::uuid(),
        'affected_ledger_account_id' => $context['counterparty']->id,
    ])->assertUnprocessable();
});

it('reverses with a new folio and restores the bank balance exactly', function (): void {
    $context = financialAdjustmentContext();
    $bankLedger = LedgerAccount::firstOrCreate(['code' => '1110'], ['name' => 'Banco', 'type' => 'asset', 'is_active' => true]);
    $bank = FinancialAccount::create([
        'name' => 'Cuenta reversible', 'type' => 'bank', 'institution_name' => 'BAC',
        'masked_number' => '•••• 9000', 'currency' => 'USD', 'opening_balance' => 100,
        'current_balance' => 100, 'ledger_account_id' => $bankLedger->id,
        'is_active' => true, 'is_legacy' => false, 'created_by' => $context['admin']->id,
    ]);
    $created = $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', adjustmentPayload('bank', $context['counterparty'], [
        'financial_account_id' => $bank->id,
    ]))->assertCreated()->json('data');

    $this->actingAs($context['admin'])->postJson("/api/v1/reports/adjustments/{$created['id']}/reverse", [
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Cuenta bancaria equivocada',
        'business_datetime' => now('America/Panama')->format('Y-m-d H:i:s'),
    ])->assertCreated()->assertJsonPath('data.reversal_of.folio', $created['folio']);

    expect((float) $bank->fresh()->current_balance)->toBe(100.0)
        ->and(FinancialAdjustment::findOrFail($created['id'])->status)->toBe('reversed')
        ->and(FinancialAdjustment::count())->toBe(2)
        ->and(JournalEntry::where('source_type', 'financial_adjustment')->count())->toBe(2);
});

it('adjusts receivable and payable documents without recording a payment or receipt', function (): void {
    $context = financialAdjustmentContext();
    $customer = Customer::create(['code' => 'CLI-AJ-001', 'name' => 'Cliente ajuste', 'status' => 'active', 'current_balance' => 100]);
    $sale = SalesOrder::create([
        'order_number' => 'SO-AJ-001', 'customer_id' => $customer->id,
        'user_id' => $context['admin']->id, 'date' => now()->toDateString(),
        'status' => 'completed', 'subtotal' => 100, 'tax_amount' => 0,
        'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
    ]);
    $supplier = Supplier::create(['name' => 'Proveedor ajuste', 'status' => 'active']);
    $purchase = PurchaseOrder::create([
        'po_number' => 'PO-AJ-001', 'supplier_id' => $supplier->id,
        'warehouse_id' => $context['warehouse']->id, 'user_id' => $context['admin']->id,
        'status' => 'received', 'payment_status' => 'pending',
        'order_date' => now()->toDateString(), 'subtotal' => 100,
        'tax_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
    ]);

    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', [
        ...adjustmentPayload('accounts_receivable', $context['counterparty'], [
            'customer_id' => $customer->id, 'sales_order_id' => $sale->id,
        ]),
        'direction' => 'decrease', 'amount' => 25.50,
    ])->assertCreated();
    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', [
        ...adjustmentPayload('accounts_payable', $context['counterparty'], [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $purchase->id,
        ]),
        'direction' => 'decrease', 'amount' => 10,
    ])->assertCreated();

    expect((float) $sale->fresh()->amount_due)->toBe(74.5)
        ->and((float) $customer->fresh()->current_balance)->toBe(74.5)
        ->and((float) $purchase->fresh()->amount_due)->toBe(90.0)
        ->and(AccountLedgerEntry::where('movement_type', 'financial_adjustment')->count())->toBe(2)
        ->and($sale->payments()->count())->toBe(0)
        ->and($purchase->payments()->count())->toBe(0);
});

it('adjusts physical inventory through FIFO and posts its real valuation', function (): void {
    $context = financialAdjustmentContext();
    $brand = Brand::create(['name' => 'Marca ajuste', 'status' => 'active']);
    $product = Product::create(['sku_base' => 'SKU-AJ-1', 'name' => 'Producto ajuste', 'status' => 'active']);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'brand_id' => $brand->id,
        'sku' => 'SKU-AJ-1', 'status' => 'active', 'is_default' => true,
    ]);

    $response = $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', [
        ...adjustmentPayload('inventory', $context['counterparty'], [
            'warehouse_id' => $context['warehouse']->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'unit_cost' => 4,
        ]),
        'amount' => null,
    ])->assertCreated()->assertJsonPath('data.amount', '12.00');

    $adjustmentId = $response->json('data.id');
    expect(ProductInventory::where('warehouse_id', $context['warehouse']->id)->where('variant_id', $variant->id)->value('quantity_available'))->toBe(3)
        ->and(InventoryMovement::where('reference_type', 'financial_adjustment')->where('reference_id', $adjustmentId)->value('quantity'))->toBe(3)
        ->and(JournalEntry::where('source_type', 'financial_adjustment')->where('source_id', $adjustmentId)->count())->toBe(1);

    $reversal = $this->actingAs($context['admin'])->postJson("/api/v1/reports/adjustments/{$adjustmentId}/reverse", [
        'idempotency_key' => (string) Str::uuid(),
        'business_datetime' => now()->toIso8601String(),
        'reason' => 'Reversión de prueba FIFO',
    ])->assertCreated();

    expect((float) $reversal->json('data.amount'))->toBe(12.0)
        ->and(ProductInventory::where('warehouse_id', $context['warehouse']->id)->where('variant_id', $variant->id)->value('quantity_available'))->toBe(0)
        ->and(FinancialAdjustment::findOrFail($adjustmentId)->status)->toBe('reversed');
});

it('rolls back the operational effect when journal posting fails', function (): void {
    $context = financialAdjustmentContext();
    $bankLedger = LedgerAccount::firstOrCreate(['code' => '1110'], ['name' => 'Banco', 'type' => 'asset', 'is_active' => true]);
    $bank = FinancialAccount::create([
        'name' => 'Cuenta rollback', 'type' => 'bank', 'institution_name' => 'Banco Nacional',
        'masked_number' => '•••• 7777', 'currency' => 'USD', 'opening_balance' => 100,
        'current_balance' => 100, 'ledger_account_id' => $bankLedger->id,
        'is_active' => true, 'is_legacy' => false, 'created_by' => $context['admin']->id,
    ]);
    $ledgerPosting = Mockery::mock(LedgerPostingService::class);
    $ledgerPosting->shouldReceive('postManualEntry')->once()->andThrow(new RuntimeException('Fallo contable simulado'));
    app()->instance(LedgerPostingService::class, $ledgerPosting);

    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', adjustmentPayload('bank', $context['counterparty'], [
        'financial_account_id' => $bank->id,
    ]))->assertServerError();

    expect((float) $bank->fresh()->current_balance)->toBe(100.0)
        ->and(FinancialAdjustment::count())->toBe(0)
        ->and(FinancialAccountMovement::count())->toBe(0)
        ->and(JournalEntry::where('source_type', 'financial_adjustment')->count())->toBe(0);
});

it('enforces configurable permission and the accounting lock date', function (): void {
    $context = financialAdjustmentContext();
    $manager = User::factory()->create([
        'role' => 'manager', 'is_active' => true,
        'can_manage_financial_adjustments' => false,
        'current_warehouse_id' => $context['warehouse']->id,
    ]);
    $manager->warehouses()->attach($context['warehouse']->id);

    $managerDebit = LedgerAccount::create(['code' => '1198', 'name' => 'Temporal', 'type' => 'asset', 'is_active' => true]);
    $this->actingAs($manager)
        ->postJson('/api/v1/reports/adjustments', adjustmentPayload('manual_correction', $context['counterparty'], [
            'affected_ledger_account_id' => $managerDebit->id,
        ]))
        ->assertForbidden();
    $manager->update(['can_manage_financial_adjustments' => true]);
    $this->actingAs($manager)
        ->postJson('/api/v1/reports/adjustments', adjustmentPayload('manual_correction', $context['counterparty'], [
            'affected_ledger_account_id' => $managerDebit->id,
        ]))
        ->assertCreated();

    Setting::set('accounting_lock_date', now('America/Panama')->toDateString(), 'string', 'accounting');
    $debit = LedgerAccount::create(['code' => '1197', 'name' => 'Bloqueada', 'type' => 'asset', 'is_active' => true]);
    $payload = adjustmentPayload('manual_correction', $context['counterparty'], ['affected_ledger_account_id' => $debit->id]);
    unset($payload['direction']);

    $this->actingAs($context['admin'])->postJson('/api/v1/reports/adjustments', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La fecha pertenece a un período contable cerrado.');

    expect(FinancialAdjustment::count())->toBe(1)->and(JournalEntry::where('source_type', 'financial_adjustment')->count())->toBe(1);
});
