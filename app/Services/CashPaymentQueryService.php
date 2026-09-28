<?php

namespace App\Services;

use App\Models\SalesOrderPayment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CashPaymentQueryService
{
    public function normalizeRow(object|array $row): array
    {
        $row = (array) $row;
        $row['created_at'] = \Carbon\Carbon::parse($row['created_at'], 'UTC')->toISOString();

        return $row;
    }

    public function query(int $warehouseId, array $filters): Builder
    {
        $sales = SalesOrderPayment::query()->from('sales_order_payments as p')->join('sales_orders as d', 'd.id', '=', 'p.sales_order_id')
            ->leftJoin('pos_sessions as s', 's.id', '=', 'd.pos_session_id')->leftJoin('sales_returns as r', 'r.id', '=', 'p.sales_return_id')->leftJoinSub(DB::table('sales_order_items')->selectRaw('sales_order_id, MIN(warehouse_id) as warehouse_id')->groupBy('sales_order_id')->havingRaw('COUNT(DISTINCT warehouse_id) = 1'), 'iw', 'iw.sales_order_id', '=', 'd.id')
            ->selectRaw("p.id, 'sales' as scope, d.id as document_id, COALESCE(r.return_number, d.order_number) as document_number, p.method, p.liquidity_bucket, p.amount, p.financial_account_id, p.reference_number, p.created_at, p.created_by as user_id, COALESCE(p.pos_session_id, d.pos_session_id) as pos_session_id, COALESCE(p.warehouse_id, r.warehouse_id, s.warehouse_id, iw.warehouse_id) as warehouse_id, p.sales_return_id as return_id")
            ->whereRaw('COALESCE(p.warehouse_id, r.warehouse_id, s.warehouse_id, iw.warehouse_id) = ?', [$warehouseId])->whereNotIn('p.method', \App\Models\PaymentMethod::DEFERRED_CODES)->toBase();
        $purchases = DB::table('purchase_order_payments as p')->join('purchase_orders as d', 'd.id', '=', 'p.purchase_order_id')
            ->selectRaw("p.id, 'purchases' as scope, d.id as document_id, d.po_number as document_number, p.method, p.liquidity_bucket, -p.amount as amount, p.financial_account_id, p.reference_number, p.created_at, p.user_id, p.pos_session_id, d.warehouse_id, NULL as return_id")->where('d.warehouse_id', $warehouseId)->whereNotIn('p.method', \App\Models\PaymentMethod::DEFERRED_CODES);
        $operations = DB::table('operational_payments as p')->leftJoin('cash_movements as c', 'c.id', '=', 'p.cash_movement_id')
            ->selectRaw("p.id, 'operations' as scope, p.source_id as document_id, p.source_type as document_number, p.method, p.liquidity_bucket, CASE WHEN p.direction = 'in' THEN p.amount ELSE -p.amount END as amount, p.financial_account_id, p.reference_number, p.created_at, p.created_by as user_id, p.pos_session_id, p.warehouse_id, NULL as return_id")
            ->where('p.warehouse_id', $warehouseId)->whereNull('p.reversed_at');
        $query = DB::query()->fromSub($sales->unionAll($purchases)->unionAll($operations), 'movements')
            ->leftJoin('financial_accounts as account', 'account.id', '=', 'movements.financial_account_id')
            ->leftJoin('users as operator', 'operator.id', '=', 'movements.user_id')
            ->leftJoin('payment_methods as method_record', 'method_record.code', '=', 'movements.method')
            ->select('movements.*', 'account.name as account_name', 'operator.name as user_name', 'method_record.name as method_name');
        $query->where('movements.scope', $filters['scope'] ?? 'sales');
        foreach (['method', 'pos_session_id', 'user_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('movements.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['date_from'])) {
            $query->where('movements.created_at', '>=', \Carbon\Carbon::parse($filters['date_from'], \App\Support\RegionalDateRange::timezone())->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where('movements.created_at', '<=', \Carbon\Carbon::parse($filters['date_to'], \App\Support\RegionalDateRange::timezone())->endOfDay()->utc());
        }
        if (! empty($filters['pending_account'])) {
            $query->where('movements.liquidity_bucket', 'bank')->whereNull('movements.financial_account_id');
        }

        return $query;
    }

    public function summary(int $warehouseId, array $filters): array
    {
        return $this->query($warehouseId, $filters)->select(['movements.method'])->selectRaw('MAX(method_record.name) as method_name, SUM(CASE WHEN movements.amount > 0 THEN movements.amount ELSE 0 END) as incoming, SUM(CASE WHEN movements.amount < 0 THEN -movements.amount ELSE 0 END) as outgoing, SUM(movements.amount) as net')->groupBy('movements.method')->get()->all();
    }
}
