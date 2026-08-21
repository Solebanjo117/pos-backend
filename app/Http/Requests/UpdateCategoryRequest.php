<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name'))) ?? '']);
        }
        $this->normalizeIntegerPercentField('itbm_percent');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:100|unique:categories,slug,'.$category->id,
            'parent_id' => 'nullable|exists:categories,id',
            'parent_ids' => 'nullable|array',
            'parent_ids.*' => 'integer|distinct|exists:categories,id',
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
            'upsale_path' => 'nullable|string|max:255',
            'applies_itbm' => 'nullable|boolean',
            'itbm_percent' => 'nullable|integer|min:0|max:100',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Category $category */
                $category = $this->route('category');
                $parentIds = $this->validatedParentIds();

                if (Category::parentIdsWouldCreateCycle($category->id, $parentIds)) {
                    $validator->errors()->add(
                        'parent_ids',
                        'La categoria no puede ser padre de si misma ni formar ciclos.'
                    );
                }

                $name = (string) $this->input('name', $category->name);
                $parentId = $this->has('parent_ids')
                    ? $this->input('parent_ids.0')
                    : $this->input('parent_id', $category->parent_id);
                $duplicate = Category::query()
                    ->whereKeyNot($category->id)
                    ->where('normalized_name', Category::normalizeName($name))
                    ->when($parentId, fn ($query) => $query->where('parent_id', (int) $parentId), fn ($query) => $query->whereNull('parent_id'))
                    ->exists();
                $identityChanged = Category::normalizeName($name) !== $category->normalized_name
                    || (int) ($parentId ?? 0) !== (int) ($category->parent_id ?? 0);

                if ($duplicate && $identityChanged) {
                    $validator->errors()->add('name', 'Ya existe una categoría con ese nombre bajo el mismo padre.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_ids.*.exists' => 'Una de las categorias padre seleccionadas no existe.',
            'parent_ids.*.distinct' => 'No puedes repetir la misma categoria padre.',
            'parent_id.exists' => 'La categoria padre seleccionada no existe.',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function validatedParentIds(): array
    {
        if ($this->has('parent_ids') && is_array($this->input('parent_ids'))) {
            return Category::normalizeParentIds($this->input('parent_ids'));
        }

        if ($this->has('parent_id')) {
            return Category::normalizeParentIds([$this->input('parent_id')]);
        }

        return [];
    }

    private function normalizeIntegerPercentField(string $field): void
    {
        if (! $this->has($field)) {
            return;
        }

        $value = $this->input($field);
        if (is_numeric($value)) {
            $this->merge([$field => (int) round((float) $value)]);
        }
    }
}
