<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\SalesOrderPayment;
use App\Models\WarehouseCashBalance;
use App\Models\WarehouseCashEntry;
use App\Support\RegionalDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CashPositionReportService
{
    public function normalize(object $row, ?float $opening): array
    {
        $opening = isset($row->tracked_opening_balance) ? (float) $row->tracked_opening_balance : $opening;
        $included = isset($row->included_in_cash_balance) ? (bool) $row->included_in_cash_balance : true;
        $row->balance = ! $included || $opening === null ? null : round($opening + (float) $row->running_change, 2);
        $row->included_in_cash_balance = $included;
        $row->row_key = ($row->origin ?? 'ledger').':'.$row->id;
        $row->document_url = $row->reporting_document_url ?? ($row->transfer_id ? '/finance/transfers/'.$row->transfer_id : ($row->sales_return_id ? '/returns/'.$row->sales_return_id : ($row->sales_order_id ? '/sales-orders/'.$row->sales_order_id : null)));
        foreach (['occurred_at', 'business_datetime', 'effective_at'] as $field) {
            $row->$field = $row->$field ? \Carbon\Carbon::parse($row->$field, 'UTC')->toISOString() : null;
        }
        $row->amount = (float) $row->amount;

        return (array) $row;
    }

    public function bounds(array $filters): array
    {
        $mode = $filters['mode'] ?? 'period';
        [$from, $to] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
        if ($mode === 'current') {
            $to = now();
        } elseif ($mode === 'as_of') {
            [, $to] = RegionalDateRange::utcBounds($filters['as_of'] ?? $filters['date_to'], $filters['as_of'] ?? $filters['date_to']);
        }

        return [$from, $to->min(now())];
    }

    public function query(int $warehouseId, ?WarehouseCashBalance $baseline = null): Builder
    {
        $entries = WarehouseCashEntry::query()->where('warehouse_id', $warehouseId);
        $query = DB::query()->fromSub($entries, 'e')
            ->leftJoin('cash_movements as cm', fn ($j) => $j->on('cm.id', '=', 'e.source_id')->where('e.source_type', CashMovement::class))
            ->leftJoin('sales_order_payments as sp', fn ($j) => $j->on('sp.id', '=', 'e.source_id')->where('e.source_type', SalesOrderPayment::class))
            ->leftJoin('account_payment_batches as ab', fn ($j) => $j->on('ab.id', '=', 'e.source_id')->where('e.source_type', AccountPaymentBatch::class))
            ->leftJoin('financial_transfers as ft', fn ($j) => $j->on('ft.id', '=', 'cm.source_id')->where('cm.source_type', 'financial_transfer'))
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->selectRaw("e.*, u.name as user_name, COALESCE(e.reporting_reference, cm.reference_number, sp.reference_number, ab.reference_number, ft.folio) as reference, COALESCE(e.reporting_business_datetime, cm.business_datetime, sp.business_datetime, ab.business_datetime) as business_datetime, COALESCE(e.reporting_method, sp.method, ab.method, cm.payment_method, 'cash') as method, COALESCE(e.reporting_session_id, cm.pos_session_id, sp.pos_session_id, ab.pos_session_id) as pos_session_id, sp.sales_order_id, sp.sales_return_id, ab.id as receipt_id, ft.id as transfer_id, COALESCE(cm.reason, e.reason) as description")
            ->selectRaw("COALESCE(e.reporting_kind, CASE WHEN ft.id IS NOT NULL OR cm.subtype = 'financial_transfer' THEN 'transfer' WHEN sp.amount < 0 OR cm.subtype = 'sales_return_refund' THEN 'return' WHEN sp.id IS NOT NULL OR ab.direction = 'incoming' THEN 'sale' WHEN cm.subtype = 'purchase_payment' THEN 'purchase' WHEN cm.subtype = 'funding' OR (cm.subtype = 'operational_payment' AND cm.type = 'in') THEN 'funding' WHEN cm.subtype IN ('operational_expense', 'expense', 'operational_expense_payment') OR (cm.subtype = 'operational_payment' AND cm.type = 'out') THEN 'expense' ELSE 'adjustment' END) as kind");

        $query->selectRaw('COALESCE(e.reporting_business_datetime, cm.business_datetime, sp.business_datetime, sp.paid_at, ab.business_datetime, ab.paid_at, e.occurred_at) as effective_at');

        return DB::query()->fromSub($query, 'cash_entries')->select('*')
            ->when($baseline, fn ($q) => $q->where(fn ($scope) => $scope->where('effective_at', '>', $baseline->cutoff_at)->orWhere(fn ($same) => $same->where('effective_at', $baseline->cutoff_at)->where('id', '>', $baseline->starting_entry_id))));
    }

    public function report(array $filters, bool $export = false): array
    {
        $warehouseId = (int) $filters['warehouse_id'];
        $baseline = WarehouseCashBalance::where('warehouse_id', $warehouseId)->first();
        [$from, $to] = $this->bounds($filters);
        $all = $this->query($warehouseId, $baseline)->where('effective_at', '<=', $to);
        $asOfAvailable = $baseline && $to->gte($baseline->cutoff_at);
        $startAvailable = $baseline && $from->gte($baseline->cutoff_at) && $from->lte($to);
        $runningOpening = $asOfAvailable ? round((float) $baseline->opening_balance + (float) (clone $all)->where('effective_at', '<', $from)->sum('amount'), 2) : null;
        $initial = $startAvailable ? $runningOpening : null;
        $closing = $asOfAvailable ? round((float) $baseline->opening_balance + (float) (clone $all)->sum('amount'), 2) : null;
        [, $historyTo] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
        $historyTo = $historyTo->min($to);
        $historyService = app(CashHistoryQueryService::class);
        $history = $historyService->query($warehouseId);
        $range = (clone $history)->where('effective_at', '>=', $from)->where('effective_at', '<=', $historyTo);
        $untracked = (clone $range)->where('origin', '!=', 'ledger')->count();
        $missingAfterCutoff = $baseline ? (clone $history)->where('origin', '!=', 'ledger')->where('effective_at', '>', $baseline->cutoff_at)->where('effective_at', '<=', $to)->count() : 0;
        if ($missingAfterCutoff) {
            $closing = null;
            $runningOpening = null;
            $initial = null;
        }
        $totals = (clone $range)->select([])->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as incoming, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as outgoing')->first();
        $categories = (clone $range)->select('kind')->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as incoming, SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as outgoing, SUM(amount) as net')->groupBy('kind')->get();
        $marked = (clone $range)->when($baseline && $asOfAvailable && ! $missingAfterCutoff,
            fn ($q) => $q->selectRaw("CASE WHEN origin = 'ledger' AND (effective_at > ? OR (effective_at = ? AND id > ?)) THEN 1 ELSE 0 END as included_in_cash_balance", [$baseline->cutoff_at, $baseline->cutoff_at, $baseline->starting_entry_id]),
            fn ($q) => $q->selectRaw('0 as included_in_cash_balance'));
        $window = DB::query()->fromSub($marked, 'marked')->select('*')
            ->selectRaw('SUM(CASE WHEN included_in_cash_balance = 1 THEN amount ELSE 0 END) OVER (ORDER BY effective_at, origin, id ROWS UNBOUNDED PRECEDING) as running_change')
            ->selectRaw('CAST(? AS DECIMAL(18,2)) as tracked_opening_balance', [$runningOpening]);
        $detail = DB::query()->fromSub($window, 'detail')->select('*');
        foreach (['user_id' => 'created_by', 'kind' => 'kind', 'method' => 'method', 'pos_session_id' => 'pos_session_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $detail->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $like = '%'.mb_strtolower($filters['search']).'%';
            $detail->where(fn ($q) => $q->whereRaw('LOWER(description) LIKE ?', [$like])->orWhereRaw('LOWER(reference) LIKE ?', [$like]));
        }
        $detail->orderBy('effective_at')->orderBy('origin')->orderBy('id');
        $rangeCount = (clone $range)->count();
        $historySummary = ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'effective_to' => $historyTo->toISOString(), 'records_in_range' => $rangeCount, 'untracked_records' => $untracked,
            'outside_range_records' => max(0, (clone $history)->where('effective_at', '<=', now())->count() - $rangeCount)];
        $summary = ['initialized' => (bool) $baseline, 'historical_available' => (bool) ($asOfAvailable && $startAvailable && ! $missingAfterCutoff), 'balance_complete' => (bool) ($asOfAvailable && ! $missingAfterCutoff), 'opening_balance' => $initial, 'incoming' => round((float) $totals->incoming, 2), 'outgoing' => round((float) $totals->outgoing, 2), 'closing_balance' => $closing, 'cutoff_at' => $baseline?->cutoff_at, 'effective_as_of' => $to->toISOString(), 'warehouse_id' => $warehouseId];
        $warnings = ! $baseline ? ['Saldo sin inicializar.'] : (! $startAvailable || ! $asOfAvailable ? ['Saldo histórico no disponible para todo el período anterior a la conciliación.'] : []);
        if ($untracked) {
            $warnings[] = 'El historial incluye documentos originales sin registro en el libro de efectivo. No se utilizan para calcular el saldo disponible.';
        }
        if ($missingAfterCutoff) {
            $warnings[] = 'Hay documentos sin registro en el libro posteriores al corte conciliado. Revise su trazabilidad antes de utilizar el saldo disponible.';
        }
        if ($historyService->hasUnscopedDocuments()) {
            $warnings[] = 'Hay documentos de efectivo sin sucursal identificable de forma única. No se atribuyen a esta sucursal; revise el documento original.';
        }
        if ($export) {
            return ['query' => $detail, 'summary' => $summary, 'history_summary' => $historySummary, 'warnings' => $warnings];
        }
        $paginator = $detail->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $paginator->getCollection()->transform(fn ($row) => $this->normalize($row, $initial));

        return ['data' => $paginator->items(), 'summary' => $summary, 'history_summary' => $historySummary, 'categories' => $categories, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()], 'warnings' => $warnings];
    }
}
