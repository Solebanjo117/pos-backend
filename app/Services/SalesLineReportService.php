<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Support\RegionalDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesLineReportService
{
    public function options(array $filters, \App\Models\User $user): array
    {
        $field = $filters['field'] ?? 'warehouse_id';
        if ($field === 'warehouse_id') {
            $query = \App\Models\Warehouse::query()->when(! $user->isAdmin(), fn ($q) => $q->where('id', $user->getEffectiveWarehouseId()));
        } else {
            $model = match ($field) {
                'product_id' => \App\Models\Product::class, 'brand_id' => \App\Models\Brand::class,
                'category_id' => \App\Models\Category::class, 'customer_id' => \App\Models\Customer::class,
                'seller_id' => \App\Models\User::class,
            };
            $query = $model::query();
        }

        return $query->when($filters['search'] ?? null, fn ($q, $search) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name'])->all();
    }

    private const FIELDS = ['quantity', 'gross_sales', 'discounts', 'net_sales', 'tax', 'cost'];

    public function currentLines(?int $orderId = null): Builder
    {
        $totals = DB::table('sales_order_items')->selectRaw('sales_order_id, SUM(subtotal) as line_subtotal, SUM(tax_amount) as line_tax')->groupBy('sales_order_id');
        $zeroCosts = DB::table('inventory_lot_allocations')->selectRaw('sales_order_item_id, SUM(quantity) as allocated, SUM(total_cost) as cost')->groupBy('sales_order_item_id');
        $lines = DB::table('sales_order_items as i')->join('sales_orders as d', 'd.id', '=', 'i.sales_order_id')->joinSub($totals, 't', 't.sales_order_id', '=', 'd.id')
            ->leftJoinSub($zeroCosts, 'z', 'z.sales_order_item_id', '=', 'i.id')
            ->when($orderId, fn ($q) => $q->where('d.id', $orderId))
            ->selectRaw('i.id, i.sales_order_id, i.quantity, i.subtotal, i.tax_amount as tax, ROUND(i.quantity * i.unit_price, 2) as gross_sales, i.discount_amount as line_discount, t.line_subtotal')
            ->selectRaw('CASE WHEN i.unit_cost > 0 OR (z.allocated >= i.quantity AND z.cost = 0) THEN ROUND(i.quantity * i.unit_cost, 2) ELSE NULL END as cost')
            ->selectRaw('CASE WHEN t.line_subtotal + t.line_tax + d.shipping_cost - d.total > 0 AND d.discount_amount > 0 THEN CASE WHEN d.discount_amount < t.line_subtotal + t.line_tax + d.shipping_cost - d.total THEN d.discount_amount ELSE t.line_subtotal + t.line_tax + d.shipping_cost - d.total END ELSE 0 END as header_discount');
        $allocated = DB::query()->fromSub($lines, 'l')->selectRaw('l.*, CASE WHEN line_subtotal > 0 THEN ROUND(header_discount * 100 * subtotal / line_subtotal, 0) ELSE 0 END as share_cents');
        $balanced = DB::query()->fromSub($allocated, 'a')->selectRaw('a.*, share_cents + CASE WHEN id = MAX(id) OVER (PARTITION BY sales_order_id) THEN ROUND(header_discount * 100, 0) - SUM(share_cents) OVER (PARTITION BY sales_order_id) ELSE 0 END as allocated_cents');

        return DB::query()->fromSub($balanced, 'b')->selectRaw('id, sales_order_id, quantity, gross_sales, ROUND(line_discount + allocated_cents / 100.0, 2) as discounts, ROUND(subtotal - allocated_cents / 100.0, 2) as net_sales, tax, cost');
    }

    public function snapshot(int $orderId): array
    {
        return $this->currentLines($orderId)->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    public function recordAmendment(SalesOrder $order, array $before, string $key): void
    {
        $hasEarlierAudit = collect($order->audit_logs ?? [])->contains(fn ($log) => ($log['action'] ?? '') === 'order_amended');
        foreach ($this->snapshot($order->id) as $id => $after) {
            $previous = $before[$id] ?? array_fill_keys(self::FIELDS, 0);
            $first = DB::table('sales_line_amendments')->where('sales_order_item_id', $id)->orderBy('id')->first();
            $values = ['sales_order_item_id' => $id, 'amendment_key' => $key, 'occurred_at' => now(), 'history_complete' => $first ? $first->history_complete : ! $hasEarlierAudit];
            foreach (self::FIELDS as $field) {
                $values['before_'.$field] = $previous[$field];
                $values[$field] = $after[$field] === null || $previous[$field] === null ? null : round($after[$field] - $previous[$field], 2);
            }
            DB::table('sales_line_amendments')->insert($values);
        }
    }

    public function query(array $filters): Builder
    {
        $first = DB::table('sales_line_amendments')->selectRaw('sales_order_item_id, MIN(id) as first_id')->groupBy('sales_order_item_id');
        $base = DB::query()->fromSub($this->currentLines(), 'v')->join('sales_order_items as i', 'i.id', '=', 'v.id')->join('sales_orders as d', 'd.id', '=', 'i.sales_order_id')
            ->leftJoinSub($first, 'first_revision', 'first_revision.sales_order_item_id', '=', 'i.id')->leftJoin('sales_line_amendments as a', 'a.id', '=', 'first_revision.first_id')
            ->where(fn ($q) => $q->where('a.history_complete', true)->orWhere(fn ($q) => $q->whereNull('a.id')->where(fn ($q) => $q->whereNull('d.audit_logs')->orWhereRaw('CAST(d.audit_logs AS TEXT) NOT LIKE ?', ['%order_amended%']))))
            ->selectRaw("i.id as event_id, i.id as line_id, 'sale' as kind, d.date as occurred_at, CAST(NULL AS BIGINT) as return_id");
        foreach (self::FIELDS as $field) {
            $base->selectRaw("CASE WHEN a.id IS NULL THEN v.$field ELSE a.before_$field END as $field");
        }
        $base->selectRaw('0 as returns_amount')->whereRaw('CASE WHEN a.id IS NULL THEN v.quantity ELSE a.before_quantity END > 0');
        $amendments = DB::table('sales_line_amendments as a')->selectRaw("a.id as event_id, a.sales_order_item_id as line_id, 'amendment' as kind, a.occurred_at, CAST(NULL AS BIGINT) as return_id, a.quantity, a.gross_sales, a.discounts, a.net_sales, a.tax, a.cost, 0 as returns_amount")
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('net_sales', '!=', 0)->orWhere('tax', '!=', 0));
        $returnTotals = DB::table('sales_return_items')->selectRaw('sales_return_id, SUM(subtotal) as subtotal')->groupBy('sales_return_id');
        $returns = DB::table('sales_return_items as r')->join('sales_returns as sr', 'sr.id', '=', 'r.sales_return_id')->join('sales_order_items as i', 'i.id', '=', 'r.sales_order_item_id')
            ->joinSub($returnTotals, 'rt', 'rt.sales_return_id', '=', 'sr.id')
            ->where('sr.status', 'approved')->whereNull('sr.deleted_at')
            ->selectRaw("r.id as event_id, i.id as line_id, 'return' as kind, COALESCE(sr.approved_at, sr.updated_at) as occurred_at, sr.id as return_id, -r.quantity as quantity, 0 as gross_sales, 0 as discounts, -r.subtotal as net_sales, -(ROUND(CASE WHEN rt.subtotal > 0 THEN (sr.total_amount - rt.subtotal) * 100 * r.subtotal / rt.subtotal ELSE 0 END, 0) + CASE WHEN r.id = MAX(r.id) OVER (PARTITION BY sr.id) THEN ROUND((sr.total_amount - rt.subtotal) * 100, 0) - SUM(ROUND(CASE WHEN rt.subtotal > 0 THEN (sr.total_amount - rt.subtotal) * 100 * r.subtotal / rt.subtotal ELSE 0 END, 0)) OVER (PARTITION BY sr.id) ELSE 0 END) / 100.0 as tax, -ROUND(r.quantity * COALESCE(r.reporting_unit_cost, CASE WHEN i.unit_cost > 0 AND NOT EXISTS (SELECT 1 FROM sales_line_amendments la WHERE la.sales_order_item_id = i.id) THEN i.unit_cost ELSE NULL END), 2) as cost, r.subtotal as returns_amount");
        $defaultVariants = DB::table('product_variants')->where('is_default', true)->selectRaw('product_id, MIN(id) as id')->groupBy('product_id');
        $query = DB::query()->fromSub($base->unionAll($amendments)->unionAll($returns), 'e')
            ->join('sales_order_items as i', 'i.id', '=', 'e.line_id')->join('sales_orders as d', 'd.id', '=', 'i.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->leftJoinSub($defaultVariants, 'default_variant', 'default_variant.product_id', '=', 'p.id')
            ->leftJoin('product_variants as dv', 'dv.id', '=', 'default_variant.id')
            ->leftJoin('brands as b', 'b.id', '=', DB::raw('COALESCE(v.brand_id, dv.brand_id)'))->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'd.customer_id')->leftJoin('users as seller', 'seller.id', '=', 'i.seller_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->whereIn('d.status', ['confirmed', 'processing', 'completed'])->whereNull('d.deleted_at')
            ->selectRaw("e.*, d.id as document_id, d.order_number as document_number, i.product_id, i.variant_id, i.warehouse_id, w.name as warehouse_name, COALESCE(v.brand_id, dv.brand_id) as brand_id, p.category_id, d.customer_id, i.seller_id, i.unit_price, CASE WHEN i.is_custom_line THEN 'MISC' ELSE COALESCE(v.sku, p.sku_base, 'MISC') END as sku, CASE WHEN i.is_custom_line THEN COALESCE(i.custom_description, 'Artículo') ELSE COALESCE(p.name, 'Artículo') END as product_name, COALESCE(b.name, 'Sin marca') as brand_name, COALESCE(c.name, 'Sin categoría') as category_name, COALESCE(customer.name, 'Sin cliente') as customer_name, COALESCE(seller.name, 'Sin vendedor') as seller_name");
        foreach (['warehouse_id' => 'i', 'product_id' => 'i', 'category_id' => 'p', 'customer_id' => 'd', 'seller_id' => 'i'] as $field => $alias) {
            if (! empty($filters[$field])) {
                $query->where("$alias.$field", $filters[$field]);
            }
        }
        if (! empty($filters['brand_id'])) {
            $query->whereRaw('COALESCE(v.brand_id, dv.brand_id) = ?', [$filters['brand_id']]);
        }
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            [$from, $to] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
            $query->where(fn ($q) => $q->where(fn ($q) => $q->where('e.kind', 'sale')->whereBetween('e.occurred_at', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59']))->orWhere(fn ($q) => $q->where('e.kind', '!=', 'sale')->whereBetween('e.occurred_at', [$from, $to])));
        }
        if (! empty($filters['search'])) {
            $like = '%'.mb_strtolower($filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(d.order_number) LIKE ?', [$like])->orWhereRaw('LOWER(p.name) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(v.sku, p.sku_base)) LIKE ?', [$like])->orWhereRaw('LOWER(i.custom_description) LIKE ?', [$like]));
        }

        return DB::query()->fromSub($query, 'lines')->select('*');
    }

    private function aggregate(Builder $query): Builder
    {
        return $query->selectRaw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as quantity_sold, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as quantity_returned, SUM(gross_sales) as gross_sales, SUM(discounts) as discounts, SUM(returns_amount) as returns_amount, SUM(net_sales) as net_sales, SUM(tax) as tax, SUM(COALESCE(cost, 0)) as known_cost, SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as missing_cost_count, CASE WHEN SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(cost) END as cost');
    }

    public function rows(array $filters): Builder
    {
        $query = $this->query($filters);
        $group = $filters['group_by'] ?? 'line';
        if ($group === 'line') {
            return $query->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('event_id');
        }
        $fields = match ($group) {
            'brand' => ['brand_id', 'brand_name'], 'category' => ['category_id', 'category_name'],
            'customer' => ['customer_id', 'customer_name'], 'seller' => ['seller_id', 'seller_name'],
            default => ['product_id', 'variant_id', 'sku', 'product_name'],
        };

        return $this->aggregate($query->select($fields))->groupBy($fields)->orderByDesc('net_sales')->orderBy($fields[0]);
    }

    public function normalize(object|array $row): array
    {
        $row = (array) $row;
        if (! empty($row['occurred_at'])) {
            $row['occurred_at'] = \Carbon\Carbon::parse($row['occurred_at'], $row['kind'] === 'sale' ? RegionalDateRange::timezone() : 'UTC')->utc()->toISOString();
        }
        if (array_key_exists('missing_cost_count', $row) && $row['missing_cost_count'] === null) {
            foreach (['quantity_sold', 'quantity_returned', 'gross_sales', 'discounts', 'returns_amount', 'net_sales', 'tax', 'cost', 'known_cost', 'missing_cost_count'] as $field) {
                $row[$field] = 0;
            }
        }
        foreach (['quantity', 'quantity_sold', 'quantity_returned', 'gross_sales', 'discounts', 'returns_amount', 'net_sales', 'tax', 'cost', 'known_cost'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : round((float) $row[$field], 2);
            }
        }
        $row['profit'] = $row['cost'] === null ? null : round($row['net_sales'] - $row['cost'], 2);
        $row['margin'] = $row['profit'] !== null && $row['net_sales'] > 0 ? round($row['profit'] / $row['net_sales'] * 100, 2) : null;
        $row['incomplete'] = $row['cost'] === null;

        return $row;
    }

    public function report(array $filters): array
    {
        $summary = $this->normalize($this->aggregate($this->query($filters)->select([]))->first());
        $paginator = $this->rows($filters)->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $summary['count'] = $paginator->total();
        $uncertain = SalesOrder::query()->whereRaw('CAST(audit_logs AS TEXT) LIKE ?', ['%order_amended%'])
            ->whereHas('items', fn ($q) => $q->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))->whereNotIn('id', DB::table('sales_line_amendments')->where('history_complete', true)->select('sales_order_item_id')))->count();

        return ['data' => collect($paginator->items())->map(fn ($row) => $this->normalize($row))->all(), 'summary' => $summary,
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            'warnings' => $uncertain ? ["$uncertain transacciones ampliadas no tienen evidencia completa de sus líneas originales; esas bases se excluyen del reporte."] : []];
    }

    public function legacy(array $filters, string $group): array
    {
        $result = $this->report([...$filters, 'group_by' => $group, 'per_page' => PHP_INT_MAX]);
        $result['data'] = array_map(fn ($row) => [...$row, 'revenue' => $row['net_sales'], 'cogs' => $row['cost'], 'items_sold' => $row['quantity_sold']], $result['data']);

        return $result;
    }
}
