<?php

namespace App\Http\Controllers\Api;

use App\Helpers\TaxRateHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\CategoryMedia;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\PricingFormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     * Can return flat list or hierarchical tree based on query parameter.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('tree')) {
            $categories = Category::with('parents')
                ->orderBy('priority')
                ->get();

            return response()->json($this->buildCategoryTree($categories));
        }

        if ($request->boolean('with_parent')) {
            $categories = Category::with(['parent', 'parents'])
                ->orderBy('parent_id')
                ->orderBy('priority')
                ->get();

            return response()->json($categories);
        }

        $categories = Category::with('parents')->orderBy('priority')->get();

        return response()->json($categories);
    }

    /**
     * Get all categories (for dropdowns).
     */
    public function all(): JsonResponse
    {
        $categories = Category::with('parents')
            ->orderBy('name')
            ->select('id', 'name', 'parent_id', 'image_url')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $parentIds = $this->resolveParentIds($validated);
        unset($validated['parent_ids']);
        $validated['parent_id'] = $parentIds[0] ?? null;

        // Normalizar y validar default_attributes
        if (isset($validated['default_attributes'])) {
            $validated['default_attributes'] = $this->normalizeDefaultAttributes($validated['default_attributes']);
        }

        $validated['itbm_percent'] = TaxRateHelper::resolve($validated['itbm_percent'] ?? null);

        $category = DB::transaction(function () use ($validated, $parentIds): Category {
            $category = Category::create($validated);
            $category->syncParents($parentIds);

            return $category;
        });

        return response()->json($category->load(['parent', 'parents']), 201);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'parents', 'childrenThroughParents', 'products']);

        return response()->json($category);
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $validated = $request->validated();
        $syncParents = $request->has('parent_ids') || $request->has('parent_id');
        $parentIds = $syncParents ? $this->resolveParentIds($validated) : [];
        unset($validated['parent_ids']);

        if ($syncParents) {
            $validated['parent_id'] = $parentIds[0] ?? null;
        }

        $oldDefaults = $category->default_attributes ?? [];

        // Normalizar y validar default_attributes
        if (isset($validated['default_attributes'])) {
            $validated['default_attributes'] = $this->normalizeDefaultAttributes($validated['default_attributes']);
        }

        if (array_key_exists('itbm_percent', $validated)) {
            $validated['itbm_percent'] = TaxRateHelper::resolve($validated['itbm_percent']);
        }

        DB::transaction(function () use ($category, $validated, $syncParents, $parentIds): void {
            $category->update($validated);

            if ($syncParents) {
                $category->syncParents($parentIds);
            }
        });

        // Propagate attribute name renames to existing products
        if (array_key_exists('default_attributes', $validated)) {
            $newDefaults = $validated['default_attributes'] ?? [];
            $this->propagateAttributeRenames($category, $oldDefaults, $newDefaults);
        }

        return response()->json($category->fresh()->load(['parent', 'parents']));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoryTree($categories): array
    {
        $childrenByParent = [];

        foreach ($categories as $category) {
            foreach ($category->parent_ids as $parentId) {
                $childrenByParent[$parentId] ??= [];
                $childrenByParent[$parentId][] = $category;
            }
        }

        return $categories
            ->filter(fn (Category $category): bool => empty($category->parent_ids))
            ->sortBy('priority')
            ->map(fn (Category $category): array => $this->buildCategoryTreeNode($category, $childrenByParent))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, Category>>  $childrenByParent
     * @param  array<int, bool>  $visited
     * @return array<string, mixed>
     */
    private function buildCategoryTreeNode(Category $category, array $childrenByParent, array $visited = []): array
    {
        $visited[$category->id] = true;
        $node = $category->toArray();
        $children = collect($childrenByParent[$category->id] ?? [])
            ->reject(fn (Category $child): bool => isset($visited[$child->id]))
            ->sortBy('priority')
            ->map(fn (Category $child): array => $this->buildCategoryTreeNode($child, $childrenByParent, $visited))
            ->values()
            ->all();

        $node['children'] = $children;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function resolveParentIds(array $validated): array
    {
        if (array_key_exists('parent_ids', $validated) && is_array($validated['parent_ids'])) {
            return Category::normalizeParentIds($validated['parent_ids']);
        }

        if (array_key_exists('parent_id', $validated)) {
            return Category::normalizeParentIds([$validated['parent_id']]);
        }

        return [];
    }

    /**
     * When a category's default_attributes names change, rename the matching
     * product_attributes on all products that belong to this category.
     * Matching is index-based: position 0 old → position 0 new, etc.
     */
    private function propagateAttributeRenames(Category $category, array $oldDefaults, array $newDefaults): void
    {
        $renames = [];

        $count = min(count($oldDefaults), count($newDefaults));
        for ($i = 0; $i < $count; $i++) {
            $oldName = trim($oldDefaults[$i]['name'] ?? '');
            $newName = trim($newDefaults[$i]['name'] ?? '');

            if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
                $renames[$oldName] = $newName;
            }
        }

        if (empty($renames)) {
            return;
        }

        $productIds = Product::where('category_id', $category->id)
            ->orWhereHas('extraCategories', function ($query) use ($category) {
                $query->where('categories.id', $category->id);
            })
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($productIds, $renames) {
            foreach ($renames as $oldName => $newName) {
                ProductAttribute::whereIn('product_id', $productIds)
                    ->where('name', $oldName)
                    ->update(['name' => $newName]);
            }
        });
    }

    /**
     * Preview the calculated price for a given formula and cost.
     * Used by the frontend for real-time formula previews.
     * POST /api/v1/categories/preview-price
     * Body: { formula: string, costo: number, applies_itbm: boolean, itbm_percent: number }
     */
    public function previewPrice(Request $request, PricingFormulaService $formulaService): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'itbm_percent');

        $data = $request->validate([
            'formula' => 'required|string|max:500',
            'costo' => 'required|numeric|min:0',
            'applies_itbm' => 'nullable|boolean',
            'itbm_percent' => 'nullable|integer|min:0|max:100',
        ]);

        $price = $formulaService->calculate(
            $data['formula'],
            (float) $data['costo'],
            (bool) ($data['applies_itbm'] ?? false),
            (float) TaxRateHelper::resolve($data['itbm_percent'] ?? null)
        );

        if ($price === null) {
            return response()->json(['price' => null, 'error' => 'Fórmula inválida']);
        }

        return response()->json(['price' => $price, 'error' => null]);
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category): JsonResponse
    {
        // Check if category has products
        $hasProducts = $category->products()->exists()
            || Product::whereHas('extraCategories', function ($query) use ($category) {
                $query->where('categories.id', $category->id);
            })->exists();

        if ($hasProducts) {
            return response()->json([
                'message' => 'Cannot delete category with associated products',
            ], 422);
        }

        $this->deleteAllCategoryMedia($category);
        $this->deleteStoredCategoryImage($category->getRawOriginal('image_url'));
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }

    /**
     * Get category image.
     */
    public function getImages(Category $category): JsonResponse
    {
        $images = $category->media()
            ->where('media_type', 'image')
            ->orderBy('display_order')
            ->get()
            ->map(fn (CategoryMedia $media) => [
                'id' => $media->id,
                'url' => $media->url,
                'file_path' => $media->file_path,
                'original_filename' => $media->original_filename,
                'display_order' => $media->display_order,
            ])
            ->values()
            ->all();

        $rawImagePath = $category->getRawOriginal('image_url');
        if (! empty($rawImagePath) && is_string($rawImagePath)) {
            $legacyExists = collect($images)->contains(fn (array $image) => $image['file_path'] === $rawImagePath);
            if (! $legacyExists) {
                $images[] = [
                    'id' => null,
                    'url' => $category->image_url,
                    'file_path' => $rawImagePath,
                    'original_filename' => basename($rawImagePath),
                    'display_order' => count($images),
                ];
            }
        }

        return response()->json([
            'data' => $images,
        ]);
    }

    /**
     * Upload category image.
     */
    public function uploadImage(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $files = $this->resolveUploadFiles($request);
        if (empty($files)) {
            return response()->json([
                'message' => 'Debes enviar al menos una imagen',
            ], 422);
        }

        $uploaded = $this->addCategoryImages($category, $files);
        $category->refresh();

        return response()->json([
            'message' => count($uploaded).' imagen(es) de categoría subidas correctamente',
            'data' => $uploaded,
        ]);
    }

    /**
     * Delete category image.
     */
    public function deleteImage(Category $category, ?int $media = null): JsonResponse
    {
        if ($media === null) {
            $this->deleteAllCategoryMedia($category);
            $this->deleteStoredCategoryImage($category->getRawOriginal('image_url'));
            $category->update(['image_url' => null]);

            return response()->json([
                'message' => 'Imágenes de categoría eliminadas correctamente',
            ]);
        }

        $categoryMedia = $category->media()
            ->where('media_type', 'image')
            ->where('id', $media)
            ->first();

        if (! $categoryMedia) {
            return response()->json([
                'message' => 'Imagen no encontrada',
            ], 404);
        }

        $wasThumb = $category->getRawOriginal('image_url') === $categoryMedia->file_path;
        $this->deleteStoredCategoryImage($categoryMedia->file_path);
        $categoryMedia->delete();

        if ($wasThumb) {
            $this->syncCategoryPrimaryImageFromMedia($category);
        }

        return response()->json([
            'message' => 'Imagen de categoría eliminada correctamente',
        ]);
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function resolveUploadFiles(Request $request): array
    {
        $files = $request->file('images');
        if (is_array($files)) {
            return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
        }

        $singleFile = $request->file('image') ?? $request->file('images');

        return $singleFile instanceof UploadedFile ? [$singleFile] : [];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function addCategoryImages(Category $category, array $files): array
    {
        $maxOrder = $category->media()
            ->where('media_type', 'image')
            ->max('display_order') ?? -1;

        $uploaded = [];

        foreach ($files as $file) {
            $path = $file->store('categories', 'public');
            $maxOrder++;

            $media = $category->media()->create([
                'media_type' => 'image',
                'category' => 'photo',
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'display_order' => $maxOrder,
            ]);

            $uploaded[] = [
                'id' => $media->id,
                'url' => $media->url,
                'file_path' => $media->file_path,
                'original_filename' => $media->original_filename,
                'display_order' => $media->display_order,
            ];
        }

        if (! $category->getRawOriginal('image_url') && ! empty($uploaded)) {
            $category->update(['image_url' => $uploaded[0]['file_path']]);
        }

        return $uploaded;
    }

    private function deleteAllCategoryMedia(Category $category): void
    {
        $categoryMedia = $category->media()
            ->where('media_type', 'image')
            ->get();

        foreach ($categoryMedia as $media) {
            $this->deleteStoredCategoryImage($media->file_path);
            $media->delete();
        }
    }

    private function syncCategoryPrimaryImageFromMedia(Category $category): void
    {
        $nextImage = $category->media()
            ->where('media_type', 'image')
            ->orderBy('display_order')
            ->first();

        $category->update(['image_url' => $nextImage?->file_path]);
    }

    private function deleteStoredCategoryImage(mixed $imagePath): void
    {
        if (! is_string($imagePath) || $imagePath === '') {
            return;
        }

        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            return;
        }

        if (Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
    }

    /**
     * Normaliza y valida los atributos por defecto de categoría.
     * - Asigna type = 'string' si no está presente
     * - Rechaza select sin opciones válidas
     * - Limpia options si type != 'select'
     */
    private function normalizeDefaultAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $attribute) {
            $type = $attribute['type'] ?? 'string';
            $options = $attribute['options'] ?? null;

            // Normalizar type a string por defecto
            if (! in_array($type, ['string', 'select'], true)) {
                $type = 'string';
            }

            // Validar select con opciones válidas
            if ($type === 'select') {
                if (! is_array($options) || count($options) === 0) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator([], []),
                        response()->json([
                            'message' => 'El atributo "'.$attribute['name'].'" tipo select debe tener al menos una opción válida',
                            'errors' => [
                                'default_attributes' => ['Los atributos tipo select requieren opciones válidas'],
                            ],
                        ], 422)
                    );
                }

                // Filtrar opciones vacías
                $options = array_values(array_filter(
                    array_map('trim', $options),
                    fn ($opt) => $opt !== ''
                ));

                if (count($options) === 0) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator([], []),
                        response()->json([
                            'message' => 'El atributo "'.$attribute['name'].'" tipo select debe tener al menos una opción válida',
                            'errors' => [
                                'default_attributes' => ['Los atributos tipo select requieren opciones válidas'],
                            ],
                        ], 422)
                    );
                }
            } else {
                // Limpiar options si no es select
                $options = null;
            }

            $normalized[] = [
                'name' => $attribute['name'],
                'type' => $type,
                'options' => $options,
                'required' => $attribute['required'] ?? false,
                'unit' => $attribute['unit'] ?? null,
                'value' => $attribute['value'] ?? null,
                'default_value' => $attribute['default_value'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeIntegerPercentField(Request $request, string $field): void
    {
        if (! $request->has($field)) {
            return;
        }

        $value = $request->input($field);
        if (is_numeric($value)) {
            $request->merge([$field => (int) round((float) $value)]);
        }
    }
}
