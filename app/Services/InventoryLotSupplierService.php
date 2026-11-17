<?php

namespace App\Services;

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proveedor de un lote FIFO. Los lotes de compras lo traen de la compra; los demás (existencia
 * inicial, ajustes, devoluciones) quedan "sin proveedor" y se les puede cargar después a mano.
 */
class InventoryLotSupplierService
{
    /** Orígenes cuyo proveedor viene de la compra y no se cambia a mano. */
    private const PURCHASE_SOURCES = ['purchase_order', 'supply_list'];

    public function __construct(private readonly VariantSupplierService $variantSuppliers) {}

    public static function canAssign(InventoryLot $lot): bool
    {
        return ! in_array($lot->source_type, self::PURCHASE_SOURCES, true);
    }

    public function assign(InventoryLot $lot, ?int $supplierId, ?User $user = null): InventoryLot
    {
        if (! self::canAssign($lot)) {
            abort(422, 'Este lote viene de una compra: su proveedor es el de la compra.');
        }

        return DB::transaction(function () use ($lot, $supplierId, $user): InventoryLot {
            $previous = $lot->supplier_id;
            $lot->forceFill(['supplier_id' => $supplierId])->save();

            // Si la marca aún no tiene ese proveedor, se agrega con el costo del lote.
            if ($supplierId && $lot->variant_id) {
                $variant = ProductVariant::query()->find($lot->variant_id);
                $alreadyLinked = $variant?->suppliers()->whereKey($supplierId)->exists();
                if ($variant && ! $alreadyLinked) {
                    $this->variantSuppliers->upsert($variant, $supplierId, ['supplier_cost' => (float) $lot->unit_cost_base]);
                }
            }

            activity()
                ->performedOn($lot)
                ->causedBy($user)
                ->withProperties(['supplier_id' => ['old' => $previous, 'new' => $supplierId]])
                ->log('Proveedor del lote actualizado');

            return $lot->fresh(['supplier:id,name']);
        });
    }
}
