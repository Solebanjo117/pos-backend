<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
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
            $lines = collect($data['lines'] ?? []);
            $totalDebit = round((float) $lines->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)), 2);
            $totalCredit = round((float) $lines->sum(fn (array $line): float => (float) ($line['credit'] ?? 0)), 2);

            if ($lines->count() < 2 || abs($totalDebit - $totalCredit) > 0.005) {
                throw new InvalidArgumentException('El asiento contable debe estar balanceado.');
            }

            $entry = JournalEntry::create([
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'occurred_at' => $data['occurred_at'],
                'status' => $data['status'] ?? 'posted',
                'memo' => $data['memo'] ?? null,
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
