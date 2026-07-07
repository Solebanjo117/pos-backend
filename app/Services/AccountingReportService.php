<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AccountingReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function journal(array $filters): array
    {
        $entries = $this->entryQuery($filters)
            ->with(['lines.ledgerAccount', 'warehouse', 'creator'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => $entries->through(fn (JournalEntry $entry): array => $this->entryRow($entry))->items(),
            'meta' => $this->paginationMeta($entries),
            'summary' => $this->summary($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(JournalEntry $entry): array
    {
        $entry->load(['lines.ledgerAccount', 'warehouse', 'creator']);

        return [
            'data' => [
                ...$this->entryRow($entry),
                'lines' => $entry->lines->map(fn (JournalLine $line): array => $this->lineRow($line))->values(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function accounts(array $filters): array
    {
        $accounts = LedgerAccount::query()
            ->withSum(['journalLines as debit_total' => fn (Builder $query) => $this->lineDateScope($query, $filters)], 'debit')
            ->withSum(['journalLines as credit_total' => fn (Builder $query) => $this->lineDateScope($query, $filters)], 'credit')
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account): array {
                $debit = round((float) ($account->debit_total ?? 0), 2);
                $credit = round((float) ($account->credit_total ?? 0), 2);

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => round($debit - $credit, 2),
                    'is_active' => $account->is_active,
                ];
            })
            ->values();

        return [
            'data' => $accounts,
            'summary' => [
                'accounts' => $accounts->count(),
                'debit' => round((float) $accounts->sum('debit'), 2),
                'credit' => round((float) $accounts->sum('credit'), 2),
                'balance' => round((float) $accounts->sum('balance'), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function accountLedger(LedgerAccount $account, array $filters): array
    {
        $summary = $this->accountSummary($account, $filters);
        $lines = $this->accountLineQuery($account, $filters)
            ->with(['journalEntry.warehouse', 'journalEntry.creator'])
            ->orderByDesc(
                JournalEntry::select('occurred_at')
                    ->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id')
                    ->limit(1),
            )
            ->paginate((int) ($filters['per_page'] ?? 25));

        $rows = $lines->getCollection()
            ->map(fn (JournalLine $line): array => $this->ledgerLineRow($line))
            ->values();

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ],
            'data' => $rows,
            'meta' => $this->paginationMeta($lines),
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function tAccount(LedgerAccount $account, array $filters): array
    {
        $report = $this->accountLedger($account, $filters);
        $rows = collect($report['data']);

        return [
            ...$report,
            'debits' => $rows->where('debit', '>', 0)->values(),
            'credits' => $rows->where('credit', '>', 0)->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function trialBalance(array $filters): array
    {
        $accounts = collect($this->accounts($filters)['data']);

        return [
            'data' => $accounts->filter(fn (array $account): bool => abs((float) $account['debit']) > 0.005 || abs((float) $account['credit']) > 0.005)->values(),
            'summary' => [
                'debit' => round((float) $accounts->sum('debit'), 2),
                'credit' => round((float) $accounts->sum('credit'), 2),
                'difference' => round((float) $accounts->sum('debit') - (float) $accounts->sum('credit'), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalEntry>
     */
    private function entryQuery(array $filters): Builder
    {
        return JournalEntry::query()
            ->where('status', 'posted')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $query, int|string $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType) => $query->where('source_type', $sourceType))
            ->when($filters['source_id'] ?? null, fn (Builder $query, int|string $sourceId) => $query->where('source_id', $sourceId));
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @param  array<string, mixed>  $filters
     */
    private function entryFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->where('status', 'posted')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $query, int|string $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType) => $query->where('source_type', $sourceType))
            ->when($filters['source_id'] ?? null, fn (Builder $query, int|string $sourceId) => $query->where('source_id', $sourceId));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<JournalLine>
     */
    private function accountLineQuery(LedgerAccount $account, array $filters): Builder
    {
        return JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereHas('journalEntry', fn (Builder $query) => $this->entryFilters($query, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, float>
     */
    private function accountSummary(LedgerAccount $account, array $filters): array
    {
        $opening = $this->accountOpeningBalance($account, $filters);
        $debit = $this->money((clone $this->accountLineQuery($account, $filters))->sum('debit'));
        $credit = $this->money((clone $this->accountLineQuery($account, $filters))->sum('credit'));
        $ending = $this->money($opening + $debit - $credit);

        return [
            'opening_balance' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'ending_balance' => $ending,
            'balance' => $ending,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function accountOpeningBalance(LedgerAccount $account, array $filters): float
    {
        if (empty($filters['date_from'])) {
            return 0.0;
        }

        $dateFrom = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $query = JournalLine::query()
            ->where('ledger_account_id', $account->id)
            ->whereHas('journalEntry', function (Builder $query) use ($filters, $dateFrom): void {
                $query
                    ->where('status', 'posted')
                    ->where('occurred_at', '<', $dateFrom)
                    ->when($filters['warehouse_id'] ?? null, fn (Builder $query, int|string $warehouseId) => $query->where('warehouse_id', $warehouseId))
                    ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType) => $query->where('source_type', $sourceType))
                    ->when($filters['source_id'] ?? null, fn (Builder $query, int|string $sourceId) => $query->where('source_id', $sourceId));
            });

        return $this->money((clone $query)->sum('debit') - (clone $query)->sum('credit'));
    }

    /**
     * @param  Builder<JournalLine>  $query
     * @param  array<string, mixed>  $filters
     */
    private function lineDateScope(Builder $query, array $filters): Builder
    {
        return $query->whereHas('journalEntry', fn (Builder $entryQuery) => $this->entryFilters($entryQuery, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $query = $this->entryQuery($filters);

        return [
            'entries' => (clone $query)->count(),
            'lines' => JournalLine::query()
                ->whereHas('journalEntry', fn (Builder $entryQuery) => $this->entryFilters($entryQuery, $filters))
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(JournalEntry $entry): array
    {
        $debit = round((float) $entry->lines->sum('debit'), 2);
        $credit = round((float) $entry->lines->sum('credit'), 2);

        return [
            'id' => $entry->id,
            'occurred_at' => $entry->occurred_at,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'warehouse' => $entry->warehouse?->name,
            'user' => $entry->creator?->name,
            'memo' => $entry->memo,
            'status' => $entry->status,
            'debit' => $debit,
            'credit' => $credit,
            'balanced' => abs($debit - $credit) <= 0.005,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRow(JournalLine $line): array
    {
        return [
            'id' => $line->id,
            'account_id' => $line->ledger_account_id,
            'account_code' => $line->ledgerAccount?->code,
            'account_name' => $line->ledgerAccount?->name,
            'third_party_type' => $line->third_party_type,
            'third_party_id' => $line->third_party_id,
            'debit' => round((float) $line->debit, 2),
            'credit' => round((float) $line->credit, 2),
            'metadata' => $line->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerLineRow(JournalLine $line): array
    {
        $debit = round((float) $line->debit, 2);
        $credit = round((float) $line->credit, 2);

        return [
            'id' => $line->id,
            'journal_entry_id' => $line->journal_entry_id,
            'occurred_at' => $line->journalEntry?->occurred_at,
            'source_type' => $line->journalEntry?->source_type,
            'source_id' => $line->journalEntry?->source_id,
            'memo' => $line->journalEntry?->memo,
            'user' => $line->journalEntry?->creator?->name,
            'warehouse' => $line->journalEntry?->warehouse?->name,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => round($debit - $credit, 2),
            'metadata' => $line->metadata,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
