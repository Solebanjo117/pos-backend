<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerSalesHistoryRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService
    ) {}

    /**
     * Get list of customers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($request->boolean('has_balance')) {
            $query->where('current_balance', '>', 0);
        }

        // Apply other filters from the original implementation if they are still desired
        // The instruction only provided search and has_balance, implying a replacement of the service call.
        // If other filters were meant to be kept, they would need to be re-implemented here.
        // For now, strictly following the provided snippet.

        $perPage = $request->input('per_page', 15);
        $customers = $query->latest()->paginate($perPage);

        return response()->json($customers);
    }

    /**
     * Store a new customer
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:customers',
            'type' => 'required|in:individual,business',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:100',
            'tax_name' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_days' => 'nullable|integer|min:0',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,blocked',
            'initial_balance' => 'nullable|numeric',
        ]);

        $customer = $this->customerService->createCustomer($validated);

        return response()->json($customer, 201);
    }

    /**
     * Get a specific customer
     */
    public function show(int $id): JsonResponse
    {
        $customer = $this->customerService->getCustomerWithTransactions($id);
        $balanceSummary = $this->customerService->getBalanceSummary($customer);

        return response()->json([
            'customer' => $customer,
            'balance_summary' => $balanceSummary,
            'profile_summary' => $this->customerService->getProfileSummary($customer),
        ]);
    }

    /**
     * Get customer sales history with items and payments
     */
    public function salesHistory(CustomerSalesHistoryRequest $request, Customer $customer): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->customerService->getSalesHistory(
            $customer,
            $validated,
            (int) ($validated['per_page'] ?? 15)
        ));
    }

    /**
     * Update a customer
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:customers,code,'.$customer->id,
            'type' => 'sometimes|in:individual,business',
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:100',
            'tax_name' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'credit_limit' => 'nullable|numeric|min:0',
            'credit_days' => 'nullable|integer|min:0',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,blocked',
        ]);

        $customer = $this->customerService->updateCustomer($customer, $validated);

        return response()->json($customer);
    }

    /**
     * Delete a customer
     */
    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $this->customerService->deleteCustomer($customer);

            return response()->json(['message' => 'Cliente eliminado exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get customer transactions
     */
    public function transactions(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $transactions = $customer->transactions()->paginate(50);

        return response()->json($transactions);
    }

    /**
     * Get customer sales orders
     */
    public function orders(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $orders = $customer->salesOrders()->latest()->paginate(20);

        return response()->json($orders);
    }

    /**
     * Search customers (autocomplete)
     */
    public function search(Request $request): JsonResponse
    {
        $term = $request->input('q', '');
        $limit = $request->input('limit', 10);

        $customers = $this->customerService->searchCustomers($term, $limit);

        return response()->json($customers);
    }

    /**
     * Adjust customer balance
     */
    public function adjustBalance(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'reason' => 'required|string|max:500',
        ]);

        $transaction = $this->customerService->adjustBalance(
            $customer,
            $validated['amount'],
            $validated['reason'],
            auth()->id()
        );

        return response()->json([
            'message' => 'Saldo ajustado exitosamente',
            'transaction' => $transaction,
            'new_balance' => $customer->fresh()->current_balance,
        ]);
    }
}
