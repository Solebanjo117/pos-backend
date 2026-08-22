<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerBulkController extends Controller
{
    public function __construct(private CustomerService $customerService) {}

    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:delete,deactivate',
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct|exists:customers,id',
        ]);

        $result = DB::transaction(function () use ($data): array {
            $customers = Customer::query()->whereKey($data['ids'])->lockForUpdate()->get();
            $blocked = $data['action'] === 'delete'
                ? $customers->mapWithKeys(fn (Customer $customer): array => [$customer->id => $this->customerService->customerDeleteBlockReason($customer)])->filter()
                : collect();

            if ($blocked->isNotEmpty()) {
                return ['blocked' => $blocked];
            }

            foreach ($customers as $customer) {
                if ($data['action'] === 'deactivate') {
                    $customer->update(['status' => 'inactive']);
                    $customer->vehicles()->where('status', 'active')->update(['status' => 'inactive']);
                } else {
                    $this->customerService->deleteCustomer($customer);
                }
            }

            return ['affected' => $customers->count()];
        });

        return isset($result['blocked'])
            ? response()->json(['message' => 'Ningún cliente fue modificado.', 'blocked' => $result['blocked']], 409)
            : response()->json(['message' => 'Acción aplicada.', 'affected' => $result['affected']]);
    }

    public function customerTypes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:delete,deactivate',
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct|exists:customer_types,id',
        ]);

        $result = DB::transaction(function () use ($data): array {
            $types = CustomerType::query()->whereKey($data['ids'])->withCount('customers')->lockForUpdate()->get();
            $blocked = $data['action'] === 'delete'
                ? $types->filter(fn (CustomerType $type): bool => $type->customers_count > 0)->mapWithKeys(fn (CustomerType $type): array => [$type->id => 'El tipo está asignado a clientes.'])
                : collect();

            if ($blocked->isNotEmpty()) {
                return ['blocked' => $blocked];
            }

            foreach ($types as $type) {
                $data['action'] === 'deactivate' ? $type->update(['is_active' => false]) : $type->delete();
            }

            return ['affected' => $types->count()];
        });

        return isset($result['blocked'])
            ? response()->json(['message' => 'Ningún tipo fue modificado.', 'blocked' => $result['blocked']], 409)
            : response()->json(['message' => 'Acción aplicada.', 'affected' => $result['affected']]);
    }
}
