<?php

namespace App\Services;

use App\Models\ProductInventory;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupplierProfileService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function purchaseHistory(Supplier $supplier, array $filters = []): array
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));
        $summaryRows = $this->purchaseHistoryQuery($supplier, $filters)
            ->get()
            ->map(fn (PurchaseOrder $order): array => $this->purchaseHistoryOrderRow($order, $supplier))
            ->values()
            ->all();

        $orders = $this->purchaseHistoryQuery($supplier, $filters)->paginate($perPage);
        $mappedOrders = $orders->getCollection()
            ->map(fn (PurchaseOrder $order): array => $this->purchaseHistoryOrderRow($order, $supplier))
            ->values();

        $orders->setCollection($mappedOrders);

        return [
            'data' => $orders->items(),
            'summary' => $this->purchaseHistorySummary($summaryRows),
            'meta' => $this->paginationMeta($orders),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function suppliedProductMetrics(ProductSupplier $productSupplier): array
    {
        $purchaseItems = $this->purchaseItemsForSupplierProduct($productSupplier)
            ->with('purchaseOrder:id,status,order_date,received_date')
            ->orderByDesc(
                PurchaseOrder::query()
                    ->select('order_date')
                    ->whereColumn('purchase_orders.id', 'purchase_order_items.purchase_order_id')
                    ->limit(1)
            )
            ->orderByDesc('purchase_order_items.id')
            ->get();

        $latest = $purchaseItems->first();
        $previous = $purchaseItems->skip(1)->first();
        $purchaseDates = $purchaseItems
            ->pluck('purchaseOrder.order_date')
            ->filter()
            ->map(fn ($date) => $date->copy()->startOfDay())
            ->sort()
            ->values();
        $actualLeadTimes = $purchaseItems
            ->filter(fn (PurchaseOrderItem $item): bool => $item->purchaseOrder?->received_date !== null)
            ->map(fn (PurchaseOrderItem $item): int => max(
                0,
                $item->purchaseOrder->order_date->diffInDays($item->purchaseOrder->received_date, false)
            ));
        $pendingItems = $purchaseItems->filter(fn (PurchaseOrderItem $item): bool => in_array(
            $item->purchaseOrder?->status,
            ['draft', 'open', 'sent', 'partial'],
            true
        ));
        $lastUnitCost = $latest ? (float) $latest->unit_cost : null;
        $previousUnitCost = $previous ? (float) $previous->unit_cost : null;
        $costDelta = $lastUnitCost !== null && $previousUnitCost !== null
            ? round($lastUnitCost - $previousUnitCost, 2)
            : null;

        return [
            'last_unit_cost' => $lastUnitCost,
            'last_landed_unit_cost' => $latest ? $this->landedUnitCost($latest) : null,
            'previous_unit_cost' => $previousUnitCost,
            'cost_delta' => $costDelta,
            'cost_delta_percent' => $costDelta !== null && $previousUnitCost > 0
                ? round(($costDelta / $previousUnitCost) * 100, 2)
                : null,
            'purchase_count' => $purchaseItems->count(),
            'average_days_between_purchases' => $this->averageDaysBetweenPurchases($purchaseDates),
            'pending_orders_count' => $pendingItems->pluck('purchase_order_id')->unique()->count(),
            'pending_units' => (int) $pendingItems->sum(fn (PurchaseOrderItem $item): int => $item->pending_quantity),
            'last_purchase_at' => $latest?->purchaseOrder?->order_date?->toDateString(),
            'promised_lead_time_days' => $productSupplier->lead_time_days,
            'average_actual_lead_time_days' => $actualLeadTimes->isNotEmpty()
                ? round((float) $actualLeadTimes->avg(), 1)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function commercialDashboard(array $filters = []): array
    {
        $uncovered = $this->productsWithoutSupplier($filters);

        return [
            'summary' => [
                'suppliers_count' => Supplier::query()->whereHas('productSuppliers')->count(),
                'supplied_variants_count' => ProductSupplier::query()->count(),
                'products_without_supplier_count' => $uncovered->count(),
                'low_stock_without_supplier_count' => $uncovered
                    ->filter(fn (array $row): bool => (bool) $row['needs_reorder'])
                    ->count(),
            ],
            'products_without_supplier' => $uncovered->values()->all(),
            'suppliers_with_pending_orders' => $this->suppliersWithPendingOrders(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function purchaseHistoryQuery(Supplier $supplier, array $filters): Builder
    {
        $operator = $this->caseInsensitiveLikeOperator();

        return PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'warehouse:id,name',
                'items' => fn ($query) => $query
                    ->where(function ($query) use ($supplier): void {
                        $query->where('supplier_id', $supplier->id)
                            ->orWhereHas('purchaseOrder', function ($query) use ($supplier): void {
                                $query->where('supplier_id', $supplier->id);
                            });
                    })
                    ->with(['product:id,name,sku_base', 'variant:id,product_id,brand_id,sku', 'variant.brand:id,name']),
                'payments.user:id,name',
            ])
            ->where(function (Builder $query) use ($supplier): void {
                $query->where('supplier_id', $supplier->id)
                    ->orWhereHas('items', function (Builder $query) use ($supplier): void {
                        $query->where('supplier_id', $supplier->id);
                    });
            })
            ->when(! empty($filters['date_from']), function (Builder $query) use ($filters): void {
                $query->whereDate('order_date', '>=', $filters['date_from']);
            })
            ->when(! empty($filters['date_to']), function (Builder $query) use ($filters): void {
                $query->whereDate('order_date', '<=', $filters['date_to']);
            })
            ->when(! empty($filters['status']), function (Builder $query) use ($filters): void {
                $query->where('status', $filters['status']);
            })
            ->when(! empty($filters['payment_status']), function (Builder $query) use ($filters): void {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when(! empty($filters['search']), function (Builder $query) use ($filters, $operator): void {
                $search = (string) $filters['search'];

                $query->where(function (Builder $query) use ($search, $operator): void {
                    $query->where('po_number', $operator, "%{$search}%")
                        ->orWhere('supplier_invoice_number', $operator, "%{$search}%")
                        ->orWhereHas('items.product', function (Builder $query) use ($search, $operator): void {
                            $query->where('name', $operator, "%{$search}%")
                                ->orWhere('sku_base', $operator, "%{$search}%");
                        })
                        ->orWhereHas('items.variant', function (Builder $query) use ($search, $operator): void {
                            $query->where('sku', $operator, "%{$search}%")
                                ->orWhereHas('brand', function (Builder $query) use ($search, $operator): void {
                                    $query->where('name', $operator, "%{$search}%");
                                });
                        });
                });
            })
            ->latest('order_date')
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseHistoryOrderRow(PurchaseOrder $order, Supplier $supplier): array
    {
        $isAccountsPayable = (int) $order->supplier_id === $supplier->id;
        $items = $order->items
            ->filter(fn (PurchaseOrderItem $item): bool => (int) ($item->supplier_id ?? $order->supplier_id) === $supplier->id)
            ->map(fn (PurchaseOrderItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product?->name,
                'product_sku' => $item->product?->sku_base,
                'variant_sku' => $item->variant?->sku,
                'brand_name' => $item->variant?->brand?->name,
                'quantity_ordered' => (int) $item->quantity_ordered,
                'quantity_received' => (int) $item->quantity_received,
                'unit_cost' => (float) $item->unit_cost,
                'tax_amount' => (float) $item->tax_amount,
                'misc_cost' => (float) ($item->misc_cost ?? 0),
                'total' => (float) $item->total,
            ])
            ->values();

        $supplierItemsTotal = round((float) $items->sum('total'), 2);
        $payments = $isAccountsPayable
            ? $order->payments
            : collect();

        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'supplier_invoice_number' => $order->supplier_invoice_number,
            'order_date' => optional($order->order_date)->toDateString(),
            'status' => $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status,
            'payment_status_label' => $order->payment_status_label,
            'is_accounts_payable' => $isAccountsPayable,
            'warehouse' => $order->warehouse ? [
                'id' => $order->warehouse->id,
                'name' => $order->warehouse->name,
            ] : null,
            'document_total' => (float) $order->total,
            'total' => (float) $order->total,
            'amount_paid' => $isAccountsPayable ? (float) $order->amount_paid : 0.0,
            'amount_due' => $isAccountsPayable ? (float) $order->amount_due : 0.0,
            'supplier_items_total' => $supplierItemsTotal,
            'items' => $items->all(),
            'payments' => $payments
                ->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'method_label' => $payment->method_label,
                    'amount' => (float) $payment->amount,
                    'reference_number' => $payment->reference_number,
                    'paid_at' => $payment->paid_at?->toISOString(),
                    'notes' => $payment->notes,
                    'user_name' => $payment->user?->name,
                ])
                ->values()
                ->all(),
            'payment_summary' => $payments
                ->groupBy('method')
                ->map(fn ($payments, string $method): array => [
                    'method' => $method,
                    'method_label' => $payments->first()->method_label,
                    'amount' => round((float) $payments->sum('amount'), 2),
                    'count' => $payments->count(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<string, mixed>
     */
    private function purchaseHistorySummary(array $orders): array
    {
        $items = collect($orders)->flatMap(fn (array $order): array => $order['items']);

        return [
            'orders_count' => count($orders),
            'items_count' => $items->count(),
            'total_ordered' => round((float) collect($orders)->sum('supplier_items_total'), 2),
            'total_paid' => round((float) collect($orders)->sum('amount_paid'), 2),
            'total_due' => round((float) collect($orders)
                ->filter(fn (array $order): bool => $order['is_accounts_payable'] && $order['payment_status'] !== 'paid')
                ->sum('amount_due'), 2),
            'total_received_units' => (int) $items->sum('quantity_received'),
            'total_ordered_units' => (int) $items->sum('quantity_ordered'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $orders): array
    {
        return [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ];
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';
    }

    private function purchaseItemsForSupplierProduct(ProductSupplier $productSupplier): Builder
    {
        return PurchaseOrderItem::query()
            ->where(function (Builder $query) use ($productSupplier): void {
                $query->where('supplier_id', $productSupplier->supplier_id)
                    ->orWhereHas('purchaseOrder', function (Builder $query) use ($productSupplier): void {
                        $query->where('supplier_id', $productSupplier->supplier_id);
                    });
            })
            ->where('product_id', $productSupplier->product_id)
            ->when(
                $productSupplier->variant_id !== null,
                fn (Builder $query): Builder => $query->where('variant_id', $productSupplier->variant_id)
            );
    }

    private function landedUnitCost(PurchaseOrderItem $item): float
    {
        $units = max(1, (int) $item->quantity_ordered * (int) ($item->units_per_package ?? 1));

        return round(((float) $item->subtotal + (float) $item->tax_amount + (float) ($item->misc_cost ?? 0)) / $units, 4);
    }

    private function averageDaysBetweenPurchases(\Illuminate\Support\Collection $purchaseDates): ?float
    {
        if ($purchaseDates->count() < 2) {
            return null;
        }

        return round($purchaseDates->first()->diffInDays($purchaseDates->last()) / ($purchaseDates->count() - 1), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function productsWithoutSupplier(array $filters): \Illuminate\Support\Collection
    {
        $operator = $this->caseInsensitiveLikeOperator();

        return ProductInventory::query()
            ->with(['product:id,name,sku_base', 'variant:id,product_id,brand_id,sku', 'variant.brand:id,name', 'warehouse:id,name'])
            ->when(! empty($filters['warehouse_id']), function (Builder $query) use ($filters): void {
                $query->where('warehouse_id', $filters['warehouse_id']);
            })
            ->when(! empty($filters['only_low_stock']), function (Builder $query): void {
                $query->whereColumn('quantity_available', '<=', 'reorder_point');
            })
            ->when(! empty($filters['search']), function (Builder $query) use ($filters, $operator): void {
                $search = (string) $filters['search'];

                $query->where(function (Builder $query) use ($search, $operator): void {
                    $query->whereHas('product', function (Builder $query) use ($search, $operator): void {
                        $query->where('name', $operator, "%{$search}%")
                            ->orWhere('sku_base', $operator, "%{$search}%");
                    })->orWhereHas('variant', function (Builder $query) use ($search, $operator): void {
                        $query->where('sku', $operator, "%{$search}%");
                    });
                });
            })
            ->get()
            ->filter(fn (ProductInventory $inventory): bool => ! $this->hasSupplierCoverage($inventory))
            ->map(fn (ProductInventory $inventory): array => [
                'product_id' => $inventory->product_id,
                'variant_id' => $inventory->variant_id,
                'product_name' => $inventory->product?->name,
                'product_sku' => $inventory->product?->sku_base,
                'variant_sku' => $inventory->variant?->sku,
                'brand_name' => $inventory->variant?->brand?->name,
                'warehouse' => $inventory->warehouse ? [
                    'id' => $inventory->warehouse->id,
                    'name' => $inventory->warehouse->name,
                ] : null,
                'quantity_available' => (int) $inventory->quantity_available,
                'reorder_point' => (int) $inventory->reorder_point,
                'reorder_quantity' => (int) $inventory->reorder_quantity,
                'needs_reorder' => (int) $inventory->quantity_available <= (int) $inventory->reorder_point,
            ]);
    }

    private function hasSupplierCoverage(ProductInventory $inventory): bool
    {
        return ProductSupplier::query()
            ->where('product_id', $inventory->product_id)
            ->where(function (Builder $query) use ($inventory): void {
                $query->whereNull('variant_id');

                if ($inventory->variant_id !== null) {
                    $query->orWhere('variant_id', $inventory->variant_id);
                }
            })
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suppliersWithPendingOrders(): array
    {
        return Supplier::query()
            ->whereHas('purchaseOrders', fn (Builder $query) => $query->whereIn('status', ['draft', 'open', 'sent', 'partial']))
            ->withCount(['purchaseOrders as pending_orders_count' => fn (Builder $query) => $query->whereIn('status', ['draft', 'open', 'sent', 'partial'])])
            ->orderByDesc('pending_orders_count')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'pending_orders_count' => (int) $supplier->pending_orders_count,
            ])
            ->all();
    }
}
