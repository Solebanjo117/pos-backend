<?php

namespace App\Services;

use App\Models\AccountPaymentBatch;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\FinancialAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JournalLine;
use App\Models\OperationalExpense;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Support\RegionalDateRange;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ReportService
{
    private const NON_MONEY_METHODS = ['credit', 'store_credit', 'supplier_credit'];

    public function generateCustomerReferenceReport(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $orders = SalesOrder::query()
            ->with(['customer:id,code,name', 'referredByCustomer:id,code,name'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereDate('date', '>=', $filters['date_from'])
            ->whereDate('date', '<=', $filters['date_to'])
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->whereHas('items', function ($query) use ($warehouseId): void {
                    $query->where('warehouse_id', $warehouseId);
                });
            })
            ->orderBy('date')
            ->get();

        $customerIds = $orders
            ->flatMap(fn (SalesOrder $order) => [$order->customer_id, $order->referred_by_customer_id])
            ->filter()
            ->unique()
            ->values();

        if (! empty($filters['customer_id'])) {
            $filterCustomerId = (int) $filters['customer_id'];
            $customerIds = $customerIds->filter(fn (int $customerId): bool => $customerId === $filterCustomerId)->values();
        }

        $customers = Customer::query()
            ->whereIn('id', $customerIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $rows = $customers->map(function (Customer $customer) use ($orders): array {
            $directOrders = $orders->where('customer_id', $customer->id)->values();
            $referredOrders = $orders->where('referred_by_customer_id', $customer->id)->values();
            $directRevenue = round((float) $directOrders->sum('total'), 2);
            $referredRevenue = round((float) $referredOrders->sum('total'), 2);

            return [
                'customer_id' => $customer->id,
                'customer_code' => $customer->code,
                'customer_name' => $customer->name,
                'direct_revenue' => $directRevenue,
                'referred_revenue' => $referredRevenue,
                'combined_revenue' => round($directRevenue + $referredRevenue, 2),
                'orders_count' => $directOrders->count(),
                'referred_orders_count' => $referredOrders->count(),
                'direct_orders' => $directOrders->map(fn (SalesOrder $order) => $this->customerReferenceOrderRow($order))->all(),
                'referred_orders' => $referredOrders->map(fn (SalesOrder $order) => $this->customerReferenceOrderRow($order))->all(),
            ];
        })->sortByDesc('combined_revenue')->values();

        return [
            'summary' => [
                'direct_revenue' => round((float) $rows->sum('direct_revenue'), 2),
                'referred_revenue' => round((float) $rows->sum('referred_revenue'), 2),
                'combined_revenue' => round((float) $rows->sum('combined_revenue'), 2),
                'customers_count' => $rows->count(),
            ],
            'data' => $rows->all(),
        ];
    }

    private function customerReferenceOrderRow(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'date' => $order->date?->format('Y-m-d'),
            'occurred_at' => $order->created_at?->toIso8601String(),
            'business_datetime' => $order->business_datetime?->toIso8601String(),
            'status' => $order->status,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            'referred_by_customer_id' => $order->referred_by_customer_id,
            'referred_by_customer_name' => $order->referredByCustomer?->name,
            'total' => round((float) $order->total, 2),
        ];
    }

    /**
     * Generate sales grouped by customer for the operational client report.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generateSalesByClientReport(array $filters): array
    {
        $orders = $this->salesByClientOrders($filters);
        $rows = $orders
            ->groupBy('customer_id')
            ->map(fn (Collection $customerOrders): array => $this->salesByClientRow($customerOrders))
            ->values();
        $rows = $this->filterSalesByClientRows($rows, $filters);
        $rows = $this->sortSalesByClientRows($rows, $filters);
        $paginated = $this->paginateSalesByClientRows($rows, $filters);

        return [
            'summary' => $this->salesByClientSummary($rows),
            'data' => $paginated->items(),
            'by_referrer' => $this->salesByClientReferrers($orders),
            'top_clients' => $rows
                ->sortByDesc('total_revenue')
                ->take(10)
                ->map(fn (array $row): array => [
                    'customer_id' => $row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'total_revenue' => $row['total_revenue'],
                    'orders_count' => $row['orders_count'],
                    'growth' => 0,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SalesOrder>
     */
    private function salesByClientOrders(array $filters): Collection
    {
        $query = SalesOrder::query()
            ->with([
                'customer.vehicles.model.make',
                'customer.vehicles.chassis',
                'customer.vehicles.engine',
                'referredByCustomer:id,code,name',
                'payments',
                'returns',
                'items.product:id,sku_base,name',
                'items.variant:id,sku,brand_id',
                'items.variant.brand:id,name',
                'items.warehouse:id,name',
            ])
            ->whereNotNull('customer_id')
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('date', '>=', $filters['date_from'])
            ->whereDate('date', '<=', $filters['date_to']);

        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('items', function ($query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            });
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['referred_by_customer_id'])) {
            $query->where('referred_by_customer_id', $filters['referred_by_customer_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_status'])) {
            $statuses = collect(explode(',', (string) $filters['payment_status']))
                ->map(fn (string $status): string => trim($status))
                ->filter()
                ->values()
                ->all();

            if (! empty($statuses)) {
                $query->whereIn('payment_status', $statuses);
            }
        }

        if (! empty($filters['search'])) {
            $operator = $this->caseInsensitiveLikeOperator();
            $search = (string) $filters['search'];

            $query->where(function ($query) use ($operator, $search): void {
                $query->where('order_number', $operator, "%{$search}%")
                    ->orWhereHas('customer', function ($query) use ($operator, $search): void {
                        $query->where('name', $operator, "%{$search}%")
                            ->orWhere('code', $operator, "%{$search}%")
                            ->orWhere('email', $operator, "%{$search}%")
                            ->orWhere('phone', $operator, "%{$search}%");
                    })
                    ->orWhereHas('referredByCustomer', function ($query) use ($operator, $search): void {
                        $query->where('name', $operator, "%{$search}%")
                            ->orWhere('code', $operator, "%{$search}%");
                    });
            });
        }

        return $query->latest('date')->latest('id')->get();
    }

    private function salesByClientRow(Collection $orders): array
    {
        /** @var SalesOrder $firstOrder */
        $firstOrder = $orders->first();
        $customer = $firstOrder->customer;
        $totalRevenue = round((float) $orders->sum('total'), 2);
        $totalPaid = round((float) $orders->sum(fn (SalesOrder $order): float => (float) $order->amount_paid), 2);
        $pendingAmount = round((float) $orders->sum(fn (SalesOrder $order): float => (float) $order->amount_due), 2);
        $lastPayment = $orders
            ->flatMap(fn (SalesOrder $order) => $order->payments)
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->sortByDesc('paid_at')
            ->first();
        $dominantPayment = $orders
            ->flatMap(fn (SalesOrder $order) => $order->payments)
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->groupBy('method')
            ->map(fn (Collection $payments, string $method): array => [
                'method' => $method,
                'method_label' => $payments->first()->formatted_method,
                'amount' => round((float) $payments->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->first();
        $fallbackMethod = $orders->whereNotNull('payment_method')->groupBy('payment_method')
            ->map(fn (Collection $group, string $method): array => [
                'method' => $method,
                'method_label' => $method === 'credit' ? 'Credito' : ucfirst(str_replace('_', ' ', $method)),
                'amount' => round((float) $group->sum('total'), 2),
            ])
            ->sortByDesc('amount')
            ->first();
        $referrerOrders = $orders->whereNotNull('referred_by_customer_id');
        $referrer = $referrerOrders->count() > 0
            ? $referrerOrders->groupBy('referred_by_customer_id')->sortByDesc(fn (Collection $group): int => $group->count())->first()?->first()?->referredByCustomer
            : null;
        $status = $this->salesByClientStatus($orders, $pendingAmount);

        return [
            'customer_id' => $customer?->id,
            'customer_code' => $customer?->code,
            'customer_name' => $customer?->name ?? 'Cliente sin nombre',
            'customer_email' => $customer?->email,
            'customer_phone' => $customer?->phone,
            'initials' => $this->customerInitials($customer?->name ?? 'CL'),
            'referred_by_customer_id' => $referrer?->id,
            'referred_by_customer_name' => $referrer?->name,
            'orders_count' => $orders->count(),
            'total_revenue' => $totalRevenue,
            'total_paid' => $totalPaid,
            'pending_amount' => $pendingAmount,
            'last_payment_amount' => $lastPayment ? round((float) $lastPayment->amount, 2) : 0.0,
            'last_payment_date' => $lastPayment?->paid_at?->toDateString(),
            'last_payment_at' => $lastPayment?->paid_at?->toIso8601String(),
            'dominant_payment_method' => $dominantPayment['method'] ?? $fallbackMethod['method'] ?? null,
            'dominant_payment_method_label' => $dominantPayment['method_label'] ?? $fallbackMethod['method_label'] ?? 'Sin pagos',
            'status' => $status,
            'status_label' => match ($status) {
                'vencido' => 'Vencido',
                'pendiente' => 'Pendiente',
                default => 'Al dia',
            },
            'details' => [
                'invoices' => $orders->map(fn (SalesOrder $order): array => $this->salesByClientInvoiceRow($order))->values()->all(),
                'items' => $this->salesByClientPurchasedItems($orders),
                'vehicles' => $this->salesByClientVehicles($customer),
            ],
        ];
    }

    private function salesByClientInvoiceRow(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'date' => $order->date?->toDateString(),
            'occurred_at' => $order->created_at?->toIso8601String(),
            'business_datetime' => $order->business_datetime?->toIso8601String(),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total' => round((float) $order->total, 2),
            'amount_paid' => round((float) $order->amount_paid, 2),
            'pending_amount' => round((float) $order->amount_due, 2),
            'method' => $order->payment_method,
            'method_label' => $order->payment_method === 'credit'
                ? 'Credito'
                : ucfirst(str_replace('_', ' ', (string) $order->payment_method)),
            'href' => "/sales-orders/{$order->id}",
        ];
    }

    private function salesByClientPurchasedItems(Collection $orders): array
    {
        return $orders
            ->flatMap(fn (SalesOrder $order) => $order->items)
            ->groupBy(fn ($item): string => (string) ($item->variant_id ?? $item->product_id ?? $item->product_name))
            ->map(function (Collection $items): array {
                $first = $items->first();

                return [
                    'product_id' => $first->product_id,
                    'variant_id' => $first->variant_id,
                    'name' => $first->product_name,
                    'sku' => $first->variant?->sku ?? $first->product_sku,
                    'brand_name' => $first->variant?->brand?->name,
                    'quantity' => (int) $items->sum('quantity'),
                    'total' => round((float) $items->sum('total'), 2),
                ];
            })
            ->sortByDesc('total')
            ->take(8)
            ->values()
            ->all();
    }

    private function salesByClientVehicles(?Customer $customer): array
    {
        return $customer?->vehicles
            ->map(fn ($vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->display_name,
                'vin' => $vehicle->vin,
                'status' => $vehicle->status,
            ])
            ->values()
            ->all() ?? [];
    }

    private function salesByClientStatus(Collection $orders, float $pendingAmount): string
    {
        if ($pendingAmount <= 0) {
            return 'aldia';
        }

        $hasOverdue = $orders->contains(function (SalesOrder $order): bool {
            if ((float) $order->amount_due <= 0 || ! $order->date) {
                return false;
            }

            $creditDays = (int) ($order->customer?->credit_days ?? 0);

            return $order->date->copy()->addDays($creditDays)->isPast();
        });

        return $hasOverdue ? 'vencido' : 'pendiente';
    }

    private function filterSalesByClientRows(Collection $rows, array $filters): Collection
    {
        if (empty($filters['search'])) {
            return $rows;
        }

        $search = mb_strtolower((string) $filters['search']);

        return $rows
            ->filter(fn (array $row): bool => str_contains(mb_strtolower($row['customer_name']), $search)
                || str_contains(mb_strtolower((string) $row['customer_code']), $search)
                || str_contains(mb_strtolower((string) $row['referred_by_customer_name']), $search)
                || collect($row['details']['invoices'] ?? [])->contains(
                    fn (array $invoice): bool => str_contains(mb_strtolower((string) $invoice['order_number']), $search)
                ))
            ->values();
    }

    private function sortSalesByClientRows(Collection $rows, array $filters): Collection
    {
        $sortBy = $filters['sort_by'] ?? 'total_revenue';
        $descending = ($filters['sort_direction'] ?? 'desc') === 'desc';
        $sorted = $rows->sortBy(fn (array $row) => $row[$sortBy] ?? null, SORT_REGULAR, $descending);

        return $sorted->values();
    }

    private function paginateSalesByClientRows(Collection $rows, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page
        );
    }

    private function salesByClientSummary(Collection $rows): array
    {
        $ordersCount = (int) $rows->sum('orders_count');
        $totalRevenue = round((float) $rows->sum('total_revenue'), 2);

        return [
            'total_revenue' => $totalRevenue,
            'orders_count' => $ordersCount,
            'average_ticket' => $ordersCount > 0 ? round($totalRevenue / $ordersCount, 2) : 0.0,
            'active_customers' => $rows->count(),
            'total_paid' => round((float) $rows->sum('total_paid'), 2),
            'pending_amount' => round((float) $rows->sum('pending_amount'), 2),
            'overdue_amount' => round((float) $rows->where('status', 'vencido')->sum('pending_amount'), 2),
        ];
    }

    private function salesByClientReferrers(Collection $orders): array
    {
        return $orders
            ->whereNotNull('referred_by_customer_id')
            ->groupBy('referred_by_customer_id')
            ->map(function (Collection $referrerOrders): array {
                $first = $referrerOrders->first();
                $totalRevenue = round((float) $referrerOrders->sum('total'), 2);

                return [
                    'customer_id' => $first->referredByCustomer?->id,
                    'customer_name' => $first->referredByCustomer?->name ?? 'Sin referido',
                    'initials' => $this->customerInitials($first->referredByCustomer?->name ?? 'SR'),
                    'clients_count' => $referrerOrders->pluck('customer_id')->filter()->unique()->count(),
                    'orders_count' => $referrerOrders->count(),
                    'total_revenue' => $totalRevenue,
                    'commission' => round($totalRevenue * 0.05, 2),
                    'growth' => 0,
                ];
            })
            ->sortByDesc('total_revenue')
            ->values()
            ->all();
    }

    private function customerInitials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('') ?: 'CL';
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return config('database.default') === 'sqlite' ? 'LIKE' : 'ILIKE';
    }

    /**
     * Generate stock levels report
     */
    public function generateStockLevelsReport(array $filters = []): array
    {
        // Use defaultVariant.brand — Product has no direct 'brand' Eloquent relation
        $query = ProductInventory::with([
            'product.defaultVariant.brand',
            'product.defaultVariant.pricing',
            'variant.brand',
            'warehouse',
        ])->select('product_inventory.*');

        // Apply filters
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->whereHas('product.variants', function ($q) use ($filters) {
                $q->where('brand_id', $filters['brand_id']);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->whereHas('product', function ($q) use ($filters) {
                $q->where('category_id', $filters['category_id']);
            });
        }

        // Only rows that have a product
        $query->whereHas('product');

        $inventory = $query->get();

        // Calculate metrics
        $summary = $this->calculateStockMetrics($inventory);

        // Group by warehouse
        $byWarehouse = $this->groupByWarehouse($inventory);

        // Filter by status if needed
        if (! empty($filters['status'])) {
            $inventory = $inventory->filter(function ($item) use ($filters) {
                return $this->getStockStatus($item) === $filters['status'];
            })->values();
        }

        // Format products
        $products = $inventory->map(function ($item) {
            $variant = $item->variant;
            $defaultVariant = $variant ?: $item->product->defaultVariant;
            $stockValue = $this->fifoStockValue($item);
            $currentCost = app(InventoryFifoService::class)->currentFifoCost(
                (int) $item->product_id,
                $item->variant_id ? (int) $item->variant_id : null,
                (int) $item->warehouse_id
            );

            return [
                'id' => $item->product_id,
                'sku' => $item->product->sku_base ?? '',
                'name' => $item->product->name,
                'brand' => $defaultVariant?->brand?->name ?? 'Sin Marca',
                'variant_id' => $item->variant_id,
                'variant_sku' => $variant?->sku,
                'warehouse_id' => $item->warehouse_id,
                'warehouse' => $item->warehouse?->name ?? '',
                'current_stock' => $item->quantity_available,
                'reorder_point' => $item->reorder_point ?? 0,
                'target_level' => $item->reorder_quantity ?? 0,
                'status' => $this->getStockStatus($item),
                'stock_value' => $stockValue,
                'current_cost' => $currentCost,
                'fifo_cost_current' => $currentCost,
            ];
        });
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDirection = $filters['sort_direction'] ?? 'asc';
        $allowedSorts = [
            'sku',
            'name',
            'brand',
            'warehouse',
            'current_stock',
            'reorder_point',
            'target_level',
            'status',
            'current_cost',
            'stock_value',
        ];

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $products = $products
            ->sortBy(function (array $product) use ($sortBy) {
                return $sortBy === 'sku'
                    ? ($product['variant_sku'] ?? $product['sku'])
                    : ($product[$sortBy] ?? null);
            }, SORT_REGULAR, $sortDirection === 'desc')
            ->values();

        $page = max((int) ($filters['page'] ?? 1), 1);
        $totalProducts = $products->count();
        $exportAll = (bool) ($filters['export_all'] ?? false);
        $perPage = $exportAll ? max($totalProducts, 1) : 25;
        $paginatedProducts = $products->forPage($page, $perPage)->values();

        return [
            'summary' => $summary,
            'by_warehouse' => $byWarehouse,
            'products' => $paginatedProducts->all(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($totalProducts / $perPage), 1),
                'per_page' => $perPage,
                'total' => $totalProducts,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function generateInventoryItemsExportRows(array $filters = []): array
    {
        $operator = $this->caseInsensitiveLikeOperator();
        $query = ProductInventory::query()
            ->with([
                'product' => fn ($query) => $query->withTrashed()->with(['category', 'pricing', 'defaultVariant.brand', 'defaultVariant.pricing']),
                'variant.brand',
                'variant.pricing',
                'variant.suppliers',
                'warehouse',
            ])
            ->select('product_inventory.*');

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['brand_id'])) {
            $brandIds = is_array($filters['brand_id']) ? $filters['brand_id'] : [$filters['brand_id']];
            $query->where(function ($query) use ($brandIds): void {
                $query->whereHas('variant', fn ($query) => $query->whereIn('brand_id', $brandIds))
                    ->orWhereHas('product.defaultVariant', fn ($query) => $query->whereIn('brand_id', $brandIds));
            });
        }

        if (! empty($filters['category_id'])) {
            $categoryIds = is_array($filters['category_id']) ? $filters['category_id'] : [$filters['category_id']];
            $query->whereHas('product', fn ($query) => $query->withTrashed()->whereIn('category_id', $categoryIds));
        }

        if (! empty($filters['status'])) {
            $query->whereHas('product', fn ($query) => $query->withTrashed()->where('status', $filters['status']));
        }

        if (! empty($filters['has_stock']) || ! empty($filters['in_stock'])) {
            $query->where('quantity_available', '>', 0);
        }

        $archived = (string) ($filters['archived'] ?? 'false');
        if ($archived === 'true') {
            $query->whereHas('product', fn ($query) => $query->onlyTrashed());
        } elseif ($archived !== 'all') {
            $query->whereHas('product', fn ($query) => $query->whereNull('deleted_at'));
        } else {
            $query->whereHas('product', fn ($query) => $query->withTrashed());
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($query) use ($operator, $search): void {
                $query->whereHas('product', function ($query) use ($operator, $search): void {
                    $query->withTrashed()
                        ->where('name', $operator, $search)
                        ->orWhere('sku_base', $operator, $search)
                        ->orWhere('system_id', $operator, $search);
                })
                    ->orWhereHas('variant', function ($query) use ($operator, $search): void {
                        $query->where('sku', $operator, $search)
                            ->orWhere('upc', $operator, $search)
                            ->orWhere('system_id', $operator, $search)
                            ->orWhereHas('brand', fn ($query) => $query->where('name', $operator, $search));
                    });
            });
        }

        return $query->get()
            ->sortBy(fn (ProductInventory $item): string => (string) ($item->product?->name ?? $item->product?->sku_base ?? ''))
            ->values()
            ->map(function (ProductInventory $item): array {
                $product = $item->product;
                $variant = $item->variant ?: $product?->defaultVariant;
                $pricing = $variant?->pricing ?: $product?->pricing;
                $fifoCost = $product
                    ? app(InventoryFifoService::class)->currentFifoCost(
                        (int) $item->product_id,
                        $item->variant_id ? (int) $item->variant_id : null,
                        (int) $item->warehouse_id
                    )
                    : 0.0;
                $stockValue = $this->fifoStockValue($item);
                $supplier = $variant?->suppliers
                    ?->sortByDesc(fn ($supplier) => (bool) ($supplier->pivot?->is_preferred ?? false))
                    ?->first();

                return [
                    'product_system_id' => $product?->system_id,
                    'variant_system_id' => $variant?->system_id,
                    'product_sku' => $product?->sku_base,
                    'variant_sku' => $variant?->sku,
                    'upc' => $variant?->upc,
                    'product_name' => $product?->name,
                    'brand' => $variant?->brand?->name,
                    'category' => $product?->category?->name,
                    'status' => $product?->status,
                    'warehouse' => $item->warehouse?->name,
                    'warehouse_code' => $item->warehouse?->code,
                    'location' => $item->location_in_warehouse,
                    'quantity_available' => (int) $item->quantity_available,
                    'quantity_reserved' => (int) $item->quantity_reserved,
                    'reorder_point' => (int) $item->reorder_point,
                    'reorder_quantity' => (int) $item->reorder_quantity,
                    'fifo_cost' => $fifoCost,
                    'price' => (float) ($pricing?->calculated_price ?? $pricing?->base_price ?? 0),
                    'stock_value' => $stockValue,
                    'preferred_supplier' => $supplier?->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Generate movement history report
     */
    public function generateMovementHistoryReport(array $filters): array
    {
        [$from, $to] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);

        $query = InventoryMovement::with(['product', 'variant', 'warehouse', 'destinationWarehouse', 'user', 'lotAllocations.lot'])
            ->whereBetween('created_at', [$from, $to]);

        // Apply filters
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('warehouse_id', $filters['warehouse_id'])
                    ->orWhere('destination_warehouse_id', $filters['warehouse_id']);
            });
        }

        if (! empty($filters['movement_type'])) {
            $query->where('movement_type', $filters['movement_type']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $movements = (clone $query)->orderBy('created_at', 'desc')->get();
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = 25;
        $paginatedMovements = (clone $query)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Calculate summary
        $summary = $this->calculateMovementSummary($movements);

        // Group by timeline
        $groupBy = $filters['group_by'] ?? 'day';
        $timeline = $this->groupMovementsByTimeline($movements, $groupBy);

        // Group by product
        $byProduct = $this->groupByProduct($movements);

        // Format movements
        $formattedMovements = $paginatedMovements->getCollection()->map(function ($movement) {
            $lotTotalCost = round((float) $movement->lotAllocations->sum('total_cost'), 2);
            $lotAllocations = $movement->lotAllocations->map(fn ($allocation) => [
                'lot_id' => $allocation->inventory_lot_id,
                'quantity' => (int) $allocation->quantity,
                'unit_cost' => (float) $allocation->unit_cost,
                'total_cost' => (float) $allocation->total_cost,
                'source_type' => $allocation->lot?->source_type,
                'source_id' => $allocation->lot?->source_id,
            ])->values()->all();

            return [
                'id' => $movement->id,
                'date' => $movement->created_at->toIso8601String(),
                'product_id' => $movement->product_id,
                'product_name' => $movement->product->name,
                'product_sku' => $movement->product->sku_base,
                'warehouse' => $movement->warehouse->name,
                'destination_warehouse' => $movement->destinationWarehouse?->name,
                'movement_type' => $movement->movement_type,
                'quantity' => $movement->quantity,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'user' => $movement->user?->name,
                'variant_id' => $movement->variant_id,
                'variant_sku' => $movement->variant?->sku,
                'lot_total_cost' => $lotTotalCost,
                'fifo_total_cost' => $lotTotalCost,
                'lot_allocations' => $lotAllocations,
                'fifo_allocations' => $lotAllocations,
            ];
        });

        return [
            'summary' => $summary,
            'timeline' => $timeline,
            'by_product' => $byProduct,
            'movements' => $formattedMovements->all(),
            'pagination' => [
                'current_page' => $paginatedMovements->currentPage(),
                'last_page' => $paginatedMovements->lastPage(),
                'per_page' => $paginatedMovements->perPage(),
                'total' => $paginatedMovements->total(),
            ],
        ];
    }

    /**
     * Calculate stock metrics
     */
    protected function calculateStockMetrics(Collection $inventory): array
    {
        $totalProducts = $inventory->count();
        $critical = 0;
        $low = 0;
        $normal = 0;
        $optimal = 0;
        $totalValue = 0;
        $criticalValue = 0;
        $lowValue = 0;
        $normalValue = 0;
        $optimalValue = 0;

        foreach ($inventory as $item) {
            $status = $this->getStockStatus($item);
            $stockValue = $this->fifoStockValue($item);

            switch ($status) {
                case 'critical':
                    $critical++;
                    $criticalValue += $stockValue;
                    break;
                case 'low':
                    $low++;
                    $lowValue += $stockValue;
                    break;
                case 'normal':
                    $normal++;
                    $normalValue += $stockValue;
                    break;
                case 'optimal':
                    $optimal++;
                    $optimalValue += $stockValue;
                    break;
            }

            $totalValue += $stockValue;
        }

        return [
            'total_products' => $totalProducts,
            'critical_stock' => $critical,
            'low_stock' => $low,
            'normal_stock' => $normal,
            'optimal_stock' => $optimal,
            'total_value' => round($totalValue, 2),
            'critical_value' => round($criticalValue, 2),
            'low_value' => round($lowValue, 2),
            'normal_value' => round($normalValue, 2),
            'optimal_value' => round($optimalValue, 2),
            'at_risk_value' => round($criticalValue + $lowValue, 2),
        ];
    }

    /**
     * Get stock status
     */
    protected function getStockStatus($item): string
    {
        $stock = $item->quantity_available;
        $reorderPoint = $item->reorder_point ?? 0;
        $targetLevel = $item->reorder_quantity ?? 0;

        if ($stock <= 0) {
            return 'critical';
        }

        if ($reorderPoint > 0 && $stock <= $reorderPoint) {
            return 'critical';
        }

        if ($reorderPoint > 0 && $stock <= ($reorderPoint * 1.5)) {
            return 'low';
        }

        if ($targetLevel > 0 && $stock >= $targetLevel) {
            return 'optimal';
        }

        return 'normal';
    }

    private function fifoStockValue(ProductInventory $item): float
    {
        $lotValue = InventoryLot::query()
            ->where('warehouse_id', $item->warehouse_id)
            ->where('quantity_available', '>', 0)
            ->when(
                $item->variant_id,
                fn ($query) => $query->where('variant_id', $item->variant_id),
                fn ($query) => $query->where('product_id', $item->product_id)->whereNull('variant_id')
            )
            ->get()
            ->sum(fn ($lot) => (int) $lot->quantity_available * (float) $lot->landed_unit_cost);

        return round((float) $lotValue, 2);
    }

    /**
     * Group by warehouse
     */
    protected function groupByWarehouse(Collection $inventory): array
    {
        return $inventory->groupBy('warehouse_id')->map(function ($items, $warehouseId) {
            $warehouse = $items->first()->warehouse;
            $metrics = $this->calculateStockMetrics($items);

            return [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouse->name,
                'total_products' => $metrics['total_products'],
                'critical' => $metrics['critical_stock'],
                'low' => $metrics['low_stock'],
                'normal' => $metrics['normal_stock'],
                'optimal' => $metrics['optimal_stock'],
                'total_value' => $metrics['total_value'],
                'critical_value' => $metrics['critical_value'],
                'low_value' => $metrics['low_value'],
                'at_risk_value' => $metrics['at_risk_value'],
            ];
        })->values()->all();
    }

    /**
     * Calculate movement summary
     */
    protected function calculateMovementSummary(Collection $movements): array
    {
        $totalInbound = $movements->where('movement_type', 'in')->count();
        $totalOutbound = $movements->where('movement_type', 'out')->count();
        $totalTransfers = $movements->where('movement_type', 'transfer')->count();

        $quantityIn = $movements->where('movement_type', 'in')->sum('quantity');
        $quantityOut = $movements->where('movement_type', 'out')->sum('quantity');
        $totalAssignedCost = $movements->sum(
            fn (InventoryMovement $movement): float => (float) $movement->lotAllocations->sum('total_cost')
        );

        return [
            'total_movements' => $movements->count(),
            'total_inbound' => $totalInbound,
            'total_outbound' => $totalOutbound,
            'total_transfers' => $totalTransfers,
            'total_quantity_in' => $quantityIn,
            'total_quantity_out' => $quantityOut,
            'net_change' => $quantityIn - $quantityOut,
            'total_assigned_cost' => round((float) $totalAssignedCost, 2),
        ];
    }

    /**
     * Group movements by timeline
     */
    public function groupMovementsByTimeline(Collection $movements, string $groupBy): array
    {
        $format = match ($groupBy) {
            'week' => 'Y-W',
            'month' => 'Y-m',
            default => 'Y-m-d',
        };

        return $movements->groupBy(function ($movement) use ($format) {
            return $movement->created_at
                ->copy()
                ->setTimezone(RegionalDateRange::timezone())
                ->format($format);
        })->map(function ($items, $date) {
            $inbound = $items->where('movement_type', 'in')->sum('quantity');
            $outbound = $items->where('movement_type', 'out')->sum('quantity');
            $transfers = $items->where('movement_type', 'transfer')->count();

            return [
                'date' => $date,
                'inbound' => $inbound,
                'outbound' => $outbound,
                'transfers' => $transfers,
                'net' => $inbound - $outbound,
            ];
        })->sortKeys()->values()->all();
    }

    /**
     * Group by product
     */
    protected function groupByProduct(Collection $movements): array
    {
        return $movements->groupBy('product_id')->map(function ($items, $productId) {
            $product = $items->first()->product;

            return [
                'product_id' => $productId,
                'product_name' => $product->name,
                'product_sku' => $product->sku_base,
                'total_movements' => $items->count(),
                'inbound' => $items->where('movement_type', 'in')->count(),
                'outbound' => $items->where('movement_type', 'out')->count(),
                'transfers' => $items->where('movement_type', 'transfer')->count(),
            ];
        })->sortByDesc('total_movements')->take(10)->values()->all();
    }

    /**
     * Generate financial report (Revenue, COGS, Profit)
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     * Includes refund totals from approved SalesReturns.
     */
    public function generateFinancialReport(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59']);

        // Filter orders that have items in the given warehouse
        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->orderBy('date', 'asc')->get();

        $totalRevenue = 0;
        $totalCogs = 0;
        $totalTax = 0;
        $totalDiscounts = 0;

        $timeline = [];

        foreach ($orders as $order) {
            $date = $order->date->format('Y-m-d');
            if (! isset($timeline[$date])) {
                $timeline[$date] = [
                    'date' => $date,
                    'revenue' => 0,
                    'cogs' => 0,
                    'profit' => 0,
                    'orders_count' => 0,
                ];
            }

            $orderCogs = 0;
            $orderDiscount = 0;

            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                // Use unit_cost stored on the line item
                $cost = (float) $item->unit_cost;
                $orderCogs += ($cost * $item->quantity);
                $orderDiscount += (float) $item->discount_amount;
            }

            $totalCogs += $orderCogs;
            $totalTax += $order->tax_amount;
            $totalDiscounts += $orderDiscount;

            $timeline[$date]['cogs'] += $orderCogs;
            $timeline[$date]['orders_count'] += 1;
        }

        // Cash-basis: Fetch payments for the given period
        [$timestampFrom, $timestampTo] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
        $paymentsQuery = \App\Models\SalesOrderPayment::with('salesOrder.items')
            ->whereBetween('paid_at', [$timestampFrom, $timestampTo])
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->where('amount', '>', 0)
            ->whereHas('salesOrder', fn ($query) => $query->whereIn('status', ['confirmed', 'processing', 'completed']));

        if ($warehouseId) {
            $paymentsQuery->whereHas('salesOrder.items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $payments = $paymentsQuery->get();

        foreach ($payments as $payment) {
            $paymentDate = \Carbon\Carbon::parse($payment->paid_at)
                ->setTimezone(RegionalDateRange::timezone())
                ->format('Y-m-d');

            if (! isset($timeline[$paymentDate])) {
                $timeline[$paymentDate] = [
                    'date' => $paymentDate,
                    'revenue' => 0,
                    'cogs' => 0,
                    'profit' => 0,
                    'orders_count' => 0,
                ];
            }

            $amount = $warehouseId && $payment->salesOrder
                ? (float) $payment->amount * $this->salesOrderWarehouseRatioForReport($payment->salesOrder, (int) $warehouseId)
                : (float) $payment->amount;

            $timeline[$paymentDate]['revenue'] += $amount;
            $totalRevenue += $amount;
        }

        // Refunds: Approved SalesReturns in the date range
        $refundsQuery = SalesReturn::query()
            ->with('items.salesOrderItem')
            ->where('status', 'approved')
            ->where(function ($query) use ($timestampFrom, $timestampTo): void {
                $query->whereBetween('approved_at', [$timestampFrom, $timestampTo])
                    ->orWhere(function ($legacy) use ($timestampFrom, $timestampTo): void {
                        $legacy->whereNull('approved_at')
                            ->whereBetween('updated_at', [$timestampFrom, $timestampTo]);
                    });
            });

        if ($warehouseId) {
            $refundsQuery->where('warehouse_id', $warehouseId);
        }

        $refunds = $refundsQuery->get();
        $totalRefunds = (float) $refunds->sum('total_amount');
        $refundCogs = (float) $refunds->sum(fn (SalesReturn $return): float => $this->salesReturnCogs($return));
        $this->applyRefundsToFinancialTimeline($timeline, $refunds);

        $expenses = $this->financialOperationalExpenses($filters);
        $totalOperationalExpenses = (float) $expenses['total'];
        $creditBalances = $this->financialCreditBalances($filters);

        foreach ($expenses['timeline'] as $expenseDay) {
            $date = $expenseDay['date'];
            if (! isset($timeline[$date])) {
                $timeline[$date] = [
                    'date' => $date,
                    'revenue' => 0,
                    'cogs' => 0,
                    'profit' => 0,
                    'orders_count' => 0,
                ];
            }

            $timeline[$date]['operational_expenses'] = ($timeline[$date]['operational_expenses'] ?? 0) + $expenseDay['amount'];
        }

        // Recalculate profit per timeline day
        foreach ($timeline as &$dayData) {
            $dayData['operational_expenses'] = $dayData['operational_expenses'] ?? 0;
            $dayData['gross_profit'] = $dayData['revenue'] - $dayData['cogs'];
            $dayData['profit'] = $dayData['gross_profit'] - $dayData['operational_expenses'];
        }
        unset($dayData);
        ksort($timeline);

        $netCogs = $totalCogs - $refundCogs;
        $grossProfit = $totalRevenue - $netCogs - $totalRefunds;
        $netProfit = $grossProfit - $totalOperationalExpenses;
        $margin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

        return [
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_cogs' => round($netCogs, 2),
                'gross_cogs' => round($totalCogs, 2),
                'return_cogs' => round($refundCogs, 2),
                'total_refunds' => round($totalRefunds, 2),
                'total_operational_expenses' => round($totalOperationalExpenses, 2),
                'gross_profit' => round($grossProfit, 2),
                'net_profit' => round($netProfit, 2),
                'margin_percentage' => round($margin, 2),
                'total_tax' => round($totalTax, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'orders_count' => $orders->count(),
            ],
            'timeline' => array_values($timeline),
            'operational_expenses' => [
                'total' => round($totalOperationalExpenses, 2),
                'by_category' => $expenses['by_category'],
                'timeline' => $expenses['timeline'],
            ],
            'credit_balances' => $creditBalances,
        ];
    }

    public function generateIncomeStatementReport(array $filters): array
    {
        $basis = $filters['basis'] ?? 'cash';
        $financialReport = $basis === 'accrual_operational'
            ? $this->generateAccrualIncomeStatement($filters)
            : $this->generateFinancialReport($filters);
        $summary = $financialReport['summary'];
        $expenses = $financialReport['operational_expenses'];
        $creditBalances = $this->financialCreditBalances(array_merge($filters, ['as_of' => true]));
        $reconciliation = $financialReport['reconciliation'] ?? [
            'gross_sales_ex_tax' => round((float) $summary['total_revenue'] + (float) $summary['total_discounts'], 2),
            'discounts' => round((float) $summary['total_discounts'], 2),
            'returns_ex_tax' => round((float) $summary['total_refunds'], 2),
            'net_sales_ex_tax' => round((float) $summary['total_revenue'] - (float) $summary['total_refunds'], 2),
            'tax_collected' => round((float) $summary['total_tax'], 2),
            'gross_cogs' => round((float) ($summary['gross_cogs'] ?? $summary['total_cogs']), 2),
            'return_cogs' => round((float) ($summary['return_cogs'] ?? 0), 2),
            'net_cogs' => round((float) $summary['total_cogs'], 2),
            'gross_profit' => round((float) $summary['gross_profit'], 2),
            'operational_expenses' => round((float) $summary['total_operational_expenses'], 2),
            'net_income' => round((float) $summary['net_profit'], 2),
        ];

        $incomeTotal = (float) $reconciliation['net_sales_ex_tax'];
        $costTotal = (float) $reconciliation['net_cogs'];
        $grossSales = (float) $reconciliation['gross_sales_ex_tax'];
        $grossCogs = (float) $reconciliation['gross_cogs'];
        $returnCogs = (float) $reconciliation['return_cogs'];
        $operationalExpenseTotal = (float) $reconciliation['operational_expenses'];

        return [
            'basis' => $basis,
            'period' => [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'warehouse_id' => $filters['warehouse_id'] ?? null,
            ],
            'summary' => $summary,
            'reconciliation' => $reconciliation,
            'sections' => [
                [
                    'key' => 'income',
                    'label' => 'Ingresos',
                    'amount' => round($incomeTotal, 2),
                    'rows' => [
                        $this->statementRow('gross-sales', 'Subtotal', $grossSales, 'income', 'Ventas antes de descuentos, devoluciones e ITBMS.'),
                        $this->statementRow('discounts', 'Descuentos', -1 * (float) $reconciliation['discounts'], 'expense', 'Descuentos aplicados a las lineas vendidas.'),
                        $this->statementRow('approved-refunds', 'Devoluciones / notas de credito', -1 * (float) $reconciliation['returns_ex_tax'], 'expense', 'Contra-ingreso sin ITBMS del periodo.'),
                        ...((float) ($reconciliation['sales_adjustments'] ?? 0) !== 0.0 ? [
                            $this->statementRow('sales-adjustments', 'Ajustes de ventas', (float) $reconciliation['sales_adjustments'], 'income', 'Correcciones contables autorizadas; no crean facturas.'),
                        ] : []),
                        $this->statementRow('tax-collected', 'ITBMS cobrado', (float) $reconciliation['tax_collected'], 'neutral', 'Informativo; no esta incluido en Sales ni altera la utilidad neta.', false),
                    ],
                ],
                [
                    'key' => 'cost-of-goods-sold',
                    'label' => 'Costo de ventas',
                    'amount' => round($costTotal, 2),
                    'rows' => [
                        $this->statementRow('gross-cogs', 'Costo de productos vendidos', $grossCogs, 'expense', 'Costo registrado en lineas vendidas.'),
                        $this->statementRow('return-cogs', 'Reversion por devoluciones', -1 * $returnCogs, 'income', 'Costo reintegrado al inventario por devoluciones aprobadas.'),
                    ],
                ],
                [
                    'key' => 'gross-profit',
                    'label' => 'Utilidad bruta',
                    'amount' => (float) $reconciliation['gross_profit'],
                    'rows' => [],
                ],
                [
                    'key' => 'operational-expenses',
                    'label' => 'Gastos operativos',
                    'amount' => round($operationalExpenseTotal, 2),
                    'rows' => collect($expenses['by_category'])
                        ->map(fn (array $category): array => $this->statementRow(
                            'operational-expense-'.$category['category'],
                            $category['label'],
                            (float) $category['amount'],
                            'expense',
                            $category['count'].' registros'
                        ))
                        ->values()
                        ->all(),
                ],
                [
                    'key' => 'open-balances',
                    'label' => 'Saldos abiertos',
                    'amount' => round(
                        (float) $creditBalances['accounts_receivable']['total_open_balance']
                        - (float) $creditBalances['accounts_payable']['total_open_balance'],
                        2
                    ),
                    'rows' => [
                        $this->statementRow(
                            'accounts-receivable',
                            'Credito cliente',
                            (float) $creditBalances['accounts_receivable']['total_open_balance'],
                            'asset',
                            'Cuentas por cobrar; no son caja cobrada ni modifican utilidad neta.'
                        ),
                        $this->statementRow(
                            'accounts-payable',
                            'Credito proveedor',
                            (float) $creditBalances['accounts_payable']['total_open_balance'],
                            'liability',
                            'Cuentas por pagar; no son salida de caja hasta pagarse.'
                        ),
                    ],
                ],
                [
                    'key' => 'net-profit',
                    'label' => 'Utilidad neta',
                    'amount' => (float) $reconciliation['net_income'],
                    'rows' => [],
                ],
            ],
            'timeline' => $financialReport['timeline'],
            'operational_expenses' => $expenses,
            'expense_breakdown' => $this->generateExpenseBreakdown($filters),
            'credit_balances' => $creditBalances,
            'comparison' => ($filters['compare'] ?? 'none') === 'previous_period'
                ? $this->previousPeriodComparison($filters, $basis)
                : null,
            'breakdowns' => $this->financialBreakdowns($filters),
            'warnings' => $financialReport['warnings'] ?? [],
        ];
    }

    public function generateFinancialPositionReport(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;
        $mode = $filters['mode'] ?? 'current_snapshot';
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $cash = $mode === 'as_of'
            ? $this->operationalCashAsOf($dateTo, $warehouseId)
            : $this->currentOperationalCash($warehouseId);
        $creditBalances = $mode === 'as_of'
            ? $this->financialCreditBalances(['warehouse_id' => $warehouseId, 'date_to' => $dateTo, 'as_of' => true])
            : $this->financialCreditBalances(['warehouse_id' => $warehouseId]);
        $inventoryValue = $mode === 'as_of'
            ? $this->inventoryValueAsOf($dateTo, $warehouseId)
            : $this->currentInventoryValue($warehouseId);

        $accountsReceivable = (float) $creditBalances['accounts_receivable']['total_open_balance'];
        $accountsPayable = (float) $creditBalances['accounts_payable']['total_open_balance'];
        $totalAssets = round($cash + $accountsReceivable + $inventoryValue, 2);
        $totalLiabilities = round($accountsPayable, 2);
        $operationalEquity = round($totalAssets - $totalLiabilities, 2);

        return [
            'basis' => 'current_operational_snapshot',
            'mode' => $mode,
            'as_of' => $dateTo,
            'warehouse_id' => $warehouseId,
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'operational_equity' => $operationalEquity,
                'operational_cash' => round($cash, 2),
                'accounts_receivable' => round($accountsReceivable, 2),
                'inventory_value' => round($inventoryValue, 2),
                'accounts_payable' => round($accountsPayable, 2),
            ],
            'sections' => [
                [
                    'key' => 'assets',
                    'label' => 'Activos',
                    'amount' => $totalAssets,
                    'rows' => [
                        $this->statementRow('operational-cash', 'Caja operativa estimada', $cash, 'asset', 'Sesiones de caja abiertas con ingresos y retiros de efectivo.'),
                        $this->statementRow('accounts-receivable', 'Credito cliente', $accountsReceivable, 'asset', 'Cuentas por cobrar abiertas.'),
                        $this->statementRow('inventory', 'Inventario disponible', $inventoryValue, 'asset', 'Lotes actuales valorizados por costo landed.'),
                    ],
                ],
                [
                    'key' => 'liabilities',
                    'label' => 'Pasivos',
                    'amount' => $totalLiabilities,
                    'rows' => [
                        $this->statementRow('accounts-payable', 'Credito proveedor', $accountsPayable, 'liability', 'Cuentas por pagar abiertas.'),
                    ],
                ],
                [
                    'key' => 'equity',
                    'label' => 'Patrimonio operativo',
                    'amount' => $operationalEquity,
                    'rows' => [
                        $this->statementRow('operational-equity', 'Activos menos pasivos', $operationalEquity, 'equity', 'Resultado operativo calculado, no capital contable formal.'),
                    ],
                ],
            ],
            'credit_balances' => $creditBalances,
            'aging' => [
                'accounts_receivable' => $creditBalances['accounts_receivable']['aging_buckets'],
                'accounts_payable' => $creditBalances['accounts_payable']['aging_buckets'],
            ],
            'documents' => [
                'accounts_receivable' => $creditBalances['accounts_receivable']['documents'],
                'accounts_payable' => $creditBalances['accounts_payable']['documents'],
            ],
            'warnings' => $mode === 'current_snapshot'
                ? [[
                    'code' => 'current_snapshot',
                    'message' => 'Foto actual; no reconstruye saldos historicos al corte.',
                ]]
                : [],
        ];
    }

    private function statementRow(
        string $key,
        string $label,
        float $amount,
        string $type,
        ?string $detail = null,
        bool $includedInSectionTotal = true,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'amount' => round($amount, 2),
            'type' => $type,
            'detail' => $detail,
            'included_in_section_total' => $includedInSectionTotal,
        ];
    }

    private function moneyToCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function centsToMoney(int $amount): float
    {
        return round($amount / 100, 2);
    }

    private function currentOperationalCash(?int $warehouseId = null): float
    {
        $sessions = PosSession::query()
            ->where('status', 'open')
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId);
            })
            ->get();

        $cash = $sessions->sum(function (PosSession $session): float {
            $movementsIn = (float) CashMovement::query()
                ->where('pos_session_id', $session->id)
                ->where('type', 'in')
                ->sum('amount');
            $movementsOut = (float) CashMovement::query()
                ->where('pos_session_id', $session->id)
                ->where('type', 'out')
                ->sum('amount');

            return (float) $session->opening_amount + $movementsIn - $movementsOut;
        });

        return round((float) $cash, 2);
    }

    private function currentInventoryValue(?int $warehouseId = null): float
    {
        $value = InventoryLot::query()
            ->where('quantity_available', '>', 0)
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId);
            })
            ->get(['quantity_available', 'landed_unit_cost'])
            ->sum(fn (InventoryLot $lot): float => (int) $lot->quantity_available * (float) $lot->landed_unit_cost);

        return round((float) $value, 2);
    }

    private function generateAccrualIncomeStatement(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $orders = SalesOrder::with(['items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59'])
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->whereHas('items', function ($query) use ($warehouseId): void {
                    $query->where('warehouse_id', $warehouseId);
                });
            })
            ->orderBy('date')
            ->get();

        $timeline = [];
        $subtotalCents = 0;
        $totalCogsCents = 0;
        $totalTaxCents = 0;
        $totalDiscountsCents = 0;

        foreach ($orders as $order) {
            $date = $order->date->format('Y-m-d');
            $timeline[$date] ??= [
                'date' => $date,
                'revenue' => 0,
                'cogs' => 0,
                'profit' => 0,
                'orders_count' => 0,
                'operational_expenses' => 0,
            ];

            $items = $warehouseId ? $order->items->where('warehouse_id', $warehouseId) : $order->items;
            $orderSubtotalCents = (int) $items->sum(fn ($item): int => $this->moneyToCents($item->subtotal));
            $orderDiscountsCents = (int) $items->sum(fn ($item): int => $this->moneyToCents($item->discount_amount));
            $orderTaxCents = (int) $items->sum(fn ($item): int => $this->moneyToCents($item->tax_amount));
            $orderCogsCents = (int) $items->sum(
                fn ($item): int => $this->moneyToCents((float) $item->unit_cost * (int) $item->quantity)
            );

            $subtotalCents += $orderSubtotalCents;
            $totalCogsCents += $orderCogsCents;
            $totalTaxCents += $orderTaxCents;
            $totalDiscountsCents += $orderDiscountsCents;
            $timeline[$date]['revenue'] += $this->centsToMoney($orderSubtotalCents);
            $timeline[$date]['cogs'] += $this->centsToMoney($orderCogsCents);
            $timeline[$date]['orders_count'] += 1;
        }

        [$timestampFrom, $timestampTo] = RegionalDateRange::utcBounds($filters['date_from'], $filters['date_to']);
        $refundsQuery = SalesReturn::query()
            ->with('items.salesOrderItem')
            ->where('status', 'approved')
            ->where(function ($query) use ($timestampFrom, $timestampTo): void {
                $query->whereBetween('approved_at', [$timestampFrom, $timestampTo])
                    ->orWhere(function ($legacy) use ($timestampFrom, $timestampTo): void {
                        $legacy->whereNull('approved_at')
                            ->whereBetween('updated_at', [$timestampFrom, $timestampTo]);
                    });
            });

        if ($warehouseId) {
            $refundsQuery->where('warehouse_id', $warehouseId);
        }

        $refunds = $refundsQuery->get();
        $returnSubtotalCents = 0;
        $returnTaxCents = 0;
        $refundCogsCents = 0;
        $warnings = [];

        foreach ($refunds as $return) {
            $amounts = $this->salesReturnIncomeAmounts($return);
            $returnSubtotalCents += $amounts['subtotal_cents'];
            $returnTaxCents += $amounts['tax_cents'];
            $refundCogsCents += $this->moneyToCents($this->salesReturnCogs($return));

            if ($amounts['legacy_tax_unknown']) {
                $warnings[] = [
                    'code' => 'legacy_return_tax_unknown',
                    'message' => 'Una devolucion historica no tiene lineas suficientes para separar su ITBMS; se conservo el total como contra-ingreso.',
                    'return_id' => $return->id,
                ];
            }
        }

        $this->applyRefundsToFinancialTimeline($timeline, $refunds);
        $expenses = $this->financialOperationalExpenses($filters);
        $reportAdjustments = FinancialAdjustment::query()
            ->whereIn('type', [FinancialAdjustment::TYPE_SALES, FinancialAdjustment::TYPE_EXPENSES])
            ->whereBetween('adjustment_date', [$filters['date_from'], $filters['date_to']])
            ->when($warehouseId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get(['type', 'direction', 'amount', 'adjustment_date']);
        $salesAdjustmentCents = (int) $reportAdjustments
            ->where('type', FinancialAdjustment::TYPE_SALES)
            ->sum(fn (FinancialAdjustment $adjustment): int => $this->moneyToCents($adjustment->signedAmount()));
        $expenseAdjustmentCents = (int) $reportAdjustments
            ->where('type', FinancialAdjustment::TYPE_EXPENSES)
            ->sum(fn (FinancialAdjustment $adjustment): int => $this->moneyToCents($adjustment->signedAmount()));
        $totalOperationalExpenseCents = $this->moneyToCents($expenses['total']) + $expenseAdjustmentCents;

        foreach ($reportAdjustments as $adjustment) {
            $date = $adjustment->adjustment_date?->format('Y-m-d');
            if (! $date) {
                continue;
            }
            $timeline[$date] ??= [
                'date' => $date, 'revenue' => 0, 'cogs' => 0, 'profit' => 0,
                'orders_count' => 0, 'operational_expenses' => 0,
            ];
            if ($adjustment->type === FinancialAdjustment::TYPE_SALES) {
                $timeline[$date]['revenue'] += $adjustment->signedAmount();
            } else {
                $timeline[$date]['operational_expenses'] += $adjustment->signedAmount();
            }
        }

        foreach ($expenses['timeline'] as $expenseDay) {
            $date = $expenseDay['date'];
            $timeline[$date] ??= [
                'date' => $date,
                'revenue' => 0,
                'cogs' => 0,
                'profit' => 0,
                'orders_count' => 0,
                'operational_expenses' => 0,
            ];
            $timeline[$date]['operational_expenses'] = ($timeline[$date]['operational_expenses'] ?? 0) + $expenseDay['amount'];
        }

        foreach ($timeline as &$dayData) {
            $dayData['gross_profit'] = $dayData['revenue'] - $dayData['cogs'];
            $dayData['profit'] = $dayData['gross_profit'] - ($dayData['operational_expenses'] ?? 0);
        }
        unset($dayData);
        ksort($timeline);

        $grossSalesCents = $subtotalCents + $totalDiscountsCents;
        $netSalesCents = $subtotalCents - $returnSubtotalCents + $salesAdjustmentCents;
        $netTaxCents = $totalTaxCents - $returnTaxCents;
        $netCogsCents = $totalCogsCents - $refundCogsCents;
        $grossProfitCents = $netSalesCents - $netCogsCents;
        $netIncomeCents = $grossProfitCents - $totalOperationalExpenseCents;
        $netSales = $this->centsToMoney($netSalesCents);
        $margin = $netSalesCents !== 0 ? ($netIncomeCents / $netSalesCents) * 100 : 0;

        $reconciliation = [
            'gross_sales_ex_tax' => $this->centsToMoney($grossSalesCents),
            'discounts' => $this->centsToMoney($totalDiscountsCents),
            'returns_ex_tax' => $this->centsToMoney($returnSubtotalCents),
            'net_sales_ex_tax' => $netSales,
            'tax_collected' => $this->centsToMoney($netTaxCents),
            'gross_cogs' => $this->centsToMoney($totalCogsCents),
            'return_cogs' => $this->centsToMoney($refundCogsCents),
            'net_cogs' => $this->centsToMoney($netCogsCents),
            'gross_profit' => $this->centsToMoney($grossProfitCents),
            'operational_expenses' => $this->centsToMoney($totalOperationalExpenseCents),
            'net_income' => $this->centsToMoney($netIncomeCents),
            'sales_adjustments' => $this->centsToMoney($salesAdjustmentCents),
            'expense_adjustments' => $this->centsToMoney($expenseAdjustmentCents),
        ];

        return [
            'summary' => [
                'total_revenue' => $reconciliation['net_sales_ex_tax'],
                'total_cogs' => $reconciliation['net_cogs'],
                'gross_cogs' => $reconciliation['gross_cogs'],
                'return_cogs' => $reconciliation['return_cogs'],
                'total_refunds' => $reconciliation['returns_ex_tax'],
                'total_operational_expenses' => $reconciliation['operational_expenses'],
                'gross_profit' => $reconciliation['gross_profit'],
                'net_profit' => $reconciliation['net_income'],
                'margin_percentage' => round($margin, 2),
                'total_tax' => $reconciliation['tax_collected'],
                'total_discounts' => $reconciliation['discounts'],
                'orders_count' => $orders->count(),
            ],
            'reconciliation' => $reconciliation,
            'timeline' => array_values($timeline),
            'operational_expenses' => [
                'total' => $reconciliation['operational_expenses'],
                'by_category' => collect($expenses['by_category'])
                    ->when($expenseAdjustmentCents !== 0, fn ($categories) => $categories->push([
                        'category' => 'financial_adjustments',
                        'label' => 'Ajustes financieros',
                        'amount' => $this->centsToMoney($expenseAdjustmentCents),
                        'count' => $reportAdjustments->where('type', FinancialAdjustment::TYPE_EXPENSES)->count(),
                    ]))
                    ->values()
                    ->all(),
                'timeline' => $expenses['timeline'],
            ],
            'credit_balances' => $this->financialCreditBalances($filters),
            'warnings' => $warnings,
        ];
    }

    private function previousPeriodComparison(array $filters, string $basis): array
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);
        $previousFilters = array_merge($filters, [
            'date_from' => $previousFrom->toDateString(),
            'date_to' => $previousTo->toDateString(),
            'basis' => $basis,
            'compare' => 'none',
        ]);

        $current = $basis === 'accrual_operational'
            ? $this->generateAccrualIncomeStatement($filters)
            : $this->generateFinancialReport($filters);
        $previous = $basis === 'accrual_operational'
            ? $this->generateAccrualIncomeStatement($previousFilters)
            : $this->generateFinancialReport($previousFilters);

        $variance = collect(['total_revenue', 'total_cogs', 'total_operational_expenses', 'gross_profit', 'net_profit', 'margin_percentage'])
            ->mapWithKeys(function (string $key) use ($current, $previous): array {
                $currentValue = (float) ($current['summary'][$key] ?? 0);
                $previousValue = (float) ($previous['summary'][$key] ?? 0);
                $amount = round($currentValue - $previousValue, 2);

                return [
                    $key => [
                        'amount' => $amount,
                        'percentage' => $previousValue != 0.0 ? round(($amount / $previousValue) * 100, 2) : null,
                    ],
                ];
            })
            ->all();

        return [
            'previous_period' => [
                'date_from' => $previousFilters['date_from'],
                'date_to' => $previousFilters['date_to'],
            ],
            'previous' => [
                'summary' => $previous['summary'],
            ],
            'variance' => $variance,
        ];
    }

    private function financialBreakdowns(array $filters): array
    {
        return [
            'by_brand' => collect($this->generateBrandProfitability($filters)['data'] ?? [])
                ->map(fn (array $row): array => array_merge($row, ['id' => $row['brand_id']]))
                ->all(),
            'by_category' => collect($this->generateCategoryProfitability($filters)['data'] ?? [])
                ->map(fn (array $row): array => array_merge($row, ['id' => $row['category_id']]))
                ->all(),
            'by_warehouse' => $this->warehouseProfitabilityBreakdown($filters),
        ];
    }

    private function warehouseProfitabilityBreakdown(array $filters): array
    {
        $warehouseIds = ! empty($filters['warehouse_id'])
            ? collect([(int) $filters['warehouse_id']])
            : Warehouse::query()->pluck('id');

        return $warehouseIds->map(function (int $warehouseId) use ($filters): array {
            $warehouse = Warehouse::find($warehouseId);
            $report = $this->generateFinancialReport(array_merge($filters, ['warehouse_id' => $warehouseId]));

            return [
                'id' => $warehouseId,
                'name' => $warehouse?->name ?? 'Sucursal #'.$warehouseId,
                'revenue' => $report['summary']['total_revenue'],
                'cogs' => $report['summary']['total_cogs'],
                'profit' => $report['summary']['net_profit'],
                'margin' => $report['summary']['margin_percentage'],
            ];
        })->values()->all();
    }

    private function operationalCashAsOf(string $dateTo, ?int $warehouseId = null): float
    {
        $end = Carbon::parse($dateTo)->endOfDay();

        $opening = (float) PosSession::query()
            ->where('opened_at', '<=', $end)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->sum('opening_amount');

        $sessionIds = PosSession::query()
            ->where('opened_at', '<=', $end)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->pluck('id');

        $movementsIn = (float) CashMovement::query()
            ->whereIn('pos_session_id', $sessionIds)
            ->where('type', 'in')
            ->where('created_at', '<=', $end)
            ->sum('amount');
        $movementsOut = (float) CashMovement::query()
            ->whereIn('pos_session_id', $sessionIds)
            ->where('type', 'out')
            ->where('created_at', '<=', $end)
            ->sum('amount');
        $cashReceipts = (float) SalesOrderPayment::query()
            ->where('method', 'cash')
            ->where('paid_at', '<=', $end)
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->whereHas('salesOrder.items', fn ($query) => $query->where('warehouse_id', $warehouseId));
            })
            ->sum('amount');
        $cashPayments = (float) PurchaseOrderPayment::query()
            ->where('method', 'cash')
            ->where('paid_at', '<=', $end)
            ->when($warehouseId, fn ($query) => $query->whereHas('purchaseOrder', fn ($query) => $query->where('warehouse_id', $warehouseId)))
            ->sum('amount');

        return round($opening + $movementsIn + $cashReceipts - $movementsOut - $cashPayments, 2);
    }

    private function inventoryValueAsOf(string $dateTo, ?int $warehouseId = null): float
    {
        $end = Carbon::parse($dateTo)->endOfDay();
        $value = InventoryLot::query()
            ->where('received_at', '<=', $end)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->get(['quantity_available', 'landed_unit_cost'])
            ->sum(fn (InventoryLot $lot): float => (int) $lot->quantity_available * (float) $lot->landed_unit_cost);

        return round((float) $value, 2);
    }

    /**
     * @return array<string, array<string, float|int>>
     */
    private function financialCreditBalances(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $asOf = ($filters['as_of'] ?? false) && $dateTo
            ? Carbon::parse($dateTo)->endOfDay()
            : null;

        $receivableOrders = SalesOrder::query()
            ->with(['payments', 'returns', 'customer:id,code,name', 'items:id,sales_order_id,warehouse_id'])
            ->whereNotIn('status', ['cancelled'])
            ->when(! $asOf, function ($query): void {
                $query->whereIn('payment_status', ['pending', 'partial']);
            })
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate('date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate('date', '<=', $dateTo);
            })
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->whereHas('items', function ($query) use ($warehouseId): void {
                    $query->where('warehouse_id', $warehouseId);
                });
            })
            ->get()
            ->map(fn (SalesOrder $order): array => $this->receivableDocumentRow($order, $asOf))
            ->filter(fn (array $document): bool => $document['amount_due'] > 0)
            ->values();

        $payableOrders = PurchaseOrder::query()
            ->with(['payments', 'supplier:id,name'])
            ->where('status', '!=', 'cancelled')
            ->when(! $asOf, function ($query): void {
                $query->whereIn('payment_status', ['pending', 'partial']);
            })
            ->when($dateFrom, function ($query) use ($dateFrom): void {
                $query->whereDate('order_date', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo): void {
                $query->whereDate('order_date', '<=', $dateTo);
            })
            ->when($warehouseId, function ($query) use ($warehouseId): void {
                $query->where('warehouse_id', $warehouseId);
            })
            ->get()
            ->map(fn (PurchaseOrder $order): array => $this->payableDocumentRow($order, $asOf))
            ->filter(fn (array $document): bool => $document['amount_due'] > 0)
            ->values();

        return [
            'accounts_receivable' => [
                'total_open_balance' => round((float) $receivableOrders->sum('amount_due'), 2),
                'open_documents_count' => $receivableOrders->count(),
                'accounts_count' => $receivableOrders->pluck('account_id')->filter()->unique()->count(),
                'aging_buckets' => $this->agingBuckets($receivableOrders),
                'documents' => $receivableOrders->all(),
                'recent_movements' => $this->recentCreditMovements('customer', $filters),
            ],
            'accounts_payable' => [
                'total_open_balance' => round((float) $payableOrders->sum('amount_due'), 2),
                'open_documents_count' => $payableOrders->count(),
                'accounts_count' => $payableOrders->pluck('account_id')->filter()->unique()->count(),
                'aging_buckets' => $this->agingBuckets($payableOrders),
                'documents' => $payableOrders->all(),
                'recent_movements' => $this->recentCreditMovements('supplier', $filters),
            ],
        ];
    }

    private function receivableDocumentRow(SalesOrder $order, ?Carbon $asOf = null): array
    {
        $paid = (float) $order->payments
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->filter(fn ($payment): bool => $asOf === null || $payment->paid_at <= $asOf)
            ->sum('amount');
        $returned = $asOf
            ? (float) $order->returns->where('status', 'approved')->filter(fn ($return): bool => $return->created_at <= $asOf)->sum('total_amount')
            : (float) $order->total_returned;
        $effectiveTotal = max(0, (float) $order->total - $returned);
        $amountDue = round(max(0, $effectiveTotal - $paid), 2);
        $referenceDate = $order->date?->toDateString();
        $daysOutstanding = $referenceDate
            ? Carbon::parse($referenceDate)->diffInDays($asOf ?? now(), false)
            : 0;

        return [
            'id' => $order->id,
            'document_type' => 'sales_order',
            'document_number' => $order->order_number,
            'account_id' => $order->customer_id,
            'account_name' => $order->customer?->name,
            'date' => $referenceDate,
            'due_date' => $referenceDate,
            'total' => round((float) $order->total, 2),
            'amount_paid' => round($paid, 2),
            'amount_due' => $amountDue,
            'days_overdue' => max(0, (int) $daysOutstanding),
            'warehouse_id' => $order->items->first()?->warehouse_id,
            'status' => $order->payment_status,
        ];
    }

    private function payableDocumentRow(PurchaseOrder $order, ?Carbon $asOf = null): array
    {
        $paid = (float) $order->payments
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->filter(fn ($payment): bool => $asOf === null || $payment->paid_at <= $asOf)
            ->sum('amount');
        $amountDue = round(max(0, (float) $order->total - $paid), 2);
        $referenceDate = $order->order_date?->toDateString();
        $daysOutstanding = $referenceDate
            ? Carbon::parse($referenceDate)->diffInDays($asOf ?? now(), false)
            : 0;

        return [
            'id' => $order->id,
            'document_type' => 'purchase_order',
            'document_number' => $order->po_number,
            'account_id' => $order->supplier_id,
            'account_name' => $order->supplier?->name,
            'date' => $referenceDate,
            'due_date' => $referenceDate,
            'total' => round((float) $order->total, 2),
            'amount_paid' => round($paid, 2),
            'amount_due' => $amountDue,
            'days_overdue' => max(0, (int) $daysOutstanding),
            'warehouse_id' => $order->warehouse_id,
            'status' => $order->payment_status,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     */
    private function agingBuckets(Collection $documents): array
    {
        $buckets = collect([
            'current' => ['label' => 'Actual', 'amount' => 0.0, 'count' => 0],
            '1_30' => ['label' => '1-30 dias', 'amount' => 0.0, 'count' => 0],
            '31_60' => ['label' => '31-60 dias', 'amount' => 0.0, 'count' => 0],
            '61_90' => ['label' => '61-90 dias', 'amount' => 0.0, 'count' => 0],
            '90_plus' => ['label' => '90+ dias', 'amount' => 0.0, 'count' => 0],
        ])->all();

        foreach ($documents as $document) {
            $days = (int) $document['days_overdue'];
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };

            $buckets[$key]['amount'] = round($buckets[$key]['amount'] + (float) $document['amount_due'], 2);
            $buckets[$key]['count']++;
        }

        return $buckets;
    }

    private function recentCreditMovements(string $accountType, array $filters): array
    {
        return AccountPaymentBatch::query()
            ->where('account_type', $accountType)
            ->when(! empty($filters['date_from']), fn ($query) => $query->whereDate('paid_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('paid_at', '<=', $filters['date_to']))
            ->orderByDesc('paid_at')
            ->limit(10)
            ->get()
            ->map(fn (AccountPaymentBatch $batch): array => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'account_type' => $batch->account_type,
                'account_id' => $batch->account_id,
                'direction' => $batch->direction,
                'amount' => round((float) $batch->amount, 2),
                'method' => $batch->method,
                'paid_at' => $batch->paid_at?->toISOString(),
                'reference_number' => $batch->reference_number,
            ])
            ->values()
            ->all();
    }

    private function salesReturnCogs(SalesReturn $return): float
    {
        return round((float) $return->items->sum(
            fn ($item): float => (float) ($item->salesOrderItem?->unit_cost ?? 0) * (int) $item->quantity
        ), 2);
    }

    /** @return array{subtotal_cents: int, tax_cents: int, legacy_tax_unknown: bool} */
    private function salesReturnIncomeAmounts(SalesReturn $return): array
    {
        $totalCents = $this->moneyToCents($return->total_amount);
        $subtotalCents = (int) $return->items->sum(
            fn ($item): int => $this->moneyToCents($item->subtotal)
        );

        if ($return->items->isEmpty()) {
            return [
                'subtotal_cents' => $totalCents,
                'tax_cents' => 0,
                'legacy_tax_unknown' => $totalCents !== 0,
            ];
        }

        if ($totalCents <= 0) {
            return [
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => 0,
                'legacy_tax_unknown' => false,
            ];
        }

        return [
            'subtotal_cents' => $subtotalCents,
            'tax_cents' => max(0, $totalCents - $subtotalCents),
            'legacy_tax_unknown' => false,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $timeline
     * @param  Collection<int, SalesReturn>  $refunds
     */
    private function applyRefundsToFinancialTimeline(array &$timeline, Collection $refunds): void
    {
        foreach ($refunds as $return) {
            $date = $this->salesReturnImpactDate($return);
            $timeline[$date] ??= $this->emptyFinancialTimelineDay($date);
            $timeline[$date]['revenue'] -= $this->centsToMoney(
                $this->salesReturnIncomeAmounts($return)['subtotal_cents']
            );
            $timeline[$date]['cogs'] -= $this->salesReturnCogs($return);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFinancialTimelineDay(string $date): array
    {
        return [
            'date' => $date,
            'revenue' => 0,
            'cogs' => 0,
            'profit' => 0,
            'orders_count' => 0,
            'operational_expenses' => 0,
        ];
    }

    private function salesReturnImpactDate(SalesReturn $return): string
    {
        return Carbon::parse($return->approved_at ?? $return->updated_at ?? $return->created_at ?? now())
            ->format('Y-m-d');
    }

    private function financialOperationalExpenses(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = OperationalExpense::query()
            ->with(['categoryRecord', 'customer', 'supplier', 'beneficiary'])
            ->whereDate('expense_date', '>=', $filters['date_from'])
            ->whereDate('expense_date', '<=', $filters['date_to'])
            ->where(fn ($query) => $query
                ->where('affects_income_statement', true)
                ->orWhereNull('affects_income_statement'))
            ->whereIn('status', ['pending', 'scheduled', 'queued', 'paid'])
            ->where('amount', '>', 0);

        if ($warehouseId) {
            $query->where(function ($query) use ($warehouseId): void {
                $query->whereNull('warehouse_id')
                    ->orWhere('warehouse_id', $warehouseId);
            });
        }

        $expenses = $query->orderBy('expense_date')->get();

        $byCategory = $expenses
            ->groupBy(function (OperationalExpense $expense): string {
                if ($expense->categoryRecord) {
                    return (string) $expense->categoryRecord->slug;
                }

                return array_key_exists((string) $expense->category, OperationalExpense::CATEGORIES)
                    ? (string) $expense->category
                    : 'other';
            })
            ->map(function ($items, string $category): array {
                /** @var OperationalExpense|null $first */
                $first = $items->first();
                $label = $category === 'other'
                    ? 'Others'
                    : ($first?->categoryRecord?->name ?? OperationalExpense::CATEGORIES[$category] ?? $category);

                return [
                    'category' => $category,
                    'label' => $label,
                    'amount' => $this->centsToMoney((int) $items->sum(
                        fn (OperationalExpense $expense): int => $this->moneyToCents($expense->amount)
                    )),
                    'count' => $items->count(),
                ];
            })
            ->sortBy(fn (array $category): string => $category['category'] === 'other'
                ? 'zzzzzzzzzz-other'
                : $category['category'])
            ->values()
            ->all();

        return [
            'total' => $this->centsToMoney((int) $expenses->sum(
                fn (OperationalExpense $expense): int => $this->moneyToCents($expense->amount)
            )),
            'by_category' => $byCategory,
            'by_party' => $expenses
                ->groupBy(fn (OperationalExpense $expense): string => ($expense->party['type'] ?? 'none').':'.($expense->party['id'] ?? 0))
                ->map(function ($items): array {
                    /** @var OperationalExpense $first */
                    $first = $items->first();

                    return [
                        'party_type' => $first->party['type'] ?? null,
                        'party_id' => $first->party['id'] ?? null,
                        'party_name' => $first->party['name'] ?? 'Sin beneficiario',
                        'amount' => round((float) $items->sum('amount'), 2),
                        'count' => $items->count(),
                    ];
                })
                ->sortBy('party_name')
                ->values()
                ->all(),
            'timeline' => $expenses
                ->groupBy(fn (OperationalExpense $expense) => $expense->expense_date->format('Y-m-d'))
                ->map(fn ($items, $date) => [
                    'date' => $date,
                    'amount' => round((float) $items->sum('amount'), 2),
                    'count' => $items->count(),
                ])
                ->sortBy('date')
                ->values()
                ->all(),
        ];
    }

    public function generateLedgerTrialBalance(array $filters): array
    {
        $lines = JournalLine::query()
            ->with('ledgerAccount')
            ->whereHas('journalEntry', function ($query) use ($filters): void {
                $query->whereBetween('occurred_at', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59'])
                    ->when(! empty($filters['warehouse_id']), fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']));
            })
            ->get();

        $accounts = $lines
            ->groupBy('ledger_account_id')
            ->map(function (Collection $accountLines): array {
                $account = $accountLines->first()->ledgerAccount;
                $debit = round((float) $accountLines->sum('debit'), 2);
                $credit = round((float) $accountLines->sum('credit'), 2);

                return [
                    'account_id' => $account?->id,
                    'account_code' => $account?->code,
                    'account_name' => $account?->name,
                    'account_type' => $account?->type,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => round($debit - $credit, 2),
                ];
            })
            ->sortBy('account_code')
            ->values()
            ->all();

        $totalDebit = round((float) $lines->sum('debit'), 2);
        $totalCredit = round((float) $lines->sum('credit'), 2);

        return [
            'period' => [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'warehouse_id' => $filters['warehouse_id'] ?? null,
            ],
            'summary' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced' => abs($totalDebit - $totalCredit) <= 0.005,
            ],
            'accounts' => $accounts,
        ];
    }

    /**
     * Generate brand profitability report
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     */
    public function generateBrandProfitability(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items.product.defaultVariant.brand', 'items.variant.brand'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59']);

        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->get();

        $brandsData = [];

        foreach ($orders as $order) {
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                // Determine brand
                $brandId = null;
                $brandName = 'Sin Marca';

                if ($item->variant && $item->variant->brand) {
                    $brandId = $item->variant->brand->id;
                    $brandName = $item->variant->brand->name;
                } elseif ($item->product && $item->product->defaultVariant && $item->product->defaultVariant->brand) {
                    $brandId = $item->product->defaultVariant->brand->id;
                    $brandName = $item->product->defaultVariant->brand->name;
                }

                $key = $brandId ?? 'unknown';

                if (! isset($brandsData[$key])) {
                    $brandsData[$key] = [
                        'brand_id' => $brandId,
                        'brand_name' => $brandName,
                        'revenue' => 0,
                        'cogs' => 0,
                        'profit' => 0,
                        'items_sold' => 0,
                    ];
                }

                $revenue = $item->subtotal;
                $cogs = (float) $item->unit_cost * $item->quantity;

                $brandsData[$key]['revenue'] += $revenue;
                $brandsData[$key]['cogs'] += $cogs;
                $brandsData[$key]['profit'] += ($revenue - $cogs);
                $brandsData[$key]['items_sold'] += $item->quantity;
            }
        }

        // Calculate margins and sort by profit
        $result = collect(array_values($brandsData))->map(function ($data) {
            $data['margin'] = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0;
            $data['revenue'] = round($data['revenue'], 2);
            $data['cogs'] = round($data['cogs'], 2);
            $data['profit'] = round($data['profit'], 2);
            $data['margin'] = round($data['margin'], 2);

            return $data;
        })->sortByDesc('profit')->values()->all();

        return ['data' => $result];
    }

    /**
     * Generate category profitability report
     * Groups sold items by product.category.
     * Filters by warehouse_id through sales_order_items.
     * Uses unit_cost from sales_order_items for COGS.
     */
    public function generateCategoryProfitability(array $filters): array
    {
        $warehouseId = $filters['warehouse_id'] ?? null;

        $query = \App\Models\SalesOrder::with(['items.product.category'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$filters['date_from'].' 00:00:00', $filters['date_to'].' 23:59:59']);

        if ($warehouseId) {
            $query->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $orders = $query->get();

        $categoriesData = [];

        foreach ($orders as $order) {
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                $categoryId = $item->product->category_id ?? null;
                $categoryName = $item->product->category->name ?? 'Sin Categoría';

                $key = $categoryId ?? 'unknown';

                if (! isset($categoriesData[$key])) {
                    $categoriesData[$key] = [
                        'category_id' => $categoryId,
                        'category_name' => $categoryName,
                        'revenue' => 0,
                        'cogs' => 0,
                        'profit' => 0,
                        'items_sold' => 0,
                    ];
                }

                $revenue = $item->subtotal;
                $cogs = (float) $item->unit_cost * $item->quantity;

                $categoriesData[$key]['revenue'] += $revenue;
                $categoriesData[$key]['cogs'] += $cogs;
                $categoriesData[$key]['profit'] += ($revenue - $cogs);
                $categoriesData[$key]['items_sold'] += $item->quantity;
            }
        }

        // Calculate margins and sort by profit
        $result = collect(array_values($categoriesData))->map(function ($data) {
            $data['margin'] = $data['revenue'] > 0 ? ($data['profit'] / $data['revenue']) * 100 : 0;
            $data['revenue'] = round($data['revenue'], 2);
            $data['cogs'] = round($data['cogs'], 2);
            $data['profit'] = round($data['profit'], 2);
            $data['margin'] = round($data['margin'], 2);

            return $data;
        })->sortByDesc('profit')->values()->all();

        return ['data' => $result];
    }

    /**
     * Generate Z-Report (Cierre de Caja / Arqueo)
     * Includes cash drawer reconciliation, real net sales, daily P&L, and average ticket.
     */
    public function generateZReport(string $date, ?int $warehouseId = null, ?int $userId = null): array
    {
        [$startOfDay, $endOfDay] = RegionalDateRange::utcBounds($date, $date);
        $sessions = \App\Models\PosSession::with('user')
            ->whereBetween('opened_at', [$startOfDay, $endOfDay])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get();

        // =========================================
        // 1. CASH DRAWER: PosSession opening balance
        // =========================================
        $openingAmount = (float) $sessions->sum('opening_amount');

        // =========================================
        // 2. PAYMENTS received today (Cash-basis revenue)
        // =========================================
        $paymentsQuery = \App\Models\SalesOrderPayment::with('salesOrder')
            ->whereBetween('paid_at', [$startOfDay, $endOfDay])
            ->where('amount', '>', 0);

        if ($warehouseId || $userId) {
            $paymentsQuery->whereHas('salesOrder', function ($q) use ($warehouseId, $userId) {
                if ($userId) {
                    $q->where('user_id', $userId);
                }
                if ($warehouseId) {
                    $q->whereHas('items', function ($q2) use ($warehouseId) {
                        $q2->where('warehouse_id', $warehouseId);
                    });
                }
            });
        }

        $payments = $paymentsQuery->get();

        // =========================================
        // 3. ORDERS created today (for count, tax, discounts, COGS)
        // =========================================
        $ordersQuery = \App\Models\SalesOrder::with(['payments', 'items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('created_at', [$startOfDay, $endOfDay]);

        if ($userId) {
            $ordersQuery->where('user_id', $userId);
        }

        if ($warehouseId) {
            $ordersQuery->whereHas('items', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $ordersToday = $ordersQuery->get();

        // =========================================
        // 4. REFUNDS: Approved SalesReturns for the day
        // =========================================
        $refundsQuery = SalesReturn::query()
            ->with('items.salesOrderItem')
            ->where('status', 'approved')
            ->where(function ($query) use ($startOfDay, $endOfDay): void {
                $query->whereBetween('approved_at', [$startOfDay, $endOfDay])
                    ->orWhere(function ($legacy) use ($startOfDay, $endOfDay): void {
                        $legacy->whereNull('approved_at')
                            ->whereBetween('updated_at', [$startOfDay, $endOfDay]);
                    });
            });

        if ($userId) {
            $refundsQuery->where('user_id', $userId);
        }

        if ($warehouseId) {
            $refundsQuery->where('warehouse_id', $warehouseId);
        }

        $refunds = $refundsQuery->get();
        $totalRefunds = (float) $refunds->sum('total_amount');
        $refundCogs = (float) $refunds->sum(fn (SalesReturn $return): float => $this->salesReturnCogs($return));

        // =========================================
        // 5. BUILD SUMMARY
        // =========================================
        $summary = [
            'date' => $date,
            'total_sales' => 0,          // Gross sales (from payments)
            'total_tax' => 0,
            'total_discounts' => 0,
            'total_shipping' => 0,
            'refunds' => round($totalRefunds, 2),
            'net_sales' => 0,            // total_sales - refunds
            'orders_count' => $ordersToday->count(),
            'average_ticket' => 0,
            'cogs' => 0,                 // Cost of goods sold today
            'gross_profit' => 0,         // net_sales - cogs
            'by_payment_method' => [],
            'cash_drawer' => [
                'opening_amount' => round($openingAmount, 2),
                'cash_sales' => 0,
                'cash_refunds' => 0,
                'expected_cash' => 0,
                'declared_cash' => 0,
                'closing_amount' => 0,
                'difference' => 0,
                'sessions_count' => $sessions->count(),
            ],
            'by_user' => [],
        ];

        // 5a. Payments → total_sales + by_payment_method
        foreach ($payments as $payment) {
            $summary['total_sales'] += $payment->amount;

            $method = $payment->method ?? 'unknown';
            if (! isset($summary['by_payment_method'][$method])) {
                $summary['by_payment_method'][$method] = 0;
            }
            $summary['by_payment_method'][$method] += $payment->amount;
        }

        // 5b. Orders today → tax, discounts, shipping, COGS, + legacy cash
        foreach ($ordersToday as $order) {
            $summary['total_tax'] += $order->tax_amount;
            $summary['total_discounts'] += $order->discount_amount;
            $summary['total_shipping'] += $order->shipping_cost;

            // COGS: sum unit_cost * quantity for each item
            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            foreach ($items as $item) {
                $summary['cogs'] += ((float) $item->unit_cost * $item->quantity);
            }

            // LEGACY: If order is paid but has no payment records
            if ($order->payment_status === 'paid' && $order->payments->isEmpty()) {
                $summary['total_sales'] += $order->total;
                $method = $order->payment_method ?? 'cash';
                if (! isset($summary['by_payment_method'][$method])) {
                    $summary['by_payment_method'][$method] = 0;
                }
                $summary['by_payment_method'][$method] += $order->total;
            }
        }

        $summary['cogs'] -= $refundCogs;

        // 5c. Calculate derived metrics
        $summary['net_sales'] = $summary['total_sales'] - $totalRefunds;
        $summary['gross_profit'] = $summary['net_sales'] - $summary['cogs'];
        $summary['average_ticket'] = $summary['orders_count'] > 0
            ? $summary['net_sales'] / $summary['orders_count']
            : 0;

        // 5d. Cash drawer reconciliation
        $cashSales = $summary['by_payment_method']['cash'] ?? 0;
        $cashRefunds = $refunds->sum(function (SalesReturn $return): float {
            if ($return->refund_method !== 'cash') {
                return 0.0;
            }

            $moneyRefund = (float) ($return->money_refund_amount ?? 0);

            return $moneyRefund > 0 ? $moneyRefund : (float) $return->total_amount;
        });

        // Cash movements (retiros / ingresos extra) from sessions of the day
        $sessionIds = $sessions->pluck('id');

        $cashMovementsIn = (float) \App\Models\CashMovement::whereIn('pos_session_id', $sessionIds)->where('type', 'in')->sum('amount');
        $cashMovementsOut = (float) \App\Models\CashMovement::whereIn('pos_session_id', $sessionIds)->where('type', 'out')->sum('amount');
        $sessionExpectedCash = (float) $sessions->sum('expected_amount');
        $sessionClosingAmount = (float) $sessions->sum('closing_amount');
        $sessionDeclaredCash = (float) $sessions->sum(function ($session): float {
            $summary = $session->closing_details['summary'] ?? [];

            return (float) ($summary['declared_cash'] ?? $summary['counted_cash'] ?? $session->closing_amount ?? 0);
        });
        $sessionDifference = (float) $sessions->sum(function ($session): float {
            $summary = $session->closing_details['summary'] ?? [];

            if (array_key_exists('total_difference', $summary)) {
                return (float) $summary['total_difference'];
            }

            if (array_key_exists('difference', $summary)) {
                return (float) $summary['difference'];
            }

            if ($session->closing_amount !== null && $session->expected_amount !== null) {
                return (float) $session->closing_amount - (float) $session->expected_amount;
            }

            return 0.0;
        });

        $summary['cash_drawer']['cash_sales'] = round($cashSales, 2);
        $summary['cash_drawer']['cash_refunds'] = round($cashRefunds, 2);
        $summary['cash_drawer']['cash_movements_in'] = round($cashMovementsIn, 2);
        $summary['cash_drawer']['cash_movements_out'] = round($cashMovementsOut, 2);
        $summary['cash_drawer']['expected_cash'] = round(
            $sessionExpectedCash > 0
                ? $sessionExpectedCash
                : $openingAmount + $cashSales - $cashRefunds + $cashMovementsIn - $cashMovementsOut,
            2
        );
        $summary['cash_drawer']['declared_cash'] = round($sessionDeclaredCash, 2);
        $summary['cash_drawer']['closing_amount'] = round($sessionClosingAmount, 2);
        $summary['cash_drawer']['difference'] = round($sessionDifference, 2);
        $summary['by_user'] = $sessions
            ->groupBy('user_id')
            ->map(function (Collection $userSessions, $groupedUserId): array {
                $declaredCash = (float) $userSessions->sum(function ($session): float {
                    $summary = $session->closing_details['summary'] ?? [];

                    return (float) ($summary['declared_cash'] ?? $summary['counted_cash'] ?? $session->closing_amount ?? 0);
                });
                $expectedCash = (float) $userSessions->sum('expected_amount');
                $closingAmount = (float) $userSessions->sum('closing_amount');
                $difference = (float) $userSessions->sum(function ($session): float {
                    $summary = $session->closing_details['summary'] ?? [];

                    if (array_key_exists('total_difference', $summary)) {
                        return (float) $summary['total_difference'];
                    }

                    if (array_key_exists('difference', $summary)) {
                        return (float) $summary['difference'];
                    }

                    if ($session->closing_amount !== null && $session->expected_amount !== null) {
                        return (float) $session->closing_amount - (float) $session->expected_amount;
                    }

                    return 0.0;
                });

                return [
                    'user_id' => (int) $groupedUserId,
                    'user_name' => $userSessions->first()->user?->name,
                    'sessions_count' => $userSessions->count(),
                    'opening_amount' => round((float) $userSessions->sum('opening_amount'), 2),
                    'expected_cash' => round($expectedCash, 2),
                    'declared_cash' => round($declaredCash, 2),
                    'closing_amount' => round($closingAmount, 2),
                    'difference' => round($difference, 2),
                ];
            })
            ->values()
            ->all();

        // Round everything
        $summary['total_sales'] = round($summary['total_sales'], 2);
        $summary['total_tax'] = round($summary['total_tax'], 2);
        $summary['total_discounts'] = round($summary['total_discounts'], 2);
        $summary['total_shipping'] = round($summary['total_shipping'], 2);
        $summary['net_sales'] = round($summary['net_sales'], 2);
        $summary['average_ticket'] = round($summary['average_ticket'], 2);
        $summary['cogs'] = round($summary['cogs'], 2);
        $summary['gross_profit'] = round($summary['gross_profit'], 2);

        foreach ($summary['by_payment_method'] as $k => $v) {
            $summary['by_payment_method'][$k] = round($v, 2);
        }

        return $summary;
    }

    public function generateDailyFinancialSummary(array $filters): array
    {
        return app(FinancialSnapshotService::class)->rangeSummary($filters);
    }

    public function generateBalanceSheet(array $filters): array
    {
        return app(FinancialSnapshotService::class)->balanceSheet($filters);
    }

    public function generateExpenseBreakdown(array $filters): array
    {
        $expenses = $this->financialOperationalExpenses($filters);
        $byCategory = collect($expenses['by_category']);
        $other = $byCategory->firstWhere('category', 'other');
        $items = $byCategory
            ->reject(fn (array $category): bool => $category['category'] === 'other')
            ->map(fn (array $category): array => [
                'label' => $category['label'],
                'amount' => (float) $category['amount'],
                'expense_category' => $category['category'],
            ])
            ->values();

        return [
            'items' => $items->all(),
            'other' => [
                'label' => 'Others',
                'amount' => (float) ($other['amount'] ?? 0),
                'expense_category' => 'other',
            ],
            'total' => (float) $expenses['total'],
        ];
    }

    private function emptyDailyRow(string $date): array
    {
        return [
            'date' => $date,
            'cash' => 0.0,
            'bank' => 0.0,
            'inventory' => 0.0,
            'purchase' => 0.0,
            'expenses' => 0.0,
            'sales' => 0.0,
            'cogs' => 0.0,
            'profit' => 0.0,
            'credit_payable' => 0.0,
            'credit_receivable' => 0.0,
            'cogs_credit' => 0.0,
            'investment' => 0.0,
        ];
    }

    private function fillDailySales(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $payments = SalesOrderPayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->where('amount', '>', 0)
            ->when($warehouseId, fn ($q) => $q->whereHas('salesOrder.items', fn ($q) => $q->where('warehouse_id', $warehouseId)))
            ->get();

        foreach ($payments as $payment) {
            $date = $payment->paid_at?->format('Y-m-d');
            if (isset($days[$date])) {
                $days[$date]['sales'] = round($days[$date]['sales'] + (float) $payment->amount, 2);
            }
        }
    }

    private function fillDailyCogs(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $orders = SalesOrder::with(['items'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->whereHas('items', fn ($q) => $q->where('warehouse_id', $warehouseId)))
            ->get();

        foreach ($orders as $order) {
            $date = $order->date?->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }

            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            $cogs = $items->sum(fn ($item): float => (float) $item->unit_cost * (int) $item->quantity);
            $days[$date]['cogs'] = round($days[$date]['cogs'] + $cogs, 2);
        }
    }

    private function fillDailyPurchases(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $purchases = PurchaseOrder::query()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereBetween('order_date', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get(['order_date', 'total']);

        foreach ($purchases as $purchase) {
            $date = $purchase->order_date?->format('Y-m-d');
            if (isset($days[$date])) {
                $days[$date]['purchase'] = round($days[$date]['purchase'] + (float) $purchase->total, 2);
            }
        }
    }

    private function fillDailyExpenses(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $expenses = OperationalExpense::query()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->where('affects_income_statement', true)->orWhereNull('affects_income_statement'))
            ->when($warehouseId, fn ($q) => $q->where(fn ($q) => $q->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get(['expense_date', 'amount']);

        foreach ($expenses as $expense) {
            $date = $expense->expense_date?->format('Y-m-d');
            if (isset($days[$date])) {
                $days[$date]['expenses'] = round($days[$date]['expenses'] + (float) $expense->amount, 2);
            }
        }
    }

    private function fillDailyCash(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $sessionIds = PosSession::query()
            ->whereBetween('opened_at', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->pluck('id');

        $movements = CashMovement::query()
            ->whereIn('pos_session_id', $sessionIds)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        foreach ($movements as $movement) {
            $date = $movement->created_at?->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }

            $amount = (float) $movement->amount;
            $days[$date]['cash'] = round($days[$date]['cash'] + ($movement->type === 'in' ? $amount : -$amount), 2);
        }

        $openingTotal = (float) PosSession::query()
            ->whereBetween('opened_at', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('opening_amount');

        if ($openingTotal > 0) {
            $firstDate = $from->toDateString();
            if (isset($days[$firstDate])) {
                $days[$firstDate]['cash'] = round($days[$firstDate]['cash'] + $openingTotal, 2);
            }
        }
    }

    private function fillDailyInventory(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        foreach ($days as $date => &$row) {
            $row['inventory'] = $this->inventoryValueAsOf($date, $warehouseId);
        }
    }

    private function fillDailyCredits(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $payableOrders = PurchaseOrder::query()
            ->whereNotIn('status', ['cancelled'])
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereDate('order_date', '<=', $to->toDateString())
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->with('payments')
            ->get();

        $receivableOrders = SalesOrder::query()
            ->whereNotIn('status', ['cancelled'])
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereDate('date', '<=', $to->toDateString())
            ->when($warehouseId, fn ($q) => $q->whereHas('items', fn ($q) => $q->where('warehouse_id', $warehouseId)))
            ->with('payments')
            ->get();

        foreach ($days as $date => &$row) {
            $asOf = Carbon::parse($date)->endOfDay();
            $row['credit_payable'] = round((float) $payableOrders
                ->filter(fn ($order) => $order->order_date?->lte($asOf))
                ->sum(fn ($order) => max(0, (float) $order->total - (float) $order->payments->where('paid_at', '<=', $asOf)->sum('amount'))), 2);
            $row['credit_receivable'] = round((float) $receivableOrders
                ->filter(fn ($order) => $order->date?->lte($asOf))
                ->sum(fn ($order) => max(0, (float) $order->total - (float) $order->payments->where('paid_at', '<=', $asOf)->sum('amount'))), 2);
        }
    }

    private function fillDailyCogsCredit(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $creditOrders = SalesOrder::with(['items', 'payments'])
            ->whereIn('status', ['confirmed', 'processing', 'completed'])
            ->whereBetween('date', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->whereHas('items', fn ($q) => $q->where('warehouse_id', $warehouseId)))
            ->get();

        foreach ($creditOrders as $order) {
            $hasCredit = $order->payments->contains('method', 'credit');
            if (! $hasCredit) {
                continue;
            }

            $date = $order->date?->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }

            $items = $warehouseId
                ? $order->items->where('warehouse_id', $warehouseId)
                : $order->items;

            $cogs = $items->sum(fn ($item): float => (float) $item->unit_cost * (int) $item->quantity);
            $days[$date]['cogs_credit'] = round($days[$date]['cogs_credit'] + $cogs, 2);
        }
    }

    private function fillDailyInvestment(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $investments = CashMovement::query()
            ->where('type', 'investment')
            ->whereBetween('created_at', [$from, $to])
            ->when($warehouseId, fn ($q) => $q->whereHas('posSession', fn ($q) => $q->where('warehouse_id', $warehouseId)))
            ->get();

        foreach ($investments as $investment) {
            $date = $investment->created_at?->format('Y-m-d');
            if (isset($days[$date])) {
                $days[$date]['investment'] = round($days[$date]['investment'] + (float) $investment->amount, 2);
            }
        }
    }

    private function fillDailyAdjustments(array &$days, Carbon $from, Carbon $to, ?int $warehouseId): void
    {
        $adjustments = FinancialAdjustment::query()
            ->whereBetween('adjustment_date', [$from->toDateString(), $to->toDateString()])
            ->when($warehouseId, fn ($q) => $q->where(fn ($q) => $q->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get();

        foreach ($adjustments as $adjustment) {
            $date = $adjustment->adjustment_date?->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }

            $amount = $adjustment->signedAmount();

            match ($adjustment->type) {
                FinancialAdjustment::TYPE_CASH => $days[$date]['cash'] = round($days[$date]['cash'] + $amount, 2),
                FinancialAdjustment::TYPE_BANK => $days[$date]['bank'] = round($days[$date]['bank'] + $amount, 2),
                FinancialAdjustment::TYPE_INVENTORY => $days[$date]['inventory'] = round($days[$date]['inventory'] + $amount, 2),
                FinancialAdjustment::TYPE_SALES => $days[$date]['sales'] = round($days[$date]['sales'] + $amount, 2),
                FinancialAdjustment::TYPE_PURCHASES => $days[$date]['purchase'] = round($days[$date]['purchase'] + $amount, 2),
                FinancialAdjustment::TYPE_EXPENSES => $days[$date]['expenses'] = round($days[$date]['expenses'] + $amount, 2),
                FinancialAdjustment::TYPE_INVESTMENT => $days[$date]['investment'] = round($days[$date]['investment'] + $amount, 2),
                FinancialAdjustment::TYPE_ACCOUNTS_RECEIVABLE => $days[$date]['credit_receivable'] = round($days[$date]['credit_receivable'] + $amount, 2),
                FinancialAdjustment::TYPE_ACCOUNTS_PAYABLE => $days[$date]['credit_payable'] = round($days[$date]['credit_payable'] + $amount, 2),
                default => null,
            };
        }
    }

    private function dailyTotals(array $days): array
    {
        $keys = ['cash', 'bank', 'inventory', 'purchase', 'expenses', 'sales', 'cogs', 'profit', 'credit_payable', 'credit_receivable', 'cogs_credit', 'investment'];

        return collect($keys)->mapWithKeys(fn (string $key): array => [
            $key => round(collect($days)->sum($key), 2),
        ])->all();
    }

    private function adjustmentsTotal(string $type, ?int $warehouseId = null): float
    {
        return round((float) FinancialAdjustment::query()
            ->where('type', $type)
            ->when($warehouseId, fn ($q) => $q->where(fn ($q) => $q->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get(['amount', 'direction'])
            ->sum(fn (FinancialAdjustment $adjustment): float => $adjustment->signedAmount()), 2);
    }

    private function salesOrderWarehouseRatioForReport(SalesOrder $order, int $warehouseId): float
    {
        $scopedTotal = (float) $order->items
            ->where('warehouse_id', $warehouseId)
            ->sum(fn ($item): float => (float) $item->total);

        return min(1, max(0, $scopedTotal / max(0.01, (float) $order->total)));
    }
}
