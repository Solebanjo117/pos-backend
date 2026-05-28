<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerService
{
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

            $customer = Customer::create($data);

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
            $customer->update($data);

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
        // Check if customer has pending orders
        if ($customer->salesOrders()->where('status', '!=', 'completed')->exists()) {
            throw new \Exception('No se puede eliminar un cliente con órdenes pendientes');
        }

        return $customer->delete();
    }

    /**
     * Get customer with transactions
     */
    public function getCustomerWithTransactions(int $customerId): Customer
    {
        return Customer::with([
            'vehicles.model.make',
            'vehicles.chassis',
            'vehicles.engine',
            'transactions' => function ($query) {
                $query->orderBy('transaction_date', 'desc')->limit(50);
            },
        ])->findOrFail($customerId);
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

    /**
     * Adjust customer balance
     */
    public function adjustBalance(Customer $customer, float $amount, string $reason, ?int $userId = null): CustomerTransaction
    {
        return $this->createTransaction(
            $customer,
            'adjustment',
            $amount,
            $reason,
            null,
            null,
            $userId
        );
    }
}
