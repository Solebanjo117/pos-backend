<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFundingCategoryRequest;
use App\Http\Requests\UpdateFundingCategoryRequest;
use App\Models\FundingCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FundingCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FundingCategory::query()->orderBy('sort_order')->orderBy('name');
        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(StoreFundingCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $category = FundingCategory::query()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        activity('cash_operations')->performedOn($category)->causedBy($request->user())->log('Categoría de aportación creada');

        return response()->json(['message' => 'Categoría de aportación creada.', 'data' => $category], 201);
    }

    public function update(UpdateFundingCategoryRequest $request, FundingCategory $fundingCategory): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
            $data['slug'] = $this->uniqueSlug($data['name'], $fundingCategory->id);
        }
        $fundingCategory->update($data);
        activity('cash_operations')->performedOn($fundingCategory)->causedBy($request->user())->log('Categoría de aportación actualizada');

        return response()->json(['message' => 'Categoría de aportación actualizada.', 'data' => $fundingCategory->fresh()]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'aportacion';
        $slug = $base;
        $suffix = 2;
        while (FundingCategory::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
