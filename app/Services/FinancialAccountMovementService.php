<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use InvalidArgumentException;

class FinancialAccountMovementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(FinancialAccount $account, string $direction, float $amount, array $data): FinancialAccountMovement
    {
        if (! in_array($direction, ['in', 'out'], true) || $amount <= 0) {
            throw new InvalidArgumentException('El movimiento financiero no es válido.');
        }

        $existing = FinancialAccountMovement::query()
            ->where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->where('source_line_key', $data['source_line_key'] ?? 'main')
            ->first();

        if ($existing) {
            return $existing;
        }

        $balanceBefore = round((float) $account->current_balance, 2);
        $balanceAfter = round($balanceBefore + ($direction === 'in' ? $amount : -$amount), 2);

        if ($balanceAfter < 0 && ! $account->allow_negative_balance) {
            throw new InvalidArgumentException('La cuenta no tiene saldo suficiente.');
        }

        $movement = FinancialAccountMovement::create([
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'source_line_key' => $data['source_line_key'] ?? 'main',
            'business_datetime' => $data['business_datetime'] ?? now(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $account->update(['current_balance' => $balanceAfter]);

        return $movement;
    }
}
