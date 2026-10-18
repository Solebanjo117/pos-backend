<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\SalesOrderPayment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read original cash documents without importing them into the cash ledger. */
class CashHistoryQueryService
{
    public function query(int $warehouseId): Builder
    {
        $ledger = DB::query()->fromSub(app(CashPositionReportService::class)->query($warehouseId), 'l')
            ->selectRaw("l.id, 'ledger' as origin, l.source_type, l.source_id, l.source_line_key, l.amount, l.occurred_at, l.created_by, l.user_name, l.reference, l.business_datetime, l.method, l.pos_session_id, l.sales_order_id, l.sales_return_id, l.receipt_id, l.transfer_id, l.description, l.kind, l.effective_at, l.reporting_document_url, 'ledger' as warehouse_origin");

        $documents = DB::query()->fromSub(app(CashPaymentQueryService::class)->query($warehouseId, ['scope' => 'sales']), 'm')
            ->leftJoin('payment_methods as pm', 'pm.code', '=', 'm.method')
            ->whereRaw("COALESCE(m.liquidity_bucket, pm.liquidity_bucket, CASE WHEN m.method = 'cash' THEN 'cash' END) = 'cash'")
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('warehouse_cash_entries as e')->whereColumn('e.source_id', 'm.id')
                    ->where(fn ($scope) => $scope->where(fn ($sale) => $sale->where('m.scope', 'sales')->where('e.source_type', SalesOrderPayment::class))
                        ->orWhere(fn ($receipt) => $receipt->where('m.scope', 'receipts')->where('e.source_type', AccountPaymentBatch::class)));
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('warehouse_cash_entries as e')->join('account_payment_batches as b', 'b.cash_movement_id', '=', 'e.source_id')
                    ->where('m.scope', 'receipts')->whereColumn('b.id', 'm.id')->where('e.source_type', CashMovement::class);
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('warehouse_cash_entries as e')->join('cash_movements as c', 'c.id', '=', 'e.source_id')
                    ->where('e.source_type', CashMovement::class)->where('m.scope', 'sales')->where('m.amount', '<', 0)
                    ->where('c.source_type', 'sales_return')->where('c.subtype', 'sales_return_refund')->whereColumn('c.source_id', 'm.return_id');
            })
            ->selectRaw("m.id, m.scope as origin, CASE WHEN m.scope = 'sales' THEN ? ELSE ? END as source_type, m.id as source_id, 'main' as source_line_key, m.amount, m.created_at as occurred_at, m.user_id as created_by, m.user_name, COALESCE(m.reference_number, m.document_number) as reference, m.business_datetime, m.method, m.pos_session_id, CASE WHEN m.scope = 'sales' THEN m.document_id END as sales_order_id, m.return_id as sales_return_id, CASE WHEN m.scope = 'receipts' THEN m.id END as receipt_id, NULL as transfer_id, m.document_number as description, CASE WHEN m.amount < 0 THEN 'return' ELSE 'sale' END as kind, m.business_datetime as effective_at, NULL as reporting_document_url, CASE WHEN m.scope = 'receipts' THEN 'receipt_or_session' ELSE 'document_or_payment' END as warehouse_origin", [SalesOrderPayment::class, AccountPaymentBatch::class]);

        $movements = DB::table('cash_movements as c')->join('pos_sessions as s', 's.id', '=', 'c.pos_session_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('financial_transfers as ft', fn ($j) => $j->on('ft.id', '=', 'c.source_id')->where('c.source_type', 'financial_transfer'))
            ->leftJoin('operational_payments as op', fn ($j) => $j->on('op.id', '=', 'c.source_id')->where('c.source_type', \App\Models\OperationalPayment::class))
            ->where('s.warehouse_id', $warehouseId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('warehouse_cash_entries as e')->where('e.source_type', CashMovement::class)->whereColumn('e.source_id', 'c.id'))
            // A customer receipt is one full receipt, not its linked movement or applications.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('account_payment_batches as b')->leftJoin('pos_sessions as bs', 'bs.id', '=', 'b.pos_session_id')->where('b.account_type', 'customer')->where('b.direction', 'incoming')->whereColumn('b.cash_movement_id', 'c.id')->whereRaw('COALESCE(b.warehouse_id, bs.warehouse_id) = ?', [$warehouseId]))
            // Monetary return payments already describe the same refund.
            ->whereNotExists(function ($q) use ($warehouseId): void {
                $q->selectRaw('1')->fromSub(app(CashPaymentQueryService::class)->query($warehouseId, ['scope' => 'sales']), 'p')->leftJoin('payment_methods as pm', 'pm.code', '=', 'p.method')
                    ->where('p.scope', 'sales')->where('c.subtype', 'sales_return_refund')->where('c.source_type', 'sales_return')->whereColumn('p.return_id', 'c.source_id')->where('p.amount', '<', 0)
                    ->whereRaw("COALESCE(p.liquidity_bucket, pm.liquidity_bucket, CASE WHEN p.method = 'cash' THEN 'cash' END) = 'cash'");
            })
            ->selectRaw("c.id, 'movements' as origin, ? as source_type, c.id as source_id, 'main' as source_line_key, CASE WHEN c.type = 'out' THEN -c.amount ELSE c.amount END as amount, c.created_at as occurred_at, c.user_id as created_by, u.name as user_name, COALESCE(c.reference_number, ft.folio) as reference, c.business_datetime, COALESCE(op.method, c.payment_method, 'cash') as method, c.pos_session_id, NULL as sales_order_id, CASE WHEN c.source_type = 'sales_return' THEN c.source_id END as sales_return_id, NULL as receipt_id, ft.id as transfer_id, c.reason as description, CASE WHEN ft.id IS NOT NULL OR c.subtype = 'financial_transfer' THEN 'transfer' WHEN c.subtype = 'sales_return_refund' THEN 'return' WHEN c.subtype = 'purchase_payment' THEN 'purchase' WHEN c.subtype = 'funding' OR op.direction = 'in' THEN 'funding' WHEN c.subtype IN ('expense', 'operational_expense', 'operational_expense_payment') OR op.direction = 'out' THEN 'expense' ELSE 'adjustment' END as kind, COALESCE(c.business_datetime, c.created_at) as effective_at, NULL as reporting_document_url, 'session' as warehouse_origin", [CashMovement::class]);

        return DB::query()->fromSub($ledger->unionAll($documents)->unionAll($movements), 'cash_history')->select('*');
    }

    public function hasUnscopedDocuments(): bool
    {
        $receipts = DB::table('account_payment_batches as b')->leftJoin('payment_methods as pm', 'pm.code', '=', 'b.method')
            ->where('b.account_type', 'customer')->where('b.direction', 'incoming')->whereNull('b.warehouse_id')->whereNull('b.pos_session_id')
            ->whereRaw("COALESCE(b.liquidity_bucket, pm.liquidity_bucket, CASE WHEN b.method = 'cash' THEN 'cash' END) = 'cash'")->exists();
        $items = DB::table('sales_order_items')->selectRaw('sales_order_id, MIN(warehouse_id) as warehouse_id')->groupBy('sales_order_id')->havingRaw('COUNT(DISTINCT warehouse_id) = 1');
        $sales = DB::table('sales_order_payments as p')->join('sales_orders as d', 'd.id', '=', 'p.sales_order_id')
            ->leftJoin('pos_sessions as s', 's.id', '=', 'd.pos_session_id')->leftJoin('sales_returns as r', 'r.id', '=', 'p.sales_return_id')->leftJoinSub($items, 'iw', 'iw.sales_order_id', '=', 'd.id')
            ->leftJoin('payment_methods as pm', 'pm.code', '=', 'p.method')->whereNull('p.account_payment_batch_id')
            ->whereRaw("COALESCE(p.liquidity_bucket, pm.liquidity_bucket, CASE WHEN p.method = 'cash' THEN 'cash' END) = 'cash'")
            ->whereRaw('COALESCE(p.warehouse_id, r.warehouse_id, s.warehouse_id, iw.warehouse_id) IS NULL')->exists();

        return $receipts || $sales;
    }
}
