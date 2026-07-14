<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperationalExpenseRequest;
use App\Http\Requests\UpdateOperationalExpenseRequest;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseBeneficiary;
use App\Models\OperationalExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\OperationalExpenseService;
use App\Services\CashierQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OperationalExpenseController extends Controller
{
    public function __construct(
        private OperationalExpenseService $operationalExpenseService,
        private CashierQueueService $cashierQueueService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'expense_kind' => ['nullable', 'string', 'in:operational,administrative,financial,other'],
            'status' => ['nullable', 'string', 'in:draft,pending,scheduled,queued,paid,cancelled,reversed'],
            'party_type' => ['nullable', 'string', 'in:customer,supplier,beneficiary,staff'],
            'party_id' => ['nullable', 'integer', 'min:1', 'required_with:party_type'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = OperationalExpense::query()
            ->with(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'customer', 'beneficiary', 'ledgerAccount'])
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('operational_expenses.category', $category))
            ->when($validated['expense_kind'] ?? null, fn ($query, $kind) => $query->where('operational_expenses.expense_kind', $kind))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('operational_expenses.status', $status))
            ->when($validated['party_type'] ?? null, function ($query, $type) use ($validated) {
                if ($type === 'staff') {
                    $query->whereHas('beneficiary', fn ($beneficiaryQuery) => $beneficiaryQuery->where('user_id', $validated['party_id']));

                    return;
                }
                $column = $type === 'beneficiary' ? 'beneficiary_id' : $type.'_id';
                $query->where('operational_expenses.'.$column, $validated['party_id']);
            })
            ->when(array_key_exists('warehouse_id', $validated), function ($query) use ($validated) {
                if ($validated['warehouse_id'] === null) {
                    $query->whereNull('warehouse_id');

                    return;
                }

                $query->where('warehouse_id', $validated['warehouse_id']);
            });

        $summary = [
            'total_amount' => round((float) (clone $query)->sum('amount'), 2),
            'count' => (clone $query)->count(),
            'by_category' => (clone $query)
                ->selectRaw('operational_expenses.category, SUM(operational_expenses.amount) as total_amount, COUNT(*) as count')
                ->groupBy('operational_expenses.category')
                ->orderByDesc('total_amount')
                ->get(),
            'by_party' => (clone $query)
                ->leftJoin('customers as expense_customers', 'expense_customers.id', '=', 'operational_expenses.customer_id')
                ->leftJoin('suppliers as expense_suppliers', 'expense_suppliers.id', '=', 'operational_expenses.supplier_id')
                ->leftJoin('operational_expense_beneficiaries as expense_beneficiaries', 'expense_beneficiaries.id', '=', 'operational_expenses.beneficiary_id')
                ->selectRaw("operational_expenses.customer_id, operational_expenses.supplier_id, operational_expenses.beneficiary_id, expense_beneficiaries.user_id as beneficiary_user_id, COALESCE(expense_customers.name, expense_suppliers.name, expense_beneficiaries.name, 'Sin beneficiario') as party_name, SUM(operational_expenses.amount) as total_amount, COUNT(*) as count")
                ->groupBy('operational_expenses.customer_id', 'operational_expenses.supplier_id', 'operational_expenses.beneficiary_id', 'expense_beneficiaries.user_id', 'expense_customers.name', 'expense_suppliers.name', 'expense_beneficiaries.name')
                ->orderByDesc('total_amount')
                ->get()
                ->map(function ($row): array {
                    $type = $row->customer_id
                        ? 'customer'
                        : ($row->supplier_id ? 'supplier' : ($row->beneficiary_user_id ? 'staff' : ($row->beneficiary_id ? 'beneficiary' : null)));
                    $id = $row->customer_id ?? $row->supplier_id ?? $row->beneficiary_user_id ?? $row->beneficiary_id;

                    return [
                        'party_type' => $type,
                        'party_id' => $id ? (int) $id : null,
                        'party_name' => $row->party_name,
                        'total_amount' => round((float) $row->total_amount, 2),
                        'count' => (int) $row->count,
                    ];
                }),
        ];

        $expenses = $query
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
            ],
            'summary' => $summary,
            'categories' => OperationalExpense::CATEGORIES,
            'expense_categories' => OperationalExpenseCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'payment_methods' => $this->paymentMethodOptions(),
            'statuses' => OperationalExpense::STATUSES,
            'recurrences' => OperationalExpense::RECURRENCES,
            'expense_kinds' => OperationalExpense::EXPENSE_KINDS,
        ]);
    }

    public function store(StoreOperationalExpenseRequest $request): JsonResponse
    {
        try {
            $expense = $this->operationalExpenseService->create($request->validated());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment_method' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Gasto operativo registrado exitosamente.',
            'data' => $expense->load(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'customer', 'beneficiary', 'ledgerAccount']),
        ], 201);
    }

    public function show(OperationalExpense $operationalExpense): JsonResponse
    {
        return response()->json([
            'data' => $operationalExpense->load(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'customer', 'beneficiary', 'ledgerAccount', 'events.user']),
        ]);
    }

    public function update(UpdateOperationalExpenseRequest $request, OperationalExpense $operationalExpense): JsonResponse
    {
        try {
            $operationalExpense = $this->operationalExpenseService->update($operationalExpense, $request->validated());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment_method' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Gasto operativo actualizado exitosamente.',
            'data' => $operationalExpense->fresh(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'customer', 'beneficiary', 'ledgerAccount', 'events.user']),
        ]);
    }

    public function sendToCashier(OperationalExpense $operationalExpense): JsonResponse
    {
        if (! in_array($operationalExpense->status, ['pending', 'scheduled'], true)) {
            return response()->json(['message' => 'Solo gastos pendientes o programados pueden enviarse a Caja.'], 422);
        }

        $entry = $this->cashierQueueService->enqueueExpense($operationalExpense);

        return response()->json([
            'message' => 'Gasto enviado a la cola de Caja.',
            'data' => $entry->load(['warehouse', 'source']),
        ]);
    }

    public function destroy(OperationalExpense $operationalExpense): JsonResponse
    {
        try {
            $this->operationalExpenseService->delete($operationalExpense);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment_method' => [$exception->getMessage()],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Gasto cancelado o reversado con trazabilidad.',
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function paymentMethodOptions(): array
    {
        $configured = PaymentMethod::query()
            ->active()
            ->notArchived()
            ->forContext(PaymentMethod::CONTEXT_SUPPLIER_PAYMENTS)
            ->whereNotIn('code', PaymentMethod::DEFERRED_CODES)
            ->ordered()
            ->get(['code', 'name'])
            ->map(fn (PaymentMethod $method): array => [
                'value' => $method->code,
                'label' => $method->name,
            ])
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        return collect(OperationalExpense::PAYMENT_METHODS)
            ->map(fn (string $code): array => [
                'value' => $code,
                'label' => PaymentMethod::getNameByCode($code),
            ])
            ->values()
            ->all();
    }

    public function parties(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $searchPattern = '%'.Str::lower($search).'%';
        $limit = (int) ($validated['limit'] ?? 20);

        $map = fn ($record, string $type): array => [
            'type' => $type,
            'id' => (int) $record->id,
            'name' => (string) $record->name,
        ];

        $customers = Customer::query()->where('status', 'active')
            ->when($search, fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$searchPattern]))
            ->limit($limit)->get(['id', 'name'])->map(fn ($item) => $map($item, 'customer'));
        $suppliers = Supplier::query()->where('status', 'active')
            ->when($search, fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$searchPattern]))
            ->limit($limit)->get(['id', 'name'])->map(fn ($item) => $map($item, 'supplier'));
        $beneficiaries = OperationalExpenseBeneficiary::query()->where('is_active', true)
            ->whereNull('user_id')
            ->when($search, fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$searchPattern]))
            ->limit($limit)->get(['id', 'name'])->map(fn ($item) => $map($item, 'beneficiary'));
        $staff = \App\Models\User::query()->where('is_active', true)
            ->when($search, fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$searchPattern]))
            ->limit($limit)->get(['id', 'name'])->map(fn ($item) => $map($item, 'staff'));

        return response()->json([
            'data' => $customers->concat($suppliers)->concat($beneficiaries)->concat($staff)->sortBy('name')->take($limit)->values(),
        ]);
    }
}
