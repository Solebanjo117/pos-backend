<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Models\AccountPaymentBatch;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\PosSession;
use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Services\AccountLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $validPaymentMethods = PaymentMethod::realMoneyCodes(PaymentMethod::CONTEXT_CUSTOMER_RECEIPTS);

        $validated = $request->validate([
            'idempotency_key' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'method' => ['required', Rule::in($validPaymentMethods)],
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ]);
        $requestHash = $this->requestHash($validated, $orderId);

        if ($response = $this->idempotentResponse($validated['idempotency_key'], $requestHash, $orderId)) {
            return $response;
        }

        try {
            DB::transaction(function () use ($orderId, $validated, $requestHash): void {
                $customerId = SalesOrder::query()->whereKey($orderId)->value('customer_id');
                if ($customerId) {
                    Customer::query()->lockForUpdate()->findOrFail($customerId);
                }

                $order = SalesOrder::query()
                    ->with(['payments', 'customer'])
                    ->lockForUpdate()
                    ->findOrFail($orderId);

                $amountDue = $order->amount_due;
                if ((float) $validated['amount'] > $amountDue + 0.01) {
                    throw ValidationException::withMessages([
                        'amount' => "El monto excede el saldo pendiente de \${$amountDue}",
                    ]);
                }

                $bucket = PaymentMethod::liquidityBucket($validated['method']);
                if ($bucket === PaymentMethod::LIQUIDITY_BANK) {
                    $eligibleAccount = ! empty($validated['financial_account_id'])
                        && FinancialAccount::query()
                            ->whereKey($validated['financial_account_id'])
                            ->where('is_active', true)
                            ->where('is_legacy', false)
                            ->exists();
                    if (! $eligibleAccount) {
                        throw ValidationException::withMessages([
                            'financial_account_id' => 'Selecciona una cuenta financiera activa para este cobro.',
                        ]);
                    }
                }

                $session = null;
                if ($bucket === PaymentMethod::LIQUIDITY_CASH) {
                    $warehouseId = $order->items()->value('warehouse_id')
                        ?? auth()->user()?->current_warehouse_id;
                    $session = PosSession::query()
                        ->where('user_id', auth()->id())
                        ->where('warehouse_id', $warehouseId)
                        ->where('status', 'open')
                        ->lockForUpdate()
                        ->first();
                    if (! $session) {
                        throw ValidationException::withMessages([
                            'pos_session' => 'Debe abrir Caja en la sucursal de la venta antes de recibir efectivo.',
                        ]);
                    }
                }

                if ($order->customer) {
                    $customer = $order->customer()->lockForUpdate()->firstOrFail();
                    $this->accountLedgerService->recordCustomerReceipt($customer, [
                        'amount' => (float) $validated['amount'],
                        'method' => $validated['method'],
                        'financial_account_id' => $validated['financial_account_id'] ?? null,
                        'pos_session_id' => $session?->id,
                        'idempotency_key' => $validated['idempotency_key'],
                        'request_hash' => $requestHash,
                        'reference_number' => $validated['reference_number'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                        'paid_at' => $validated['paid_at'] ?? now(),
                        'applications' => [[
                            'document_id' => $order->id,
                            'amount' => (float) $validated['amount'],
                        ]],
                    ]);
                } else {
                    $order->addPayment(
                        amount: (float) $validated['amount'],
                        method: $validated['method'],
                        notes: $validated['notes'] ?? null,
                        paidAt: $validated['paid_at'] ?? now(),
                        referenceNumber: $validated['reference_number'] ?? null,
                        financialAccountId: $validated['financial_account_id'] ?? null,
                        idempotencyKey: $validated['idempotency_key'],
                        requestHash: $requestHash,
                    );
                }
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                && ($response = $this->idempotentResponse($validated['idempotency_key'], $requestHash, $orderId))) {
                return $response;
            }

            throw $exception;
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => ['amount' => [$exception->getMessage()]],
            ], 422);
        }

        $order = SalesOrder::with('payments')->findOrFail($orderId);

        return $this->paymentResponse($order, 201);
    }

    private function idempotentResponse(string $key, string $requestHash, int $orderId): ?JsonResponse
    {
        $batch = AccountPaymentBatch::query()->where('idempotency_key', $key)->first();
        $payment = SalesOrderPayment::query()->where('idempotency_key', $key)->first();
        $existingHash = $batch?->request_hash ?? $payment?->request_hash;
        if (! $batch && ! $payment) {
            return null;
        }

        if (! $existingHash || ! hash_equals($existingHash, $requestHash)) {
            return response()->json([
                'message' => 'La clave de idempotencia ya fue utilizada con datos diferentes.',
            ], 409);
        }

        $order = SalesOrder::with('payments')->findOrFail($orderId);

        return $this->paymentResponse($order, 200);
    }

    private function paymentResponse(SalesOrder $order, int $status): JsonResponse
    {
        $order->refresh()->load('payments');

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amount_due,
            'payment_status' => $order->payment_status,
            'payments' => $order->payments,
        ], $status);
    }

    private function requestHash(array $validated, int $orderId): string
    {
        unset($validated['idempotency_key']);
        ksort($validated);

        return hash('sha256', json_encode([
            'order_id' => $orderId,
            'payload' => $validated,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }
}
