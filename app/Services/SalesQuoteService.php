<?php

namespace App\Services;

use App\Models\ProductInventory;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesQuoteService
{
    /**
     * @return array{order: SalesOrder, warnings: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function sendToWarehouse(SalesQuote $quote, User $user): array
    {
        return DB::transaction(function () use ($quote, $user): array {
            $quote = SalesQuote::query()
                ->with(['items.product', 'items.variant'])
                ->lockForUpdate()
                ->findOrFail($quote->id);

            $this->ensureQuoteCanBeSentToWarehouse($quote);

            $warnings = $this->buildStockWarnings($quote);

            $order = SalesOrder::create([
                'customer_id' => $quote->customer_id,
                'user_id' => $user->id,
                'pos_session_id' => $quote->pos_session_id,
                'date' => now(),
                'status' => 'pending',
                'payment_status' => 'pending',
                'payment_method' => null,
                'sent_to_warehouse_at' => now(),
                'notes' => "Convertida de cotizacion {$quote->quote_number} y enviada a bodega",
            ]);

            foreach ($quote->items as $quoteItem) {
                SalesOrderItem::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $quoteItem->product_id,
                    'variant_id' => $quoteItem->is_custom_line ? null : $quoteItem->variant_id,
                    'warehouse_id' => $quoteItem->warehouse_id,
                    'quantity' => $quoteItem->quantity,
                    'unit_price' => $quoteItem->unit_price,
                    'discount_percent' => $quoteItem->discount_percent,
                    'discount_amount' => $quoteItem->discount_amount,
                    'tax_percent' => $quoteItem->tax_percent,
                    'tax_amount' => $quoteItem->tax_amount,
                    'subtotal' => $quoteItem->subtotal,
                    'total' => $quoteItem->total,
                    'is_custom_line' => (bool) $quoteItem->is_custom_line,
                    'custom_description' => $quoteItem->custom_description,
                    'notes' => $quoteItem->notes,
                ]);
            }

            $order->load('items');
            $order->calculateTotals();

            $quote->update([
                'status' => 'sent_to_deposit',
                'converted_order_id' => $order->id,
            ]);

            return [
                'order' => $order->fresh(['customer', 'user', 'items.product', 'items.variant']),
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureQuoteCanBeSentToWarehouse(SalesQuote $quote): void
    {
        if (! in_array($quote->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages([
                'quote' => "No se puede enviar a deposito desde el estado '{$quote->status_label}'.",
            ]);
        }

        if ($quote->converted_order_id !== null) {
            throw ValidationException::withMessages([
                'quote' => 'Esta cotizacion ya tiene una orden asociada.',
            ]);
        }

        if ($quote->items->isEmpty()) {
            throw ValidationException::withMessages([
                'quote' => 'Esta cotizacion no puede enviarse a bodega porque no tiene articulos.',
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStockWarnings(SalesQuote $quote): array
    {
        $warnings = [];

        foreach ($quote->items as $item) {
            if ($item->is_custom_line) {
                continue;
            }

            $query = ProductInventory::query()
                ->where('product_id', $item->product_id)
                ->where('warehouse_id', $item->warehouse_id);

            if ($item->variant_id) {
                $query->where('variant_id', $item->variant_id);
            } else {
                $query->whereNull('variant_id');
            }

            $available = max(0, (int) ($query->value('quantity_available') ?? 0));
            $requested = (int) $item->quantity;

            if ($available >= $requested) {
                continue;
            }

            $label = $item->product?->name
                ?? $item->variant?->sku
                ?? "Producto #{$item->product_id}";

            $warnings[] = [
                'type' => 'stock_shortage',
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'warehouse_id' => $item->warehouse_id,
                'product_name' => $label,
                'requested_quantity' => $requested,
                'available_quantity' => $available,
                'missing_quantity' => $requested - $available,
                'message' => "{$label}: solicitado {$requested}, disponible {$available}, faltante ".($requested - $available),
            ];
        }

        return $warnings;
    }
}
