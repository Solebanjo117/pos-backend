<?php

namespace App\Services;

use App\Models\ProductInventory;
use App\Models\SalesQuote;
use App\Models\SalesQuoteItem;
use Illuminate\Support\Carbon;

class SalesQuoteOperationalDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function build(SalesQuote $quote): array
    {
        $quote->loadMissing([
            'customer:id,code,name,phone,email',
            'user:id,name',
            'warehouse:id,name',
            'posSession:id,opened_at,closed_at,status',
            'convertedOrder:id,order_number,status,payment_status,total,sent_to_warehouse_at,prepared_at,created_at',
            'items.product:id,name,sku_base',
            'items.variant:id,sku,brand_id',
            'items.variant.brand:id,name',
            'items.warehouse:id,name',
        ]);

        $items = $quote->items
            ->sortBy('id')
            ->map(fn (SalesQuoteItem $item): array => $this->itemRow($item))
            ->values();
        $warnings = $items
            ->pluck('stock.warning')
            ->filter()
            ->values()
            ->all();

        return [
            'quote' => $this->quoteRow($quote),
            'items' => $items->all(),
            'workflow' => $this->workflow($quote),
            'stock_context' => [
                'has_warnings' => $warnings !== [],
                'warnings_count' => count($warnings),
                'warnings' => $warnings,
            ],
            'documents' => $this->documents($quote),
            'converted_order_context' => $this->convertedOrderContext($quote),
            'timeline' => $this->timeline($quote),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteRow(SalesQuote $quote): array
    {
        return [
            'id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'date' => $this->date($quote->date),
            'valid_until' => $this->date($quote->valid_until),
            'status' => $quote->status,
            'status_label' => $quote->status_label,
            'subtotal' => $this->money($quote->subtotal),
            'tax_amount' => $this->money($quote->tax_amount),
            'discount_amount' => $this->money($quote->discount_amount),
            'total' => $this->money($quote->total),
            'formatted_total' => $quote->formatted_total,
            'notes' => $quote->notes,
            'internal_notes' => $quote->internal_notes,
            'sent_to_cashier_at' => $this->dateTime($quote->sent_to_cashier_at),
            'converted_order_id' => $quote->converted_order_id,
            'customer' => $quote->customer ? [
                'id' => $quote->customer->id,
                'code' => $quote->customer->code,
                'name' => $quote->customer->name,
                'phone' => $quote->customer->phone,
                'email' => $quote->customer->email,
            ] : null,
            'user' => $quote->user ? [
                'id' => $quote->user->id,
                'name' => $quote->user->name,
            ] : null,
            'warehouse' => $quote->warehouse ? [
                'id' => $quote->warehouse->id,
                'name' => $quote->warehouse->name,
            ] : null,
            'pos_session' => $quote->posSession ? [
                'id' => $quote->posSession->id,
                'status' => $quote->posSession->status,
                'opened_at' => $this->dateTime($quote->posSession->opened_at),
                'closed_at' => $this->dateTime($quote->posSession->closed_at),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(SalesQuoteItem $item): array
    {
        $stock = $this->stockForItem($item);

        return [
            'id' => $item->id,
            'product' => $item->product ? [
                'id' => $item->product->id,
                'name' => $item->product->name,
                'sku' => $item->product->sku_base,
            ] : null,
            'variant' => $item->variant ? [
                'id' => $item->variant->id,
                'sku' => $item->variant->sku,
                'brand' => $item->variant->brand ? [
                    'id' => $item->variant->brand->id,
                    'name' => $item->variant->brand->name,
                ] : null,
            ] : null,
            'warehouse' => $item->warehouse ? [
                'id' => $item->warehouse->id,
                'name' => $item->warehouse->name,
            ] : null,
            'product_name' => $item->product_name,
            'product_sku' => $item->product_sku,
            'is_custom_line' => (bool) $item->is_custom_line,
            'custom_description' => $item->custom_description,
            'quantity' => (int) $item->quantity,
            'unit_price' => $this->money($item->unit_price),
            'discount_percent' => $this->money($item->discount_percent),
            'discount_amount' => $this->money($item->discount_amount),
            'tax_percent' => $this->money($item->tax_percent),
            'tax_amount' => $this->money($item->tax_amount),
            'subtotal' => $this->money($item->subtotal),
            'total' => $this->money($item->total),
            'notes' => $item->notes,
            'stock' => $stock,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stockForItem(SalesQuoteItem $item): array
    {
        if ($item->is_custom_line) {
            return [
                'is_stock_tracked' => false,
                'available_quantity' => null,
                'requested_quantity' => (int) $item->quantity,
                'missing_quantity' => 0,
                'warning' => null,
            ];
        }

        $available = (int) (ProductInventory::query()
            ->where('product_id', $item->product_id)
            ->where('warehouse_id', $item->warehouse_id)
            ->when(
                $item->variant_id,
                fn ($query) => $query->where('variant_id', $item->variant_id),
                fn ($query) => $query->whereNull('variant_id')
            )
            ->value('quantity_available') ?? 0);
        $requested = (int) $item->quantity;
        $missing = max(0, $requested - $available);
        $label = $item->product?->name ?? $item->variant?->sku ?? "Producto #{$item->product_id}";
        $warning = $missing > 0 ? [
            'type' => 'stock_shortage',
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'warehouse_id' => $item->warehouse_id,
            'product_name' => $label,
            'requested_quantity' => $requested,
            'available_quantity' => $available,
            'missing_quantity' => $missing,
            'message' => "{$label}: solicitado {$requested}, disponible {$available}, faltante {$missing}",
        ] : null;

        return [
            'is_stock_tracked' => true,
            'available_quantity' => $available,
            'requested_quantity' => $requested,
            'missing_quantity' => $missing,
            'warning' => $warning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(SalesQuote $quote): array
    {
        $hasItems = $quote->items->isNotEmpty();
        $canSendToDeposit = in_array($quote->status, ['draft', 'sent'], true)
            && $quote->converted_order_id === null
            && $hasItems;
        $canSendToCashier = in_array($quote->status, ['draft', 'sent', 'sent_to_deposit'], true);
        $canMarkCollected = in_array($quote->status, ['sent_to_cashier', 'accepted'], true);
        $canOpenInPos = ! in_array($quote->status, ['converted', 'rejected', 'expired', 'collected'], true);

        return [
            'status' => $quote->status,
            'status_label' => $quote->status_label,
            'is_expired' => $quote->isExpired(),
            'actions' => [
                'can_edit' => in_array($quote->status, ['draft', 'sent'], true),
                'can_open_in_pos' => $canOpenInPos,
                'can_send_to_deposit' => $canSendToDeposit,
                'can_send_to_cashier' => $canSendToCashier,
                'can_mark_collected' => $canMarkCollected,
                'can_convert' => $quote->canBeConverted(),
                'can_delete' => $quote->status !== 'converted',
            ],
            'links' => [
                'quote' => "/sales-quotes/{$quote->id}",
                'edit' => "/sales-quotes/{$quote->id}/edit",
                'customer' => $quote->customer_id ? "/customers/{$quote->customer_id}" : null,
                'converted_order' => $quote->converted_order_id ? "/sales-orders/{$quote->converted_order_id}" : null,
                'pos' => $canOpenInPos ? '/pos' : null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(SalesQuote $quote): array
    {
        return [
            [
                'type' => 'ticket',
                'label' => 'Ticket',
                'mode' => 'standard',
                'href' => "/api/v1/sales-quotes/{$quote->id}/ticket?mode=standard",
            ],
            [
                'type' => 'proforma',
                'label' => 'Proforma',
                'mode' => 'standard',
                'href' => "/api/v1/sales-quotes/{$quote->id}/proforma?mode=standard",
            ],
            [
                'type' => 'ticket',
                'label' => 'Ticket ciego',
                'mode' => 'blind',
                'href' => "/api/v1/sales-quotes/{$quote->id}/ticket?mode=blind",
            ],
            [
                'type' => 'proforma',
                'label' => 'Proforma ciega',
                'mode' => 'blind',
                'href' => "/api/v1/sales-quotes/{$quote->id}/proforma?mode=blind",
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertedOrderContext(SalesQuote $quote): ?array
    {
        if (! $quote->convertedOrder) {
            return null;
        }

        return [
            'id' => $quote->convertedOrder->id,
            'order_number' => $quote->convertedOrder->order_number,
            'status' => $quote->convertedOrder->status,
            'payment_status' => $quote->convertedOrder->payment_status,
            'total' => $this->money($quote->convertedOrder->total),
            'sent_to_warehouse_at' => $this->dateTime($quote->convertedOrder->sent_to_warehouse_at),
            'prepared_at' => $this->dateTime($quote->convertedOrder->prepared_at),
            'created_at' => $this->dateTime($quote->convertedOrder->created_at),
            'href' => "/sales-orders/{$quote->convertedOrder->id}",
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(SalesQuote $quote): array
    {
        $events = collect([
            [
                'type' => 'created',
                'label' => 'Cotizacion creada',
                'occurred_at' => $this->dateTime($quote->created_at),
                'source' => 'sales_quote',
            ],
        ]);

        if ($quote->convertedOrder?->sent_to_warehouse_at) {
            $events->push([
                'type' => 'sent_to_deposit',
                'label' => 'Enviada a deposito',
                'occurred_at' => $this->dateTime($quote->convertedOrder->sent_to_warehouse_at),
                'source' => 'sales_order',
                'href' => "/sales-orders/{$quote->convertedOrder->id}",
            ]);
        }

        if ($quote->sent_to_cashier_at) {
            $events->push([
                'type' => 'sent_to_cashier',
                'label' => 'Enviada a caja',
                'occurred_at' => $this->dateTime($quote->sent_to_cashier_at),
                'source' => 'sales_quote',
            ]);
        }

        if ($quote->status === 'collected') {
            $events->push([
                'type' => 'collected',
                'label' => 'Marcada como cobrada',
                'occurred_at' => $this->dateTime($quote->updated_at),
                'source' => 'sales_quote',
            ]);
        }

        if ($quote->status === 'converted' && $quote->convertedOrder) {
            $events->push([
                'type' => 'converted',
                'label' => 'Convertida a orden de venta',
                'occurred_at' => $this->dateTime($quote->convertedOrder->created_at ?? $quote->updated_at),
                'source' => 'sales_order',
                'href' => "/sales-orders/{$quote->convertedOrder->id}",
            ]);
        }

        if ($quote->isExpired()) {
            $events->push([
                'type' => 'expired',
                'label' => 'Validez vencida',
                'occurred_at' => $this->dateTime($quote->valid_until),
                'source' => 'sales_quote',
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => filled($event['occurred_at'] ?? null))
            ->sortBy('occurred_at')
            ->values()
            ->all();
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function dateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)->toJSON();
    }
}
