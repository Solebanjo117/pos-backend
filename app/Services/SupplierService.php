<?php

namespace App\Services;

use App\Models\OperationalExpense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function create(array $data): Supplier
    {
        try {
            return DB::transaction(function () use ($data): Supplier {
                $this->assertUniqueName((string) $data['name']);
                $brandIds = $data['brand_ids'] ?? [];
                unset($data['brand_ids']);
                $supplier = Supplier::query()->create($data);
                $supplier->brands()->sync($brandIds);

                return $supplier->fresh()->load('brands:id,name');
            });
        } catch (QueryException $exception) {
            $this->throwNormalizedConflict($exception);
        }
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        try {
            return DB::transaction(function () use ($supplier, $data): Supplier {
                if (array_key_exists('name', $data)) {
                    $this->assertUniqueName((string) $data['name'], $supplier->id);
                }
                $hasBrands = array_key_exists('brand_ids', $data);
                $brandIds = $data['brand_ids'] ?? [];
                unset($data['brand_ids']);
                $supplier->update($data);
                if ($hasBrands) {
                    $supplier->brands()->sync($brandIds);
                }

                return $supplier->fresh()->load('brands:id,name');
            });
        } catch (QueryException $exception) {
            $this->throwNormalizedConflict($exception);
        }
    }

    public function deleteBlockReason(Supplier $supplier): ?string
    {
        if ($this->relationExists($supplier, 'has_purchase_orders', fn (): bool => PurchaseOrder::query()->where('supplier_id', $supplier->id)->exists())
            || $this->relationExists($supplier, 'has_purchase_order_items', fn (): bool => PurchaseOrderItem::query()->where('supplier_id', $supplier->id)->exists())) {
            return 'Tiene compras u órdenes relacionadas. Desactívalo en su lugar.';
        }
        if ($this->relationExists($supplier, 'has_product_suppliers', fn (): bool => $supplier->productSuppliers()->exists())) {
            return 'Tiene productos o variantes vinculados. Retira los vínculos o desactívalo.';
        }
        if ($this->relationExists($supplier, 'has_brands', fn (): bool => $supplier->brands()->exists())) {
            return 'Tiene marcas suministradas vinculadas. Retira los vínculos o desactívalo.';
        }
        if ($this->relationExists($supplier, 'has_operational_expenses', fn (): bool => OperationalExpense::query()->where('supplier_id', $supplier->id)->exists())) {
            return 'Tiene gastos relacionados. Desactívalo en su lugar.';
        }

        return null;
    }

    public function delete(Supplier $supplier): void
    {
        $reason = $this->deleteBlockReason($supplier);
        if ($reason !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['supplier' => $reason]);
        }
        $supplier->delete();
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $normalized = Supplier::normalizeName($name);
        $duplicate = Supplier::query()
            ->withTrashed()
            ->lockForUpdate()
            ->where('normalized_name', $normalized)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Ya existe un proveedor con ese nombre.']);
        }
    }

    private function relationExists(Supplier $supplier, string $attribute, callable $fallback): bool
    {
        if (array_key_exists($attribute, $supplier->getAttributes())) {
            return (bool) $supplier->getAttribute($attribute);
        }

        return $fallback();
    }

    private function throwNormalizedConflict(QueryException $exception): never
    {
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            abort(response()->json([
                'message' => 'Ya existe un proveedor con ese nombre.',
                'errors' => ['name' => ['Ya existe un proveedor con ese nombre.']],
            ], 409));
        }

        throw $exception;
    }
}
