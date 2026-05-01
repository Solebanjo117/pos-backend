<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChangelogEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole(['admin', 'manager']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'category' => [
                'required',
                'string',
                Rule::in(['operations', 'imports', 'catalog', 'pos', 'pricing', 'stability', 'general']),
            ],
            'version' => ['nullable', 'string', 'max:40'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El titulo de la nota es obligatorio.',
            'title.max' => 'El titulo no debe superar 120 caracteres.',
            'body.required' => 'La descripcion del cambio es obligatoria.',
            'body.max' => 'La descripcion no debe superar 1000 caracteres.',
            'category.required' => 'La categoria es obligatoria.',
            'category.in' => 'La categoria seleccionada no es valida.',
            'version.max' => 'La version no debe superar 40 caracteres.',
        ];
    }
}
