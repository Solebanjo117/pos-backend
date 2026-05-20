<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountReceiptRequest;
use App\Models\Customer;
use App\Services\AccountLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountsReceivableController extends Controller
{
    public function __construct(
        private AccountLedgerService $accountLedgerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->accountLedgerService->receivablesSummary(
            $request->only(['search', 'per_page', 'page', 'date_from', 'date_to', 'warehouse_id'])
        ));
    }

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $this->accountLedgerService->customerStatement(
                $customer,
                $request->only(['date_from', 'date_to', 'warehouse_id'])
            ),
        ]);
    }

    public function storeReceipt(StoreAccountReceiptRequest $request, Customer $customer): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Cobro registrado exitosamente.',
                'data' => $this->accountLedgerService->recordCustomerReceipt($customer, $request->validated()),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'sesion de caja')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'pos_session_id' => [$exception->getMessage()],
                    ],
                ], 422);
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
