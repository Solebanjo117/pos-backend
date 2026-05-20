<?php

namespace App\Services;

use App\Models\CashMovement;
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
            if ($purchaseOrder->supplier) {
                $this->accountLedgerService->recordSupplierPayment($purchaseOrder->supplier, [
                    'amount' => $amount,
                    'method' => $data['method'],
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

        if ($data['method'] === 'cash') {
            $cashMovement = CashMovement::create([
                'pos_session_id' => $session->id,
                'user_id' => Auth::id(),
                'type' => 'out',
                'amount' => $amount,
                'reason' => 'Pago OC '.$purchaseOrder->po_number,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        $payment = $purchaseOrder->addPayment(
            $amount,
            $data['method'],
            $data['reference_number'] ?? null,
            $data['notes'] ?? null
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
