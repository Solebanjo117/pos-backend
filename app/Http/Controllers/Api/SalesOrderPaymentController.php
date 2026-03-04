<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SalesOrderPaymentController extends Controller
{
    /**
     * GET /api/v1/sales-orders/{orderId}/payments
     * List all payments for an order
     */
    public function index(int $orderId): JsonResponse
    {
        $order = SalesOrder::with('payments.creator')->findOrFail($orderId);

        return response()->json([
            'data'         => $order->payments,
            'amount_paid'  => $order->amount_paid,
            'amount_due'   => $order->amount_due,
            'total'        => $order->total,
            'payment_status' => $order->payment_status,
        ]);
    }

    /**
     * POST /api/v1/sales-orders/{orderId}/payments
     * Register a new payment / abono
     */
    public function store(Request $request, int $orderId): JsonResponse
    {
        $order = SalesOrder::with(['payments', 'customer'])->findOrFail($orderId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,card,transfer,credit',
            'notes'  => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ]);

        // Can't exceed the remaining balance
        $amountDue = $order->amount_due;
        if ($validated['amount'] > $amountDue) {
            return response()->json([
                'message' => "El monto excede el saldo pendiente de \${$amountDue}",
            ], 422);
        }

        // Credit method: verify customer has limit
        if ($validated['method'] === 'credit') {
            if (!$order->customer) {
                return response()->json(['message' => 'Se requiere un cliente para pagos a crédito.'], 422);
            }
            if (!$order->customer->hasAvailableCredit($validated['amount'])) {
                $available = $order->customer->getAvailableCredit();
                return response()->json([
                    'message' => "Crédito insuficiente. Disponible: \${$available}",
                ], 422);
            }
        }

        DB::transaction(function () use ($order, $validated) {
            $payment = $order->addPayment(
                amount:   (float) $validated['amount'],
                method:   $validated['method'],
                notes:    $validated['notes'] ?? null,
                paidAt:   $validated['paid_at'] ?? now(),
            );
        });

        $order->refresh()->load('payments');

        return response()->json([
            'message'        => 'Pago registrado exitosamente.',
            'amount_paid'    => $order->amount_paid,
            'amount_due'     => $order->amount_due,
            'payment_status' => $order->payment_status,
            'payments'       => $order->payments,
        ], 201);
    }
}
