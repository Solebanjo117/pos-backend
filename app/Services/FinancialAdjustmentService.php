<?php

namespace App\Services;

use App\Models\AccountLedgerEntry;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\FinancialAccount;
use App\Models\FinancialAdjustment;
use App\Models\LedgerAccount;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinancialAdjustmentService
{
    private const TIMEZONE = 'America/Panama';

    public function __construct(
        private DocumentSequenceService $documentSequenceService,
        private FinancialAccountMovementService $financialMovementService,
        private FinancialSnapshotService $snapshotService,
        private InventoryFifoService $inventoryFifoService,
        private LedgerPostingService $ledgerPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{adjustment: FinancialAdjustment, replayed: bool}
     */
    public function create(array $data, User $user, ?string $ip = null): array
    {
        $this->ensureAuthorized($user);
        $requestHash = $this->requestHash($data);
        $existing = FinancialAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing) {
            return $this->idempotentResult($existing, $requestHash);
        }

        try {
            $adjustment = DB::transaction(function () use ($data, $user, $ip, $requestHash): FinancialAdjustment {
                $businessDatetime = CarbonImmutable::parse($data['business_datetime'], self::TIMEZONE);
                $this->guardOpenPeriod($businessDatetime);

                $adjustment = FinancialAdjustment::create([
                    ...$data,
                    'folio' => $this->documentSequenceService->nextFinancialAdjustmentFolio($businessDatetime),
                    'request_hash' => $requestHash,
                    'direction' => $data['type'] === FinancialAdjustment::TYPE_MANUAL_CORRECTION
                        ? FinancialAdjustment::DIRECTION_INCREASE
                        : $data['direction'],
                    'status' => FinancialAdjustment::STATUS_COMPLETED,
                    'is_legacy' => false,
                    'amount' => round((float) ($data['amount'] ?? 0), 2),
                    'adjustment_date' => $businessDatetime->toDateString(),
                    'business_datetime' => $businessDatetime->utc(),
                    'timezone' => self::TIMEZONE,
                    'created_ip' => $ip,
                    'user_id' => $user->id,
                ]);

                $this->applyOperationalEffect($adjustment, $user);
                $this->postJournal($adjustment, $user);
                $this->snapshotService->rebuildRange(
                    $adjustment->adjustment_date->toDateString(),
                    now(self::TIMEZONE)->toDateString(),
                    $adjustment->warehouse_id,
                    true,
                );

                activity('financial_adjustments')
                    ->performedOn($adjustment)
                    ->causedBy($user)
                    ->withProperties([
                        'folio' => $adjustment->folio,
                        'type' => $adjustment->type,
                        'direction' => $adjustment->direction,
                        'amount' => (float) $adjustment->amount,
                        'ip' => $ip,
                    ])
                    ->log('Ajuste financiero completado');

                return $adjustment;
            }, 3);
        } catch (QueryException $exception) {
            $existing = FinancialAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->idempotentResult($existing, $requestHash);
        }

        return ['adjustment' => $this->loadAdjustment($adjustment), 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{adjustment: FinancialAdjustment, replayed: bool}
     */
    public function reverse(FinancialAdjustment $original, array $data, User $user, ?string $ip = null): array
    {
        $this->ensureAuthorized($user);

        if ($original->is_legacy) {
            throw new InvalidArgumentException('Los ajustes heredados no pueden reversarse automáticamente porque no tienen contrapartida completa.');
        }

        return DB::transaction(function () use ($original, $data, $user, $ip): array {
            $locked = FinancialAdjustment::query()->lockForUpdate()->findOrFail($original->id);
            if ($locked->status !== FinancialAdjustment::STATUS_COMPLETED || $locked->reversal_of_id || $locked->reversed_by_adjustment_id) {
                throw new InvalidArgumentException('Este ajuste ya fue reversado o no puede reversarse.');
            }

            $payload = [
                'idempotency_key' => $data['idempotency_key'],
                'type' => $locked->type,
                'direction' => $locked->direction === FinancialAdjustment::DIRECTION_INCREASE
                    ? FinancialAdjustment::DIRECTION_DECREASE
                    : FinancialAdjustment::DIRECTION_INCREASE,
                'amount' => (float) $locked->amount,
                'reason' => $data['reason'],
                'reference' => 'Reversa de '.$locked->folio,
                'notes' => trim('Reversa de '.$locked->folio.'. '.($data['notes'] ?? '')),
                'business_datetime' => $data['business_datetime'],
                'warehouse_id' => $locked->warehouse_id,
                'pos_session_id' => $locked->pos_session_id,
                'financial_account_id' => $locked->financial_account_id,
                'affected_ledger_account_id' => $locked->affected_ledger_account_id,
                'counterparty_ledger_account_id' => $locked->counterparty_ledger_account_id,
                'customer_id' => $locked->customer_id,
                'sales_order_id' => $locked->sales_order_id,
                'supplier_id' => $locked->supplier_id,
                'purchase_order_id' => $locked->purchase_order_id,
                'product_id' => $locked->product_id,
                'variant_id' => $locked->variant_id,
                'quantity' => $locked->quantity,
                'unit_cost' => $locked->unit_cost,
                'reversal_of_id' => $locked->id,
            ];

            if ($locked->type === FinancialAdjustment::TYPE_MANUAL_CORRECTION) {
                $payload['affected_ledger_account_id'] = $locked->counterparty_ledger_account_id;
                $payload['counterparty_ledger_account_id'] = $locked->affected_ledger_account_id;
            }

            $result = $this->create($payload, $user, $ip);
            $locked->update([
                'status' => FinancialAdjustment::STATUS_REVERSED,
                'reversed_by_adjustment_id' => $result['adjustment']->id,
            ]);

            return $result;
        }, 3);
    }

    public function createDetail(FinancialAdjustment $adjustment): FinancialAdjustment
    {
        return $this->loadAdjustment($adjustment);
    }

    private function applyOperationalEffect(FinancialAdjustment $adjustment, User $user): void
    {
        match ($adjustment->type) {
            FinancialAdjustment::TYPE_CASH => $this->applyCash($adjustment, $user),
            FinancialAdjustment::TYPE_BANK => $this->applyBank($adjustment, $user),
            FinancialAdjustment::TYPE_INVENTORY => $this->applyInventory($adjustment, $user),
            FinancialAdjustment::TYPE_INVESTMENT => $this->applyInvestment($adjustment, $user),
            FinancialAdjustment::TYPE_ACCOUNTS_RECEIVABLE => $this->applyReceivable($adjustment, $user),
            FinancialAdjustment::TYPE_ACCOUNTS_PAYABLE => $this->applyPayable($adjustment, $user),
            default => $this->applyLedgerOnly($adjustment),
        };
    }

    private function applyCash(FinancialAdjustment $adjustment, User $user): void
    {
        $session = PosSession::query()->with('warehouse')->lockForUpdate()->findOrFail($adjustment->pos_session_id);
        if ($session->status !== 'open') {
            throw new InvalidArgumentException('La sesión de caja está cerrada.');
        }

        $balanceBefore = $this->cashBalance($session);
        $balanceAfter = round($balanceBefore + $adjustment->signedAmount(), 2);
        if ($balanceAfter < 0) {
            throw new InvalidArgumentException('La sesión de caja no tiene saldo suficiente.');
        }

        $movement = CashMovement::create([
            'pos_session_id' => $session->id,
            'user_id' => $user->id,
            'type' => $adjustment->direction === FinancialAdjustment::DIRECTION_INCREASE ? 'in' : 'out',
            'subtype' => 'financial_adjustment',
            'amount' => (float) $adjustment->amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reason' => $adjustment->reason,
            'reference_number' => $adjustment->reference ?: $adjustment->folio,
            'payment_method' => 'cash',
            'notes' => $adjustment->notes,
            'source_type' => 'financial_adjustment',
            'source_id' => $adjustment->id,
            'business_datetime' => $adjustment->business_datetime,
        ]);

        $cashLedger = $this->accountByCode('1100', 'Efectivo');
        $adjustment->update([
            'warehouse_id' => $adjustment->warehouse_id ?: $session->warehouse_id,
            'affected_ledger_account_id' => $cashLedger->id,
            'cash_movement_id' => $movement->id,
            'before_values' => ['cash_balance' => $balanceBefore],
            'after_values' => ['cash_balance' => $balanceAfter],
        ]);
    }

    private function applyBank(FinancialAdjustment $adjustment, User $user): void
    {
        $account = FinancialAccount::query()->with('ledgerAccount')->lockForUpdate()->findOrFail($adjustment->financial_account_id);
        if (! $account->is_active || $account->is_legacy || $account->type !== 'bank') {
            throw new InvalidArgumentException('Seleccione una cuenta bancaria activa y específica.');
        }
        if (! $account->institution_name || ! $account->masked_number || ! $account->ledgerAccount) {
            throw new InvalidArgumentException('La cuenta bancaria debe tener banco, identificador y cuenta contable configurados.');
        }

        $movement = $this->financialMovementService->record(
            $account,
            $adjustment->direction === FinancialAdjustment::DIRECTION_INCREASE ? 'in' : 'out',
            (float) $adjustment->amount,
            [
                'source_type' => 'financial_adjustment',
                'source_id' => $adjustment->id,
                'source_line_key' => 'main',
                'business_datetime' => $adjustment->business_datetime,
                'reference' => $adjustment->reference ?: $adjustment->folio,
                'notes' => $adjustment->notes,
                'created_by' => $user->id,
            ],
        );

        $adjustment->update([
            'affected_ledger_account_id' => $account->ledger_account_id,
            'financial_account_movement_id' => $movement->id,
            'before_values' => ['financial_account_balance' => (float) $movement->balance_before],
            'after_values' => ['financial_account_balance' => (float) $movement->balance_after],
        ]);
    }

    private function applyInvestment(FinancialAdjustment $adjustment, User $user): void
    {
        $counterparty = LedgerAccount::query()->findOrFail($adjustment->counterparty_ledger_account_id);
        if ($counterparty->type !== 'equity') {
            throw new InvalidArgumentException('La contrapartida de una inversión debe ser una cuenta de patrimonio.');
        }

        if ((bool) $adjustment->pos_session_id === (bool) $adjustment->financial_account_id) {
            throw new InvalidArgumentException('Seleccione exactamente una cuenta de efectivo o banco para el aporte.');
        }

        if ($adjustment->financial_account_id) {
            $this->applyBank($adjustment, $user);

            return;
        }

        $this->applyCash($adjustment, $user);
    }

    private function applyInventory(FinancialAdjustment $adjustment, User $user): void
    {
        if ($adjustment->variant_id) {
            $belongs = ProductVariant::query()
                ->whereKey($adjustment->variant_id)
                ->where('product_id', $adjustment->product_id)
                ->exists();
            if (! $belongs) {
                throw new InvalidArgumentException('La variante no pertenece al producto seleccionado.');
            }
        }

        $common = [
            'product_id' => $adjustment->product_id,
            'variant_id' => $adjustment->variant_id,
            'warehouse_id' => $adjustment->warehouse_id,
            'quantity' => $adjustment->quantity,
            'reference_type' => 'financial_adjustment',
            'reference_id' => $adjustment->id,
            'source_type' => 'financial_adjustment',
            'source_id' => $adjustment->id,
            'reason' => $adjustment->reason,
            'notes' => $adjustment->notes,
            'user_id' => $user->id,
        ];

        $original = $adjustment->reversal_of_id
            ? FinancialAdjustment::query()->with('inventoryMovement')->findOrFail($adjustment->reversal_of_id)
            : null;

        if ($original?->inventoryMovement) {
            if ($original->direction === FinancialAdjustment::DIRECTION_INCREASE) {
                $movement = $this->inventoryFifoService->reverseInboundMovement($original->inventoryMovement, [
                    ...$common,
                    'business_datetime' => $adjustment->business_datetime,
                ]);
            } else {
                $movement = $this->inventoryFifoService->restoreFromOutboundMovement($original->inventoryMovement, [
                    ...$common,
                    'business_datetime' => $adjustment->business_datetime,
                ]);
            }
            $amount = round((float) $original->amount, 2);
            $unitCost = round((float) $original->unit_cost, 4);
        } elseif ($adjustment->direction === FinancialAdjustment::DIRECTION_INCREASE) {
            $unitCost = round((float) $adjustment->unit_cost, 4);
            $movement = $this->inventoryFifoService->receiveStock([
                ...$common,
                'unit_cost_base' => $unitCost,
                'landed_unit_cost' => $unitCost,
                'received_at' => $adjustment->business_datetime,
            ]);
            $amount = round((int) $adjustment->quantity * $unitCost, 2);
        } else {
            $result = $this->inventoryFifoService->consumeStock([...$common, 'strict' => true]);
            $movement = $result['movement'];
            $amount = round((float) $result['allocations']->sum('total_cost'), 2);
            $unitCost = round((float) ($result['weighted_unit_cost'] ?? 0), 4);
        }

        if (! $movement || $amount <= 0) {
            throw new InvalidArgumentException('El ajuste de inventario debe tener una valoración mayor que cero.');
        }

        $inventoryLedger = $this->accountByCode('1300', 'Inventario');
        $adjustment->update([
            'amount' => $amount,
            'unit_cost' => $unitCost,
            'affected_ledger_account_id' => $inventoryLedger->id,
            'inventory_movement_id' => $movement->id,
            'before_values' => ['quantity' => (int) $movement->quantity_before],
            'after_values' => ['quantity' => (int) $movement->quantity_after],
        ]);
    }

    private function applyReceivable(FinancialAdjustment $adjustment, User $user): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($adjustment->customer_id);
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($adjustment->sales_order_id);
        if ((int) $order->customer_id !== (int) $customer->id) {
            throw new InvalidArgumentException('La venta no pertenece al cliente seleccionado.');
        }

        $before = $this->salesOrderBalance($order, $adjustment->id);
        $after = round($before + $adjustment->signedAmount(), 2);
        $maximum = round((float) $order->total - (float) $order->total_returned, 2);
        if ($after < 0 || $after > $maximum) {
            throw new InvalidArgumentException('El ajuste dejaría el saldo de CxC fuera del rango permitido.');
        }

        $customerBefore = round((float) $customer->current_balance, 2);
        $customerAfter = round($customerBefore + $adjustment->signedAmount(), 2);
        $customer->update(['current_balance' => $customerAfter]);

        CustomerTransaction::create([
            'customer_id' => $customer->id,
            'type' => 'adjustment',
            'reference_type' => 'financial_adjustment',
            'reference_id' => $adjustment->id,
            'amount' => $adjustment->signedAmount(),
            'balance_before' => $customerBefore,
            'balance_after' => $customerAfter,
            'description' => $adjustment->folio.' — '.$adjustment->reason,
            'transaction_date' => $adjustment->business_datetime,
            'created_by' => $user->id,
        ]);

        $ledger = $this->accountByCode('1200', 'Cuentas por cobrar');
        $entry = $this->createPartyLedgerEntry($adjustment, 'customer', $customer->id, $order->id, $order->order_number, $after, $user);
        $adjustment->update([
            'affected_ledger_account_id' => $ledger->id,
            'account_ledger_entry_id' => $entry->id,
            'before_values' => ['document_balance' => $before, 'customer_balance' => $customerBefore],
            'after_values' => ['document_balance' => $after, 'customer_balance' => $customerAfter],
        ]);
    }

    private function applyPayable(FinancialAdjustment $adjustment, User $user): void
    {
        $supplier = Supplier::query()->lockForUpdate()->findOrFail($adjustment->supplier_id);
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($adjustment->purchase_order_id);
        if ((int) $order->supplier_id !== (int) $supplier->id) {
            throw new InvalidArgumentException('La compra no pertenece al proveedor seleccionado.');
        }

        $before = $this->purchaseOrderBalance($order, $adjustment->id);
        $after = round($before + $adjustment->signedAmount(), 2);
        if ($after < 0 || $after > round((float) $order->total, 2)) {
            throw new InvalidArgumentException('El ajuste dejaría el saldo de CxP fuera del rango permitido.');
        }

        $ledger = $this->accountByCode('2100', 'Cuentas por pagar');
        $entry = $this->createPartyLedgerEntry($adjustment, 'supplier', $supplier->id, $order->id, $order->po_number, $after, $user);
        $adjustment->update([
            'affected_ledger_account_id' => $ledger->id,
            'account_ledger_entry_id' => $entry->id,
            'before_values' => ['document_balance' => $before],
            'after_values' => ['document_balance' => $after],
        ]);
    }

    private function applyLedgerOnly(FinancialAdjustment $adjustment): void
    {
        $account = LedgerAccount::query()->lockForUpdate()->findOrFail($adjustment->affected_ledger_account_id);
        if (! $account->is_active) {
            throw new InvalidArgumentException('La cuenta afectada está inactiva.');
        }
        if ($adjustment->type === FinancialAdjustment::TYPE_SALES && $account->type !== 'income') {
            throw new InvalidArgumentException('Los ajustes de ventas deben afectar una cuenta de ingresos.');
        }
        if ($adjustment->type === FinancialAdjustment::TYPE_EXPENSES && $account->type !== 'expense') {
            throw new InvalidArgumentException('Los ajustes de gastos deben afectar una cuenta de gastos.');
        }
        if ($adjustment->type === FinancialAdjustment::TYPE_PURCHASES && ! in_array($account->type, ['asset', 'expense'], true)) {
            throw new InvalidArgumentException('Los ajustes de compras deben afectar una cuenta de activo o gasto.');
        }
        $adjustment->update([
            'before_values' => ['ledger_balance' => $this->ledgerBalance($account)],
        ]);
    }

    private function postJournal(FinancialAdjustment $adjustment, User $user): void
    {
        $adjustment->refresh();
        $affected = LedgerAccount::query()->lockForUpdate()->findOrFail($adjustment->affected_ledger_account_id);
        $counterparty = LedgerAccount::query()->lockForUpdate()->findOrFail($adjustment->counterparty_ledger_account_id);
        if (! $affected->is_active || ! $counterparty->is_active) {
            throw new InvalidArgumentException('Las cuentas contables deben estar activas.');
        }
        if ($affected->is($counterparty)) {
            throw new InvalidArgumentException('La cuenta afectada y la contrapartida deben ser diferentes.');
        }

        $debitAffected = $adjustment->type === FinancialAdjustment::TYPE_MANUAL_CORRECTION
            || $this->directionUsesDebit($affected, $adjustment->direction);

        $entry = $this->ledgerPostingService->postManualEntry([
            'source_type' => 'financial_adjustment',
            'source_id' => $adjustment->id,
            'warehouse_id' => $adjustment->warehouse_id,
            'occurred_at' => $adjustment->business_datetime,
            'memo' => $adjustment->folio.' — '.$adjustment->reason,
            'created_by' => $user->id,
            'lines' => [
                $this->journalLine($affected, $debitAffected, (float) $adjustment->amount, $adjustment),
                $this->journalLine($counterparty, ! $debitAffected, (float) $adjustment->amount, $adjustment),
            ],
        ]);

        $afterValues = $adjustment->after_values ?? [];
        $afterValues['ledger_balance'] = $this->ledgerBalance($affected);
        $adjustment->update(['journal_entry_id' => $entry->id, 'after_values' => $afterValues]);
    }

    private function createPartyLedgerEntry(
        FinancialAdjustment $adjustment,
        string $accountType,
        int $accountId,
        int $documentId,
        string $documentNumber,
        float $balanceAfter,
        User $user,
    ): AccountLedgerEntry {
        $increase = $adjustment->direction === FinancialAdjustment::DIRECTION_INCREASE;

        return AccountLedgerEntry::create([
            'account_type' => $accountType,
            'account_id' => $accountId,
            'movement_type' => 'financial_adjustment',
            'document_type' => $accountType === 'customer' ? 'sales_order' : 'purchase_order',
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'debit' => $accountType === 'customer' ? ($increase ? $adjustment->amount : 0) : ($increase ? 0 : $adjustment->amount),
            'credit' => $accountType === 'customer' ? ($increase ? 0 : $adjustment->amount) : ($increase ? $adjustment->amount : 0),
            'balance_after' => $balanceAfter,
            'occurred_at' => $adjustment->business_datetime,
            'reference_number' => $adjustment->reference ?: $adjustment->folio,
            'notes' => $adjustment->notes,
            'created_by' => $user->id,
        ]);
    }

    private function directionUsesDebit(LedgerAccount $account, string $direction): bool
    {
        $normalDebit = in_array($account->type, ['asset', 'expense'], true);

        return $direction === FinancialAdjustment::DIRECTION_INCREASE ? $normalDebit : ! $normalDebit;
    }

    /** @return array<string, mixed> */
    private function journalLine(LedgerAccount $account, bool $debit, float $amount, FinancialAdjustment $adjustment): array
    {
        $thirdPartyType = $adjustment->customer_id ? 'customer' : ($adjustment->supplier_id ? 'supplier' : null);
        $thirdPartyId = $adjustment->customer_id ?: $adjustment->supplier_id;

        return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'third_party_type' => $thirdPartyType,
            'third_party_id' => $thirdPartyId,
            'debit' => $debit ? $amount : 0,
            'credit' => $debit ? 0 : $amount,
            'metadata' => ['adjustment_id' => $adjustment->id, 'folio' => $adjustment->folio],
        ];
    }

    private function salesOrderBalance(SalesOrder $order, ?int $excludingId = null): float
    {
        $base = max(0, (float) $order->total - (float) $order->total_returned - (float) $order->amount_paid);

        return round($base + $this->documentAdjustmentTotal('sales_order_id', $order->id, $excludingId), 2);
    }

    private function purchaseOrderBalance(PurchaseOrder $order, ?int $excludingId = null): float
    {
        $base = max(0, (float) $order->total - (float) $order->amount_paid);

        return round($base + $this->documentAdjustmentTotal('purchase_order_id', $order->id, $excludingId), 2);
    }

    private function documentAdjustmentTotal(string $column, int $id, ?int $excludingId): float
    {
        return (float) FinancialAdjustment::query()
            ->where($column, $id)
            ->where('is_legacy', false)
            ->when($excludingId, fn ($query) => $query->whereKeyNot($excludingId))
            ->get(['amount', 'direction'])
            ->sum(fn (FinancialAdjustment $item): float => $item->signedAmount());
    }

    private function cashBalance(PosSession $session): float
    {
        $in = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'in')->sum('amount');
        $out = (float) CashMovement::query()->where('pos_session_id', $session->id)->where('type', 'out')->sum('amount');

        return round((float) $session->opening_amount + $in - $out, 2);
    }

    private function ledgerBalance(LedgerAccount $account): float
    {
        $debit = (float) $account->journalLines()->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))->sum('debit');
        $credit = (float) $account->journalLines()->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))->sum('credit');

        return round(in_array($account->type, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit, 2);
    }

    private function accountByCode(string $code, string $name): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => substr($code, 0, 1) === '2' ? 'liability' : 'asset', 'is_active' => true],
        );
    }

    private function guardOpenPeriod(CarbonImmutable $businessDatetime): void
    {
        $lockDate = trim((string) Setting::get('accounting_lock_date', ''));
        if ($lockDate !== '' && $businessDatetime->setTimezone(self::TIMEZONE)->toDateString() <= $lockDate) {
            throw new InvalidArgumentException('La fecha pertenece a un período contable cerrado.');
        }
    }

    private function ensureAuthorized(User $user): void
    {
        if (! $user->canManageFinancialAdjustments()) {
            throw new InvalidArgumentException('No tiene permiso para administrar ajustes financieros.');
        }
    }

    /** @param array<string, mixed> $data */
    private function requestHash(array $data): string
    {
        $normalized = collect($data)->except(['reversal_of_id'])->sortKeys()->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /** @return array{adjustment: FinancialAdjustment, replayed: bool} */
    private function idempotentResult(FinancialAdjustment $existing, string $requestHash): array
    {
        if (! $existing->request_hash || ! hash_equals($existing->request_hash, $requestHash)) {
            throw new InvalidArgumentException('La clave de idempotencia ya fue utilizada con otros datos.');
        }

        return ['adjustment' => $this->loadAdjustment($existing), 'replayed' => true];
    }

    private function loadAdjustment(FinancialAdjustment $adjustment): FinancialAdjustment
    {
        return $adjustment->load([
            'user:id,name', 'warehouse:id,name', 'affectedLedgerAccount:id,code,name,type',
            'counterpartyLedgerAccount:id,code,name,type', 'financialAccount:id,name,type,institution_name,masked_number',
            'posSession:id,warehouse_id,status', 'customer:id,name', 'supplier:id,name',
            'salesOrder', 'purchaseOrder',
            'product:id,name', 'variant:id,name', 'journalEntry.lines.ledgerAccount',
            'cashMovement', 'financialAccountMovement', 'inventoryMovement', 'accountLedgerEntry',
            'reversalOf:id,folio,type', 'reversedBy:id,folio,type',
        ]);
    }
}
