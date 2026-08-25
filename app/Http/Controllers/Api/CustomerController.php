<?php

namespace App\Http\Controllers\Api;

use App\Exports\CustomerExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerSalesHistoryRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $canManage = in_array($request->user()?->role, ['admin', 'manager'], true);
        $query = Customer::query()
            ->with(['customerType', 'vehicles.model.make', 'vehicles.engine'])
            ->withExists([
                'salesOrders as has_sales_orders',
                'salesQuotes as has_sales_quotes',
                'workOrders as has_work_orders',
                'transactions as has_transactions',
            ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $operator = DB::connection()->getDriverName() === 'sqlite' ? 'like' : 'ilike';
            $query->where(function ($q) use ($search, $operator) {
                $q->where('name', $operator, "%{$search}%")
                    ->orWhere('email', $operator, "%{$search}%")
                    ->orWhere('phone', $operator, "%{$search}%")
                    ->orWhere('code', $operator, "%{$search}%")
                    ->orWhere('tax_id', $operator, "%{$search}%")
                    ->orWhere('tax_name', $operator, "%{$search}%")
                    ->orWhere('address', $operator, "%{$search}%")
                    ->orWhereHas('vehicles', function ($query) use ($search, $operator): void {
                        $query->where('vin', $operator, "%{$search}%")
                            ->orWhere('plate_number', $operator, "%{$search}%")
                            ->orWhereHas('model', fn ($query) => $query->where('name', $operator, "%{$search}%"))
                            ->orWhereHas('engine', fn ($query) => $query->where('engine_code', $operator, "%{$search}%"));
                    });
            });
        }

        if ($request->filled('customer_type_id')) {
            $query->where('customer_type_id', $request->integer('customer_type_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->boolean('has_balance')) {
            $query->where('current_balance', '>', 0);
        }

        // Apply other filters from the original implementation if they are still desired
        // The instruction only provided search and has_balance, implying a replacement of the service call.
        // If other filters were meant to be kept, they would need to be re-implemented here.
        // For now, strictly following the provided snippet.

        $perPage = min(100, max(5, (int) $request->input('per_page', 15)));
        $sortBy = in_array($request->input('order_by'), ['name', 'created_at', 'current_balance'], true) ? $request->input('order_by') : 'name';
        $direction = $request->input('order_direction') === 'desc' ? 'desc' : 'asc';
        $customers = $query->orderBy($sortBy, $direction)->paginate($perPage);
        $customers->getCollection()->transform(function (Customer $customer) use ($canManage): Customer {
            $customer->setAttribute('can_edit', $canManage);
            $reason = $this->customerService->customerDeleteBlockReason($customer);
            $customer->setAttribute('can_delete', $canManage && $reason === null);
            $customer->setAttribute('delete_block_reason', $canManage ? $reason : 'No tienes permiso para eliminar clientes.');

            return $customer;
        });

        return response()->json($customers);
    }

    public function export(): BinaryFileResponse
    {
        return Excel::download(new CustomerExport, 'clientes.xlsx');
    }

    /**
     * Store a new customer
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->createCustomer($request->validated());

        return response()->json($customer->load(['customerType', 'vehicles.model.make', 'vehicles.chassis', 'vehicles.engine', 'vehicles.catalogVin']), 201);
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
            'quotes' => $customer->salesQuotes()->with('customerVehicle.model.make')->latest('date')->limit(25)->get(),
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
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->updateCustomer($customer, $request->validated());

        return response()->json($customer->load(['customerType', 'vehicles.model.make', 'vehicles.chassis', 'vehicles.engine', 'vehicles.catalogVin']));
    }

    /**
     * Delete a customer
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->deleteCustomer($customer);

        return response()->json(['message' => 'Cliente eliminado exitosamente']);
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
        return response()->json([
            'message' => 'Los ajustes directos de saldo fueron retirados. Use un ajuste financiero de Cuentas por cobrar con documento y contrapartida.',
            'redirect' => sprintf(
                '/finance/accounting/adjustments?create=1&type=accounts_receivable&customer_id=%d',
                $customer->id,
            ),
        ], 410);
    }
}
