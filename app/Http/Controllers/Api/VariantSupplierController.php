<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignInventoryLotSupplierRequest;
use App\Http\Requests\UpsertVariantSupplierRequest;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\InventoryLotSupplierService;
use App\Services\VariantSupplierService;
use Illuminate\Http\JsonResponse;

/**
 * Proveedores de una marca (variante) desde la ventana de la marca, y proveedor de un lote.
 */
class VariantSupplierController extends Controller
{
    public function __construct(
        private readonly VariantSupplierService $variantSuppliers,
        private readonly InventoryLotSupplierService $lotSuppliers,
    ) {}

    public function index(Product $product, ProductVariant $variant): JsonResponse
    {
        abort_if($variant->product_id !== $product->id, 404, 'Variante no corresponde al producto');

        return response()->json(['data' => $this->rows($variant)]);
    }

    public function upsert(UpsertVariantSupplierRequest $request, Product $product, ProductVariant $variant, Supplier $supplier): JsonResponse
    {
        abort_if($variant->product_id !== $product->id, 404, 'Variante no corresponde al producto');

        $this->variantSuppliers->upsert($variant, $supplier->id, $request->validated());

        return response()->json(['data' => $this->rows($variant), 'message' => 'Proveedor guardado']);
    }

    public function destroy(Product $product, ProductVariant $variant, Supplier $supplier): JsonResponse
    {
        abort_if($variant->product_id !== $product->id, 404, 'Variante no corresponde al producto');

        $this->variantSuppliers->remove($variant, $supplier->id);

        return response()->json(['data' => $this->rows($variant), 'message' => 'Proveedor quitado']);
    }

    public function assignLotSupplier(AssignInventoryLotSupplierRequest $request, InventoryLot $lot): JsonResponse
    {
        $lot = $this->lotSuppliers->assign($lot, $request->validated()['supplier_id'], $request->user());

        return response()->json([
            'data' => [
                'id' => $lot->id,
                'supplier' => $lot->supplier ? ['id' => $lot->supplier->id, 'name' => $lot->supplier->name] : null,
            ],
            'message' => 'Proveedor del lote actualizado',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(ProductVariant $variant): array
    {
        return $this->variantSuppliers->forVariant($variant)
            ->map(fn (ProductSupplier $link): array => [
                'supplier_id' => $link->supplier_id,
                'supplier_name' => $link->supplier?->name,
                'supplier_code' => $link->supplier_code,
                'supplier_cost' => round((float) $link->supplier_cost, 2),
                'last_purchased_at' => $link->last_purchased_at?->toIso8601String(),
                'lead_time_days' => (int) $link->lead_time_days,
                'minimum_order_quantity' => (int) $link->minimum_order_quantity,
                'is_default' => (bool) $link->is_preferred,
            ])
            ->values()
            ->all();
    }
}
