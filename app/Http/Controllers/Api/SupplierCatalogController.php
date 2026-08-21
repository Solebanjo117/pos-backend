<?php

namespace App\Http\Controllers\Api;

use App\Exports\SupplierCatalogExport;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierCatalogController extends Controller
{
    public function __construct(private SupplierService $supplierService) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => Category::query()->count(),
            'brands' => Brand::query()->count(),
            'suppliers' => Supplier::query()->count(),
            'active_suppliers' => Supplier::query()->active()->count(),
        ]]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => 'required|in:category,brand,supplier',
            'action' => 'required|in:delete,deactivate',
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct',
        ]);

        if ($data['action'] === 'deactivate' && $data['entity'] === 'category') {
            return response()->json(['message' => 'Las categorías no admiten desactivación.'], 422);
        }

        $result = DB::transaction(function () use ($data): array {
            $models = match ($data['entity']) {
                'supplier' => Supplier::query()->whereKey($data['ids'])->lockForUpdate()->get(),
                'brand' => Brand::query()->whereKey($data['ids'])->lockForUpdate()->get(),
                default => Category::query()->whereKey($data['ids'])->lockForUpdate()->get(),
            };
            if ($models->count() !== count($data['ids'])) {
                return ['missing' => true];
            }

            $blocked = collect();
            foreach ($data['action'] === 'delete' ? $models : [] as $model) {
                $reason = match ($data['entity']) {
                    'supplier' => $this->supplierService->deleteBlockReason($model),
                    'brand' => $model->productVariants()->exists() || $model->suppliers()->exists()
                        ? 'La marca tiene productos, variantes o proveedores relacionados.' : null,
                    default => $model->products()->exists()
                        || Product::query()->whereHas('extraCategories', fn ($query) => $query->whereKey($model->id))->exists()
                        || $model->children()->exists() || $model->childrenThroughParents()->exists()
                        ? 'La categoría tiene productos o categorías dependientes.' : null,
                };
                if ($reason) {
                    $blocked->put($model->id, $reason);
                }
            }
            if ($blocked->isNotEmpty()) {
                return ['blocked' => $blocked];
            }

            foreach ($models as $model) {
                if ($data['action'] === 'deactivate') {
                    $model->update(['status' => 'inactive']);
                } else {
                    $model->delete();
                }
            }

            return ['affected' => $models->count()];
        });

        if (isset($result['missing'])) {
            return response()->json(['message' => 'Uno o más registros no existen.'], 422);
        }

        return isset($result['blocked'])
            ? response()->json(['message' => 'Ningún registro fue modificado.', 'blocked' => $result['blocked']], 409)
            : response()->json(['message' => 'Acción aplicada.', 'affected' => $result['affected']]);
    }

    public function export(): BinaryFileResponse
    {
        return Excel::download(new SupplierCatalogExport, 'catalogo_proveedores.xlsx');
    }
}
