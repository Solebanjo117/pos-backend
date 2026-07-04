<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;

class ProductImportVendorResolver
{
    public function hasVendorPayload(array $row): bool
    {
        return $this->clean($row['vendor'] ?? null) !== null
            || $this->clean($row['vendor_id'] ?? null) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function validateRow(array $row): array
    {
        if (! $this->hasVendorPayload($row)) {
            return [];
        }

        $vendorId = $this->clean($row['vendor_id'] ?? null);
        $vendorName = $this->clean($row['vendor'] ?? null);
        $supplierById = $vendorId !== null ? $this->findByVendorId($vendorId) : null;
        $supplierByName = $vendorName !== null ? $this->findByName($vendorName) : null;

        if ($vendorId !== null && $supplierById === null && $supplierByName === null) {
            return ["No existe proveedor para Vendor ID '{$vendorId}'."];
        }

        if ($vendorId === null && $vendorName !== null && $supplierByName === null) {
            return ["No existe proveedor '{$vendorName}'."];
        }

        if ($supplierById !== null && $supplierByName !== null && (int) $supplierById->id !== (int) $supplierByName->id) {
            return ["Vendor ID '{$vendorId}' no coincide con proveedor '{$vendorName}'."];
        }

        if ($vendorId !== null && $supplierByName !== null) {
            $externalId = $this->clean($supplierByName->vendor_external_id);
            if ($externalId !== null && mb_strtolower($externalId) !== mb_strtolower($vendorId)) {
                return ["Proveedor '{$vendorName}' ya tiene otro Vendor ID ({$externalId})."];
            }
        }

        return [];
    }

    public function resolve(array $row): ?Supplier
    {
        if (! $this->hasVendorPayload($row)) {
            return null;
        }

        $vendorId = $this->clean($row['vendor_id'] ?? null);
        $vendorName = $this->clean($row['vendor'] ?? null);
        $supplier = $vendorId !== null ? $this->findByVendorId($vendorId) : null;
        $supplier ??= $vendorName !== null ? $this->findByName($vendorName) : null;

        if ($supplier instanceof Supplier && $vendorId !== null && $this->clean($supplier->vendor_external_id) === null) {
            $supplier->forceFill(['vendor_external_id' => $vendorId])->save();
        }

        return $supplier;
    }

    /**
     * @return array{0: ProductSupplier, 1: bool}
     */
    public function sync(Product $product, ?ProductVariant $variant, Supplier $supplier, ?float $supplierCost): array
    {
        $query = ProductSupplier::query()
            ->where('supplier_id', $supplier->id);

        if ($variant instanceof ProductVariant) {
            $query->where('variant_id', $variant->id);
        } else {
            $query->where('product_id', $product->id)->whereNull('variant_id');
        }

        $productSupplier = $query->first();
        $created = false;
        $payload = [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'supplier_id' => $supplier->id,
            'supplier_cost' => $supplierCost ?? 0,
            'lead_time_days' => (int) ($supplier->lead_time_days ?? 0),
            'minimum_order_quantity' => 1,
            'is_preferred' => true,
        ];

        if ($productSupplier instanceof ProductSupplier) {
            $productSupplier->fill($payload)->save();
        } else {
            $productSupplier = ProductSupplier::query()->create($payload);
            $created = true;
        }

        return [$productSupplier, $created];
    }

    private function findByVendorId(string $vendorId): ?Supplier
    {
        $supplier = Supplier::query()
            ->whereRaw('LOWER(vendor_external_id) = ?', [mb_strtolower($vendorId)])
            ->first();

        if ($supplier instanceof Supplier) {
            return $supplier;
        }

        return ctype_digit($vendorId)
            ? Supplier::query()->find((int) $vendorId)
            : null;
    }

    private function findByName(string $vendorName): ?Supplier
    {
        return Supplier::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($vendorName)])
            ->first();
    }

    private function clean(mixed $value): ?string
    {
        $clean = trim((string) ($value ?? ''));

        return $clean === '' ? null : $clean;
    }
}
