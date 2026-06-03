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
            ['key' => 'categorias_padre', 'label' => 'Categorias Padre', 'type' => 'text'],
            ['key' => 'categoria_padre', 'label' => 'Categoría Padre', 'type' => 'searchable', 'endpoint' => '/categories/all'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'type' => 'text'],
            ['key' => 'imagen_url', 'label' => 'Imagen URL', 'type' => 'text'],
            ['key' => 'margen_default', 'label' => 'Margen Default %', 'type' => 'number'],
            ['key' => 'formula_precio', 'label' => 'Fórmula Precio', 'type' => 'text'],
            ['key' => 'aplica_itbm', 'label' => 'Aplica ITBM', 'type' => 'text'],
            ['key' => 'itbm_porcentaje', 'label' => 'ITBM %', 'type' => 'number'],
            ['key' => 'prioridad', 'label' => 'Prioridad', 'type' => 'number'],
            ['key' => 'precio_minimo_sugerido', 'label' => 'Precio Minimo Sugerido', 'type' => 'number'],
            ['key' => 'upsale_path', 'label' => 'Path Upsale', 'type' => 'text'],
            ['key' => 'atributos_default', 'label' => 'Atributos Default', 'type' => 'text'],
        ];
    }

    public function getExampleRow(): array
    {
        return [
            'nombre' => 'Frenos',
            'slug' => '',
            'categorias_padre' => 'Filtros, Sistema Combustible',
            'categoria_padre' => 'Auto',
            'descripcion' => 'Partes del sistema de frenado',
            'imagen_url' => 'https://example.com/frenos.jpg',
            'margen_default' => 30,
            'formula_precio' => 'costo * 1.3',
            'aplica_itbm' => 'si',
            'itbm_porcentaje' => 7,
            'prioridad' => 10,
            'precio_minimo_sugerido' => '',
            'upsale_path' => 'motor/encendido',
            'atributos_default' => 'posicion:requerido,largo:15cm',
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
            'categorias_padre', 'parent_categories', 'parents' => 'categorias_padre',
            'categoria_padre', 'parent_category', 'parent' => 'categoria_padre',
            'descripcion', 'description' => 'descripcion',
            'imagen_url', 'image_url' => 'imagen_url',
            'margen_default', 'default_margin', 'margen' => 'margen_default',
            'formula_precio', 'pricing_formula', 'formula' => 'formula_precio',
            'aplica_itbm', 'applies_itbm' => 'aplica_itbm',
            'itbm_porcentaje', 'itbm_percent', 'itbm_percentage' => 'itbm_porcentaje',
            'prioridad', 'priority' => 'prioridad',
            'precio_minimo_sugerido', 'min_suggested_price', 'precio_minimo' => 'precio_minimo_sugerido',
            'upsale_path', 'path_upsale', 'categoria_upsale', 'category_upsale' => 'upsale_path',
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

        try {
            $this->parseDefaultAttributes($row['atributos_default'] ?? null);
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }

        $slugRaw = $this->nullIfEmpty($row['slug'] ?? null);
        if ($slugRaw !== null) {
            $slug = $this->normalizeCategorySlug($slugRaw);
            if ($slug === '') {
                $errors[] = 'El slug no es valido.';
            } else {
                $existing = $this->findExistingCategory($row, $this->findExistingParentId($row));
                $slugOwner = Category::query()
                    ->whereRaw('LOWER(slug) = ?', [mb_strtolower($slug)])
                    ->first();

                if ($slugOwner && (! $existing || $slugOwner->id !== $existing->id)) {
                    $errors[] = "El slug '{$slug}' ya existe en la categoria '{$slugOwner->name}'. Use otro slug.";
                }
            }
        }

        return $errors;
    }

    protected function getWarningsForRow(array $row): array
    {
        $attributesRaw = $this->nullIfEmpty($row['atributos_default'] ?? null);
        if ($attributesRaw !== null && (str_starts_with($attributesRaw, '[') || str_starts_with($attributesRaw, '{'))) {
            return ['La fila usa JSON legacy en atributos_default; se acepta por compatibilidad.'];
        }

        return [];
    }

    public function processRow(array $row): mixed
    {
        $parentIds = $this->resolveParentIdsForProcessing($row);
        $parentId = $parentIds[0] ?? null;

        $existing = $this->findExistingCategory($row, $parentId);
        $slug = $this->resolveSlugForRow($row, $existing);
        $appliesItbm = $this->parseBoolean($row['aplica_itbm'] ?? null);

        $data = [
            'name' => $row['nombre'],
            'parent_id' => $parentId,
            'description' => $this->nullIfEmpty($row['descripcion'] ?? null),
            'image_url' => $this->nullIfEmpty($row['imagen_url'] ?? null),
            'default_margin_percent' => $this->parseMoney($row['margen_default'] ?? null),
            'pricing_formula' => $this->nullIfEmpty($row['formula_precio'] ?? null),
            'applies_itbm' => $appliesItbm,
            'itbm_percent' => $this->parseMoney($row['itbm_porcentaje'] ?? null),
            'priority' => $this->parseInteger($row['prioridad'] ?? null),
            'min_suggested_price' => $this->parseMoney($row['precio_minimo_sugerido'] ?? null),
            'upsale_path' => $this->nullIfEmpty($row['upsale_path'] ?? null),
        ];

        if ($slug !== null) {
            $data['slug'] = $slug;
        }

        $uiAttributes = $row['__attributes'] ?? [];
        if (is_array($uiAttributes) && ! empty($uiAttributes)) {
            $data['default_attributes'] = array_values(array_filter(
                $uiAttributes,
                fn ($a) => ! empty($a['name'])
            ));
        } else {
            $attributesRaw = $this->nullIfEmpty($row['atributos_default'] ?? null);
            if ($attributesRaw) {
                $data['default_attributes'] = $this->parseDefaultAttributes($attributesRaw);
            }
        }

        if ($existing) {
            $existing->update(array_filter($data, fn ($v) => $v !== null));
            $existing->syncParents($parentIds);

            return $existing;
        }

        $category = Category::create(array_filter($data, fn ($v) => $v !== null));
        $category->syncParents($parentIds);

        return $category;
    }

    private function findExistingParentId(array $row): ?int
    {
        $parentNames = $this->parseParentNames($row);
        $parentName = $parentNames[0] ?? null;

        if ($parentName === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $parentName) === 1) {
            return Category::query()->whereKey((int) $parentName)->value('id');
        }

        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($parentName)])
            ->value('id');
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentIdsForProcessing(array $row): array
    {
        $parentIds = [];

        foreach ($this->parseParentNames($row) as $parentName) {
            $parentId = $this->resolveIdOrCreateByName(Category::class, 'name', $parentName);

            if (! in_array($parentId, $parentIds, true)) {
                $parentIds[] = $parentId;
            }
        }

        return $parentIds;
    }

    /**
     * @return array<int, string>
     */
    private function parseParentNames(array $row): array
    {
        $multipleParents = $this->nullIfEmpty($row['categorias_padre'] ?? null);
        $legacyParent = $this->nullIfEmpty($row['categoria_padre'] ?? null);
        $rawValue = $multipleParents ?? $legacyParent;

        if ($rawValue === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (string $parentName): string => trim($parentName),
                preg_split('/[,>]+/', (string) $rawValue) ?: []
            ),
            fn (string $parentName): bool => $parentName !== ''
        ));
    }

    private function findExistingCategory(array $row, ?int $parentId): ?Category
    {
        return Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) ($row['nombre'] ?? '')))])
            ->when($parentId, fn ($query) => $query->where('parent_id', $parentId))
            ->first();
    }

    private function resolveSlugForRow(array $row, ?Category $existing): ?string
    {
        $slugRaw = $this->nullIfEmpty($row['slug'] ?? null);
        if ($slugRaw !== null) {
            return $this->normalizeCategorySlug($slugRaw);
        }

        if ($existing) {
            return null;
        }

        return $this->makeUniqueCategorySlug($this->normalizeCategorySlug($row['nombre'] ?? 'categoria'));
    }

    private function normalizeCategorySlug(mixed $value): string
    {
        return Str::slug((string) $value);
    }

    private function makeUniqueCategorySlug(string $baseSlug): string
    {
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'categoria';
        $slug = $baseSlug;
        $suffix = 2;

        while (Category::query()->whereRaw('LOWER(slug) = ?', [mb_strtolower($slug)])->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    protected function getLabelColumn(): string
    {
        return 'nombre';
    }
}
