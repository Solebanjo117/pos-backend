<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountReceiptRequest;
use App\Models\Supplier;
use App\Services\AccountLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountsPayableController extends Controller
{
    public function __construct(
        private AccountLedgerService $accountLedgerService
    ) {}

    /**
     * Return a summary of Accounts Payable: all suppliers with their
     * outstanding debt on purchase orders.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->accountLedgerService->payablesSummary(
            $request->only(['search'])
        ));
    }

    public function statement(Supplier $supplier): JsonResponse
    {
        return response()->json([
            'data' => $this->accountLedgerService->supplierStatement($supplier),
        ]);
    }

    public function storePayment(StoreAccountReceiptRequest $request, Supplier $supplier): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Pago registrado exitosamente.',
                'data' => $this->accountLedgerService->recordSupplierPayment($supplier, $request->validated()),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
