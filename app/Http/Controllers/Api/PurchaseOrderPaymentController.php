<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseOrderPaymentRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderPaymentService;
use Illuminate\Http\JsonResponse;

class PurchaseOrderPaymentController extends Controller
{
    public function __construct(
        private PurchaseOrderPaymentService $purchaseOrderPaymentService
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
    public function store(StorePurchaseOrderPaymentRequest $request, int $poId): JsonResponse
    {
        $po = PurchaseOrder::with('payments')->findOrFail($poId);
        $validated = $request->validated();

        try {
            $payment = $this->purchaseOrderPaymentService->registerPayment($po, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $po->refresh();

        if (! $payment) {
            return response()->json([
                'message' => 'La compra queda como cuenta por pagar; no se registra salida inmediata de caja.',
                'payment' => null,
                'amount_paid' => $po->amount_paid,
                'amount_due' => $po->amount_due,
                'payment_status' => $po->payment_status,
            ]);
        }

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

        try {
            $this->purchaseOrderPaymentService->deletePayment($po, $payment);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment' => [$exception->getMessage()],
                ],
            ], 422);
        }

        $po->refresh();

        return response()->json([
            'message' => 'Pago eliminado.',
            'amount_paid' => $po->amount_paid,
            'amount_due' => $po->amount_due,
            'payment_status' => $po->payment_status,
        ]);
    }
}
