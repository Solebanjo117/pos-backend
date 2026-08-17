<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\BalanceSheetLedgerService;
use App\Services\LedgerPostingService;
use App\Services\OperationalLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReconcileBalanceSheetLedger extends Command
{
    protected $signature = 'accounting:reconcile-balance-sheet
        {--as-of= : Fecha de corte YYYY-MM-DD}
        {--apply : Genera los asientos operativos faltantes y cierres históricos}
        {--dry-run : Audita sin escribir cambios (comportamiento predeterminado)}
        {--opening= : Archivo JSON de un asiento de apertura verificado}';

    protected $description = 'Audita y concilia el libro mayor que alimenta el Balance Sheet consolidado';

    public function handle(
        BalanceSheetLedgerService $balanceSheet,
        OperationalLedgerService $operationalLedger,
        LedgerPostingService $postingService,
    ): int {
        $asOf = Carbon::parse($this->option('as-of') ?: now()->toDateString(), 'America/Panama')->endOfDay();
        $before = $balanceSheet->generate(['as_of' => $asOf->toDateString()]);
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->line(json_encode(['mode' => 'dry-run', 'report' => $before], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($asOf, $operationalLedger, $postingService): array {
            $opening = $this->option('opening')
                ? $this->postOpeningBalance((string) $this->option('opening'), $postingService)
                : null;
            $backfill = $operationalLedger->backfill($asOf);
            $closedYears = [];
            $firstOccurredAt = JournalEntry::query()->min('occurred_at');
            $firstYear = $firstOccurredAt ? Carbon::parse($firstOccurredAt)->year : $asOf->year;

            for ($year = $firstYear; $year < $asOf->year; $year++) {
                if ($operationalLedger->closeYear($year)) {
                    $closedYears[] = $year;
                }
            }

            return ['opening_entry_id' => $opening?->id, 'backfill' => $backfill, 'closed_years' => $closedYears];
        }, 3);

        $after = $balanceSheet->generate(['as_of' => $asOf->toDateString()]);
        $this->line(json_encode([
            'mode' => 'apply',
            'as_of' => $asOf->toDateString(),
            'changes' => $result,
            'before' => $before['reconciliation'],
            'after' => $after,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function postOpeningBalance(string $path, LedgerPostingService $postingService): JournalEntry
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('No se puede leer el archivo de apertura indicado.');
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $lines = collect($payload['lines'] ?? []);
        if ($lines->count() < 2 || empty($payload['date']) || empty($payload['approved_by']) || empty($payload['evidence'])) {
            throw new InvalidArgumentException('La apertura requiere fecha, aprobador, evidencia y al menos dos líneas.');
        }

        $debit = round((float) $lines->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)), 2);
        $credit = round((float) $lines->sum(fn (array $line): float => (float) ($line['credit'] ?? 0)), 2);
        if (abs($debit - $credit) > 0.005) {
            throw new InvalidArgumentException('El asiento de apertura no está balanceado.');
        }

        $postingKey = 'accounting_opening_balance:'.Carbon::parse($payload['date'])->toDateString();
        $existingOpening = JournalEntry::query()->where('source_type', 'accounting_opening_balance')->first();
        if ($existingOpening && $existingOpening->posting_key !== $postingKey) {
            throw new InvalidArgumentException('Ya existe un asiento de apertura contable; debe corregirse mediante reversión, no creando otro.');
        }

        $journalLines = $lines->map(function (array $line): array {
            $account = LedgerAccount::query()->where('code', $line['account_code'] ?? null)->firstOrFail();

            return [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'metadata' => ['evidence' => $line['evidence'] ?? null],
            ];
        })->all();

        return $postingService->postManualEntry([
            'posting_key' => $postingKey,
            'source_type' => 'accounting_opening_balance',
            'occurred_at' => Carbon::parse($payload['date'], 'America/Panama')->startOfDay()->utc(),
            'memo' => $payload['memo'] ?? 'Apertura contable verificada',
            'created_by' => (int) $payload['approved_by'],
            'metadata' => [
                'evidence' => $payload['evidence'],
                'approved_by' => (int) $payload['approved_by'],
                'opening_balance' => true,
            ],
            'lines' => $journalLines,
        ]);
    }
}
