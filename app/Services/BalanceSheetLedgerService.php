<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;
use App\Models\FinancialAdjustment;
use App\Models\InventoryLot;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BalanceSheetLedgerService
{
    private const TIMEZONE = 'America/Panama';

    /** @return array<string, mixed> */
    public function generate(array $filters): array
    {
        $asOf = Carbon::parse($filters['as_of'] ?? $filters['date_to'] ?? now()->toDateString(), self::TIMEZONE)->endOfDay();
        $cutoffUtc = $asOf->copy()->utc();
        $periodStart = $asOf->copy()->startOfYear()->startOfDay();
        $periodStartUtc = $periodStart->copy()->utc();
        $accounts = $this->accountBalances($cutoffUtc);
        $periodProfit = $this->periodProfit($periodStartUtc, $cutoffUtc);

        $assets = $this->section($accounts, 'asset');
        $liabilities = $this->section($accounts, 'liability');
        $postedEquity = $this->section($accounts, 'equity');
        $totalAssets = $this->money($assets->sum('balance'));
        $totalLiabilities = $this->money($liabilities->sum('balance'));
        $totalPostedEquity = $this->money($postedEquity->sum('balance'));
        $totalEquity = $this->money($totalPostedEquity + $periodProfit);
        $liabilitiesAndEquity = $this->money($totalLiabilities + $totalEquity);
        $difference = $this->money($totalAssets - $liabilitiesAndEquity);
        $trialBalance = $this->trialBalance($cutoffUtc);
        $sourceCoverage = $this->sourceCoverage($cutoffUtc);
        $controlAccounts = $this->controlAccountReconciliation($accounts, $cutoffUtc, $asOf);
        $unclassified = $accounts->whereIn('type', ['asset', 'liability', 'equity'])->whereNull('report_group')->count();
        $controlDifference = $this->money($controlAccounts->sum(fn (array $row): float => abs((float) $row['difference'])));
        $missingSources = collect($sourceCoverage)->sum('missing');
        $historicalInventoryUnverified = ! $asOf->isToday();
        $cashUnverified = $controlAccounts->contains(fn (array $row): bool => $row['key'] === 'cash' && ! $row['verified']);
        $currencyUnverified = FinancialAccount::query()->where('currency', '!=', \App\Models\Setting::get('store_currency', 'USD'))->exists();

        $warnings = collect();
        if ($cashUnverified) {
            $warnings->push('El efectivo de todas las sucursales debe estar conciliado al corte antes de considerar oficial este Balance.');
        }
        if ($currencyUnverified) {
            $warnings->push('Existen cuentas en otra moneda sin conversión configurada; los totales consolidados no son oficiales.');
        }
        if ($missingSources > 0) {
            $warnings->push('Existen operaciones históricas sin asiento contable; ejecute la conciliación antes de considerar oficial este Balance.');
        }
        if ($controlDifference > 0.005) {
            $warnings->push('Uno o más saldos de control no coinciden con sus submódulos operativos.');
        }
        if ($historicalInventoryUnverified) {
            $warnings->push('La valoración FIFO histórica no puede verificarse únicamente con la disponibilidad actual de los lotes.');
        }
        if ($unclassified > 0) {
            $warnings->push('Hay cuentas contables sin grupo de presentación configurado.');
        }
        if (isset($filters['warehouse_id'])) {
            $warnings->push('El Balance Sheet oficial es consolidado; el almacén seleccionado no modifica sus totales.');
        }

        $requiresReconciliation = $missingSources > 0
            || $controlDifference > 0.005
            || $historicalInventoryUnverified
            || $cashUnverified
            || $currencyUnverified
            || $unclassified > 0;
        $status = abs($difference) > 0.005 || abs((float) $trialBalance['difference']) > 0.005
            ? 'unbalanced'
            : ($requiresReconciliation ? 'reconciliation_required' : 'ready');

        $assetGroups = $this->groupTotals($assets);
        $liabilityGroups = $this->groupTotals($liabilities);
        $equityGroups = $this->groupTotals($postedEquity);

        return [
            'as_of' => $asOf->toDateString(),
            'period_start' => $periodStart->toDateString(),
            'scope' => 'consolidated',
            'status' => $status,
            'is_official' => $status === 'ready',
            'assets' => [
                'cash' => $this->groupValue($assetGroups, 'cash'),
                'cash_initial' => 0.0,
                'bank' => $this->groupValue($assetGroups, 'bank'),
                'bank_initial' => 0.0,
                'inventory' => $this->groupValue($assetGroups, 'inventory'),
                'inventory_initial' => 0.0,
                'accounts_receivable' => $this->groupValue($assetGroups, 'accounts_receivable'),
                'other_assets' => $this->otherValue($assets, ['cash', 'bank', 'inventory', 'accounts_receivable']),
                'items' => $assets->values()->all(),
            ],
            'liabilities' => [
                'accounts_payable' => $this->groupValue($liabilityGroups, 'accounts_payable'),
                'customer_credits' => $this->groupValue($liabilityGroups, 'customer_credits'),
                'pending_refunds' => $this->groupValue($liabilityGroups, 'pending_refunds'),
                'other_liabilities' => $this->otherValue($liabilities, ['accounts_payable', 'customer_credits', 'pending_refunds']),
                'items' => $liabilities->values()->all(),
            ],
            'equity' => [
                'contributed_capital' => $this->groupValue($equityGroups, 'contributed_capital'),
                'period_profit' => $periodProfit,
                'retained_earnings' => $this->groupValue($equityGroups, 'retained_earnings'),
                'other_equity' => $this->otherValue($postedEquity, ['contributed_capital', 'retained_earnings']),
                'total_equity' => $totalEquity,
                'items' => $postedEquity->values()->all(),
            ],
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'liabilities_and_equity' => $liabilitiesAndEquity,
                'reconciliation_difference' => $difference,
                'balance' => $totalEquity,
            ],
            'reconciliation' => [
                'trial_balance' => $trialBalance,
                'control_accounts' => $controlAccounts->values()->all(),
                'control_difference' => $controlDifference,
                'source_coverage' => $sourceCoverage,
                'missing_sources' => $missingSources,
                'unclassified_accounts' => $unclassified,
                'historical_inventory_verified' => ! $historicalInventoryUnverified,
            ],
            'bank_accounts' => $this->bankAccounts($accounts),
            'warehouse_id' => null,
            'warnings' => $warnings->values()->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function drilldown(string $metric, Carbon $asOf): Collection
    {
        $cutoffUtc = $asOf->copy()->endOfDay()->utc();
        $group = match ($metric) {
            'cash' => 'cash',
            'bank' => 'bank',
            'receivable', 'accounts_receivable' => 'accounts_receivable',
            'inventory', 'inventory_bridge' => 'inventory',
            'payable', 'accounts_payable' => 'accounts_payable',
            'customer_credits' => 'customer_credits',
            'pending_refunds' => 'pending_refunds',
            'investment' => 'contributed_capital',
            'retained_earnings' => 'retained_earnings',
            default => null,
        };

        $query = JournalLine::query()
            ->with(['ledgerAccount', 'journalEntry.creator', 'journalEntry.warehouse'])
            ->whereHas('journalEntry', fn (Builder $entry) => $entry
                ->whereIn('status', ['posted', 'reversed'])
                ->where('occurred_at', '<=', $cutoffUtc));

        if ($metric === 'profit') {
            $query->whereHas('ledgerAccount', fn (Builder $account) => $account->whereIn('type', ['income', 'expense']));
        } elseif ($group !== null) {
            $query->whereHas('ledgerAccount', fn (Builder $account) => $account->where('report_group', $group));
        } else {
            $type = match ($metric) {
                'assets' => 'asset',
                'liabilities' => 'liability',
                'equity' => 'equity',
                default => null,
            };
            if ($type !== null) {
                $query->whereHas('ledgerAccount', fn (Builder $account) => $account->where('type', $type));
            }
        }

        return $query->get()->sortByDesc(fn (JournalLine $line) => $line->journalEntry?->occurred_at)->values()->map(function (JournalLine $line): array {
            $entry = $line->journalEntry;

            return [
                'id' => $line->id,
                'date' => $entry?->occurred_at?->timezone(self::TIMEZONE)->toDateString(),
                'occurred_at' => $entry?->occurred_at,
                'account' => trim(($line->ledgerAccount?->code ?? '').' '.($line->ledgerAccount?->name ?? '')),
                'document' => data_get($entry?->metadata, 'document') ?? $entry?->posting_key ?? ($entry?->source_type.' #'.$entry?->source_id),
                'description' => $entry?->memo,
                'user' => $entry?->creator?->name,
                'warehouse' => $entry?->warehouse?->name,
                'debit' => $this->money($line->debit),
                'credit' => $this->money($line->credit),
                'amount' => $this->money((float) $line->debit - (float) $line->credit),
            ];
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function accountBalances(Carbon $cutoffUtc): Collection
    {
        return LedgerAccount::query()
            ->withSum(['journalLines as debit_to_date' => fn (Builder $query) => $this->lineCutoff($query, $cutoffUtc)], 'debit')
            ->withSum(['journalLines as credit_to_date' => fn (Builder $query) => $this->lineCutoff($query, $cutoffUtc)], 'credit')
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account): array {
                $debit = $this->money($account->debit_to_date ?? 0);
                $credit = $this->money($account->credit_to_date ?? 0);
                $normalDebit = ($account->normal_balance ?? (in_array($account->type, ['asset', 'expense'], true) ? 'debit' : 'credit')) === 'debit';

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'system_key' => $account->system_key,
                    'report_group' => $account->report_group,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $this->money($normalDebit ? $debit - $credit : $credit - $debit),
                ];
            });
    }

    private function lineCutoff(Builder $query, Carbon $cutoffUtc): Builder
    {
        return $query->whereHas('journalEntry', fn (Builder $entry) => $entry
            ->whereIn('status', ['posted', 'reversed'])
            ->where('occurred_at', '<=', $cutoffUtc));
    }

    private function periodProfit(Carbon $fromUtc, Carbon $toUtc): float
    {
        $accounts = LedgerAccount::query()
            ->whereIn('type', ['income', 'expense'])
            ->withSum(['journalLines as debit_period' => fn (Builder $query) => $query->whereHas('journalEntry', fn (Builder $entry) => $entry
                ->whereIn('status', ['posted', 'reversed'])
                ->whereBetween('occurred_at', [$fromUtc, $toUtc]))], 'debit')
            ->withSum(['journalLines as credit_period' => fn (Builder $query) => $query->whereHas('journalEntry', fn (Builder $entry) => $entry
                ->whereIn('status', ['posted', 'reversed'])
                ->whereBetween('occurred_at', [$fromUtc, $toUtc]))], 'credit')
            ->get();

        return $this->money($accounts->sum(function (LedgerAccount $account): float {
            $debit = (float) ($account->debit_period ?? 0);
            $credit = (float) ($account->credit_period ?? 0);

            return $account->type === 'income' ? $credit - $debit : -($debit - $credit);
        }));
    }

    /** @param Collection<int, array<string, mixed>> $accounts */
    private function section(Collection $accounts, string $type): Collection
    {
        return $accounts->where('type', $type)->filter(fn (array $row): bool => abs((float) $row['balance']) >= 0.005)->values();
    }

    /** @param Collection<int, array<string, mixed>> $accounts */
    private function groupTotals(Collection $accounts): Collection
    {
        return $accounts->groupBy(fn (array $row): string => (string) ($row['report_group'] ?? 'other'))
            ->map(fn (Collection $rows): float => $this->money($rows->sum('balance')));
    }

    private function groupValue(Collection $groups, string $group): float
    {
        return $this->money($groups->get($group, 0));
    }

    /** @param Collection<int, array<string, mixed>> $accounts */
    private function otherValue(Collection $accounts, array $knownGroups): float
    {
        return $this->money($accounts->reject(fn (array $row): bool => in_array($row['report_group'], $knownGroups, true))->sum('balance'));
    }

    /** @return array{debit: float, credit: float, difference: float} */
    private function trialBalance(Carbon $cutoffUtc): array
    {
        $query = JournalLine::query()->whereHas('journalEntry', fn (Builder $entry) => $entry
            ->whereIn('status', ['posted', 'reversed'])
            ->where('occurred_at', '<=', $cutoffUtc));
        $debit = $this->money((clone $query)->sum('debit'));
        $credit = $this->money((clone $query)->sum('credit'));

        return ['debit' => $debit, 'credit' => $credit, 'difference' => $this->money($debit - $credit)];
    }

    /** @return array<string, array{source: int, posted: int, missing: int}> */
    private function sourceCoverage(Carbon $cutoffUtc): array
    {
        $cutoff = $cutoffUtc->copy()->timezone(self::TIMEZONE);
        $salesPayments = SalesOrderPayment::query()->where('paid_at', '<=', $cutoffUtc)->get(['id', 'method', 'amount', 'sales_return_id']);
        $purchasePayments = PurchaseOrderPayment::query()->where('paid_at', '<=', $cutoffUtc)->get(['id', 'method']);
        $definitions = [
            'sales' => [SalesOrder::query()->withTrashed()->whereIn('status', ['confirmed', 'processing', 'completed'])->whereDate('date', '<=', $cutoff)->count(), 'sales_order_recognition'],
            'sales_payments' => [$salesPayments->filter(fn (SalesOrderPayment $payment): bool => in_array(PaymentMethod::liquidityBucket($payment->method), [PaymentMethod::LIQUIDITY_CASH, PaymentMethod::LIQUIDITY_BANK], true) || $payment->method === 'store_credit')->count(), 'sales_order_payment'],
            'returns' => [SalesReturn::query()->withTrashed()->where('status', 'approved')->where('approved_at', '<=', $cutoffUtc)->count(), 'sales_return_recognition'],
            'purchases' => [PurchaseOrder::query()->withTrashed()->whereIn('status', ['partial', 'received'])->whereDate('order_date', '<=', $cutoff)->count(), 'purchase_order_recognition'],
            'purchase_payments' => [$purchasePayments->filter(fn (PurchaseOrderPayment $payment): bool => in_array(PaymentMethod::liquidityBucket($payment->method), [PaymentMethod::LIQUIDITY_CASH, PaymentMethod::LIQUIDITY_BANK], true))->count(), 'purchase_order_payment'],
            'capital' => [CapitalMovement::query()->whereDate('movement_date', '<=', $cutoff)->count(), 'capital_movement'],
        ];

        $coverage = [];
        foreach ($definitions as $key => [$source, $sourceType]) {
            $posted = JournalEntry::query()->where('source_type', $sourceType)->where('occurred_at', '<=', $cutoffUtc)->distinct('source_id')->count('source_id');
            $coverage[$key] = ['source' => $source, 'posted' => $posted, 'missing' => max(0, $source - $posted)];
        }

        return $coverage;
    }

    /** @param Collection<int, array<string, mixed>> $accounts */
    private function controlAccountReconciliation(Collection $accounts, Carbon $cutoffUtc, Carbon $asOf): Collection
    {
        $ledger = fn (string $group): float => $this->money($accounts->where('report_group', $group)->sum('balance'));
        $bankSubledger = FinancialAccount::query()->get()->sum(function (FinancialAccount $account) use ($cutoffUtc): float {
            $movements = FinancialAccountMovement::query()
                ->where('financial_account_id', $account->id)
                ->where('business_datetime', '<=', $cutoffUtc)
                ->get(['direction', 'amount']);

            return (float) $account->opening_balance + $movements->sum(fn (FinancialAccountMovement $movement): float => $movement->direction === 'in' ? (float) $movement->amount : -(float) $movement->amount);
        });
        $inventorySubledger = InventoryLot::query()->where('received_at', '<=', $cutoffUtc)->get(['quantity_available', 'landed_unit_cost'])
            ->sum(fn (InventoryLot $lot): float => (float) $lot->quantity_available * (float) $lot->landed_unit_cost);
        $accountsReceivable = $this->accountsReceivableSubledger($cutoffUtc);
        $accountsPayable = $this->accountsPayableSubledger($cutoffUtc);
        $currentCutoff = $asOf->isToday();
        $cashPositions = \App\Models\Warehouse::query()->get()->map(fn ($warehouse) => app(WarehouseCashService::class)->availability((int) $warehouse->id, $cutoffUtc->copy()->min(now())));
        $cashVerified = $cashPositions->isNotEmpty() && $cashPositions->every(fn ($position) => $position['initialized'] && $position['balance'] !== null);
        $cashSubledger = $cashPositions->sum(fn ($position) => (float) ($position['balance'] ?? 0));

        $rows = collect([
            $this->controlRow('cash', 'Efectivo consolidado de sucursales', $ledger('cash'), $cashSubledger, $cashVerified),
            $this->controlRow('bank', 'Banco', $ledger('bank'), $bankSubledger, true),
            $this->controlRow('accounts_receivable', 'Cuentas por cobrar', $ledger('accounts_receivable'), $accountsReceivable, $currentCutoff),
            $this->controlRow('inventory', 'Inventario FIFO', $ledger('inventory'), $inventorySubledger, $currentCutoff),
            $this->controlRow('accounts_payable', 'Cuentas por pagar', $ledger('accounts_payable'), $accountsPayable, $currentCutoff),
        ]);

        return $rows;
    }

    private function accountsReceivableSubledger(Carbon $cutoffUtc): float
    {
        $cutoffLocal = $cutoffUtc->copy()->timezone(self::TIMEZONE);
        $adjustments = FinancialAdjustment::query()
            ->where('type', FinancialAdjustment::TYPE_ACCOUNTS_RECEIVABLE)
            ->where('is_legacy', false)
            ->whereNotNull('sales_order_id')
            ->where(function (Builder $query) use ($cutoffUtc, $cutoffLocal): void {
                $query->where('business_datetime', '<=', $cutoffUtc)
                    ->orWhere(function (Builder $fallback) use ($cutoffLocal): void {
                        $fallback->whereNull('business_datetime')->whereDate('adjustment_date', '<=', $cutoffLocal);
                    });
            })
            ->get(['sales_order_id', 'amount', 'direction'])
            ->groupBy('sales_order_id')
            ->map(fn (Collection $rows): float => $rows->sum(fn (FinancialAdjustment $adjustment): float => $adjustment->signedAmount()));

        $orders = SalesOrder::query()
            ->withTrashed()
            ->whereDate('date', '<=', $cutoffLocal)
            ->where(function (Builder $query): void {
                $query->whereIn('status', ['confirmed', 'processing', 'completed'])
                    ->orWhere(function (Builder $cancelled): void {
                        $cancelled->where('status', 'cancelled')->whereNotNull('cancellation_return_id');
                    });
            })
            ->with([
                'payments' => fn ($query) => $query->where('paid_at', '<=', $cutoffUtc),
                'returns' => fn ($query) => $query->withTrashed()->where('status', 'approved')->where('approved_at', '<=', $cutoffUtc),
            ])
            ->get();

        return $this->money($orders->sum(function (SalesOrder $order) use ($adjustments): float {
            $receipts = $order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => (float) $payment->amount > 0
                    && $payment->sales_return_id === null
                    && (! PaymentMethod::isDeferredCode($payment->method) || $payment->method === 'store_credit'))
                ->sum('amount');
            $returns = $order->returns->sum('total_amount');
            $adjustment = (float) ($adjustments->get($order->id) ?? 0);

            return max(0, (float) $order->total - (float) $returns - (float) $receipts + $adjustment);
        }));
    }

    private function accountsPayableSubledger(Carbon $cutoffUtc): float
    {
        $cutoffLocal = $cutoffUtc->copy()->timezone(self::TIMEZONE);
        $adjustments = FinancialAdjustment::query()
            ->where('type', FinancialAdjustment::TYPE_ACCOUNTS_PAYABLE)
            ->where('is_legacy', false)
            ->whereNotNull('purchase_order_id')
            ->where(function (Builder $query) use ($cutoffUtc, $cutoffLocal): void {
                $query->where('business_datetime', '<=', $cutoffUtc)
                    ->orWhere(function (Builder $fallback) use ($cutoffLocal): void {
                        $fallback->whereNull('business_datetime')->whereDate('adjustment_date', '<=', $cutoffLocal);
                    });
            })
            ->get(['purchase_order_id', 'amount', 'direction'])
            ->groupBy('purchase_order_id')
            ->map(fn (Collection $rows): float => $rows->sum(fn (FinancialAdjustment $adjustment): float => $adjustment->signedAmount()));

        $orders = PurchaseOrder::query()
            ->withTrashed()
            ->whereIn('status', ['partial', 'received'])
            ->whereDate('order_date', '<=', $cutoffLocal)
            ->with([
                'items',
                'payments' => fn ($query) => $query->where('paid_at', '<=', $cutoffUtc),
            ])
            ->get();

        return $this->money($orders->sum(function (PurchaseOrder $order) use ($adjustments): float {
            $recognized = $order->items->sum(function ($item): float {
                $ordered = max(1, (int) $item->quantity_ordered);
                $ratio = min(1, max(0, (int) $item->quantity_received) / $ordered);

                return ((float) $item->subtotal + (float) $item->tax_amount + (float) ($item->misc_cost ?? 0)) * $ratio;
            });
            if ($order->status === 'received') {
                $recognized += (float) ($order->shipping_cost ?? 0);
            }

            $payments = $order->payments
                ->filter(fn (PurchaseOrderPayment $payment): bool => (float) $payment->amount > 0
                    && in_array(PaymentMethod::liquidityBucket($payment->method), [PaymentMethod::LIQUIDITY_CASH, PaymentMethod::LIQUIDITY_BANK], true))
                ->sum('amount');
            $adjustment = (float) ($adjustments->get($order->id) ?? 0);

            return max(0, $recognized - (float) $payments + $adjustment);
        }));
    }

    /** @return array<string, mixed> */
    private function controlRow(string $key, string $label, float $ledger, float $subledger, bool $verified): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ledger_balance' => $this->money($ledger),
            'subledger_balance' => $this->money($subledger),
            'difference' => $this->money($ledger - $subledger),
            'verified' => $verified,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $accounts */
    private function bankAccounts(Collection $accounts): array
    {
        $balances = $accounts->keyBy('id');

        return FinancialAccount::query()->with('ledgerAccount')->orderBy('name')->get()->map(function (FinancialAccount $account) use ($balances): array {
            $ledger = $balances->get($account->ledger_account_id);

            return [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'masked_number' => $account->masked_number,
                'allow_negative_balance' => $account->allow_negative_balance,
                'is_legacy' => $account->is_legacy,
                'ledger_account_code' => $account->ledgerAccount?->code,
                'balance' => $this->money($ledger['balance'] ?? 0),
            ];
        })->values()->all();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
