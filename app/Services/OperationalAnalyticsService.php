<?php

namespace App\Services;

use App\Models\SalesCreditNote;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OperationalAnalyticsService
{
    /** @param array<string, mixed> $filters */
    public function salesByProduct(array $filters): array
    {
        $returns = DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
            ->where('sr.status', 'approved')
            ->groupBy('sri.sales_order_item_id')
            ->selectRaw('sri.sales_order_item_id, SUM(sri.quantity) as returned_quantity, SUM(sri.subtotal) as returned_subtotal');

        $query = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'soi.variant_id')
            ->leftJoinSub($returns, 'returned', 'returned.sales_order_item_id', '=', 'soi.id')
            ->whereIn('so.status', ['confirmed', 'processing', 'completed'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('so.date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('so.date', '<=', $date))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('soi.warehouse_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(function (Builder $nested) use ($like): void {
                    $nested->whereRaw('LOWER(p.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(p.sku_base) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(pv.sku) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(soi.custom_description) LIKE ?', [$like]);
                });
            })
            ->groupBy('soi.product_id', 'soi.variant_id', 'p.name', 'p.sku_base', 'pv.sku', 'soi.custom_description')
            ->selectRaw("soi.product_id, soi.variant_id, COALESCE(p.name, soi.custom_description, 'Articulo') as product_name, COALESCE(pv.sku, p.sku_base, 'MISC') as sku")
            ->selectRaw('SUM(soi.quantity) as quantity_sold')
            ->selectRaw('SUM(COALESCE(returned.returned_quantity, 0)) as quantity_returned')
            ->selectRaw('SUM(soi.subtotal) as gross_sales')
            ->selectRaw('SUM(COALESCE(returned.returned_subtotal, 0)) as returns_amount')
            ->selectRaw('SUM(soi.subtotal - COALESCE(returned.returned_subtotal, 0)) as net_sales')
            ->selectRaw('SUM((soi.quantity - COALESCE(returned.returned_quantity, 0)) * soi.unit_cost) as cost')
            ->orderByDesc('net_sales');

        return $this->paginateWithSummary($query, $filters, [
            'quantity_sold', 'quantity_returned', 'gross_sales', 'returns_amount', 'net_sales', 'cost',
        ], function (array $row): array {
            $row['profit'] = round((float) $row['net_sales'] - (float) $row['cost'], 2);

            return $row;
        });
    }

    /** @param array<string, mixed> $filters */
    public function purchasesBySupplier(array $filters): array
    {
        $payments = DB::table('purchase_order_payments')
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM(amount) as paid_amount');

        $query = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoinSub($payments, 'paid', 'paid.purchase_order_id', '=', 'po.id')
            ->whereNull('po.deleted_at')
            ->whereIn('po.status', ['partial', 'received'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('po.order_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('po.order_date', '<=', $date))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('po.warehouse_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->whereRaw('LOWER(s.name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->groupBy('po.supplier_id', 's.name')
            ->selectRaw('po.supplier_id, s.name as supplier_name, COUNT(po.id) as orders_count')
            ->selectRaw('SUM(po.total) as purchases_total')
            ->selectRaw('SUM(COALESCE(paid.paid_amount, 0)) as paid_total')
            ->selectRaw('SUM(po.total - COALESCE(paid.paid_amount, 0)) as payable_total')
            ->orderByDesc('purchases_total');

        return $this->paginateWithSummary($query, $filters, ['orders_count', 'purchases_total', 'paid_total', 'payable_total']);
    }

    /** @param array<string, mixed> $filters */
    public function creditNotes(array $filters): array
    {
        $query = SalesCreditNote::query()
            ->with(['salesReturn:id,return_number,warehouse_id', 'salesOrder:id,order_number', 'customer:id,name'])
            ->when($filters['date_from'] ?? null, fn ($q, string $date) => $q->whereDate('issued_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, string $date) => $q->whereDate('issued_at', '<=', $date))
            ->when($filters['warehouse_id'] ?? null, fn ($q, int $id) => $q->whereHas('salesReturn', fn ($returnQuery) => $returnQuery->where('warehouse_id', $id)))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('credit_note_number', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']));
                });
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id');

        $summaryQuery = clone $query;
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($paginator->items())->map(fn (SalesCreditNote $note): array => [
                'id' => $note->id,
                'credit_note_number' => $note->credit_note_number,
                'return_number' => $note->salesReturn?->return_number,
                'order_number' => $note->salesOrder?->order_number,
                'customer_name' => $note->customer?->name,
                'total_amount' => (float) $note->total_amount,
                'receivable_amount' => (float) $note->receivable_amount,
                'refund_amount' => (float) $note->refund_amount,
                'store_credit_amount' => (float) $note->store_credit_amount,
                'status' => $note->status,
                'issued_at' => $note->issued_at?->toISOString(),
            ])->all(),
            'summary' => [
                'count' => (clone $summaryQuery)->count(),
                'total_amount' => round((float) (clone $summaryQuery)->sum('total_amount'), 2),
                'receivable_amount' => round((float) (clone $summaryQuery)->sum('receivable_amount'), 2),
                'refund_amount' => round((float) (clone $summaryQuery)->sum('refund_amount'), 2),
                'store_credit_amount' => round((float) (clone $summaryQuery)->sum('store_credit_amount'), 2),
            ],
            'meta' => $this->meta($paginator),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function valuedInventory(array $filters): array
    {
        $query = DB::table('inventory_lots as il')
            ->join('products as p', 'p.id', '=', 'il.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'il.variant_id')
            ->join('warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->where('il.quantity_available', '>', 0)
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('il.warehouse_id', $id))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('il.received_at', '<=', $date))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(function (Builder $nested) use ($like): void {
                    $nested->whereRaw('LOWER(p.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(p.sku_base) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(pv.sku) LIKE ?', [$like]);
                });
            })
            ->groupBy('il.product_id', 'il.variant_id', 'il.warehouse_id', 'p.name', 'p.sku_base', 'pv.sku', 'w.name')
            ->selectRaw('il.product_id, il.variant_id, il.warehouse_id, p.name as product_name, COALESCE(pv.sku, p.sku_base) as sku, w.name as warehouse_name')
            ->selectRaw('SUM(il.quantity_available) as quantity_available')
            ->selectRaw('SUM(il.quantity_available * il.landed_unit_cost) as inventory_value')
            ->selectRaw('CASE WHEN SUM(il.quantity_available) = 0 THEN 0 ELSE SUM(il.quantity_available * il.landed_unit_cost) / SUM(il.quantity_available) END as average_cost')
            ->orderByDesc('inventory_value');

        return $this->paginateWithSummary($query, $filters, ['quantity_available', 'inventory_value']);
    }

    /** @param array<string, mixed> $filters @param array<int, string> $sumColumns */
    private function paginateWithSummary(Builder $query, array $filters, array $sumColumns, ?callable $map = null): array
    {
        $summaryRows = (clone $query)->get();
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 25));
        $data = collect($paginator->items())->map(function ($row) use ($map): array {
            $array = (array) $row;
            foreach ($array as $key => $value) {
                if (is_numeric($value)) {
                    $array[$key] = str_ends_with($key, '_id') ? (int) $value : round((float) $value, 2);
                }
            }

            return $map ? $map($array) : $array;
        })->all();

        $summary = ['count' => $summaryRows->count()];
        foreach ($sumColumns as $column) {
            $summary[$column] = round((float) $summaryRows->sum($column), 2);
        }
        if (in_array('net_sales', $sumColumns, true) && in_array('cost', $sumColumns, true)) {
            $summary['profit'] = round((float) $summary['net_sales'] - (float) $summary['cost'], 2);
        }

        return ['data' => $data, 'summary' => $summary, 'meta' => $this->meta($paginator)];
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
