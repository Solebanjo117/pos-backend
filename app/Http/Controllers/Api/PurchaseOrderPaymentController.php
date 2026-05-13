<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\AccountLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderPaymentController extends Controller
{
    public function __construct(
        private AccountLedgerService $accountLedgerService
    ) {}

    /**
     * List all payments for a specific purchase order.
     */
    public function index(int $poId): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($poId);

        $payments = $po->payments()
            ->with('user')
            ->orderBy('paid_at', 'desc')
            ->get();

        return response()->json([
            'data' => $payments,
            'amount_paid' => $po->amount_paid,
            'amount_due' => $po->amount_due,
            'payment_status' => $po->payment_status,
        ]);
    }

    /**
     * Register a new payment against a purchase order.
     */
    public function store(Request $request, int $poId): JsonResponse
    {
        $po = PurchaseOrder::with('payments')->findOrFail($poId);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,card,transfer,other',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $amount = (float) $request->amount;
        $amountDue = $po->amount_due;

        if ($amount > $amountDue + 0.01) {
            return response()->json([
                'message' => "El monto del pago ($amount) excede la deuda pendiente ($amountDue).",
            ], 422);
        }

        if ($po->supplier) {
            $this->accountLedgerService->recordSupplierPayment($po->supplier, [
                'amount' => $amount,
                'method' => $request->method,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'applications' => [
                    [
                        'document_id' => $po->id,
                        'amount' => $amount,
                    ],
                ],
            ]);
            $payment = $po->payments()->latest('id')->first();
        } else {
            $payment = $po->addPayment(
                $amount,
                $request->method,
                $request->reference_number,
                $request->notes
            );
        }

        $po->refresh();

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'payment' => $payment->load('user'),
            'amount_paid' => $po->amount_paid,
            'amount_due' => $po->amount_due,
            'payment_status' => $po->payment_status,
        ], 201);
    }

    /**
     * Delete a payment (in case of an input error)
     */
    public function destroy(int $poId, int $paymentId): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($poId);
        $payment = $po->payments()->findOrFail($paymentId);

        $payment->delete();
        $po->recalculatePaymentStatus();
        $po->refresh();

        return response()->json([
            'message' => 'Pago eliminado.',
            'amount_paid' => $po->amount_paid,
            'amount_due' => $po->amount_due,
            'payment_status' => $po->payment_status,
        ]);
    }
}
