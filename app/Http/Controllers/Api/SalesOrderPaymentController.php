<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Services\AccountLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalesOrderPaymentController extends Controller
{
    public function __construct(
        private AccountLedgerService $accountLedgerService
    ) {}

    public function index(int $orderId): JsonResponse
    {
        $order = SalesOrder::with('payments.creator')->findOrFail($orderId);

        return response()->json([
            'data' => $order->payments,
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amount_due,
            'total' => $order->total,
            'payment_status' => $order->payment_status,
        ]);
    }

    public function store(Request $request, int $orderId): JsonResponse
    {
        $order = SalesOrder::with(['payments', 'customer'])->findOrFail($orderId);
        $validPaymentMethods = PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_CUSTOMER_RECEIPTS);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => ['required', Rule::in($validPaymentMethods)],
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ]);

        $amountDue = $order->amount_due;
        if ($validated['amount'] > $amountDue) {
            return response()->json([
                'message' => "El monto excede el saldo pendiente de \${$amountDue}",
            ], 422);
        }

        if ($order->customer) {
            $this->accountLedgerService->recordCustomerReceipt($order->customer, [
                'amount' => (float) $validated['amount'],
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'paid_at' => $validated['paid_at'] ?? now(),
                'applications' => [
                    [
                        'document_id' => $order->id,
                        'amount' => (float) $validated['amount'],
                    ],
                ],
            ]);
        } else {
            DB::transaction(function () use ($order, $validated): void {
                $order->addPayment(
                    amount: (float) $validated['amount'],
                    method: $validated['method'],
                    notes: $validated['notes'] ?? null,
                    paidAt: $validated['paid_at'] ?? now(),
                    referenceNumber: $validated['reference_number'] ?? null,
                );
            });
        }

        $order->refresh()->load('payments');

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amount_due,
            'payment_status' => $order->payment_status,
            'payments' => $order->payments,
        ], 201);
    }
}
