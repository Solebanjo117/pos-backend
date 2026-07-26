<?php

use App\Models\CashMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialTransfer;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\OperationalExpense;
use App\Models\PosSession;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function financialTransferContext(): array
{
    $warehouse = Warehouse::create([
        'code' => 'W-TRF-'.fake()->unique()->randomNumber(4),
        'name' => 'Almacén Transferencias',
    ]);
    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $cashier = User::factory()->create([
        'role' => 'cashier',
        'is_active' => true,
        'current_warehouse_id' => $warehouse->id,
    ]);
    $ledger = LedgerAccount::query()->where('code', '1110')->firstOrFail();
    $source = FinancialAccount::create([
        'name' => 'Banco origen',
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 1000,
        'current_balance' => 1000,
        'ledger_account_id' => $ledger->id,
        'created_by' => $admin->id,
    ]);
    $destination = FinancialAccount::create([
        'name' => 'Banco destino',
        'type' => 'bank',
        'currency' => 'USD',
        'opening_balance' => 100,
        'current_balance' => 100,
        'ledger_account_id' => $ledger->id,
        'created_by' => $admin->id,
    ]);

    return compact('warehouse', 'admin', 'manager', 'cashier', 'source', 'destination');
}

function transferPayload(FinancialAccount $source, FinancialAccount $destination, ?string $key = null): array
{
    return [
        'idempotency_key' => $key ?? (string) Str::uuid(),
        'from_endpoint_type' => 'financial_account',
        'from_endpoint_id' => $source->id,
        'to_endpoint_type' => 'financial_account',
        'to_endpoint_id' => $destination->id,
        'amount' => 125.50,
        'business_datetime' => '2026-07-23 10:30:00',
        'reference' => 'ACH-900',
        'notes' => 'Fondeo operativo',
    ];
}

it('completes an atomic bank transfer with paired movements and a journal entry', function (): void {
    $context = financialTransferContext();

    $response = $this->actingAs($context['admin'])
        ->postJson('/api/v1/financial-transfers', transferPayload($context['source'], $context['destination']))
        ->assertCreated()
        ->assertJsonPath('data.folio', 'TRF-0000000001')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.from_account.name', 'Banco origen')
        ->assertJsonPath('data.to_account.name', 'Banco destino');

    $transferId = $response->json('data.id');

    expect((float) $context['source']->fresh()->current_balance)->toBe(874.5)
        ->and((float) $context['destination']->fresh()->current_balance)->toBe(225.5)
        ->and(FinancialAccountMovement::query()->where('source_id', $transferId)->count())->toBe(2)
        ->and(JournalEntry::query()->where('source_type', 'financial_transfer')->where('source_id', $transferId)->count())->toBe(1);
});

it('is idempotent and rejects a conflicting replay', function (): void {
    $context = financialTransferContext();
    $key = (string) Str::uuid();
    $payload = transferPayload($context['source'], $context['destination'], $key);

    $this->actingAs($context['manager'])->postJson('/api/v1/financial-transfers', $payload)->assertCreated();
    $this->actingAs($context['manager'])->postJson('/api/v1/financial-transfers', $payload)
        ->assertOk()
        ->assertJsonPath('replayed', true);
    $this->actingAs($context['manager'])->postJson('/api/v1/financial-transfers', [
        ...$payload,
        'amount' => 10,
    ])->assertConflict();

    expect(FinancialTransfer::count())->toBe(1)
        ->and(FinancialAccountMovement::count())->toBe(2);
});

it('moves money between an open cash session and a financial account', function (): void {
    $context = financialTransferContext();
    $session = PosSession::create([
        'user_id' => $context['cashier']->id,
        'warehouse_id' => $context['warehouse']->id,
        'opened_at' => now(),
        'opening_amount' => 300,
        'status' => 'open',
    ]);

    $this->actingAs($context['manager'])->postJson('/api/v1/financial-transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'from_endpoint_type' => 'pos_session',
        'from_endpoint_id' => $session->id,
        'to_endpoint_type' => 'financial_account',
        'to_endpoint_id' => $context['destination']->id,
        'amount' => 40,
    ])->assertCreated();

    expect(CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'out')->sum('amount'))->toEqual(40)
        ->and((float) $context['destination']->fresh()->current_balance)->toBe(140.0);

    $options = $this->actingAs($context['manager'])
        ->getJson('/api/v1/money-accounts/options?type=cash')
        ->assertOk()
        ->json('data');

    expect($options)->toHaveCount(1)
        ->and((float) $options[0]['balance'])->toBe(260.0);
});

it('rejects invalid endpoints and insufficient balances without partial writes', function (): void {
    $context = financialTransferContext();

    $this->actingAs($context['admin'])->postJson('/api/v1/financial-transfers', [
        ...transferPayload($context['source'], $context['source']),
        'amount' => 100,
    ])->assertUnprocessable();

    $this->actingAs($context['admin'])->postJson('/api/v1/financial-transfers', [
        ...transferPayload($context['source'], $context['destination']),
        'amount' => 5000,
    ])->assertUnprocessable();

    expect(FinancialTransfer::count())->toBe(0)
        ->and(FinancialAccountMovement::count())->toBe(0)
        ->and((float) $context['source']->fresh()->current_balance)->toBe(1000.0);
});

it('reverses with a new folio and restores both balances', function (): void {
    $context = financialTransferContext();
    $transfer = $this->actingAs($context['admin'])
        ->postJson('/api/v1/financial-transfers', transferPayload($context['source'], $context['destination']))
        ->assertCreated()
        ->json('data');

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/financial-transfers/{$transfer['id']}/reverse", [
            'reason' => 'Cuenta de destino equivocada',
        ])
        ->assertCreated()
        ->assertJsonPath('data.folio', 'TRF-0000000002')
        ->assertJsonPath('data.reversal_of.folio', 'TRF-0000000001');

    expect(FinancialTransfer::query()->findOrFail($transfer['id'])->status)->toBe('reversed')
        ->and((float) $context['source']->fresh()->current_balance)->toBe(1000.0)
        ->and((float) $context['destination']->fresh()->current_balance)->toBe(100.0)
        ->and(FinancialTransfer::count())->toBe(2);
});

it('enforces transfer and account administration roles', function (): void {
    $context = financialTransferContext();

    $this->actingAs($context['cashier'])
        ->getJson('/api/v1/financial-transfers')
        ->assertForbidden();

    $this->actingAs($context['manager'])
        ->postJson('/api/v1/financial-accounts', [
            'name' => 'No permitida',
            'type' => 'bank',
            'ledger_account_id' => LedgerAccount::query()->where('code', '1110')->value('id'),
        ])
        ->assertForbidden();

    $this->actingAs($context['manager'])
        ->postJson('/api/v1/financial-transfers', transferPayload($context['source'], $context['destination']))
        ->assertCreated();
});

it('stores receipts privately and protects their download', function (): void {
    Storage::fake('local');
    $context = financialTransferContext();

    $transfer = $this->actingAs($context['admin'])
        ->post('/api/v1/financial-transfers', [
            ...transferPayload($context['source'], $context['destination']),
            'receipt' => UploadedFile::fake()->image('comprobante.png'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.has_receipt', true)
        ->json('data');

    $record = FinancialTransfer::query()->findOrFail($transfer['id']);
    Storage::disk('local')->assertExists($record->receipt_path);

    auth()->logout();
    $this->get("/api/v1/financial-transfers/{$record->id}/receipt")
        ->assertUnauthorized();
    $this->actingAs($context['manager'])
        ->get("/api/v1/financial-transfers/{$record->id}/receipt")
        ->assertOk();
});

it('backfills legacy bank activity idempotently and supports dry runs', function (): void {
    $context = financialTransferContext();

    OperationalExpense::withoutEvents(fn () => OperationalExpense::create([
        'expense_date' => '2026-07-01',
        'category' => 'software',
        'description' => 'Histórico sin cuenta',
        'amount' => 50,
        'payment_method' => 'transfer',
        'status' => 'paid',
        'affects_cash_flow' => true,
        'affects_income_statement' => true,
        'created_by' => $context['admin']->id,
    ]));

    $this->artisan('financial-accounts:backfill')->assertSuccessful();
    expect(FinancialAccountMovement::count())->toBe(0);

    $this->artisan('financial-accounts:backfill', ['--apply' => true])->assertSuccessful();
    $this->artisan('financial-accounts:backfill', ['--apply' => true])->assertSuccessful();

    $legacy = FinancialAccount::query()->where('is_legacy', true)->firstOrFail();
    expect(FinancialAccountMovement::count())->toBe(1)
        ->and((float) $legacy->fresh()->current_balance)->toBe(-50.0);
});
