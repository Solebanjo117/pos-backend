<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchasePaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasePaymentRequestService
{
    public function create(PurchaseOrder $order, array $data, User $user, int $warehouseId): PurchasePaymentRequest
    {
        return DB::transaction(function () use ($order, $data, $user, $warehouseId): PurchasePaymentRequest {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless((int) $order->warehouse_id === $warehouseId, 403);
            $hash = hash('sha256', json_encode([$order->id, $user->id, number_format((float) $data['amount'], 2, '.', ''), $data['notes'] ?? null], JSON_THROW_ON_ERROR));
            $existing = PurchasePaymentRequest::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'La clave ya fue utilizada con datos diferentes.');

                return $existing->load('queueEntry');
            }
            $active = PurchasePaymentRequest::query()->where('purchase_order_id', $order->id)->whereHas('queueEntry', fn ($query) => $query->whereIn('status', ['pending', 'opened']))->first();
            if ($active) {
                throw ValidationException::withMessages(['amount' => 'La compra ya tiene la solicitud activa #'.$active->id.'.']);
            }
            $this->validateDebt($order, (float) $data['amount']);
            $request = PurchasePaymentRequest::query()->create([...$data, 'purchase_order_id' => $order->id, 'warehouse_id' => $warehouseId, 'requested_by' => $user->id, 'request_hash' => $hash]);
            $request->queueEntry()->create(['entry_type' => 'purchase_payment', 'warehouse_id' => $warehouseId, 'status' => 'pending', 'estimated_amount' => $request->amount, 'scheduled_for' => now(), 'metadata' => ['fixed_cash_amount' => true, 'requested_by' => $user->only(['id', 'name'])]]);
            activity('purchase_payments')->performedOn($request)->causedBy($user)->log('Pago solicitado a Caja');

            return $request->load('queueEntry');
        });
    }

    public function settle(PurchasePaymentRequest $request, array $data, User $user): void
    {
        $order = PurchaseOrder::query()->findOrFail($request->purchase_order_id);
        if ($order->supplier_id) {
            \App\Models\Supplier::query()->lockForUpdate()->findOrFail($order->supplier_id);
        }
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($request->purchase_order_id);
        $this->validateDebt($order, (float) $request->amount);
        $payments = app(OperationalPaymentService::class)->normalize($data['payments'] ?? [], (float) $request->amount, PaymentMethod::CONTEXT_PURCHASE_ORDERS);
        foreach ($payments as $payment) {
            app(PurchaseOrderPaymentService::class)->registerPayment($order->fresh(), [...$payment, 'warehouse_id' => $request->warehouse_id]);
        }
        activity('purchase_payments')->performedOn($request)->causedBy($user)->log('Pago confirmado en Caja');
    }

    private function validateDebt(PurchaseOrder $order, float $amount): void
    {
        if ($order->status === 'cancelled' || $amount <= 0 || (int) round($amount * 100) > (int) round((float) $order->amount_due * 100)) {
            throw ValidationException::withMessages(['amount' => 'El importe excede el saldo actual de la compra o la compra está cancelada. Saldo: '.number_format((float) $order->amount_due, 2)]);
        }
    }
}
