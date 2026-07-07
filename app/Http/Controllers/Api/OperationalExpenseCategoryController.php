<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalExpense;
use App\Models\OperationalExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperationalExpenseCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = OperationalExpenseCategory::query()
            ->with('defaultLedgerAccount')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'default_ledger_account_id' => ['nullable', 'exists:ledger_accounts,id'],
            'expense_kind' => ['nullable', 'string', Rule::in(array_keys(OperationalExpense::EXPENSE_KINDS))],
            'affects_cash_flow' => ['nullable', 'boolean'],
            'affects_income_statement' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = Str::slug(trim($validated['name'])) ?: 'gasto';
        $category = OperationalExpenseCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => trim($validated['name']),
                'subcategory' => $validated['subcategory'] ?? null,
                'default_ledger_account_id' => $validated['default_ledger_account_id'] ?? null,
                'expense_kind' => $validated['expense_kind'] ?? 'operational',
                'affects_cash_flow' => $validated['affects_cash_flow'] ?? true,
                'affects_income_statement' => $validated['affects_income_statement'] ?? true,
                'is_active' => true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ],
        );

        return response()->json([
            'message' => 'Categoria de gasto creada exitosamente.',
            'data' => $category->load('defaultLedgerAccount'),
        ], 201);
    }

    public function update(Request $request, OperationalExpenseCategory $operationalExpenseCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'default_ledger_account_id' => ['nullable', 'exists:ledger_accounts,id'],
            'expense_kind' => ['nullable', 'string', Rule::in(array_keys(OperationalExpense::EXPENSE_KINDS))],
            'affects_cash_flow' => ['nullable', 'boolean'],
            'affects_income_statement' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $operationalExpenseCategory->id);
        }

        $operationalExpenseCategory->update($validated);

        return response()->json([
            'message' => 'Categoria de gasto actualizada exitosamente.',
            'data' => $operationalExpenseCategory->fresh('defaultLedgerAccount'),
        ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim($name)) ?: 'gasto';
        $slug = $base;
        $suffix = 2;

        while (
            OperationalExpenseCategory::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
