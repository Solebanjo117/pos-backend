<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperationalExpenseRequest;
use App\Http\Requests\UpdateOperationalExpenseRequest;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\OperationalExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalExpenseController extends Controller
{
    public function __construct(
        private OperationalExpenseService $operationalExpenseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = OperationalExpense::query()
            ->with(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'ledgerAccount'])
            ->when($validated['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
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
            'data' => $expense->load(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'ledgerAccount']),
        ], 201);
    }

    public function show(OperationalExpense $operationalExpense): JsonResponse
    {
        return response()->json([
            'data' => $operationalExpense->load(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'ledgerAccount']),
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
            'data' => $operationalExpense->fresh(['warehouse', 'creator', 'cashMovement', 'posSession', 'categoryRecord', 'supplier', 'ledgerAccount']),
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
            'message' => 'Gasto operativo eliminado exitosamente.',
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
}
