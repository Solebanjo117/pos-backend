<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalExpenseBeneficiary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class OperationalExpenseBeneficiaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = OperationalExpenseBeneficiary::query()
            ->withSum('expenses', 'amount')
            ->withCount('expenses')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.Str::lower($search).'%']))
            ->when(array_key_exists('active', $validated), fn ($query) => $query->where('is_active', $validated['active']))
            ->orderBy('name');

        return response()->json($query->paginate((int) ($validated['per_page'] ?? 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['name_key'] = OperationalExpenseBeneficiary::normalizeNameKey($data['name']);
        if (OperationalExpenseBeneficiary::query()->where('name_key', $data['name_key'])->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe un beneficiario con este nombre.']);
        }
        $beneficiary = OperationalExpenseBeneficiary::create($data);

        return response()->json(['data' => $beneficiary, 'message' => 'Beneficiario creado exitosamente.'], 201);
    }

    public function show(OperationalExpenseBeneficiary $operationalExpenseBeneficiary): JsonResponse
    {
        return response()->json([
            'data' => $operationalExpenseBeneficiary->loadCount('expenses')->loadSum('expenses', 'amount'),
        ]);
    }

    public function update(Request $request, OperationalExpenseBeneficiary $operationalExpenseBeneficiary): JsonResponse
    {
        $data = $this->validated($request, $operationalExpenseBeneficiary);
        $data['name_key'] = OperationalExpenseBeneficiary::normalizeNameKey($data['name']);
        if (OperationalExpenseBeneficiary::query()->where('name_key', $data['name_key'])->where('id', '!=', $operationalExpenseBeneficiary->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Ya existe un beneficiario con este nombre.']);
        }
        $operationalExpenseBeneficiary->update($data);

        return response()->json(['data' => $operationalExpenseBeneficiary->fresh(), 'message' => 'Beneficiario actualizado exitosamente.']);
    }

    private function validated(Request $request, ?OperationalExpenseBeneficiary $beneficiary = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['individual', 'company'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
