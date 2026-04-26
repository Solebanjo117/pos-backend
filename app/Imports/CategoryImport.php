<?php

namespace App\Imports;

use App\Models\Category;
use Illuminate\Support\Str;

class CategoryImport extends BaseImport
{
    public function getColumns(): array
    {
        return [
            ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
            ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
            ['key' => 'categoria_padre', 'label' => 'Categoría Padre', 'type' => 'searchable', 'endpoint' => '/categories/all'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
            ['key' => 'margen_default', 'label' => 'Margen Default %', 'type' => 'number'],
            ['key' => 'formula_precio', 'label' => 'Fórmula Precio', 'type' => 'text'],
            ['key' => 'aplica_itbm', 'label' => 'Aplica ITBM', 'type' => 'text'],
            ['key' => 'itbm_porcentaje', 'label' => 'ITBM %', 'type' => 'number'],
            ['key' => 'prioridad', 'label' => 'Prioridad', 'type' => 'number'],
            ['key' => 'atributos_default', 'label' => 'Atributos Default (JSON)', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Frenos',
            'slug' => '',
            'categoria_padre' => 'Auto',
            'descripcion' => 'Partes del sistema de frenado',
            'margen_default' => 30,
            'formula_precio' => 'costo * 1.3',
            'aplica_itbm' => 'si',
            'itbm_porcentaje' => 7,
            'prioridad' => 10,
            'atributos_default' => '[{"name":"posicion","value":""}]',
        ];
    }

    public function getEntityLabel(): string
    {
        return 'categoría';
    }

    protected function mapHeader(string $header): string
    {
        return match ($header) {
            'nombre', 'name' => 'nombre',
            'slug' => 'slug',
            'categoria_padre', 'parent_category', 'parent' => 'categoria_padre',
            'descripcion', 'description' => 'descripcion',
            'margen_default', 'default_margin', 'margen' => 'margen_default',
            'formula_precio', 'pricing_formula', 'formula' => 'formula_precio',
            'aplica_itbm', 'applies_itbm' => 'aplica_itbm',
            'itbm_porcentaje', 'itbm_percent', 'itbm_percentage' => 'itbm_porcentaje',
            'prioridad', 'priority' => 'prioridad',
            'atributos_default', 'default_attributes', 'attributes' => 'atributos_default',
            default => $header,
        };
    }

    public function validateRow(array $row): array
    {
        $errors = [];
        $name = trim((string) ($row['nombre'] ?? ''));

        if ($name === '') {
            $errors[] = 'El campo nombre es obligatorio.';
        }

        $margin = $this->parseMoney($row['margen_default'] ?? null);
        if ($margin !== null && ($margin < 0 || $margin > 100)) {
            $errors[] = 'El margen debe estar entre 0 y 100.';
        }

        return $errors;
    }

    public function processRow(array $row): mixed
    {
        $parentId = null;
        $parentName = $this->nullIfEmpty($row['categoria_padre'] ?? null);
        if ($parentName) {
            $parentId = $this->resolveIdOrCreateByName(Category::class, 'name', $parentName);
        }

        $slug = $this->nullIfEmpty($row['slug'] ?? null) ?: Str::slug($row['nombre']);
        $appliesItbm = $this->parseBoolean($row['aplica_itbm'] ?? null);

        $data = [
            'name' => $row['nombre'],
            'slug' => $slug,
            'parent_id' => $parentId,
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'default_margin_percent' => $this->parseMoney($row['margen_default'] ?? null),
            'pricing_formula' => $this->nullIfEmpty($row['formula_precio'] ?? null),
            'applies_itbm' => $appliesItbm,
            'itbm_percent' => $this->parseMoney($row['itbm_porcentaje'] ?? null),
            'priority' => $row['prioridad'] ?? null,
        ];

        // Priority 1: enriched __attributes from UI modal
        $uiAttributes = $row['__attributes'] ?? [];
        if (is_array($uiAttributes) && ! empty($uiAttributes)) {
            $data['default_attributes'] = array_values(array_filter(
                $uiAttributes,
                fn ($a) => ! empty($a['name'])
            ));
        } else {
            // Priority 2: raw JSON from Excel column (legacy / direct import)
            $attributesRaw = $this->nullIfEmpty($row['atributos_default'] ?? null);
            if ($attributesRaw) {
                $decoded = json_decode($attributesRaw, true);
                if (is_array($decoded)) {
                    $data['default_attributes'] = $decoded;
                }
            }
        }

        $existing = Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($row['nombre']))])
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId))
            ->first();

        if ($existing) {
            $existing->update(array_filter($data, fn ($v) => $v !== null));

            return $existing;
        }

        return Category::create(array_filter($data, fn ($v) => $v !== null));
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
