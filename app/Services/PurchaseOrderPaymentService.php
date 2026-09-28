<?php

namespace App\Services;

use App\Models\PosSession;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderPaymentService
{
    public function __construct(
        private AccountLedgerService $accountLedgerService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerPayment(PurchaseOrder $purchaseOrder, array $data): ?PurchaseOrderPayment
    {
        if (in_array($data['method'], ['credit', 'supplier_credit'], true)) {
            return null;
        }

        $normalized = app(OperationalPaymentService::class)->normalize([$data], (float) $data['amount'], \App\Models\PaymentMethod::CONTEXT_PURCHASE_ORDERS)[0];
        $data = array_merge($data, $normalized);

        $amount = round((float) $data['amount'], 2);
        $amountDue = round((float) $purchaseOrder->amount_due, 2);

        if ($amount > $amountDue + 0.01) {
            throw new \InvalidArgumentException("El monto del pago ($amount) excede la deuda pendiente ($amountDue).");
        }

        $session = $this->openSessionForPurchaseOrder(
            $purchaseOrder,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null
        );

        return DB::transaction(function () use ($purchaseOrder, $data, $amount, $session): PurchaseOrderPayment {
            if ($purchaseOrder->supplier_id) {
                \App\Models\Supplier::query()->lockForUpdate()->findOrFail($purchaseOrder->supplier_id);
            }
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            $session = PosSession::query()->whereKey($session->id)->where('status', 'open')->lockForUpdate()->first();
            if (! $session) {
                throw new \InvalidArgumentException('La sesión de Caja ya está cerrada.');
            }
            if ((int) round($amount * 100) > (int) round((float) $purchaseOrder->amount_due * 100)) {
                throw new \InvalidArgumentException('El importe excede el saldo actualizado de la compra.');
            }
            if ($purchaseOrder->supplier) {
                $this->accountLedgerService->recordSupplierPayment($purchaseOrder->supplier, [
                    'amount' => $amount,
                    'method' => $data['method'],
                    'payment_context' => \App\Models\PaymentMethod::CONTEXT_PURCHASE_ORDERS,
                    'financial_account_id' => $data['financial_account_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'pos_session_id' => $session->id,
                    'applications' => [
                        [
                            'document_id' => $purchaseOrder->id,
                            'amount' => $amount,
                        ],
                    ],
                ]);

                return $purchaseOrder->payments()->latest('id')->firstOrFail()->fresh();
            }

            return $this->registerPaymentWithoutSupplier($purchaseOrder, $data, $amount, $session);
        });
    }

    public function deletePayment(PurchaseOrder $purchaseOrder, PurchaseOrderPayment $payment): void
    {
        DB::transaction(function () use ($purchaseOrder, $payment): void {
            $payment->loadMissing(['posSession', 'cashMovement']);
            $this->ensureLinkedSessionIsOpen($payment);

            if ($purchaseOrder->supplier) {
                $this->accountLedgerService->reverseSupplierPaymentForPurchaseOrderPayment($purchaseOrder, $payment);
            } else {
                $payment->cashMovement?->delete();
                $payment->delete();
                $purchaseOrder->recalculatePaymentStatus();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function registerPaymentWithoutSupplier(PurchaseOrder $purchaseOrder, array $data, float $amount, PosSession $session): PurchaseOrderPayment
    {
        $cashMovement = null;

        if (\App\Models\PaymentMethod::liquidityBucket($data['method']) === 'cash') {
            $cashMovement = app(CashMovementService::class)->record(Auth::user(), $session->warehouse_id, [
                'pos_session_id' => $session->id,
                'user_id' => Auth::id(),
                'type' => 'out',
                'subtype' => 'purchase_payment',
                'amount' => $amount,
                'reason' => 'Pago OC '.$purchaseOrder->po_number,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        $payment = $purchaseOrder->addPayment(
            $amount,
            $data['method'],
            $data['reference_number'] ?? null,
            $data['notes'] ?? null,
            $data['financial_account_id'] ?? null
        );

        $payment->update([
            'pos_session_id' => $session->id,
            'cash_movement_id' => $cashMovement?->id,
        ]);

        return $payment->fresh();
    }

    private function openSessionForPurchaseOrder(PurchaseOrder $purchaseOrder, ?int $requestedWarehouseId = null): PosSession
    {
        $purchaseOrder->loadMissing('warehouse');

        if ($requestedWarehouseId && $requestedWarehouseId !== (int) $purchaseOrder->warehouse_id) {
            throw new \InvalidArgumentException('La sucursal del pago no coincide con la sucursal de la OC.');
        }

        $session = PosSession::query()
            ->where('user_id', Auth::id())
            ->where('warehouse_id', $purchaseOrder->warehouse_id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if (! $session) {
            $warehouseName = $purchaseOrder->warehouse?->name ?? 'esta sucursal';

            throw new \InvalidArgumentException("La OC pertenece a {$warehouseName}. Abre o selecciona caja en esa sucursal antes de registrar el pago.");
        }

        return $session;
    }

    private function ensureLinkedSessionIsOpen(PurchaseOrderPayment $payment): void
    {
        if ($payment->posSession && $payment->posSession->status !== 'open') {
            throw new \InvalidArgumentException('No se puede eliminar un pago ligado a una caja cerrada.');
        }

        if ($payment->cashMovement?->posSession && $payment->cashMovement->posSession->status !== 'open') {
            throw new \InvalidArgumentException('No se puede eliminar un movimiento de una caja cerrada.');
        }
    }
}
