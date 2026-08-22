<?php

namespace App\Exports;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SupplierCatalogExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new ArraySheet('Categorias', ['ID', 'NOMBRE', 'PADRES', 'PRODUCTOS'], Category::query()->with('parents:id,name')->withCount('products')->orderBy('name')->get()->map(fn ($category) => [$category->id, $category->name, $category->parents->pluck('name')->implode(' | '), $category->products_count])->all()),
            new ArraySheet('Marcas', ['ID', 'NOMBRE', 'ESTADO', 'PROVEEDORES', 'VARIANTES'], Brand::query()->with('suppliers:id,name')->withCount('productVariants')->orderBy('name')->get()->map(fn ($brand) => [$brand->id, $brand->name, $brand->status, $brand->suppliers->pluck('name')->implode(' | '), $brand->product_variants_count])->all()),
            new ArraySheet('Proveedores', ['ID', 'NOMBRE', 'CONTACTO', 'EMAIL', 'TELEFONO', 'ESTADO', 'MARCAS'], Supplier::query()->with('brands:id,name')->orderBy('name')->get()->map(fn ($supplier) => [$supplier->id, $supplier->name, $supplier->contact_name, $supplier->email, $supplier->phone, $supplier->status, $supplier->brands->pluck('name')->implode(' | ')])->all()),
        ];
    }
}
