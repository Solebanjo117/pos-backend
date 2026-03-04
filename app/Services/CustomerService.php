<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerService
{
    /**
     * Get paginated list of customers
     */
    public function getCustomers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Customer::query();

        // Search
        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Filter by type
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by credit
        if (!empty($filters['has_credit'])) {
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
            if (!empty($data['initial_balance']) && $data['initial_balance'] != 0) {
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
        return Customer::with(['transactions' => function ($query) {
            $query->orderBy('transaction_date', 'desc')->limit(50);
        }])->findOrFail($customerId);
    }

    /**
     * Create customer transaction
     */
    public function createTransaction(
        Customer $customer,
        string $type,
        float $amount,
        string $description = null,
        string $referenceType = null,
        int $referenceId = null,
        int $createdBy = null
    ): CustomerTransaction {
        DB::beginTransaction();
        
        try {
            $balanceBefore = $customer->current_balance;
            
            // Calculate new balance
            $balanceAfter = match($type) {
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
    public function adjustBalance(Customer $customer, float $amount, string $reason, int $userId = null): CustomerTransaction
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
