<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderAmendmentService
{
    public function __construct(private readonly InventoryFifoService $fifo) {}

    /** @return array{order: SalesOrder, difference: float, replayed: bool} */
    public function amend(int $orderId, array $data, User $user): array
    {
        return DB::transaction(function () use ($orderId, $data, $user): array {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            $warehouseId = (int) $data['warehouse_id'];
            if (! $user->hasWarehouseAccess($warehouseId)
                || (! $user->isAdmin() && $user->getEffectiveWarehouseId() !== $warehouseId)
                || ! $order->items()->where('warehouse_id', $warehouseId)->exists()
                || $order->items()->where('warehouse_id', '!=', $warehouseId)->exists()) {
                $this->fail('warehouse_id', 'La transacción no pertenece a la sucursal activa.');
            }
            $hash = hash('sha256', json_encode([$user->id, $data], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            foreach ($order->audit_logs ?? [] as $log) {
                if (($log['action'] ?? null) === 'order_amended' && ($log['metadata']['idempotency_key'] ?? null) === $data['idempotency_key']) {
                    if (! hash_equals((string) ($log['metadata']['request_hash'] ?? ''), $hash)) {
                        throw new IdempotencyConflictException('La clave de ampliación ya fue utilizada con otros datos.');
                    }

                    return ['order' => $order->load('items', 'payments'), 'difference' => (float) $log['metadata']['difference'], 'replayed' => true];
                }
            }
            if (! in_array($order->status, ['confirmed', 'processing', 'completed'], true)
                || SalesQuote::query()->where('converted_order_id', $order->id)->whereIn('status', ['sent_to_deposit', 'sent_to_cashier'])->exists()) {
                $this->fail('status', 'Esta transacción debe continuar por su flujo de preparación o cobro.');
            }
            if ($this->cents($order->total) !== $this->cents($data['expected_total'])) {
                throw new IdempotencyConflictException('La transacción cambió. Vuelve a cargarla antes de ampliar.');
            }
            $session = PosSession::query()->where('user_id', $user->id)->where('warehouse_id', $warehouseId)->where('status', 'open')->lockForUpdate()->first();
            if (! $session) {
                $this->fail('pos_session', 'Abre Caja en esta sucursal antes de ampliar la transacción.');
            }
            $customer = $order->customer_id ? Customer::query()->lockForUpdate()->findOrFail($order->customer_id) : null;
            $beforeTotal = (float) $order->total;
            $reportingBefore = app(SalesLineReportService::class)->snapshot($order->id);
            $originalItems = $order->items()->lockForUpdate()->get()->keyBy('id');
            foreach ($data['items'] as $index => $row) {
                if ((int) $row['warehouse_id'] !== $warehouseId) {
                    $this->fail("items.{$index}.warehouse_id", 'Todos los artículos deben pertenecer a la sucursal activa.');
                }
                $item = ! empty($row['order_item_id']) ? $originalItems->get((int) $row['order_item_id']) : null;
                if (! empty($row['order_item_id']) && ! $item) {
                    $this->fail("items.{$index}", 'El artículo original no pertenece a esta transacción.');
                }
                if ($item) {
                    $this->assertOriginalLine($item, $row, $index);
                    $increase = (int) $row['qty'] - (int) $item->quantity;
                    if ($increase <= 0) {
                        $this->fail("items.{$index}.qty", 'Solo puedes aumentar cantidades. Para reducirlas usa Devoluciones.');
                    }
                    $originalQty = (int) $item->quantity;
                    $originalCost = (float) $item->unit_cost;
                    $gross = round((int) $row['qty'] * (float) $item->unit_price, 2);
                    $subtotal = round($gross - (float) $item->discount_amount, 2);
                    $tax = round($subtotal * (int) $item->tax_percent / 100, 2);
                    $item->forceFill([
                        'quantity' => (int) $row['qty'], 'subtotal' => $subtotal,
                        'discount_percent' => $gross > 0 ? round((float) $item->discount_amount / $gross * 100, 2) : 0,
                        'tax_amount' => $tax, 'total' => round($subtotal + $tax, 2),
                    ])->saveQuietly();
                } else {
                    $custom = (bool) ($row['is_custom_line'] ?? false);
                    if ($custom ? trim((string) ($row['custom_description'] ?? '')) === '' : empty($row['product_id'])) {
                        $this->fail("items.{$index}", 'Selecciona un producto o indica la descripción del artículo.');
                    }
                    if (! $custom && ! Product::query()->findOrFail($row['product_id'])->isVendible()) {
                        $this->fail("items.{$index}.product_id", 'El producto no está disponible para la venta.');
                    }
                    if (! $custom && ! empty($row['variant_id']) && ! ProductVariant::query()->whereKey($row['variant_id'])->where('product_id', $row['product_id'])->exists()) {
                        $this->fail("items.{$index}.variant_id", 'La variante no pertenece al producto.');
                    }
                    if (! $custom && ! $user->hasRole(['admin', 'manager'])) {
                        $pricing = $this->fifo->currentFifoPricing((int) $row['product_id'], $row['variant_id'] ?? null, $warehouseId);
                        $basePrice = ($pricing['active_lot_id'] ?? null) !== null
                            ? $pricing['current_sale_price']
                            : ProductPricing::query()->where('product_id', $row['product_id'])->where('variant_id', $row['variant_id'] ?? null)->value('base_price');
                        if ($basePrice !== null && $this->cents($basePrice) !== $this->cents($row['price'])) {
                            $this->fail("items.{$index}.price", 'El precio cambió o requiere autorización de un administrador.');
                        }
                    }
                    $gross = round((int) $row['qty'] * (float) $row['price'], 2);
                    $discount = isset($row['discount_amount']) ? round((float) $row['discount_amount'], 2) : round($gross * (float) ($row['discount_percent'] ?? 0) / 100, 2);
                    if ($discount > $gross) {
                        $this->fail("items.{$index}.discount_amount", 'El descuento supera el importe de la línea.');
                    }
                    $item = $order->items()->create([
                        'product_id' => $custom ? null : $row['product_id'], 'variant_id' => $custom ? null : ($row['variant_id'] ?? null),
                        'warehouse_id' => $warehouseId, 'seller_id' => $row['seller_id'] ?? $user->id,
                        'quantity' => (int) $row['qty'], 'unit_price' => $row['price'], 'unit_cost' => 0,
                        'discount_amount' => $discount, 'tax_percent' => $row['tax_percent'],
                        'is_custom_line' => $custom, 'custom_description' => $custom ? trim($row['custom_description']) : null,
                        'notes' => $row['notes'] ?? null, 'added_after_sale' => true,
                    ]);
                    $increase = (int) $item->quantity;
                    $originalQty = 0;
                    $originalCost = 0;
                }
                if (! $item->is_custom_line) {
                    $inventory = ProductInventory::query()->where('warehouse_id', $warehouseId)->where('product_id', $item->product_id)->where('variant_id', $item->variant_id)->lockForUpdate()->first();
                    if (! $inventory || (int) $inventory->quantity_available < $increase) {
                        $this->fail("items.{$index}.qty", 'Stock insuficiente para ampliar esta línea.');
                    }
                    try {
                        $allocation = $this->fifo->consumeStock([
                            'product_id' => $item->product_id, 'variant_id' => $item->variant_id, 'warehouse_id' => $warehouseId,
                            'strict' => true, 'quantity' => $increase, 'reference_type' => 'post_sale_addition', 'reference_id' => $item->id,
                            'sales_order_item_id' => $item->id, 'reason' => 'post_sale_addition', 'user_id' => $user->id,
                            'notes' => 'Ampliación de transacción '.$order->order_number,
                        ]);
                    } catch (\Exception $exception) {
                        if ($exception->getMessage() === 'Stock insuficiente para consumir FIFO.') {
                            $this->fail("items.{$index}.qty", 'Stock insuficiente para ampliar esta línea.');
                        }
                        throw $exception;
                    }
                    if ((int) $allocation['consumed_quantity'] !== $increase) {
                        $this->fail("items.{$index}.qty", 'No se pudo completar la salida de inventario.');
                    }
                    $item->forceFill(['unit_cost' => round(($originalQty * $originalCost + $increase * (float) ($allocation['weighted_unit_cost'] ?? 0)) / (int) $item->quantity, 2)])->saveQuietly();
                }
            }
            $order->refresh()->calculateTotals();
            $difference = round((float) $order->total - $beforeTotal, 2);
            $submitted = array_sum(array_map(fn (array $payment): int => $this->cents($payment['amount']), $data['payment']));
            if ($difference < 0 || $submitted !== $this->cents($difference)) {
                $this->fail('payment', 'Los pagos y el crédito deben cubrir exactamente la diferencia de la ampliación.');
            }
            $credit = (float) collect($data['payment'])->where('method', 'credit')->sum('amount');
            $storeCredit = (float) collect($data['payment'])->where('method', 'store_credit')->sum('amount');
            if ($credit > 0 && (! $customer || ! $customer->hasAvailableCredit($credit + $storeCredit))) {
                $this->fail('payment', 'El cliente no dispone de crédito suficiente.');
            }
            if ($storeCredit > 0 && (! $customer || (float) $customer->store_credit + 0.001 < $storeCredit)) {
                $this->fail('payment', 'El cliente no dispone de saldo a favor suficiente.');
            }
            foreach ($data['payment'] as $payment) {
                $registered = $order->addPayment(
                    amount: (float) $payment['amount'], method: $payment['method'], notes: $payment['notes'] ?? 'Ampliación de transacción',
                    isCheckout: true, referenceNumber: $payment['reference_number'] ?? null,
                    financialAccountId: $payment['financial_account_id'] ?? null,
                );
                $registered->update(['pos_session_id' => $session->id]);
            }
            if ($customer && $credit > 0) {
                $customer->refresh();
                $beforeBalance = (float) $customer->current_balance;
                $customer->update(['current_balance' => round($beforeBalance + $credit, 2)]);
                CustomerTransaction::query()->create([
                    'customer_id' => $customer->id, 'type' => 'sale', 'amount' => $credit,
                    'balance_before' => $beforeBalance, 'balance_after' => $customer->current_balance,
                    'reference_type' => 'sales_order_modification', 'reference_id' => $order->id,
                    'description' => 'Crédito por ampliación '.$order->order_number, 'transaction_date' => now(), 'created_by' => $user->id,
                ]);
            }
            $order->refresh();
            $paid = (float) $order->amount_paid + (float) $order->payments()->where('method', 'store_credit')->sum('amount');
            $logs = $order->audit_logs ?? [];
            app(SalesLineReportService::class)->recordAmendment($order, $reportingBefore, $data['idempotency_key']);
            $logs[] = ['action' => 'order_amended', 'metadata' => [
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'difference' => $difference, 'user_id' => $user->id, 'pos_session_id' => $session->id,
            ], 'timestamp' => now()->toISOString()];
            $order->update(['audit_logs' => $logs, 'payment_status' => $order->amount_due <= 0.001 ? 'paid' : ($paid <= 0 ? 'pending' : 'partial')]);
            activity('sales')->performedOn($order)->causedBy($user)->withProperties(['difference' => $difference, 'idempotency_key' => $data['idempotency_key']])->log('Transacción ampliada');

            return ['order' => $order->fresh(['items', 'payments']), 'difference' => $difference, 'replayed' => false];
        });
    }

    private function assertOriginalLine(SalesOrderItem $item, array $row, int $index): void
    {
        if ((int) ($row['product_id'] ?? 0) !== (int) $item->product_id
            || (int) ($row['variant_id'] ?? 0) !== (int) $item->variant_id
            || $this->cents($row['price']) !== $this->cents($item->unit_price)
            || (int) $row['tax_percent'] !== (int) $item->tax_percent
            || (isset($row['discount_amount']) && $this->cents($row['discount_amount']) !== $this->cents($item->discount_amount))) {
            $this->fail("items.{$index}", 'No puedes cambiar el producto, precio, impuesto o descuento de una línea ya vendida.');
        }
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
