<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\CustomerType;
use App\Models\CustomerVehicle;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(private CustomerVehicleVinSyncService $vinSyncService) {}

    /**
     * Get paginated list of customers
     */
    public function getCustomers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Customer::query();

        // Search
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by type
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Filter by status
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by credit
        if (! empty($filters['has_credit'])) {
            $query->withCredit();
        }

        // Order by
        $orderBy = $filters['order_by'] ?? 'name';
        $orderDirection = $filters['order_direction'] ?? 'asc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Create a new customer
     */
    public function createCustomer(array $data): Customer
    {
        DB::beginTransaction();

        try {
            // Generate code if not provided
            if (empty($data['code'])) {
                $data['code'] = Customer::generateCode();
            }

            $vehicles = $data['vehicles'] ?? [];
            unset($data['vehicles'], $data['sync_vehicles']);
            $data = $this->applyCustomerType($data);
            $customer = Customer::create($data);
            $this->syncVehicles($customer, $vehicles, true);

            // Create initial transaction if balance is set
            if (! empty($data['initial_balance']) && $data['initial_balance'] != 0) {
                $this->createTransaction(
                    $customer,
                    'adjustment',
                    $data['initial_balance'],
                    'Saldo inicial',
                    null,
                    null,
                    auth()->id()
                );
            }

            DB::commit();

            return $customer->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update customer
     */
    public function updateCustomer(Customer $customer, array $data): Customer
    {
        DB::beginTransaction();

        try {
            $shouldSyncVehicles = (bool) ($data['sync_vehicles'] ?? false);
            $vehicles = $data['vehicles'] ?? [];
            unset($data['vehicles'], $data['sync_vehicles']);
            $customer->update($this->applyCustomerType($data));
            if ($shouldSyncVehicles) {
                $this->syncVehicles($customer, $vehicles, true);
            }

            DB::commit();

            return $customer->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete customer (soft delete)
     */
    public function deleteCustomer(Customer $customer): bool
    {
        $reason = $this->customerDeleteBlockReason($customer);
        if ($reason !== null) {
            throw ValidationException::withMessages(['customer' => $reason]);
        }

        return DB::transaction(function () use ($customer): bool {
            $customer->vehicles()->where('status', 'active')->update(['status' => 'inactive']);

            return $customer->delete();
        });
    }

    /**
     * Get customer with transactions
     */
    public function getCustomerWithTransactions(int $customerId): Customer
    {
        return Customer::with([
            'customerType',
            'vehicles.model.make',
            'vehicles.chassis',
            'vehicles.engine',
            'vehicles.catalogVin',
            'transactions' => function ($query) {
                $query->orderBy('transaction_date', 'desc')->limit(50);
            },
        ])->findOrFail($customerId);
    }

    public function customerDeleteBlockReason(Customer $customer): ?string
    {
        if ($this->relationExists($customer, 'has_sales_orders', fn (): bool => $customer->salesOrders()->exists())) {
            return 'No se puede eliminar: el cliente tiene ventas relacionadas. Desactívalo en su lugar.';
        }
        if ($this->relationExists($customer, 'has_sales_quotes', fn (): bool => $customer->salesQuotes()->exists())) {
            return 'No se puede eliminar: el cliente tiene cotizaciones relacionadas. Desactívalo en su lugar.';
        }
        if ($this->relationExists($customer, 'has_work_orders', fn (): bool => $customer->workOrders()->exists())) {
            return 'No se puede eliminar: el cliente tiene órdenes de trabajo relacionadas. Desactívalo en su lugar.';
        }
        if ((float) $customer->current_balance !== 0.0
            || $this->relationExists($customer, 'has_transactions', fn (): bool => $customer->transactions()->exists())) {
            return 'No se puede eliminar: el cliente tiene movimientos o saldo en cuentas por cobrar. Desactívalo en su lugar.';
        }

        return null;
    }

    private function applyCustomerType(array $data): array
    {
        if (array_key_exists('customer_type_id', $data)) {
            $type = CustomerType::query()->findOrFail($data['customer_type_id']);
            $data['type'] = $type->base_kind;
        }

        return $data;
    }

    private function syncVehicles(Customer $customer, array $vehicles, bool $deactivateMissing): void
    {
        $keptIds = [];
        foreach ($vehicles as $data) {
            $vehicleId = $data['id'] ?? null;
            unset($data['id']);
            $data['status'] = $data['status'] ?? 'active';

            $vehicle = $vehicleId
                ? $customer->vehicles()->whereKey($vehicleId)->firstOrFail()
                : $this->reusableCustomerVehicle($customer, $data);
            $vehicle->fill($data);
            $vehicle->customer_id = $customer->id;
            $vehicle->save();
            $this->vinSyncService->sync($vehicle);
            $keptIds[] = $vehicle->id;
        }

        if ($deactivateMissing) {
            $customer->vehicles()->whereNotIn('id', $keptIds ?: [0])->update(['status' => 'inactive']);
        }
    }

    private function reusableCustomerVehicle(Customer $customer, array $data): CustomerVehicle
    {
        $query = $customer->vehicles()
            ->where('status', 'inactive')
            ->where('vehicle_model_id', $data['vehicle_model_id'] ?? 0)
            ->where('vehicle_engine_id', $data['vehicle_engine_id'] ?? 0);

        if (! empty($data['vehicle_vin_id'])) {
            $query->where('vehicle_vin_id', $data['vehicle_vin_id']);
        } elseif (! empty($data['vin'])) {
            $query->whereRaw('UPPER(vin) = ?', [mb_strtoupper(trim((string) $data['vin']))]);
        } else {
            $query->whereNull('vin');
        }

        return $query->latest('id')->first()
            ?? new CustomerVehicle(['customer_id' => $customer->id]);
    }

    private function relationExists(Customer $customer, string $attribute, callable $fallback): bool
    {
        if (array_key_exists($attribute, $customer->getAttributes())) {
            return (bool) $customer->getAttribute($attribute);
        }

        return $fallback();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getSalesHistory(Customer $customer, array $filters = [], int $perPage = 15): array
    {
        $orders = $customer->salesOrders()
            ->with([
                'items.product:id,sku_base,name',
                'items.variant:id,sku,brand_id',
                'items.variant.brand:id,name',
                'items.warehouse:id,name',
                'items.seller:id,name',
                'payments.creator:id,name',
                'returns',
            ])
            ->when(! empty($filters['date_from']), function ($query) use ($filters): void {
                $query->whereDate('date', '>=', $filters['date_from']);
            })
            ->when(! empty($filters['date_to']), function ($query) use ($filters): void {
                $query->whereDate('date', '<=', $filters['date_to']);
            })
            ->when(! empty($filters['status']), function ($query) use ($filters): void {
                $query->where('status', $filters['status']);
            })
            ->when(! empty($filters['payment_status']), function ($query) use ($filters): void {
                $statuses = collect(explode(',', (string) $filters['payment_status']))
                    ->map(fn (string $status): string => trim($status))
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($statuses)) {
                    $query->whereIn('payment_status', $statuses);
                }
            })
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $search = (string) $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $operator = $this->caseInsensitiveLikeOperator();

                    $query->where('order_number', $operator, "%{$search}%")
                        ->orWhereHas('items.product', function ($query) use ($search, $operator): void {
                            $query->where('name', $operator, "%{$search}%")
                                ->orWhere('sku_base', $operator, "%{$search}%");
                        })
                        ->orWhereHas('items', function ($query) use ($search, $operator): void {
                            $query->where('custom_description', $operator, "%{$search}%");
                        });
                });
            })
            ->latest('date')
            ->latest('id')
            ->paginate($perPage);

        return [
            'data' => $orders->getCollection()
                ->map(fn (SalesOrder $order): array => $this->salesHistoryOrderRow($order))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ];
    }

    /**
     * Create customer transaction
     */
    public function createTransaction(
        Customer $customer,
        string $type,
        float $amount,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null
    ): CustomerTransaction {
        DB::beginTransaction();

        try {
            $balanceBefore = $customer->current_balance;

            // Calculate new balance
            $balanceAfter = match ($type) {
                'sale' => $balanceBefore + $amount,
                'payment' => $balanceBefore - $amount,
                'credit_note' => $balanceBefore - $amount,
                'adjustment' => $balanceBefore + $amount,
                default => $balanceBefore,
            };

            // Create transaction
            $transaction = CustomerTransaction::create([
                'customer_id' => $customer->id,
                'type' => $type,
                'amount' => abs($amount),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'transaction_date' => now(),
                'created_by' => $createdBy ?? auth()->id(),
            ]);

            // Update customer balance
            $customer->update(['current_balance' => $balanceAfter]);

            DB::commit();

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get customer balance summary
     */
    public function getBalanceSummary(Customer $customer): array
    {
        return [
            'current_balance' => $customer->current_balance,
            'credit_limit' => $customer->credit_limit,
            'available_credit' => $customer->getAvailableCredit(),
            'credit_days' => $customer->credit_days,
            'is_over_limit' => $customer->current_balance > $customer->credit_limit,
        ];
    }

    /**
     * Get customer profile summary metrics.
     *
     * @return array<string, mixed>
     */
    public function getProfileSummary(Customer $customer): array
    {
        $orders = $customer->salesOrders()
            ->with([
                'referredByCustomer:id,code,name',
                'items.product:id,sku_base,name',
                'items.variant:id,sku,brand_id',
                'items.variant.brand:id,name',
                'payments',
                'returns',
            ])
            ->whereNotIn('status', ['cancelled'])
            ->latest('date')
            ->latest('id')
            ->get();

        $ordersCount = $orders->count();
        $totalPurchased = round((float) $orders->sum('total'), 2);
        $totalPaid = round((float) $orders->sum(fn (SalesOrder $order): float => (float) $order->amount_paid), 2);
        $pendingAmount = round((float) $orders->sum(fn (SalesOrder $order): float => (float) $order->amount_due), 2);
        $creditLimit = (float) $customer->credit_limit;
        $currentBalance = max(0, (float) $customer->current_balance);
        $creditUsedPercent = $creditLimit > 0 ? round(min(100, ($currentBalance / $creditLimit) * 100), 1) : 0.0;
        $creditStatus = $this->customerProfileCreditStatus($customer);

        return [
            'total_purchased' => $totalPurchased,
            'invoices_count' => $ordersCount,
            'average_ticket' => $ordersCount > 0 ? round($totalPurchased / $ordersCount, 2) : 0.0,
            'last_purchase_date' => $orders->first()?->date?->toDateString(),
            'total_paid' => $totalPaid,
            'pending_amount' => $pendingAmount,
            'credit_used_percent' => $creditUsedPercent,
            'credit_status' => $creditStatus,
            'credit_status_label' => match ($creditStatus) {
                'over_limit' => 'Sobre limite',
                'pending' => 'Pendiente',
                default => 'Al dia',
            },
            'top_items' => $this->customerProfileTopItems($orders),
            'recent_payments' => $this->customerProfileRecentPayments($orders),
            'mechanic' => $this->customerProfileMechanic($orders),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesHistoryOrderRow(SalesOrder $order): array
    {
        return [
            'id' => $order->id,
            'document_type' => 'sales_order',
            'document_number' => $order->order_number,
            'order_number' => $order->order_number,
            'date' => optional($order->date)->toDateString(),
            'status' => $order->status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_amount,
            'shipping_cost' => (float) $order->shipping_cost,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'amount_due' => (float) $order->amount_due,
            'total_returned' => (float) $order->total_returned,
            'payment_summary' => $order->payments
                ->groupBy('method')
                ->map(fn ($payments, string $method): array => [
                    'method' => $method,
                    'method_label' => $payments->first()->formatted_method,
                    'amount' => round((float) $payments->sum('amount'), 2),
                    'count' => $payments->count(),
                ])
                ->values()
                ->all(),
            'items' => $order->items
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'variant_sku' => $item->variant?->sku,
                    'brand_name' => $item->variant?->brand?->name,
                    'warehouse_name' => $item->warehouse?->name,
                    'seller_name' => $item->seller?->name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'tax_percent' => (float) $item->tax_percent,
                    'tax_amount' => (float) $item->tax_amount,
                    'subtotal' => (float) $item->subtotal,
                    'total' => (float) $item->total,
                    'notes' => $item->notes,
                    'is_custom_line' => (bool) $item->is_custom_line,
                ])
                ->values()
                ->all(),
            'payments' => $order->payments
                ->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'method_label' => $payment->formatted_method,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at?->toISOString(),
                    'notes' => $payment->notes,
                    'creator_name' => $payment->creator?->name,
                ])
                ->values()
                ->all(),
            'returns' => $order->returns
                ->sortByDesc('created_at')
                ->map(fn ($return): array => [
                    'id' => $return->id,
                    'return_number' => $return->return_number,
                    'status' => $return->status,
                    'status_label' => $return->status_label,
                    'refund_method' => $return->refund_method,
                    'total_amount' => (float) $return->total_amount,
                    'reason' => $return->reason,
                    'created_at' => $return->created_at?->toISOString(),
                    'href' => "/returns/{$return->id}",
                ])
                ->values()
                ->all(),
        ];
    }

    private function customerProfileCreditStatus(Customer $customer): string
    {
        if ((float) $customer->credit_limit > 0 && (float) $customer->current_balance > (float) $customer->credit_limit) {
            return 'over_limit';
        }

        return (float) $customer->current_balance > 0 ? 'pending' : 'current';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function customerProfileTopItems(\Illuminate\Support\Collection $orders): array
    {
        return $orders
            ->flatMap(fn (SalesOrder $order) => $order->items)
            ->groupBy(fn ($item): string => (string) ($item->variant_id ?? $item->product_id ?? $item->product_name))
            ->map(function (\Illuminate\Support\Collection $items): array {
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
            ->sortByDesc('quantity')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function customerProfileRecentPayments(\Illuminate\Support\Collection $orders): array
    {
        return $orders
            ->flatMap(fn (SalesOrder $order) => $order->payments
                ->reject(fn ($payment): bool => PaymentMethod::isDeferredCode($payment->method))
                ->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'method' => $payment->method,
                    'method_label' => $payment->formatted_method,
                    'amount' => round((float) $payment->amount, 2),
                    'paid_at' => $payment->paid_at?->toISOString(),
                ]))
            ->sortByDesc('paid_at')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SalesOrder>  $orders
     * @return array<string, mixed>|null
     */
    private function customerProfileMechanic(\Illuminate\Support\Collection $orders): ?array
    {
        $mechanicOrders = $orders
            ->whereNotNull('referred_by_customer_id')
            ->groupBy('referred_by_customer_id')
            ->sortByDesc(fn (\Illuminate\Support\Collection $group): int => $group->count())
            ->first();

        if (! $mechanicOrders) {
            return null;
        }

        $firstOrder = $mechanicOrders->first();
        $mechanic = $firstOrder?->referredByCustomer;

        if (! $mechanic) {
            return null;
        }

        return [
            'customer_id' => $mechanic->id,
            'name' => $mechanic->name,
            'code' => $mechanic->code,
            'orders_count' => $mechanicOrders->count(),
            'last_order_date' => $mechanicOrders->sortByDesc('date')->first()?->date?->toDateString(),
        ];
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';
    }

    /**
     * Search customers (for autocomplete)
     */
    public function searchCustomers(string $term, int $limit = 10): Collection
    {
        return Customer::active()
            ->search($term)
            ->limit($limit)
            ->get(['id', 'code', 'name', 'email', 'phone', 'current_balance']);
    }
}
