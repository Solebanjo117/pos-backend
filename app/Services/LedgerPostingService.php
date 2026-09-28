<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerPostingService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function postManualEntry(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data): JournalEntry {
            $postingKey = isset($data['posting_key']) ? trim((string) $data['posting_key']) : null;
            if ($postingKey !== null && $postingKey !== '') {
                $existing = JournalEntry::query()
                    ->with('lines.ledgerAccount')
                    ->where('posting_key', $postingKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $lines = collect($data['lines'] ?? []);
            $totalDebit = round((float) $lines->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)), 2);
            $totalCredit = round((float) $lines->sum(fn (array $line): float => (float) ($line['credit'] ?? 0)), 2);

            if ($lines->count() < 2 || abs($totalDebit - $totalCredit) > 0.005) {
                throw new InvalidArgumentException('El asiento contable debe estar balanceado.');
            }

            $entry = JournalEntry::create([
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'] ?? null,
                'posting_key' => $postingKey ?: null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'occurred_at' => $data['occurred_at'],
                'status' => $data['status'] ?? 'posted',
                'memo' => $data['memo'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            foreach ($lines as $line) {
                $account = LedgerAccount::firstOrCreate(
                    ['code' => $line['account_code']],
                    [
                        'name' => $line['account_name'],
                        'type' => $this->inferAccountType((string) $line['account_code']),
                        'is_active' => true,
                    ]
                );

                $entry->lines()->create([
                    'ledger_account_id' => $account->id,
                    'third_party_type' => $line['third_party_type'] ?? null,
                    'third_party_id' => $line['third_party_id'] ?? null,
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'metadata' => $line['metadata'] ?? null,
                ]);
            }

            return $entry->load('lines.ledgerAccount');
        });
    }

    public function postOperationalExpense(OperationalExpense $expense, ?int $userId = null): ?JournalEntry
    {
        $amount = round((float) $expense->amount, 2);

        return DB::transaction(function () use ($expense, $amount, $userId): ?JournalEntry {
            $this->reverseSource(
                'operational_expense_payment',
                (int) $expense->id,
                $userId,
                'Reversa automatica antes de repostear pago de gasto.',
            );
            $this->reverseSource(
                'operational_expense',
                (int) $expense->id,
                $userId,
                'Reversa automatica antes de repostear gasto operativo.',
            );

            if (
                $amount <= 0
                || ! $expense->affects_income_statement
                || in_array($expense->status, ['draft', 'cancelled', 'reversed'], true)
            ) {
                return null;
            }

            $expenseAccount = $expense->ledgerAccount
                ?? $expense->categoryRecord?->defaultLedgerAccount
                ?? $this->accountByCode('5000', 'Operating Expenses');

            $creditAccount = $this->accountByCode('2100', 'Accounts Payable / Accrued Expenses');
            $memo = trim((string) ($expense->description ?: $expense->category_label ?: 'Gasto operativo'));
            [$thirdPartyType, $thirdPartyId] = $this->expenseThirdParty($expense);

            $entry = JournalEntry::create([
                'source_type' => 'operational_expense',
                'source_id' => $expense->id,
                'posting_key' => 'operational_expense:'.$expense->id.':'.hash('sha256', implode('|', [
                    $expense->status,
                    $amount,
                    (string) ($expense->business_datetime ?? $expense->expense_date),
                ])),
                'warehouse_id' => $expense->warehouse_id,
                'occurred_at' => $expense->business_datetime ?? $expense->expense_date ?? $expense->created_at ?? now(),
                'status' => 'posted',
                'memo' => 'Gasto operativo: '.$memo,
                'created_by' => $userId ?? $expense->created_by,
            ]);

            $entry->lines()->create([
                'ledger_account_id' => $expenseAccount->id,
                'third_party_type' => $thirdPartyType,
                'third_party_id' => $thirdPartyId,
                'debit' => $amount,
                'credit' => 0,
                'metadata' => [
                    'expense_id' => $expense->id,
                    'category' => $expense->category,
                    'status' => $expense->status,
                    'payment_method' => $expense->payment_method,
                ],
            ]);

            $entry->lines()->create([
                'ledger_account_id' => $creditAccount->id,
                'third_party_type' => $thirdPartyType,
                'third_party_id' => $thirdPartyId,
                'debit' => 0,
                'credit' => $amount,
                'metadata' => [
                    'expense_id' => $expense->id,
                    'category' => $expense->category,
                    'status' => $expense->status,
                    'payment_method' => $expense->payment_method,
                ],
            ]);

            $entry = $entry->load('lines.ledgerAccount');

            if ($expense->status === 'paid' && (bool) ($expense->affects_cash_flow ?? true)) {
                $this->postOperationalExpensePayment($expense, $creditAccount, $userId);
            }

            return $entry;
        });
    }

    private function postOperationalExpensePayment(OperationalExpense $expense, LedgerAccount $payableAccount, ?int $userId): JournalEntry
    {
        $bucket = PaymentMethod::liquidityBucket($expense->payment_method);
        $liquidityAccount = $bucket === PaymentMethod::LIQUIDITY_CASH
            ? $this->accountByCode('1100', 'Cash')
            : $this->accountByCode('1110', 'Bank');
        [$thirdPartyType, $thirdPartyId] = $this->expenseThirdParty($expense);

        $allocations = \App\Models\OperationalPayment::query()->where('source_type', $expense->getMorphClass())->where('source_id', $expense->id)->whereNull('reversed_at')->get();
        $credits = $allocations->isEmpty() ? [[
            'account_code' => $liquidityAccount->code, 'account_name' => $liquidityAccount->name,
            'debit' => 0, 'credit' => (float) $expense->amount,
        ]] : $allocations->map(fn ($payment) => [
            'account_code' => $payment->liquidity_bucket === 'cash' ? '1100' : '1110',
            'account_name' => $payment->liquidity_bucket === 'cash' ? 'Cash' : 'Bank',
            'debit' => 0, 'credit' => (float) $payment->amount,
        ])->all();

        return $this->postManualEntry([
            'source_type' => 'operational_expense_payment',
            'source_id' => $expense->id,
            'posting_key' => 'operational_expense_payment:'.$expense->id.':'.hash('sha256', implode('|', [
                (string) $expense->amount,
                (string) $expense->payment_method,
                (string) ($expense->paid_at ?? $expense->business_datetime),
            ])),
            'warehouse_id' => $expense->warehouse_id,
            'occurred_at' => $expense->paid_at ?? now(),
            'memo' => 'Pago de gasto: '.($expense->description ?: $expense->category_label),
            'created_by' => $userId ?? $expense->created_by,
            'lines' => [
                [
                    'account_code' => $payableAccount->code,
                    'account_name' => $payableAccount->name,
                    'third_party_type' => $thirdPartyType,
                    'third_party_id' => $thirdPartyId,
                    'debit' => (float) $expense->amount,
                    'credit' => 0,
                ],
                ...$credits,
            ],
        ]);
    }

    /** @return array{0: string|null, 1: int|null} */
    private function expenseThirdParty(OperationalExpense $expense): array
    {
        return match (true) {
            $expense->customer_id !== null => ['customer', (int) $expense->customer_id],
            $expense->supplier_id !== null => ['supplier', (int) $expense->supplier_id],
            $expense->beneficiary_id !== null => ['beneficiary', (int) $expense->beneficiary_id],
            default => [null, null],
        };
    }

    public function reverseSource(string $sourceType, int $sourceId, ?int $userId = null, ?string $memo = null): ?JournalEntry
    {
        $entries = JournalEntry::query()
            ->with('lines.ledgerAccount')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($entries, $sourceType, $sourceId, $userId, $memo): ?JournalEntry {
            $lastReversal = null;

            foreach ($entries as $entry) {
                $reversal = JournalEntry::create([
                    'source_type' => $sourceType.'_reversal',
                    'source_id' => $sourceId,
                    'posting_key' => 'reversal:'.$entry->id,
                    'reverses_entry_id' => $entry->id,
                    'warehouse_id' => $entry->warehouse_id,
                    'occurred_at' => now(),
                    'status' => 'posted',
                    'memo' => $memo ?? 'Reversa de asiento '.$entry->id,
                    'metadata' => ['reverses_journal_entry_id' => $entry->id],
                    'created_by' => $userId ?? $entry->created_by,
                ]);

                foreach ($entry->lines as $line) {
                    $reversal->lines()->create([
                        'ledger_account_id' => $line->ledger_account_id,
                        'third_party_type' => $line->third_party_type,
                        'third_party_id' => $line->third_party_id,
                        'debit' => round((float) $line->credit, 2),
                        'credit' => round((float) $line->debit, 2),
                        'metadata' => [
                            'reverses_journal_entry_id' => $entry->id,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                        ],
                    ]);
                }

                $entry->update(['status' => 'reversed']);
                $lastReversal = $reversal->load('lines.ledgerAccount');
            }

            return $lastReversal;
        });
    }

    private function creditAccountForExpense(OperationalExpense $expense): LedgerAccount
    {
        if (($expense->status ?? 'paid') !== 'paid' || ! (bool) ($expense->affects_cash_flow ?? true)) {
            return $this->accountByCode('2100', 'Accounts Payable / Accrued Expenses');
        }

        $bucket = PaymentMethod::liquidityBucket($expense->payment_method);

        if ($bucket === PaymentMethod::LIQUIDITY_CASH) {
            return $this->accountByCode('1100', 'Cash');
        }

        return $this->accountByCode('1110', 'Bank');
    }

    private function accountByCode(string $code, string $name): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => $this->inferAccountType($code),
                'is_active' => true,
            ],
        );
    }

    private function inferAccountType(string $code): string
    {
        return match (substr($code, 0, 1)) {
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'income',
            '5', '6' => 'expense',
            default => 'other',
        };
    }
}
