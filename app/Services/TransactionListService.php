<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionListService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters, User $user): array
    {
        $warehouseId = $this->warehouseId($filters, $user);
        $type = $filters['type'] ?? 'all';
        $orders = $this->orders($filters, $warehouseId);
        $quotes = $this->quotes($filters, $warehouseId);
        $convertedQuoteFilter = ($filters['status'] ?? null) === 'quote:converted';

        if ($type === 'order' || ($type === 'quote' && $convertedQuoteFilter)) {
            $source = $orders->toBase();
        } elseif ($type === 'quote') {
            $source = $quotes->toBase();
        } else {
            $source = $orders->toBase()->unionAll($quotes->toBase());
        }

        $base = DB::query()->fromSub($source, 'transactions');
        $metrics = (clone $base)->selectRaw(
            'COUNT(*) AS total_transactions, '.
            "SUM(CASE WHEN type = 'order' THEN 1 ELSE 0 END) AS orders, ".
            "SUM(CASE WHEN type = 'quote' THEN 1 ELSE 0 END) AS quotes, ".
            "SUM(CASE WHEN type = 'order' AND status IN ('draft', 'pending', 'confirmed', 'processing') ".
            "OR type = 'quote' AND status IN ('draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted') ".
            'THEN 1 ELSE 0 END) AS pending, '.
            "SUM(CASE WHEN type = 'order' AND status <> 'cancelled' THEN total ELSE 0 END) AS order_amount, ".
            "SUM(CASE WHEN type = 'quote' AND status NOT IN ('rejected', 'expired') THEN total ELSE 0 END) AS quote_amount"
        )->first();

        $page = $base->orderByDesc('created_at')
            ->orderBy('type')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $customerIds = $page->getCollection()->pluck('customer_id')->filter()->unique()->all();
        $customers = Customer::query()->whereIn('id', $customerIds)->pluck('name', 'id');
        $sellerIds = $page->getCollection()->pluck('user_id')->filter()->unique()->all();
        $sellers = User::query()->whereIn('id', $sellerIds)->pluck('name', 'id');
        $orderIds = $page->getCollection()->where('type', 'order')->pluck('id')->all();
        $quoteIds = $page->getCollection()->where('type', 'quote')->pluck('id')->all();
        $quoteItemCounts = SalesQuote::query()->whereIn('id', $quoteIds)->withCount('items')->pluck('items_count', 'id');
        $orders = SalesOrder::query()->with(['payments', 'returns', 'financialAdjustments'])
            ->whereIn('id', $orderIds)->get()->keyBy('id');
        $linkedQuotes = SalesQuote::withTrashed()
            ->whereIn('converted_order_id', $orderIds)
            ->orderBy('id')
            ->get(['id', 'quote_number', 'status', 'converted_order_id'])
            ->groupBy('converted_order_id');

        $page->setCollection($page->getCollection()->map(function (object $row) use ($customers, $sellers, $orders, $linkedQuotes, $quoteItemCounts, $user): array {
            $linked = $row->type === 'order' ? $linkedQuotes->get($row->id)?->first() : null;
            $linkedQuote = $linked ? [
                'id' => $linked->id,
                'number' => $linked->quote_number,
                'status' => $linked->status,
                'status_label' => $linked->status_label,
            ] : null;

            return [
                'key' => $row->type.':'.$row->id,
                'type' => $row->type,
                'id' => (int) $row->id,
                'number' => $row->number,
                'date' => $row->date,
                'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
                'customer_name' => $customers[$row->customer_id] ?? null,
                'seller_name' => $sellers[$row->user_id] ?? null,
                'warehouse_id' => $row->warehouse_id ? (int) $row->warehouse_id : null,
                'status' => $row->status,
                'status_label' => $row->type === 'quote'
                    ? $this->quoteStatusLabel($row->status)
                    : $this->orderStatusLabel($row->status),
                'total' => $row->total,
                'amount_due' => $row->type === 'order' ? $orders->get($row->id)?->amount_due : null,
                'payment_status' => $row->payment_status,
                'linked_quote' => $linkedQuote,
                'actions' => $this->actions($row, $linkedQuote, $user, (int) ($quoteItemCounts[$row->id] ?? 0) > 0),
            ];
        }));

        return $page->toArray() + [
            'metrics' => [
                'total_transactions' => (int) ($metrics->total_transactions ?? 0),
                'orders' => (int) ($metrics->orders ?? 0),
                'quotes' => (int) ($metrics->quotes ?? 0),
                'pending' => (int) ($metrics->pending ?? 0),
                'order_amount' => (string) ($metrics->order_amount ?? 0),
                'quote_amount' => (string) ($metrics->quote_amount ?? 0),
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function warehouseId(array $filters, User $user): int
    {
        $requested = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        if ($requested !== null && ! $user->hasWarehouseAccess($requested)) {
            throw ValidationException::withMessages(['warehouse_id' => 'No tienes acceso a esta sucursal.']);
        }

        $effective = $user->current_warehouse_id && $user->hasWarehouseAccess((int) $user->current_warehouse_id)
            ? (int) $user->current_warehouse_id
            : null;
        if ($effective === null && config('pos.single_warehouse_mode')) {
            $default = (int) config('pos.default_warehouse_id');
            if ($default > 0 && $user->hasWarehouseAccess($default)) {
                $effective = $default;
            }
        }
        if (! $user->isAdmin() && $effective === null) {
            throw new HttpResponseException(response()->json(['message' => 'Debe tener una sucursal asignada para realizar esta acción.'], 409));
        }
        if ($effective === null && $requested === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'Debes tener una sucursal asignada.']);
        }

        if (! $user->isAdmin() && $requested !== null && $requested !== $effective) {
            throw ValidationException::withMessages(['warehouse_id' => 'Debes usar tu sucursal activa.']);
        }

        return $requested ?? $effective;
    }

    /** @param array<string, mixed> $filters */
    private function orders(array $filters, int $warehouseId): \Illuminate\Database\Eloquent\Builder
    {
        $query = SalesOrder::query()->selectRaw(
            "'order' AS type, id, order_number AS number, date, created_at, status, total, customer_id, user_id, ".
            'NULL AS warehouse_id, payment_status'
        );

        $query->whereHas('items', fn ($items) => $items->where('warehouse_id', $warehouseId));
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($search) use ($term, $operator): void {
                $search->where('order_number', $operator, $term)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', $operator, $term))
                    ->orWhereExists(function (Builder $quote) use ($term, $operator): void {
                        $quote->selectRaw('1')->from('sales_quotes')
                            ->whereColumn('sales_quotes.converted_order_id', 'sales_orders.id')
                            ->whereNull('sales_quotes.deleted_at')
                            ->where('sales_quotes.quote_number', $operator, $term);
                    });
            });
        }
        if (($filters['status'] ?? null) === 'quote:converted') {
            $query->whereExists(function (Builder $quote): void {
                $quote->selectRaw('1')->from('sales_quotes')
                    ->whereColumn('sales_quotes.converted_order_id', 'sales_orders.id')
                    ->whereNull('sales_quotes.deleted_at')
                    ->where('sales_quotes.status', 'converted');
            });
            $filters = array_diff_key($filters, ['status' => true]);
        }
        $this->commonFilters($query, $filters, 'order');

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function quotes(array $filters, int $warehouseId): \Illuminate\Database\Eloquent\Builder
    {
        $query = SalesQuote::query()->selectRaw(
            "'quote' AS type, id, quote_number AS number, date, created_at, status, total, customer_id, user_id, ".
            'warehouse_id, NULL AS payment_status'
        )->whereNull('converted_order_id');

        $query->where('warehouse_id', $warehouseId);
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(fn ($search) => $search->where('quote_number', $operator, $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', $operator, $term)));
        }
        $this->commonFilters($query, $filters, 'quote');

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function commonFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters, string $type): void
    {
        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }
        if (isset($filters['status'])) {
            [$statusType, $status] = explode(':', $filters['status'], 2);
            if ($statusType === $type) {
                $query->where('status', $status);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        if (isset($filters['status_group'])) {
            $statuses = match ($filters['status_group']) {
                'pending' => $type === 'order' ? ['draft', 'pending', 'confirmed', 'processing'] : ['draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted'],
                'completed' => $type === 'order' ? ['completed'] : ['collected', 'converted'],
                'cancelled' => $type === 'order' ? ['cancelled'] : ['rejected', 'expired'],
            };
            $query->whereIn('status', $statuses);
        }
    }

    private function orderStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador', 'pending' => 'Pendiente', 'confirmed' => 'Confirmada',
            'processing' => 'En proceso', 'completed' => 'Completada', 'cancelled' => 'Cancelada',
            default => $status,
        };
    }

    private function quoteStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador', 'sent' => 'Enviada', 'sent_to_deposit' => 'En depósito',
            'sent_to_cashier' => 'En Caja', 'accepted' => 'Aceptada', 'collected' => 'Cobrada',
            'rejected' => 'Rechazada', 'expired' => 'Expirada', 'converted' => 'Convertida a OV',
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>|null  $linkedQuote
     * @return list<string>
     */
    private function actions(object $row, ?array $linkedQuote, User $user, bool $hasQuoteItems): array
    {
        $actions = ['view'];
        $canSell = in_array($user->role, ['admin', 'manager', 'seller'], true);
        if ($row->type === 'quote') {
            $active = in_array($row->status, ['draft', 'sent', 'sent_to_deposit', 'sent_to_cashier', 'accepted'], true);
            if ($canSell && $active && $hasQuoteItems) {
                $actions[] = 'convert';
            }
            if ($canSell && in_array($row->status, ['draft', 'sent'], true)) {
                $actions[] = 'edit';
            }
            if ($canSell && $hasQuoteItems && in_array($row->status, ['draft', 'sent'], true)) {
                $actions[] = 'send_to_deposit';
            }
            if ($canSell && in_array($row->status, ['draft', 'sent', 'sent_to_deposit'], true)) {
                $actions[] = 'send_to_cashier';
            }
            if (in_array($user->role, ['admin', 'manager'], true) && $row->status !== 'converted') {
                $actions[] = 'delete';
            }

            return $actions;
        }

        if ($row->status !== 'cancelled') {
            if (! ($linkedQuote !== null && in_array($linkedQuote['status'], ['sent_to_deposit', 'sent_to_cashier'], true))) {
                $actions[] = 'cancel';
            }
            if (! ($row->status === 'pending' && $linkedQuote !== null)) {
                $actions[] = 'edit';
            }
        }
        if ($linkedQuote !== null && $row->status === 'pending'
            && in_array($linkedQuote['status'], ['sent_to_deposit', 'sent_to_cashier'], true)) {
            $actions[] = 'continue_quote';
        }
        if (in_array($user->role, ['admin', 'manager'], true)
            && in_array($row->status, ['confirmed', 'processing', 'completed'], true)) {
            $actions[] = 'return';
        }

        return $actions;
    }
}
