<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $query = Category::query();

        // Option 1: Return only root categories with children loaded (tree structure)
        if ($request->boolean('tree')) {
            $categories = Category::whereNull('parent_id')
                ->with('descendants')
                ->orderBy('priority')
                ->get();
        }
        // Option 2: Return all categories with parent info
        elseif ($request->boolean('with_parent')) {
            $categories = Category::with('parent')
                ->orderBy('parent_id')
                ->orderBy('priority')
                ->get();
        }
        // Option 3: Return flat list (default)
        else {
            $categories = Category::orderBy('priority')->get();
        }

        return response()->json($categories);
    }

    /**
     * Get all categories (for dropdowns).
     */
    public function all(): JsonResponse
    {
        $categories = Category::orderBy('name')
            ->select('id', 'name', 'parent_id', 'image_url')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'itbm_percent');

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:categories,slug',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'default_attributes' => 'nullable|array',
            'default_attributes.*.name' => 'required_with:default_attributes|string|max:100',
            'default_attributes.*.type' => 'nullable|string|in:string,select',
            'default_attributes.*.options' => 'nullable|array',
            'default_attributes.*.options.*' => 'string|max:100',
            'default_attributes.*.required' => 'nullable|boolean',
            'default_attributes.*.unit' => 'nullable|string|max:50',
            'default_attributes.*.value' => 'nullable|string',
            'default_attributes.*.default_value' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image_url' => 'nullable|url|max:500',
            'priority' => 'nullable|integer',
            'default_margin_percent' => 'nullable|numeric|min:0|max:9999.99',
            'pricing_formula' => 'nullable|string|max:500',
            'min_suggested_price' => 'nullable|numeric|min:0',
            'applies_itbm' => 'nullable|boolean',
            'itbm_percent' => 'nullable|integer|min:0|max:100',
        ]);

        // Normalizar y validar default_attributes
        if (isset($validated['default_attributes'])) {
            $validated['default_attributes'] = $this->normalizeDefaultAttributes($validated['default_attributes']);
        }

        $category = Category::create($validated);

        return response()->json($category, 201);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children', 'products']);

        return response()->json($category);
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $this->normalizeIntegerPercentField($request, 'itbm_percent');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:100|unique:categories,slug,'.$category->id,
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'default_attributes' => 'nullable|array',
            'default_attributes.*.name' => 'required_with:default_attributes|string|max:100',
            'default_attributes.*.type' => 'nullable|string|in:string,select',
            'default_attributes.*.options' => 'nullable|array',
            'default_attributes.*.options.*' => 'string|max:100',
            'default_attributes.*.required' => 'nullable|boolean',
            'default_attributes.*.unit' => 'nullable|string|max:50',
            'default_attributes.*.value' => 'nullable|string',
            'default_attributes.*.default_value' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'image_url' => 'nullable|url|max:500',
            'priority' => 'nullable|integer',
            'default_margin_percent' => 'nullable|numeric|min:0|max:9999.99',
            'pricing_formula' => 'nullable|string|max:500',
            'min_suggested_price' => 'nullable|numeric|min:0',
            'applies_itbm' => 'nullable|boolean',
            'itbm_percent' => 'nullable|integer|min:0|max:100',
        ]);

        $oldDefaults = $category->default_attributes ?? [];

        // Normalizar y validar default_attributes
        if (isset($validated['default_attributes'])) {
            $validated['default_attributes'] = $this->normalizeDefaultAttributes($validated['default_attributes']);
        }

        $category->update($validated);

        // Propagate attribute name renames to existing products
        if (array_key_exists('default_attributes', $validated)) {
            $newDefaults = $validated['default_attributes'] ?? [];
            $this->propagateAttributeRenames($category, $oldDefaults, $newDefaults);
        }

        return response()->json($category);
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
            (float) ($data['itbm_percent'] ?? 7)
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
