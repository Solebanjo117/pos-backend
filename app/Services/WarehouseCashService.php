<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\PaymentMethod;
use App\Models\SalesOrderPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseCashBalance;
use App\Models\WarehouseCashEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseCashService
{
    public function availability(int $warehouseId, mixed $asOf = null): array
    {
        $baseline = WarehouseCashBalance::query()->where('warehouse_id', $warehouseId)->first();
        if (! $baseline) {
            return ['initialized' => false, 'balance' => null, 'warehouse_id' => $warehouseId];
        }
        $cutoff = $asOf ? \Carbon\Carbon::parse($asOf) : now();
        if ($cutoff->lt($baseline->cutoff_at)) {
            return ['initialized' => true, 'balance' => null, 'warehouse_id' => $warehouseId, 'baseline' => $baseline];
        }
        $changes = app(CashPositionReportService::class)->query($warehouseId, $baseline)->where('effective_at', '<=', $cutoff)->sum('amount');

        return ['initialized' => true, 'balance' => round((float) $baseline->opening_balance + (float) $changes, 2), 'warehouse_id' => $warehouseId, 'baseline' => $baseline];
    }

    public function reconcile(int $warehouseId, array $data, User $user): array
    {
        abort_unless($user->isAdmin(), 403);

        return DB::transaction(function () use ($warehouseId, $data, $user): array {
            Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);
            if (WarehouseCashBalance::query()->where('warehouse_id', $warehouseId)->exists()) {
                throw ValidationException::withMessages(['amount' => 'La conciliación inicial ya existe. Registre un ajuste para corregir el saldo.']);
            }
            $baseline = WarehouseCashBalance::query()->create(['warehouse_id' => $warehouseId, 'opening_balance' => $data['amount'], 'cutoff_at' => $data['cutoff_at'], 'starting_entry_id' => WarehouseCashEntry::query()->max('id') ?? 0, 'confirmed_by' => $user->id, 'reason' => $data['reason']]);
            CashMovement::query()->whereHas('posSession', fn ($query) => $query->where('warehouse_id', $warehouseId))->where('created_at', '>', $baseline->cutoff_at)->each(fn ($movement) => $this->capture($movement, false, true));
            \App\Models\AccountPaymentBatch::query()->whereHas('posSession', fn ($query) => $query->where('warehouse_id', $warehouseId))->where('created_at', '>', $baseline->cutoff_at)->each(fn ($batch) => $this->capture($batch, false, true));
            SalesOrderPayment::query()->where('created_at', '>', $baseline->cutoff_at)->where(function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId)->orWhereHas('salesOrder.items', fn ($items) => $items->where('warehouse_id', $warehouseId));
            })->each(function ($payment) use ($warehouseId): void {
                if ($this->salesPaymentWarehouse($payment) === $warehouseId) {
                    $this->capture($payment, false, true);
                }
            });
            activity('cash_reconciliation')->performedOn($baseline)->causedBy($user)->withProperties($data)->log('Saldo inicial conciliado');

            return $this->availability($warehouseId);
        });
    }

    public function minimumBalanceFrom(int $warehouseId, mixed $effectiveAt): ?float
    {
        $available = $this->availability($warehouseId, $effectiveAt);
        if ($available['balance'] === null) {
            return null;
        }
        $balance = $minimum = (float) $available['balance'];
        foreach (app(CashPositionReportService::class)->query($warehouseId, $available['baseline'])
            ->where('effective_at', '>', $effectiveAt)->where('effective_at', '<=', now())->orderBy('effective_at')->orderBy('id')->get(['amount']) as $entry) {
            $balance = round($balance + (float) $entry->amount, 2);
            $minimum = min($minimum, $balance);
        }

        return $minimum;
    }

    public function capture(Model $source, bool $reverse = false, bool $historical = false): void
    {
        if ($source instanceof CashMovement) {
            if ($source->subtype === 'sales_return_refund') {
                return;
            }
            $warehouseId = $source->posSession?->warehouse_id;
            $amount = (float) $source->amount * ($source->type === 'in' ? 1 : -1);
        } elseif ($source instanceof \App\Models\AccountPaymentBatch) {
            if ($source->direction !== 'incoming' || $source->liquidity_bucket !== 'cash') {
                return;
            }
            $warehouseId = $source->posSession?->warehouse_id;
            $amount = (float) $source->amount;
        } elseif ($source instanceof SalesOrderPayment) {
            if ($source->account_payment_batch_id) {
                return;
            }
            if (($source->liquidity_bucket ?? PaymentMethod::liquidityBucket($source->method)) !== 'cash') {
                return;
            }
            $warehouseId = $this->salesPaymentWarehouse($source);
            $amount = (float) $source->amount;
        } else {
            return;
        }
        if (! $warehouseId) {
            return;
        }
        $kind = $source instanceof CashMovement ? match (true) {
            $source->source_type === 'financial_transfer', $source->subtype === 'financial_transfer' => 'transfer',
            $source->subtype === 'purchase_payment' => 'purchase',
            $source->subtype === 'funding', $source->subtype === 'operational_payment' && $source->type === 'in' => 'funding',
            in_array($source->subtype, ['expense', 'operational_expense', 'operational_expense_payment'], true), $source->subtype === 'operational_payment' && $source->type === 'out' => 'expense',
            default => 'adjustment',
        } : ($amount < 0 ? 'return' : 'sale');
        $operationalPayment = $source instanceof CashMovement && in_array($source->subtype, ['operational_payment', 'operational_payment_reversal'], true)
            ? \App\Models\OperationalPayment::find($source->source_id) : null;
        if ($operationalPayment) {
            $kind = $operationalPayment->direction === 'in' ? 'funding' : 'expense';
        }
        $url = $source instanceof SalesOrderPayment ? ($source->sales_return_id ? '/returns/'.$source->sales_return_id : '/sales-orders/'.$source->sales_order_id) : ($kind === 'transfer' ? '/finance/transfers/'.$source->source_id : null);
        $this->record((int) $warehouseId, $source->getMorphClass(), (int) $source->id, $reverse ? -$amount : $amount, $reverse ? 'reversal' : 'main', $reverse ? now() : $source->created_at, $source->created_by ?? $source->user_id ?? auth()->id(), $source->reason, enforceBalance: ! $historical, context: [
            'reporting_kind' => $kind, 'reporting_reference' => $source->reference_number,
            'reporting_method' => $operationalPayment?->method ?? $source->method ?? $source->payment_method ?? 'cash',
            'reporting_document_url' => $url, 'reporting_session_id' => $source->pos_session_id,
            'reporting_business_datetime' => $reverse ? now() : ($source->business_datetime ?? $source->paid_at ?? $source->created_at),
        ]);
    }

    public function salesPaymentWarehouse(SalesOrderPayment $payment): ?int
    {
        if ($payment->warehouse_id) {
            return (int) $payment->warehouse_id;
        }
        $warehouseId = $payment->salesReturn?->warehouse_id
            ?? \App\Models\PosSession::query()->find($payment->pos_session_id)?->warehouse_id
            ?? $payment->salesOrder?->posSession?->warehouse_id;
        if ($warehouseId) {
            return (int) $warehouseId;
        }
        $warehouses = $payment->salesOrder?->items()->whereNotNull('warehouse_id')->distinct()->pluck('warehouse_id');

        return $warehouses?->count() === 1 ? (int) $warehouses->first() : null;
    }

    public function record(int $warehouseId, string $sourceType, int $sourceId, float $amount, string $line = 'main', mixed $occurredAt = null, ?int $userId = null, ?string $reason = null, bool $enforceBalance = true, array $context = []): WarehouseCashEntry
    {
        return DB::transaction(function () use ($warehouseId, $sourceType, $sourceId, $amount, $line, $occurredAt, $userId, $reason, $enforceBalance, $context): WarehouseCashEntry {
            Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

            $existing = WarehouseCashEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_line_key', $line)->first();
            if ($existing) {
                return $existing;
            }
            $available = $this->availability($warehouseId);
            if ($enforceBalance && DB::transactionLevel() > 1 && $amount < 0 && $available['initialized'] && round($available['balance'] + $amount, 2) < 0) {
                throw ValidationException::withMessages(['amount' => 'El efectivo acumulado de la sucursal no alcanza para esta salida.']);
            }

            return WarehouseCashEntry::query()->firstOrCreate(['source_type' => $sourceType, 'source_id' => $sourceId, 'source_line_key' => $line], [...$context, 'warehouse_id' => $warehouseId, 'amount' => round($amount, 2), 'occurred_at' => $occurredAt ?? now(), 'created_by' => $userId, 'reason' => $reason]);
        });
    }
}
