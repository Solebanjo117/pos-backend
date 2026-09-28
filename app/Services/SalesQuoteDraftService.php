<?php

namespace App\Services;

use App\Models\ProductPricing;
use App\Models\SalesQuote;
use App\Models\User;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesQuoteDraftService
{
    public function __construct(private InventoryFifoService $inventoryFifoService) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{quote: SalesQuote, created: bool, conflict: bool}
     */
    public function save(string $draftUuid, array $data, User $user): array
    {
        try {
            return DB::transaction(fn (): array => $this->saveLocked($draftUuid, $data, $user));
        } catch (QueryException $exception) {
            if (SalesQuote::withTrashed()->where('idempotency_key', $draftUuid)->exists()) {
                return DB::transaction(fn (): array => $this->saveLocked($draftUuid, $data, $user));
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{quote: SalesQuote, created: bool, conflict: bool}
     */
    private function saveLocked(string $draftUuid, array $data, User $user): array
    {
        $warehouseId = (int) $data['warehouse_id'];
        $requestHash = hash('sha256', json_encode(collect($data)->except(['quote_id', 'expected_revision'])->all(), JSON_THROW_ON_ERROR));
        if (! $user->hasWarehouseAccess($warehouseId)
            || (! $user->isAdmin() && (int) $user->getEffectiveWarehouseId() !== $warehouseId)) {
            throw new AuthorizationException('La cotización no pertenece a tu sucursal activa.');
        }

        $keyQuote = SalesQuote::withTrashed()->where('idempotency_key', $draftUuid)->lockForUpdate()->first();
        $quote = isset($data['quote_id'])
            ? SalesQuote::withTrashed()->whereKey($data['quote_id'])->lockForUpdate()->firstOrFail()
            : $keyQuote;

        if ($keyQuote && $quote && $keyQuote->id !== $quote->id) {
            throw new AuthorizationException('La clave de este borrador pertenece a otra cotización.');
        }

        if ($quote) {
            if ((int) $quote->warehouse_id !== $warehouseId) {
                throw new AuthorizationException('La cotización no pertenece a tu sucursal activa.');
            }
            if ($quote->idempotency_key !== null && $quote->idempotency_key !== $draftUuid) {
                throw new AuthorizationException('La clave del borrador no corresponde a esta cotización.');
            }
            if ($quote->trashed()
                || ! in_array($quote->status, ['draft', 'sent'], true)
                || $quote->converted_order_id !== null) {
                return $this->conflict($quote);
            }

            if ((int) $quote->draft_revision !== (int) $data['expected_revision']) {
                if ((int) $quote->draft_revision === (int) $data['expected_revision'] + 1
                    && hash_equals((string) $quote->draft_request_hash, $requestHash)) {
                    return ['quote' => $this->loadQuote($quote), 'created' => false, 'conflict' => false];
                }

                return $this->conflict($quote);
            }
        } elseif ((int) $data['expected_revision'] !== 0) {
            return ['quote' => new SalesQuote, 'created' => false, 'conflict' => true];
        }

        $items = $this->prepareItems($data['items'], $warehouseId, $user);
        $vehicleContext = $this->canonicalVehicleContext($data['vehicle_context'] ?? null);

        if (! $quote) {
            $quote = SalesQuote::create([
                'idempotency_key' => $draftUuid,
                'draft_revision' => 0,
                'user_id' => $data['seller_id'] ?? $user->id,
                'warehouse_id' => $warehouseId,
                'date' => now(),
                'valid_until' => null,
                'status' => 'draft',
                'customer_id' => $data['customer_id'],
                'customer_vehicle_id' => $data['customer_vehicle_id'],
                'vehicle_context' => $vehicleContext,
                'notes' => $data['notes'],
                'internal_notes' => $data['internal_notes'],
            ]);
            $created = true;
        } else {
            $quote->update([
                'idempotency_key' => $draftUuid,
                ...(! empty($data['seller_id']) ? ['user_id' => $data['seller_id']] : []),
                'customer_id' => $data['customer_id'],
                'customer_vehicle_id' => $data['customer_vehicle_id'],
                'vehicle_context' => $vehicleContext,
                'notes' => $data['notes'],
                'internal_notes' => $data['internal_notes'],
            ]);
            $quote->items()->delete();
            $created = false;
        }

        foreach ($items as $item) {
            $quote->items()->create($item);
        }

        $quote->calculateTotals();
        $quote->update([
            'draft_revision' => (int) $quote->draft_revision + 1,
            'draft_request_hash' => $requestHash,
        ]);

        return [
            'quote' => $this->loadQuote($quote),
            'created' => $created,
            'conflict' => false,
        ];
    }

    /** @return array{quote: SalesQuote, created: bool, conflict: bool} */
    private function conflict(SalesQuote $quote): array
    {
        return ['quote' => $this->loadQuote($quote), 'created' => false, 'conflict' => true];
    }

    private function loadQuote(SalesQuote $quote): SalesQuote
    {
        return $quote->load(['customer', 'customerVehicle.model.make', 'customerVehicle.engine', 'user', 'items.product', 'items.variant']);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, int|string>|null
     */
    private function canonicalVehicleContext(?array $context): ?array
    {
        if (! $context || empty($context['make_id'])) {
            return null;
        }

        $make = VehicleMake::query()->findOrFail($context['make_id']);
        $result = ['make_id' => $make->id, 'make_name' => $make->name];
        if (! empty($context['model_id'])) {
            $model = VehicleModel::query()->findOrFail($context['model_id']);
            $result['model_id'] = $model->id;
            $result['model_name'] = $model->name;
        }
        if (! empty($context['engine_id'])) {
            $engine = VehicleEngine::query()->findOrFail($context['engine_id']);
            $result['engine_id'] = $engine->id;
            $result['engine_name'] = $engine->full_description ?: $engine->engine_code;
        }
        if (! empty($context['year'])) {
            $result['year'] = (int) $context['year'];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function prepareItems(array $items, int $warehouseId, User $user): array
    {
        $prepared = [];
        foreach ($items as $index => $item) {
            $isCustom = (bool) ($item['is_custom_line'] ?? false);
            $productId = $isCustom ? null : (int) $item['product_id'];
            $variantId = $isCustom || empty($item['variant_id']) ? null : (int) $item['variant_id'];
            $unitPrice = (float) $item['unit_price'];

            if (! $isCustom && ! in_array($user->role, ['admin', 'manager'], true)) {
                $systemPrice = $this->systemSalePrice($productId, $variantId, $warehouseId);
                if ($systemPrice !== null) {
                    $unitPrice = $systemPrice;
                }
            }

            $gross = round((int) $item['quantity'] * $unitPrice, 2);
            $fixedDiscount = $item['discount_amount'] ?? null;
            if ($fixedDiscount !== null && round((float) $fixedDiscount, 2) > $gross) {
                throw ValidationException::withMessages([
                    "items.{$index}.discount_amount" => 'El descuento no puede superar el importe bruto de la línea.',
                ]);
            }

            $prepared[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $unitPrice,
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                ...($fixedDiscount === null ? [] : ['discount_amount' => round((float) $fixedDiscount, 2)]),
                'tax_percent' => (int) $item['tax_percent'],
                'notes' => $item['notes'] ?? null,
                'is_custom_line' => $isCustom,
                'custom_description' => $isCustom ? trim((string) $item['custom_description']) : null,
            ];
        }

        return $prepared;
    }

    private function systemSalePrice(int $productId, ?int $variantId, int $warehouseId): ?float
    {
        $fifoPrice = $this->inventoryFifoService->currentFifoPricing($productId, $variantId, $warehouseId);
        if (($fifoPrice['active_lot_id'] ?? null) !== null) {
            return (float) $fifoPrice['current_sale_price'];
        }

        $query = ProductPricing::query();
        if ($variantId) {
            $query->where('variant_id', $variantId);
        } else {
            $query->where('product_id', $productId)->whereNull('variant_id');
        }

        $price = $query->value('base_price');

        return $price !== null ? (float) $price : null;
    }
}
