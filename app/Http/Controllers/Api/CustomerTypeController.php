<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerTypeRequest;
use App\Http\Requests\UpdateCustomerTypeRequest;
use App\Models\CustomerType;
use Illuminate\Http\JsonResponse;

class CustomerTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CustomerType::query()->withCount('customers')->orderBy('name')->get()]);
    }

    public function store(StoreCustomerTypeRequest $request): JsonResponse
    {
        $type = CustomerType::query()->create($request->validated());

        return response()->json(['data' => $type->loadCount('customers')], 201);
    }

    public function update(UpdateCustomerTypeRequest $request, CustomerType $customerType): JsonResponse
    {
        $customerType->update($request->validated());

        return response()->json(['data' => $customerType->fresh()->loadCount('customers')]);
    }

    public function destroy(CustomerType $customerType): JsonResponse
    {
        if ($customerType->customers()->exists()) {
            return response()->json(['message' => 'No se puede eliminar un tipo utilizado por clientes. Desactívalo en su lugar.'], 422);
        }
        $customerType->delete();

        return response()->json(['message' => 'Tipo de cliente eliminado']);
    }
}
