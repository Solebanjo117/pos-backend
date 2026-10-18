<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrderPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinancialLiquiditySyncService
{
    public function __construct(private FinancialAccountMovementService $movementService) {}

    public function sync(Model $source): ?FinancialAccountMovement
    {
        if (\App\Models\OperationalPayment::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->exists()) {
            return null;
        }

        if ($source->getAttribute('liquidity_bucket') === 'bank' && ! $source->getAttribute('financial_account_id')) {
            return null;
        }
        $effect = $this->effect($source);
        if (! $effect) {
            return null;
        }

        return DB::transaction(function () use ($source, $effect): FinancialAccountMovement {
            $accountId = $source->getAttribute('financial_account_id')
                ?: FinancialAccount::query()->where('is_legacy', true)->value('id');
            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($accountId);
            if (strtoupper($account->currency) !== strtoupper(\App\Models\Setting::get('store_currency', 'USD'))) {
                throw new \InvalidArgumentException('La cuenta debe usar la moneda de la tienda; no hay conversión cambiaria configurada.');
            }

            return $this->movementService->record($account, $effect['direction'], $effect['amount'], [
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'source_line_key' => 'liquidity',
                'business_datetime' => $effect['business_datetime'],
                'reference' => $effect['reference'],
                'notes' => $effect['notes'],
                'created_by' => $effect['created_by'],
            ]);
        });
    }

    public function reverse(Model $source): ?FinancialAccountMovement
    {
        $original = FinancialAccountMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_line_key', 'liquidity')
            ->first();

        if (! $original) {
            return null;
        }

        return DB::transaction(function () use ($source, $original): FinancialAccountMovement {
            $account = FinancialAccount::query()->lockForUpdate()->findOrFail($original->financial_account_id);

            return $this->movementService->record(
                $account,
                $original->direction === 'in' ? 'out' : 'in',
                (float) $original->amount,
                [
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'source_line_key' => 'liquidity_reversal',
                    'business_datetime' => now(),
                    'reference' => $original->reference,
                    'notes' => 'Reversa automática del movimiento de liquidez.',
                    'created_by' => auth()->id(),
                ],
            );
        });
    }

    /**
     * @return array{direction: string, amount: float, business_datetime: mixed, reference: mixed, notes: mixed, created_by: mixed}|null
     */
    private function effect(Model $source): ?array
    {
        if ($source instanceof SalesOrderPayment) {
            if ($source->account_payment_batch_id && \App\Models\AccountPaymentBatch::query()->whereKey($source->account_payment_batch_id)->where('receipt_accounting_version', 1)->exists()) {
                return null;
            }
            if (($source->liquidity_bucket ?? PaymentMethod::liquidityBucket($source->method)) !== PaymentMethod::LIQUIDITY_BANK) {
                return null;
            }

            return $this->payload(
                (float) $source->amount >= 0 ? 'in' : 'out',
                abs((float) $source->amount),
                $source->business_datetime ?? $source->paid_at ?? $source->created_at,
                $source->reference_number,
                $source->notes,
                $source->created_by,
            );
        }

        if ($source instanceof PurchaseOrderPayment) {
            if (($source->liquidity_bucket ?? PaymentMethod::liquidityBucket($source->method)) !== PaymentMethod::LIQUIDITY_BANK) {
                return null;
            }

            return $this->payload('out', (float) $source->amount, $source->business_datetime ?? $source->paid_at ?? $source->created_at, $source->reference_number, $source->notes, $source->user_id);
        }

        if ($source instanceof \App\Models\AccountPaymentBatch && $source->receipt_accounting_version === 1 && $source->direction === 'incoming' && $source->liquidity_bucket === 'bank') {
            return $this->payload('in', (float) $source->amount, $source->business_datetime ?? $source->paid_at, $source->reference_number, $source->notes, $source->created_by);
        }

        if ($source instanceof OperationalExpense) {
            if (
                $source->status !== 'paid'
                || ! $source->affects_cash_flow
                || PaymentMethod::liquidityBucket($source->payment_method) !== PaymentMethod::LIQUIDITY_BANK
            ) {
                return null;
            }

            return $this->payload('out', (float) $source->amount, $source->business_datetime ?? $source->paid_at ?? $source->expense_date, $source->reference_number, $source->notes, $source->created_by);
        }

        if ($source instanceof CapitalMovement) {
            if ($source->cash_movement_id || $source->source_type === 'allocated_cash_operation') {
                return null;
            }

            return $this->payload($source->movement_type, (float) $source->amount, $source->business_datetime ?? $source->movement_date, null, $source->notes, $source->user_id);
        }

        return null;
    }

    /**
     * @return array{direction: string, amount: float, business_datetime: mixed, reference: mixed, notes: mixed, created_by: mixed}
     */
    private function payload(string $direction, float $amount, mixed $datetime, mixed $reference, mixed $notes, mixed $createdBy): array
    {
        return [
            'direction' => $direction,
            'amount' => round(abs($amount), 2),
            'business_datetime' => $datetime ?? now(),
            'reference' => $reference,
            'notes' => $notes,
            'created_by' => $createdBy,
        ];
    }
}
