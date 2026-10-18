<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\OperationalPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalPaymentService
{
    public function normalize(array $payments, float $total, string $context): array
    {
        if ($payments === [] || count($payments) > 20) {
            throw ValidationException::withMessages(['payments' => 'Seleccione entre uno y veinte pagos.']);
        }
        $result = [];
        foreach (array_values($payments) as $index => $payment) {
            $method = PaymentMethod::query()->active()->notArchived()->forContext($context)->where('code', $payment['method'] ?? '')->first();
            $bucket = $method ? PaymentMethod::liquidityBucket($method->code) : null;
            $amount = (float) ($payment['amount'] ?? 0);
            if (! $method || ! in_array($bucket, ['cash', 'bank'], true) || ! is_finite($amount) || $amount <= 0 || abs($amount - round($amount, 2)) > 0.000001) {
                throw ValidationException::withMessages(["payments.$index" => 'Seleccione un método monetario activo y un importe válido con dos decimales.']);
            }
            $reference = trim((string) ($payment['reference_number'] ?? $payment['reference'] ?? ''));
            if ($method->requires_reference && $reference === '') {
                throw ValidationException::withMessages(["payments.$index.reference_number" => 'La referencia es obligatoria.']);
            }
            $accountId = $bucket === 'bank' ? (array_key_exists('financial_account_id', $payment) ? $payment['financial_account_id'] : $method->default_financial_account_id) : null;
            if ($bucket === 'bank' && (($method->requires_financial_account ?? true) || $accountId)) {
                if (! FinancialAccount::query()->whereKey($accountId)->where('is_active', true)->where('is_legacy', false)->exists()) {
                    throw ValidationException::withMessages(["payments.$index.financial_account_id" => 'Seleccione una cuenta financiera activa.']);
                }
            }
            $result[] = ['method' => $method->code, 'liquidity_bucket' => $bucket, 'amount' => round($amount, 2), 'financial_account_id' => $accountId, 'reference_number' => $reference ?: null, 'notes' => $payment['notes'] ?? null];
        }
        if (array_sum(array_map(fn ($payment) => (int) round($payment['amount'] * 100), $result)) !== (int) round($total * 100)) {
            throw ValidationException::withMessages(['payments' => 'Los pagos deben coincidir exactamente con el importe solicitado.']);
        }

        return $result;
    }

    public function record(Model $source, array $payments, string $direction, User $user): Collection
    {
        return DB::transaction(function () use ($source, $payments, $direction, $user): Collection {
            $records = collect();
            foreach ($payments as $index => $payment) {
                $effectiveAt = \Carbon\Carbon::parse($source->business_datetime ?? $source->paid_at ?? now());
                if ($effectiveAt->gt(now())) {
                    throw ValidationException::withMessages(['business_datetime' => 'La fecha efectiva del pago no puede estar en el futuro.']);
                }
                $baseline = \App\Models\WarehouseCashBalance::query()->where('warehouse_id', $source->warehouse_id)->first();
                if ($payment['liquidity_bucket'] === 'cash' && $baseline && $effectiveAt->lt($baseline->cutoff_at)) {
                    throw ValidationException::withMessages(['business_datetime' => 'La salida o entrada de efectivo no puede ser anterior al saldo conciliado.']);
                }
                $record = OperationalPayment::query()->create([...$payment, 'source_type' => $source->getMorphClass(), 'source_id' => $source->id, 'line_number' => $index, 'warehouse_id' => $source->warehouse_id, 'direction' => $direction, 'created_by' => $user->id, 'business_datetime' => $source->business_datetime ?? $source->paid_at ?? now(), 'pos_session_id' => \App\Models\PosSession::query()->where('user_id', $user->id)->where('warehouse_id', $source->warehouse_id)->where('status', 'open')->value('id')]);
                if ($record->liquidity_bucket === 'cash') {
                    $movement = app(CashMovementService::class)->record($user, $source->warehouse_id, ['type' => $direction, 'subtype' => 'operational_payment', 'amount' => $record->amount, 'reason' => 'Pago operativo #'.$record->id, 'reference_number' => $record->reference_number, 'source_type' => $record->getMorphClass(), 'source_id' => $record->id, 'business_datetime' => $record->business_datetime]);
                    $record->update(['cash_movement_id' => $movement->id]);
                } elseif ($record->financial_account_id) {
                    $this->postBank($record);
                }
                $records->push($record);
            }

            return $records;
        });
    }

    public function assignAccount(OperationalPayment $payment, int $accountId, User $user, int $warehouseId): OperationalPayment
    {
        abort_unless($user->hasRole(['admin', 'manager']), 403);

        return DB::transaction(function () use ($payment, $accountId, $user, $warehouseId): OperationalPayment {
            $payment = OperationalPayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless((int) $payment->warehouse_id === $warehouseId, 403);
            if ($payment->reversed_at || $payment->liquidity_bucket !== 'bank' || ($payment->financial_account_id && (int) $payment->financial_account_id !== $accountId)) {
                throw ValidationException::withMessages(['financial_account_id' => 'Este pago no admite cambiar su cuenta.']);
            }
            $payment->update(['financial_account_id' => $accountId]);
            $this->postBank($payment);
            activity('payments')->performedOn($payment)->causedBy($user)->log('Cuenta financiera asignada');

            return $payment->fresh();
        });
    }

    public function assignDocumentAccount(Model $payment, int $accountId, User $user, int $warehouseId): Model
    {
        if ($payment instanceof OperationalPayment) {
            return $this->assignAccount($payment, $accountId, $user, $warehouseId);
        }
        abort_unless($user->hasRole(['admin', 'manager']), 403);

        return DB::transaction(function () use ($payment, $accountId, $user, $warehouseId): Model {
            $payment = $payment->newQuery()->lockForUpdate()->findOrFail($payment->id);
            if ($payment instanceof \App\Models\AccountPaymentBatch) {
                abort_unless((int) ($payment->warehouse_id ?? $payment->posSession?->warehouse_id) === $warehouseId, 403);
                if ($payment->receipt_accounting_version !== 1 || $payment->liquidity_bucket !== 'bank' || ($payment->financial_account_id && (int) $payment->financial_account_id !== $accountId)) {
                    throw ValidationException::withMessages(['financial_account_id' => 'Este recibo requiere conciliación o ya tiene otra cuenta asignada.']);
                }
                FinancialAccount::query()->where('is_active', true)->where('is_legacy', false)->lockForUpdate()->findOrFail($accountId);
                $payment->update(['financial_account_id' => $accountId]);
                app(FinancialLiquiditySyncService::class)->sync($payment);
                activity('payments')->performedOn($payment)->causedBy($user)->log('Cuenta financiera asignada al recibo');

                return $payment->fresh();
            }
            $document = $payment instanceof \App\Models\SalesOrderPayment ? $payment->salesOrder : $payment->purchaseOrder;
            $documentWarehouse = $payment instanceof \App\Models\SalesOrderPayment ? app(WarehouseCashService::class)->salesPaymentWarehouse($payment) : (int) $document->warehouse_id;
            abort_unless($documentWarehouse === $warehouseId, 403);
            if ($document->status === 'cancelled' || $payment->liquidity_bucket !== 'bank' || ($payment->financial_account_id && (int) $payment->financial_account_id !== $accountId)) {
                throw ValidationException::withMessages(['financial_account_id' => 'Este pago no admite asignar otra cuenta.']);
            }
            FinancialAccount::query()->where('is_active', true)->where('is_legacy', false)->lockForUpdate()->findOrFail($accountId);
            $payment->update(['financial_account_id' => $accountId]);
            app(FinancialLiquiditySyncService::class)->sync($payment);
            activity('payments')->performedOn($payment)->causedBy($user)->log('Cuenta financiera asignada');

            return $payment->fresh();
        });
    }

    public function reverse(Model $source, User $user): void
    {
        foreach (OperationalPayment::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->id)->whereNull('reversed_at')->lockForUpdate()->get() as $payment) {
            if ($payment->liquidity_bucket === 'cash') {
                app(CashMovementService::class)->record($user, $payment->warehouse_id, ['type' => $payment->direction === 'in' ? 'out' : 'in', 'subtype' => 'operational_payment_reversal', 'amount' => $payment->amount, 'reason' => 'Reversa pago operativo #'.$payment->id, 'source_type' => 'operational_payment_reversal', 'source_id' => $payment->id]);
            } else {
                app(FinancialLiquiditySyncService::class)->reverse($payment);
            }
            $payment->update(['reversed_at' => now()]);
        }
    }

    private function postBank(OperationalPayment $payment): void
    {
        $account = FinancialAccount::query()->where('is_active', true)->where('is_legacy', false)->lockForUpdate()->findOrFail($payment->financial_account_id);
        if (strtoupper($account->currency) !== strtoupper(\App\Models\Setting::get('store_currency', 'USD'))) {
            throw ValidationException::withMessages(['financial_account_id' => 'La cuenta debe usar la moneda de la tienda; no hay conversión cambiaria configurada.']);
        }
        app(FinancialAccountMovementService::class)->record($account, $payment->direction, (float) $payment->amount, ['source_type' => $payment->getMorphClass(), 'source_id' => $payment->id, 'source_line_key' => 'liquidity', 'business_datetime' => $payment->business_datetime ?? $payment->source?->business_datetime ?? $payment->source?->paid_at ?? $payment->created_at, 'reference' => $payment->reference_number, 'notes' => $payment->notes, 'created_by' => $payment->created_by]);
    }
}
