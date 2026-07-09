<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\FinancialAdjustment;
use App\Models\InventoryLot;
use App\Models\OperationalExpense;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderPayment;
use App\Support\RegionalDateRange;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FinancialDrilldownService
{
    private const SALES_STATUSES = ['confirmed', 'processing', 'completed'];

    private const PURCHASE_EXCLUDED_STATUSES = ['cancelled', 'draft'];

    private const NON_MONEY_METHODS = ['credit', 'store_credit', 'supplier_credit'];

    public function __construct(private readonly FinancialSnapshotService $snapshots)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(array $filters): array
    {
        $metric = (string) ($filters['metric'] ?? 'sales');
        $from = Carbon::parse($filters['date_from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['date_to'] ?? now()->toDateString())->startOfDay();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        $expenseCategory = trim((string) ($filters['expense_category'] ?? '')) ?: null;
        $expenseGroup = trim((string) ($filters['expense_group'] ?? '')) ?: null;

        return match ($metric) {
            'sales', 'sales_total' => $this->sales($metric, $from, $to, $warehouseId, $page, $perPage),
            'payments_received', 'sales_collected' => $this->salesPayments($metric, $from, $to, $warehouseId, $page, $perPage),
            'purchases', 'purchase' => $this->purchases($metric, $from, $to, $warehouseId, $page, $perPage),
            'supplier_payments', 'purchase_paid' => $this->supplierPayments($metric, $from, $to, $warehouseId, $page, $perPage),
            'expenses', 'operational_expenses' => $this->expenses($metric, $from, $to, $warehouseId, $page, $perPage, $expenseCategory, $expenseGroup),
            'inventory' => $this->inventory($metric, $to, $warehouseId, $page, $perPage),
            'receivable', 'accounts_receivable', 'credit_receivable', 'sales_pending' => $this->receivables($metric, $to, $warehouseId, $page, $perPage),
            'payable', 'accounts_payable', 'credit_payable', 'purchase_pending' => $this->payables($metric, $to, $warehouseId, $page, $perPage),
            'cogs' => $this->cogs($metric, $from, $to, $warehouseId, $page, $perPage),
            'profit', 'gross_profit', 'net_profit' => $this->profit($metric, $from, $to, $warehouseId, $page, $perPage),
            'assets', 'liabilities', 'equity' => $this->compositeBalance($metric, $from, $to, $warehouseId, $page, $perPage),
            'cash', 'bank' => $this->liquidity($metric, $from, $to, $warehouseId, $page, $perPage),
            'investment', 'investment_in', 'investment_out' => $this->investment($metric, $from, $to, $warehouseId, $page, $perPage),
            'adjustments' => $this->adjustments($metric, $from, $to, $warehouseId, $page, $perPage),
            default => $this->emptyPayload($metric, 'Metrica no soportada', $page, $perPage, ['metric_not_supported']),
        };
    }

    private function sales(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $orders = SalesOrder::query()
            ->with(['customer', 'payments', 'items', 'returns', 'user'])
            ->whereIn('status', self::SALES_STATUSES)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $rows = $orders->map(function (SalesOrder $order) use ($warehouseId, $to): array {
            $total = $this->salesOrderScopedTotal($order, $warehouseId);
            $ratio = $this->salesOrderWarehouseRatio($order, $warehouseId, $total);
            $returned = $this->approvedReturnAmountForOrder($order, $to, $warehouseId);
            $effectiveTotal = $this->money(max(0, $total - $returned));
            $paid = $this->money($order->payments
                ->whereNotIn('method', self::NON_MONEY_METHODS)
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio));

            return [
                'id' => $order->id,
                'date' => $order->date?->toDateString(),
                ...$this->traceFields($order->created_at, $order->business_datetime, 'created_at'),
                'document' => $order->order_number,
                'party' => $order->customer?->name ?? 'Cliente general',
                'user' => $order->user?->name,
                'status' => $order->status_label ?? $order->status,
                'method' => $order->payment_method,
                'total' => $effectiveTotal,
                'paid' => $paid,
                'pending' => $this->money(max(0, $effectiveTotal - $paid)),
                'amount' => $effectiveTotal,
            ];
        });

        return $this->collectionPayload($metric, 'Ventas', $this->salesColumns(), $rows, $page, $perPage);
    }

    private function salesPayments(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $payments = SalesOrderPayment::query()
            ->with(['salesOrder.customer', 'salesOrder.items', 'creator'])
            ->whereBetween('paid_at', $this->timestampBounds($from, $to))
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->where('amount', '>', 0)
            ->whereHas('salesOrder', fn (Builder $query) => $query->whereIn('status', self::SALES_STATUSES))
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('salesOrder.items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $rows = $payments->map(function (SalesOrderPayment $payment) use ($warehouseId): array {
            $order = $payment->salesOrder;
            $scopedTotal = $order ? $this->salesOrderScopedTotal($order, $warehouseId) : 0.0;
            $ratio = $order ? $this->salesOrderWarehouseRatio($order, $warehouseId, $scopedTotal) : 1.0;

            return [
                'id' => $payment->id,
                'date' => $payment->paid_at?->toDateString(),
                ...$this->traceFields($payment->paid_at, $payment->business_datetime, 'paid_at'),
                'document' => $order?->order_number,
                'party' => $order?->customer?->name ?? 'Cliente general',
                'user' => $payment->creator?->name,
                'method' => $payment->formatted_method ?? $payment->method,
                'reference' => $payment->reference_number ?? $payment->notes,
                'amount' => $this->money((float) $payment->amount * $ratio),
            ];
        });

        return $this->collectionPayload($metric, 'Pagos recibidos', $this->paymentColumns(), $rows, $page, $perPage);
    }

    private function purchases(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier', 'payments', 'user'])
            ->whereNotIn('status', self::PURCHASE_EXCLUDED_STATUSES)
            ->whereDate('order_date', '>=', $from->toDateString())
            ->whereDate('order_date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get();

        $rows = $orders->map(function (PurchaseOrder $order): array {
            $total = $this->money($order->total);
            $paid = $this->money($order->payments
                ->whereNotIn('method', self::NON_MONEY_METHODS)
                ->sum('amount'));

            return [
                'id' => $order->id,
                'date' => $order->order_date?->toDateString(),
                ...$this->traceFields($order->created_at, $order->business_datetime, 'created_at'),
                'document' => $order->po_number,
                'party' => $order->supplier?->name ?? 'Proveedor general',
                'user' => $order->user?->name,
                'method' => $order->payments
                    ->whereNotIn('method', self::NON_MONEY_METHODS)
                    ->map(fn (PurchaseOrderPayment $payment): string => $payment->method_label ?? $payment->method)
                    ->unique()
                    ->values()
                    ->join(' / ') ?: ($order->payment_status === 'pending' ? 'Credito' : null),
                'status' => $order->status_label ?? $order->status,
                'total' => $total,
                'paid' => $paid,
                'pending' => $this->money(max(0, $total - $paid)),
                'amount' => $total,
            ];
        });

        return $this->collectionPayload($metric, 'Compras', $this->salesColumns(), $rows, $page, $perPage);
    }

    private function supplierPayments(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $payments = PurchaseOrderPayment::query()
            ->with(['purchaseOrder.supplier', 'user'])
            ->whereBetween('paid_at', $this->timestampBounds($from, $to))
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereNotIn('status', self::PURCHASE_EXCLUDED_STATUSES))
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('purchaseOrder', fn (Builder $purchase) => $purchase->where('warehouse_id', $warehouseId)))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $rows = $payments->map(fn (PurchaseOrderPayment $payment): array => [
            'id' => $payment->id,
            'date' => $payment->paid_at?->toDateString(),
            ...$this->traceFields($payment->paid_at, $payment->business_datetime, 'paid_at'),
            'document' => $payment->purchaseOrder?->po_number,
            'party' => $payment->purchaseOrder?->supplier?->name ?? 'Proveedor general',
            'user' => $payment->user?->name,
            'method' => $payment->method_label ?? $payment->method,
            'reference' => $payment->reference_number,
            'amount' => $this->money($payment->amount),
        ]);

        return $this->collectionPayload($metric, 'Pagos a proveedores', $this->paymentColumns(), $rows, $page, $perPage);
    }

    private function expenses(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage, ?string $expenseCategory = null, ?string $expenseGroup = null): array
    {
        $expenses = $this->applyExpenseDrilldownFilters(
            OperationalExpense::query()
                ->with(['creator', 'categoryRecord'])
                ->whereDate('expense_date', '>=', $from->toDateString())
                ->whereDate('expense_date', '<=', $to->toDateString())
                ->where(fn (Builder $query) => $query->where('affects_income_statement', true)->orWhereNull('affects_income_statement'))
                ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId))),
            $expenseCategory,
            $expenseGroup,
        )
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $rows = $expenses->map(fn (OperationalExpense $expense): array => [
            'id' => $expense->id,
            'date' => $expense->expense_date?->toDateString(),
            ...$this->traceFields($expense->created_at, $expense->business_datetime, 'created_at'),
            'category_code' => $expense->category,
            'category' => $expense->category_label,
            'description' => $expense->description,
            'user' => $expense->creator?->name,
            'method' => $expense->payment_method_label,
            'reference' => $expense->reference_number,
            'amount' => $this->money($expense->amount),
        ]);

        $label = 'Gastos';
        if ($expenseCategory) {
            $label = 'Gastos: '.($rows->first()['category'] ?? $expenseCategory);
        } elseif ($expenseGroup) {
            $label = 'Gastos: '.$this->expenseGroupLabel($expenseGroup);
        }

        return $this->collectionPayload($metric, $label, [
            ...$this->datetimeColumns(),
            ['key' => 'category', 'label' => 'Categoria'],
            ['key' => 'description', 'label' => 'Concepto'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'method', 'label' => 'Metodo'],
            ['key' => 'reference', 'label' => 'Referencia'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ], $rows, $page, $perPage);
    }

    private function inventory(string $metric, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $lots = InventoryLot::query()
            ->with(['product.category', 'variant.brand', 'warehouse', 'sourceMovement.user'])
            ->where('quantity_available', '>', 0)
            ->whereDate('received_at', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();

        $rows = $lots->map(fn (InventoryLot $lot): array => [
            'id' => $lot->id,
            'date' => $lot->received_at?->toDateString(),
            ...$this->traceFields($lot->received_at, $lot->sourceMovement?->business_datetime, 'received_at'),
            'product' => $lot->product?->name,
            'sku' => $lot->variant?->sku ?? $lot->product?->sku_base,
            'brand' => $lot->variant?->brand?->name,
            'category' => $lot->product?->category?->name,
            'warehouse' => $lot->warehouse?->name,
            'user' => $lot->sourceMovement?->user?->name,
            'quantity' => (int) $lot->quantity_available,
            'unit_cost' => $this->money($lot->landed_unit_cost),
            'amount' => $this->money((float) $lot->quantity_available * (float) $lot->landed_unit_cost),
        ]);

        return $this->collectionPayload($metric, 'Inventario valorizado', [
            ...$this->datetimeColumns(),
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'brand', 'label' => 'Marca'],
            ['key' => 'category', 'label' => 'Categoria'],
            ['key' => 'warehouse', 'label' => 'Almacen'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'quantity', 'label' => 'Stock'],
            ['key' => 'unit_cost', 'label' => 'Costo', 'type' => 'money'],
            ['key' => 'amount', 'label' => 'Valor', 'type' => 'money'],
        ], $rows, $page, $perPage, ['inventory_historical_value_uses_current_fifo_lot_availability']);
    }

    private function receivables(string $metric, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $orders = SalesOrder::query()
            ->with(['customer', 'payments', 'items', 'returns', 'user'])
            ->whereIn('status', self::SALES_STATUSES)
            ->whereDate('date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $rows = $orders->map(function (SalesOrder $order) use ($warehouseId, $to): array {
            $total = $this->salesOrderScopedTotal($order, $warehouseId);
            $ratio = $this->salesOrderWarehouseRatio($order, $warehouseId, $total);
            $returned = $this->approvedReturnAmountForOrder($order, $to, $warehouseId);
            $effectiveTotal = $this->money(max(0, $total - $returned));
            $paid = $this->money($order->payments
                ->filter(fn (SalesOrderPayment $payment): bool => ! in_array($payment->method, self::NON_MONEY_METHODS, true)
                    && $payment->paid_at !== null
                    && $payment->paid_at->toDateString() <= $to->toDateString())
                ->sum(fn (SalesOrderPayment $payment): float => (float) $payment->amount * $ratio));
            $pending = $this->money(max(0, $effectiveTotal - $paid));

            return [
                'id' => $order->id,
                'date' => $order->date?->toDateString(),
                ...$this->traceFields($order->created_at, $order->business_datetime, 'created_at'),
                'document' => $order->order_number,
                'party' => $order->customer?->name ?? 'Cliente general',
                'user' => $order->user?->name,
                'method' => $order->payment_method,
                'total' => $effectiveTotal,
                'paid' => $paid,
                'pending' => $pending,
                'amount' => $pending,
            ];
        })->filter(fn (array $row): bool => $row['pending'] > 0)->values();

        return $this->collectionPayload($metric, 'Cuentas por cobrar', $this->salesColumns(), $rows, $page, $perPage);
    }

    private function payables(string $metric, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier', 'payments', 'user'])
            ->whereNotIn('status', self::PURCHASE_EXCLUDED_STATUSES)
            ->whereDate('order_date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get();

        $rows = $orders->map(function (PurchaseOrder $order) use ($to): array {
            $total = $this->money($order->total);
            $paid = $this->money($order->payments
                ->filter(fn (PurchaseOrderPayment $payment): bool => ! in_array($payment->method, self::NON_MONEY_METHODS, true)
                    && $payment->paid_at !== null
                    && $payment->paid_at->toDateString() <= $to->toDateString())
                ->sum('amount'));
            $pending = $this->money(max(0, $total - $paid));

            return [
                'id' => $order->id,
                'date' => $order->order_date?->toDateString(),
                ...$this->traceFields($order->created_at, $order->business_datetime, 'created_at'),
                'document' => $order->po_number,
                'party' => $order->supplier?->name ?? 'Proveedor general',
                'user' => $order->user?->name,
                'method' => $order->payments
                    ->whereNotIn('method', self::NON_MONEY_METHODS)
                    ->map(fn (PurchaseOrderPayment $payment): string => $payment->method_label ?? $payment->method)
                    ->unique()
                    ->values()
                    ->join(' / ') ?: ($order->payment_status === 'pending' ? 'Credito' : null),
                'total' => $total,
                'paid' => $paid,
                'pending' => $pending,
                'amount' => $pending,
            ];
        })->filter(fn (array $row): bool => $row['pending'] > 0)->values();

        return $this->collectionPayload($metric, 'Cuentas por pagar', $this->salesColumns(), $rows, $page, $perPage);
    }

    private function cogs(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $items = SalesOrderItem::query()
            ->with(['salesOrder.customer', 'salesOrder.user', 'product', 'variant.brand'])
            ->whereHas('salesOrder', fn (Builder $query) => $query
                ->whereIn('status', self::SALES_STATUSES)
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('sales_order_id')
            ->orderByDesc('id')
            ->get();

        $rows = $items->map(fn (SalesOrderItem $item): array => [
            'id' => $item->id,
            'date' => $item->salesOrder?->date?->toDateString(),
            ...$this->traceFields($item->salesOrder?->created_at, $item->salesOrder?->business_datetime, 'created_at'),
            'document' => $item->salesOrder?->order_number,
            'party' => $item->salesOrder?->customer?->name ?? 'Cliente general',
            'user' => $item->salesOrder?->user?->name,
            'product' => $item->product_name,
            'sku' => $item->variant?->sku ?? $item->product_sku,
            'quantity' => (int) $item->quantity,
            'unit_cost' => $this->money($item->unit_cost),
            'amount' => $this->money((float) $item->unit_cost * (int) $item->quantity),
        ]);

        return $this->collectionPayload($metric, 'Costo', $this->itemColumns('Costo'), $rows, $page, $perPage);
    }

    private function profit(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $items = SalesOrderItem::query()
            ->with(['salesOrder.customer', 'salesOrder.user', 'product', 'variant.brand'])
            ->whereHas('salesOrder', fn (Builder $query) => $query
                ->whereIn('status', self::SALES_STATUSES)
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString()))
            ->when($warehouseId, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->get();

        $rows = $items->map(function (SalesOrderItem $item): array {
            $revenue = $this->money($item->total);
            $cogs = $this->money((float) $item->unit_cost * (int) $item->quantity);

            return [
                'id' => 'sale-'.$item->id,
                'date' => $item->salesOrder?->date?->toDateString(),
                ...$this->traceFields($item->salesOrder?->created_at, $item->salesOrder?->business_datetime, 'created_at'),
                'document' => $item->salesOrder?->order_number,
                'party' => $item->salesOrder?->customer?->name ?? 'Cliente general',
                'user' => $item->salesOrder?->user?->name,
                'description' => $item->product_name,
                'revenue' => $revenue,
                'cost' => $cogs,
                'amount' => $this->money($revenue - $cogs),
            ];
        });

        $expenseRows = OperationalExpense::query()
            ->with(['creator'])
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query->where('affects_income_statement', true)->orWhereNull('affects_income_statement'))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get()
            ->map(fn (OperationalExpense $expense): array => [
                'id' => 'expense-'.$expense->id,
                'date' => $expense->expense_date?->toDateString(),
                ...$this->traceFields($expense->created_at, $expense->business_datetime, 'created_at'),
                'document' => 'Gasto',
                'party' => $expense->category_label,
                'user' => $expense->creator?->name,
                'description' => $expense->description,
                'revenue' => 0.0,
                'cost' => $this->money($expense->amount),
                'amount' => -$this->money($expense->amount),
            ]);

        $detailRows = $metric === 'gross_profit'
            ? $rows
            : $rows->concat($expenseRows)->sortByDesc('date')->values();

        return $this->collectionPayload($metric, $metric === 'gross_profit' ? 'Utilidad bruta' : 'Utilidad / profit', [
            ...$this->datetimeColumns(),
            ['key' => 'document', 'label' => 'Origen'],
            ['key' => 'party', 'label' => 'Cliente/Categoria'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'description', 'label' => 'Detalle'],
            ['key' => 'revenue', 'label' => 'Ingreso', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Costo/Gasto', 'type' => 'money'],
            ['key' => 'amount', 'label' => 'Utilidad', 'type' => 'money'],
        ], $detailRows, $page, $perPage);
    }

    private function compositeBalance(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $balance = $this->snapshots->balanceSheet([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'warehouse_id' => $warehouseId,
        ]);

        $assets = $balance['assets'] ?? [];
        $liabilities = $balance['liabilities'] ?? [];
        $totals = $balance['totals'] ?? [];

        $rows = match ($metric) {
            'assets' => collect([
                $this->compositeRow('cash', 'Efectivo', 'Cash', $assets['cash'] ?? 0, $to),
                $this->compositeRow('bank', 'Banco', 'Bank', $assets['bank'] ?? 0, $to),
                $this->compositeRow('inventory', 'Inventario', 'Inventory lots', $assets['inventory'] ?? 0, $to),
                $this->compositeRow('receivable', 'Cuentas por cobrar', 'Ventas a credito', $liabilities['accounts_receivable'] ?? 0, $to),
            ]),
            'liabilities' => collect([
                $this->compositeRow('payable', 'Cuentas por pagar', 'Compras a credito', $liabilities['accounts_payable'] ?? 0, $to),
            ]),
            default => collect([
                $this->compositeRow('assets', 'Activos', 'Balance operativo', $totals['total_assets'] ?? 0, $to),
                $this->compositeRow('liabilities', 'Pasivos', 'Balance operativo', -((float) ($totals['total_liabilities'] ?? 0)), $to),
            ]),
        };

        $label = match ($metric) {
            'assets' => 'Activos',
            'liabilities' => 'Pasivos',
            default => 'Patrimonio / balance neto',
        };

        $forcedTotal = match ($metric) {
            'assets' => $this->money($totals['total_assets'] ?? $rows->sum('amount')),
            'liabilities' => $this->money($totals['total_liabilities'] ?? $rows->sum('amount')),
            default => $this->money($totals['balance'] ?? $rows->sum('amount')),
        };

        return $this->collectionPayload($metric, $label, [
            ...$this->datetimeColumns(),
            ['key' => 'component', 'label' => 'Componente'],
            ['key' => 'source', 'label' => 'Origen'],
            ['key' => 'detail_metric', 'label' => 'Detalle'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ], $rows, $page, $perPage, $balance['warnings'] ?? [], $forcedTotal);
    }

    /**
     * @return array<string, mixed>
     */
    private function compositeRow(string $detailMetric, string $component, string $source, float|int|string $amount, Carbon $to): array
    {
        return [
            'id' => 'component-'.$detailMetric,
            'date' => $to->toDateString(),
            'occurred_at' => null,
            'business_datetime' => null,
            'datetime_source' => 'computed_balance',
            'component' => $component,
            'source' => $source,
            'detail_metric' => $detailMetric,
            'amount' => $this->money($amount),
        ];
    }

    private function liquidity(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $summary = $this->snapshots->rangeSummary([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'warehouse_id' => $warehouseId,
        ]);
        $totals = $summary['totals'] ?? [];
        $bucket = $metric === 'cash' ? 'cash' : 'bank';
        $rows = collect([[
            'id' => 'opening-'.$metric,
            'date' => $from->toDateString(),
            'occurred_at' => null,
            'business_datetime' => null,
            'datetime_source' => 'opening_balance',
            'description' => $bucket === 'cash' ? 'Saldo inicial efectivo' : 'Saldo inicial banco',
            'source' => 'Apertura',
            'user' => null,
            'method' => $bucket === 'cash' ? 'Efectivo' : 'Banco',
            'amount' => $this->money($totals['opening_'.$metric] ?? 0),
        ]]);

        $rows = $rows
            ->concat($this->liquiditySalesRows($bucket, $from, $to, $warehouseId))
            ->concat($this->liquidityPurchaseRows($bucket, $from, $to, $warehouseId))
            ->concat($this->liquidityExpenseRows($bucket, $from, $to, $warehouseId))
            ->concat($this->liquidityAdjustmentRows($bucket, $from, $to, $warehouseId));

        if ($bucket === 'cash') {
            $rows = $rows->concat($this->liquidityCapitalRows($from, $to, $warehouseId));
        }

        return $this->collectionPayload($metric, $bucket === 'cash' ? 'Efectivo' : 'Banco', [
            ...$this->datetimeColumns(),
            ['key' => 'source', 'label' => 'Origen'],
            ['key' => 'description', 'label' => 'Detalle'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'method', 'label' => 'Metodo'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ], $rows->sortByDesc('date')->values(), $page, $perPage, $summary['warnings'] ?? [], $this->money($totals[$metric] ?? 0));
    }

    private function investment(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $rows = CapitalMovement::query()
            ->with(['user'])
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->when($metric === 'investment_in', fn (Builder $query) => $query->where('movement_type', CapitalMovement::TYPE_IN))
            ->when($metric === 'investment_out', fn (Builder $query) => $query->where('movement_type', CapitalMovement::TYPE_OUT))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CapitalMovement $movement): array => [
                'id' => $movement->id,
                'date' => $movement->movement_date?->toDateString(),
                ...$this->traceFields($movement->created_at, $movement->business_datetime, 'created_at'),
                'type' => $movement->movement_type_label,
                'user' => $movement->user?->name,
                'description' => $movement->reason,
                'amount' => $this->money($movement->movement_type === CapitalMovement::TYPE_IN ? $movement->amount : -$movement->amount),
            ]);

        $label = match ($metric) {
            'investment_in' => 'Inversion - entradas',
            'investment_out' => 'Inversion - salidas',
            default => 'Inversion',
        };

        return $this->collectionPayload($metric, $label, [
            ...$this->datetimeColumns(),
            ['key' => 'type', 'label' => 'Tipo'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'description', 'label' => 'Motivo'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ], $rows, $page, $perPage);
    }

    private function adjustments(string $metric, Carbon $from, Carbon $to, ?int $warehouseId, int $page, int $perPage): array
    {
        $rows = FinancialAdjustment::query()
            ->with(['user'])
            ->whereDate('adjustment_date', '>=', $from->toDateString())
            ->whereDate('adjustment_date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinancialAdjustment $adjustment): array => [
                'id' => $adjustment->id,
                'date' => $adjustment->adjustment_date?->toDateString(),
                ...$this->traceFields($adjustment->created_at, $adjustment->business_datetime, 'created_at'),
                'type' => $adjustment->type_label,
                'user' => $adjustment->user?->name,
                'description' => $adjustment->reason,
                'amount' => $this->money($adjustment->amount),
            ]);

        return $this->collectionPayload($metric, 'Ajustes financieros', [
            ...$this->datetimeColumns(),
            ['key' => 'type', 'label' => 'Tipo'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'description', 'label' => 'Motivo'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ], $rows, $page, $perPage);
    }

    private function liquiditySalesRows(string $bucket, Carbon $from, Carbon $to, ?int $warehouseId): Collection
    {
        return SalesOrderPayment::query()
            ->with(['salesOrder.customer', 'salesOrder.items', 'salesReturn', 'creator'])
            ->whereBetween('paid_at', $this->timestampBounds($from, $to))
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->whereHas('salesOrder', fn (Builder $query) => $query->whereIn('status', self::SALES_STATUSES))
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('salesOrder.items', fn (Builder $items) => $items->where('warehouse_id', $warehouseId)))
            ->get()
            ->filter(fn (SalesOrderPayment $payment): bool => $this->paymentBucket($payment->method) === $bucket)
            ->map(function (SalesOrderPayment $payment) use ($warehouseId): array {
                $order = $payment->salesOrder;
                $total = $order ? $this->salesOrderScopedTotal($order, $warehouseId) : 0.0;
                $ratio = $order ? $this->salesOrderWarehouseRatio($order, $warehouseId, $total) : 1.0;

                return [
                    'id' => 'sale-payment-'.$payment->id,
                    'date' => $payment->paid_at?->toDateString(),
                    ...$this->traceFields($payment->paid_at, $payment->business_datetime, 'paid_at'),
                    'source' => (float) $payment->amount < 0 ? 'Reembolso' : 'Pago recibido',
                    'description' => $payment->salesReturn
                        ? $payment->salesReturn->return_number.' - '.($order?->customer?->name ?? 'Cliente general')
                        : $order?->order_number.' - '.($order?->customer?->name ?? 'Cliente general'),
                    'user' => $payment->creator?->name,
                    'method' => $payment->formatted_method ?? $payment->method,
                    'amount' => $this->money((float) $payment->amount * $ratio),
                ];
            });
    }

    private function liquidityPurchaseRows(string $bucket, Carbon $from, Carbon $to, ?int $warehouseId): Collection
    {
        return PurchaseOrderPayment::query()
            ->with(['purchaseOrder.supplier', 'user'])
            ->whereBetween('paid_at', $this->timestampBounds($from, $to))
            ->whereNotIn('method', self::NON_MONEY_METHODS)
            ->whereHas('purchaseOrder', fn (Builder $query) => $query->whereNotIn('status', self::PURCHASE_EXCLUDED_STATUSES))
            ->when($warehouseId, fn (Builder $query) => $query->whereHas('purchaseOrder', fn (Builder $purchase) => $purchase->where('warehouse_id', $warehouseId)))
            ->get()
            ->filter(fn (PurchaseOrderPayment $payment): bool => $this->paymentBucket($payment->method) === $bucket)
            ->map(fn (PurchaseOrderPayment $payment): array => [
                'id' => 'purchase-payment-'.$payment->id,
                'date' => $payment->paid_at?->toDateString(),
                ...$this->traceFields($payment->paid_at, $payment->business_datetime, 'paid_at'),
                'source' => 'Pago proveedor',
                'description' => $payment->purchaseOrder?->po_number.' - '.($payment->purchaseOrder?->supplier?->name ?? 'Proveedor general'),
                'user' => $payment->user?->name,
                'method' => $payment->method_label ?? $payment->method,
                'amount' => -$this->money($payment->amount),
            ]);
    }

    private function liquidityExpenseRows(string $bucket, Carbon $from, Carbon $to, ?int $warehouseId): Collection
    {
        return OperationalExpense::query()
            ->with(['creator'])
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query->where('affects_cash_flow', true)->orWhereNull('affects_cash_flow'))
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get()
            ->filter(fn (OperationalExpense $expense): bool => $this->paymentBucket($expense->payment_method) === $bucket)
            ->map(fn (OperationalExpense $expense): array => [
                'id' => 'expense-'.$expense->id,
                'date' => $expense->expense_date?->toDateString(),
                ...$this->traceFields($expense->created_at, $expense->business_datetime, 'created_at'),
                'source' => 'Gasto',
                'description' => $expense->description,
                'user' => $expense->creator?->name,
                'method' => $expense->payment_method_label,
                'amount' => -$this->money($expense->amount),
            ]);
    }

    private function liquidityCapitalRows(Carbon $from, Carbon $to, ?int $warehouseId): Collection
    {
        return CapitalMovement::query()
            ->with(['user'])
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get()
            ->map(fn (CapitalMovement $movement): array => [
                'id' => 'capital-'.$movement->id,
                'date' => $movement->movement_date?->toDateString(),
                ...$this->traceFields($movement->created_at, $movement->business_datetime, 'created_at'),
                'source' => 'Capital',
                'description' => $movement->reason,
                'user' => $movement->user?->name,
                'method' => 'Efectivo',
                'amount' => $this->money($movement->movement_type === CapitalMovement::TYPE_IN ? $movement->amount : -$movement->amount),
            ]);
    }

    private function liquidityAdjustmentRows(string $bucket, Carbon $from, Carbon $to, ?int $warehouseId): Collection
    {
        $type = $bucket === 'cash' ? FinancialAdjustment::TYPE_CASH : FinancialAdjustment::TYPE_BANK;

        return FinancialAdjustment::query()
            ->with(['user'])
            ->where('type', $type)
            ->whereDate('adjustment_date', '>=', $from->toDateString())
            ->whereDate('adjustment_date', '<=', $to->toDateString())
            ->when($warehouseId, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId)))
            ->get()
            ->map(fn (FinancialAdjustment $adjustment): array => [
                'id' => $bucket.'-adjustment-'.$adjustment->id,
                'date' => $adjustment->adjustment_date?->toDateString(),
                ...$this->traceFields($adjustment->created_at, $adjustment->business_datetime, 'created_at'),
                'source' => 'Ajuste',
                'description' => $adjustment->reason,
                'user' => $adjustment->user?->name,
                'method' => $bucket === 'cash' ? 'Efectivo' : 'Banco',
                'amount' => $this->money($adjustment->amount),
            ]);
    }

    private function collectionPayload(string $metric, string $label, array $columns, Collection $rows, int $page, int $perPage, array $warnings = [], ?float $forcedTotal = null): array
    {
        $total = $forcedTotal ?? $this->money($rows->sum(fn (array $row): float => (float) ($row['amount'] ?? 0)));
        $paginator = $this->paginateCollection($rows, $page, $perPage);

        return [
            'metric' => $metric,
            'label' => $label,
            'columns' => $columns,
            'rows' => $paginator->items(),
            'total' => $total,
            'warnings' => array_values(array_unique($warnings)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function applyExpenseDrilldownFilters(Builder $query, ?string $expenseCategory, ?string $expenseGroup): Builder
    {
        if ($expenseCategory) {
            return $query->where('category', $expenseCategory);
        }

        if (! $expenseGroup) {
            return $query;
        }

        $categories = $this->expenseGroupCategories($expenseGroup);

        if ($expenseGroup === 'other') {
            return $query->whereNotIn('category', ['rent', 'payroll', 'electricity', 'utilities', 'software']);
        }

        return $query->whereIn('category', $categories);
    }

    /**
     * @return array<int, string>
     */
    private function expenseGroupCategories(string $expenseGroup): array
    {
        return match ($expenseGroup) {
            'rent' => ['rent'],
            'salary' => ['payroll'],
            'utilities' => ['utilities', 'electricity'],
            'internet' => ['software'],
            default => [],
        };
    }

    private function expenseGroupLabel(string $expenseGroup): string
    {
        return match ($expenseGroup) {
            'rent' => 'Rent',
            'salary' => 'Salary',
            'utilities' => 'Utilities',
            'internet' => 'Internet',
            'other' => 'Otros',
            default => $expenseGroup,
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function timestampBounds(Carbon $from, Carbon $to): array
    {
        return RegionalDateRange::utcBounds($from, $to);
    }

    private function emptyPayload(string $metric, string $label, int $page, int $perPage, array $warnings = []): array
    {
        return $this->collectionPayload($metric, $label, [], collect(), $page, $perPage, $warnings);
    }

    private function paginateCollection(Collection $rows, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function salesColumns(): array
    {
        return array_merge($this->datetimeColumns(), [
            ['key' => 'document', 'label' => 'Documento'],
            ['key' => 'party', 'label' => 'Cliente/Proveedor'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'method', 'label' => 'Metodo'],
            ['key' => 'status', 'label' => 'Estado'],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Pagado', 'type' => 'money'],
            ['key' => 'pending', 'label' => 'Pendiente', 'type' => 'money'],
        ]);
    }

    private function paymentColumns(): array
    {
        return array_merge($this->datetimeColumns(), [
            ['key' => 'document', 'label' => 'Documento'],
            ['key' => 'party', 'label' => 'Cliente/Proveedor'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'method', 'label' => 'Metodo'],
            ['key' => 'reference', 'label' => 'Referencia'],
            ['key' => 'amount', 'label' => 'Monto', 'type' => 'money'],
        ]);
    }

    private function itemColumns(string $amountLabel): array
    {
        return array_merge($this->datetimeColumns(), [
            ['key' => 'document', 'label' => 'Venta'],
            ['key' => 'party', 'label' => 'Cliente'],
            ['key' => 'user', 'label' => 'Usuario'],
            ['key' => 'product', 'label' => 'Producto'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'quantity', 'label' => 'Cantidad'],
            ['key' => 'unit_cost', 'label' => 'Costo unit.', 'type' => 'money'],
            ['key' => 'amount', 'label' => $amountLabel, 'type' => 'money'],
        ]);
    }

    private function datetimeColumns(): array
    {
        return [
            ['key' => 'occurred_at', 'label' => 'Fecha/Hora', 'type' => 'datetime'],
            ['key' => 'business_datetime', 'label' => 'Operativa', 'type' => 'datetime'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function traceFields(mixed $occurredAt, mixed $businessDatetime, string $source): array
    {
        return [
            'occurred_at' => $this->isoDatetime($occurredAt),
            'business_datetime' => $this->isoDatetime($businessDatetime),
            'datetime_source' => $this->isoDatetime($occurredAt) ? $source : 'missing',
        ];
    }

    private function isoDatetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function salesOrderScopedTotal(SalesOrder $order, ?int $warehouseId): float
    {
        if (! $warehouseId) {
            return $this->money($order->total);
        }

        return $this->money($order->items
            ->where('warehouse_id', $warehouseId)
            ->sum(fn ($item): float => (float) $item->total));
    }

    private function salesOrderWarehouseRatio(SalesOrder $order, ?int $warehouseId, float $scopedTotal): float
    {
        if (! $warehouseId) {
            return 1.0;
        }

        return min(1, max(0, $scopedTotal / max(0.01, (float) $order->total)));
    }

    private function approvedReturnAmountForOrder(SalesOrder $order, Carbon $to, ?int $warehouseId): float
    {
        return $this->money($order->returns
            ->filter(function ($return) use ($to, $warehouseId): bool {
                if ($return->status !== 'approved') {
                    return false;
                }

                if ($warehouseId && (int) $return->warehouse_id !== (int) $warehouseId) {
                    return false;
                }

                $date = $return->approved_at ?? $return->updated_at ?? $return->created_at;

                return $date !== null && Carbon::parse($date)->lte($to->copy()->endOfDay());
            })
            ->sum('total_amount'));
    }

    private function paymentBucket(?string $method): ?string
    {
        return PaymentMethod::liquidityBucket($method);
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
