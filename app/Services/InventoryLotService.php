<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\ProductInventory;
use App\Models\ProductPricing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryLotService
{
    /**
     * @param  array{received_at: string, quantity_available: int|float|string, unit_cost_base: int|float|string, tax_per_unit: int|float|string, misc_per_unit: int|float|string, sale_price: int|float|string}  $data
     */
    public function updateAvailableLot(InventoryLot $lot, array $data, ?User $user = null): InventoryLot
    {
        return DB::transaction(function () use ($lot, $data, $user): InventoryLot {
            /** @var InventoryLot $lockedLot */
            $lockedLot = InventoryLot::query()
                ->with('sourceMovement')
                ->whereKey($lot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedLot->quantity_available <= 0) {
                abort(422, 'Solo se pueden editar lotes con stock disponible.');
            }

            $oldValues = $lockedLot->only([
                'received_at',
                'unit_cost_base',
                'tax_per_unit',
                'misc_per_unit',
                'landed_unit_cost',
                'quantity_available',
            ]);

            $quantityAvailable = max(0, (int) $data['quantity_available']);
            $unitCostBase = round((float) $data['unit_cost_base'], 4);
            $taxPerUnit = round((float) $data['tax_per_unit'], 4);
            $miscPerUnit = round((float) $data['misc_per_unit'], 4);
            $salePrice = round((float) $data['sale_price'], 2);

            $lockedLot->fill([
                'received_at' => $data['received_at'],
                'quantity_available' => $quantityAvailable,
                'unit_cost_base' => $unitCostBase,
                'tax_per_unit' => $taxPerUnit,
                'misc_per_unit' => $miscPerUnit,
                'landed_unit_cost' => round($unitCostBase + $taxPerUnit + $miscPerUnit, 4),
            ]);
            $lockedLot->save();

            if ($lockedLot->sourceMovement) {
                $lockedLot->sourceMovement->forceFill([
                    'created_at' => $lockedLot->received_at,
                ])->save();
            }

            $inventory = ProductInventory::firstOrCreate(
                [
                    'product_id' => $lockedLot->product_id,
                    'variant_id' => $lockedLot->variant_id,
                    'warehouse_id' => $lockedLot->warehouse_id,
                ],
                [
                    'quantity_available' => 0,
                    'quantity_reserved' => 0,
                    'reorder_point' => 10,
                    'reorder_quantity' => 50,
                ]
            );

            $inventory->forceFill([
                'quantity_available' => $this->availableLotQuantity(
                    (int) $lockedLot->product_id,
                    $lockedLot->variant_id ? (int) $lockedLot->variant_id : null,
                    (int) $lockedLot->warehouse_id
                ),
            ])->save();

            if ($lockedLot->variant_id) {
                ProductPricing::firstOrCreate(
                    [
                        'product_id' => $lockedLot->product_id,
                        'variant_id' => $lockedLot->variant_id,
                    ],
                    [
                        'base_price' => 0,
                        'msrp' => 0,
                        'cost' => 0,
                        'margin_percentage' => 0,
                        'tax_rate' => 0,
                    ]
                )->update([
                    'base_price' => $salePrice,
                ]);
            }

            activity('inventory')
                ->causedBy($user)
                ->performedOn($lockedLot)
                ->withProperties([
                    'old' => $oldValues,
                    'new' => $lockedLot->only([
                        'received_at',
                        'unit_cost_base',
                        'tax_per_unit',
                        'misc_per_unit',
                        'landed_unit_cost',
                        'quantity_available',
                    ]),
                    'sale_price' => $salePrice,
                ])
                ->log('inventory_lot_updated');

            return $lockedLot->refresh();
        });
    }

    private function availableLotQuantity(int $productId, ?int $variantId, int $warehouseId): int
    {
        return (int) InventoryLot::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when(
                $variantId,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->whereNull('variant_id')
            )
            ->sum('quantity_available');
    }
}
