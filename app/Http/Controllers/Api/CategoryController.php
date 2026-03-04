<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
     * Store a newly created category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:categories,slug',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'default_attributes' => 'nullable|array',
            'default_attributes.*.name' => 'required_with:default_attributes|string|max:100',
            'default_attributes.*.required' => 'nullable|boolean',
            'icon' => 'nullable|string|max:50',
            'priority' => 'nullable|integer',
        ]);

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
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:100|unique:categories,slug,' . $category->id,
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'default_attributes' => 'nullable|array',
            'default_attributes.*.name' => 'required_with:default_attributes|string|max:100',
            'default_attributes.*.required' => 'nullable|boolean',
            'icon' => 'nullable|string|max:50',
            'priority' => 'nullable|integer',
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category): JsonResponse
    {
        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with associated products'
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }
}
