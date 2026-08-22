<?php

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBrandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name'))) ?? '']);
        }
    }

    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'logo_url' => 'nullable|url|max:500',
            'status' => 'nullable|string|in:active,inactive',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
            'supplier_ids' => 'sometimes|array',
            'supplier_ids.*' => 'integer|distinct|exists:suppliers,id',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $brandId = (int) ($this->route('id') ?? 0);
            $duplicate = Brand::query()
                ->where('normalized_name', Brand::normalizeName((string) $this->input('name')))
                ->when($brandId, fn ($query) => $query->whereKeyNot($brandId))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('name', 'Ya existe una marca con ese nombre.');
            }
        }];
    }
}
