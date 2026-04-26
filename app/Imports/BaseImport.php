<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

abstract class BaseImport
{
    public array $results = [];

    public array $errors = [];

    public int $successCount = 0;

    public int $errorCount = 0;

    abstract public function getColumns(): array;

    abstract public function getExampleRow(): array;

    abstract public function validateRow(array $row): array;

    abstract public function processRow(array $row): mixed;

    abstract public function getEntityLabel(): string;

    public function preview(UploadedFile $file, int $maxRows = 200): array
    {
        $rows = $this->extractRows($file);

        return $rows
            ->take($maxRows)
            ->map(function (array $row): array {
                $rowErrors = $this->validateRow($row);
                $rowWarnings = $this->getWarningsForRow($row);

                return [
                    'row' => $row['__row'],
                    'data' => $this->mapRowToPreview($row, $rowErrors, $rowWarnings),
                    'is_valid' => empty($rowErrors),
                    'errors' => $rowErrors,
                    'warnings' => $rowWarnings,
                ];
            })
            ->values()
            ->all();
    }

    public function execute(array $rows): void
    {
        foreach ($rows as $row) {
            $rowNum = (int) ($row['__row'] ?? 0);

            try {
                $result = DB::transaction(function () use ($row) {
                    $rowErrors = $this->validateRow($row);
                    if (! empty($rowErrors)) {
                        throw new \RuntimeException(implode(' ', $rowErrors));
                    }

                    return $this->processRow($row);
                });

                $this->successCount++;
                $this->results[] = [
                    'row' => $rowNum,
                    'status' => 'ok',
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'id' => $result instanceof Model ? $result->id : ($result['id'] ?? null),
                ];
            } catch (\Throwable $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row' => $rowNum,
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    public function import(UploadedFile $file): void
    {
        $this->execute($this->extractRows($file)->all());
    }

    protected function getLabelColumn(): string
    {
        return 'name';
    }

    protected function getWarningsForRow(array $row): array
    {
        return [];
    }

    protected function mapRowToPreview(array $row, ?array $errors = null, ?array $warnings = null): array
    {
        $columns = $this->getColumns();
        $mapped = [];

        foreach ($columns as $col) {
            $key = $col['key'];
            $mapped[$key] = $row[$key] ?? '';
        }

        $mapped['__row'] = $row['__row'] ?? 0;
        $mapped['__valid'] = empty($errors ?? $this->validateRow($row));
        $mapped['__errors'] = $errors ?? $this->validateRow($row);
        $mapped['__warnings'] = $warnings ?? $this->getWarningsForRow($row);

        return $mapped;
    }

    protected function extractRows(UploadedFile $file): Collection
    {
        $sheet = Excel::toCollection(null, $file)->first() ?? collect();

        if ($sheet->isEmpty()) {
            return collect();
        }

        $rawHeaders = $sheet->shift();
        $headers = collect($rawHeaders?->toArray() ?? [])
            ->map(fn ($header) => $this->normalizeHeader((string) $header))
            ->all();

        $rows = collect();
        foreach ($sheet->values() as $index => $row) {
            $values = $row->toArray();
            $assoc = [];

            foreach ($headers as $position => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $this->cleanCell($values[$position] ?? null);
            }

            $assoc['__row'] = $index + 2;

            if ($this->isEmptyDataRow($assoc)) {
                continue;
            }

            $rows->push($assoc);
        }

        return $rows;
    }

    protected function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = Str::ascii($normalized);
        $normalized = str_replace([' ', '-', '.'], '_', $normalized);

        return $this->mapHeader($normalized);
    }

    protected function mapHeader(string $header): string
    {
        return $header;
    }

    protected function cleanCell(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    protected function parseMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9,.\-]/', '', (string) $value);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace(',', '', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function parseInteger(mixed $value): ?int
    {
        $parsed = $this->parseMoney($value);

        return $parsed === null ? null : (int) round($parsed);
    }

    protected function nullIfEmpty(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    protected function isEmptyDataRow(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === '__row') {
                continue;
            }
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    protected function resolveOrCreate(string $modelClass, string $nameColumn, string $name, array $extraData = []): int
    {
        $existing = $modelClass::query()
            ->whereRaw("LOWER({$nameColumn}) = ?", [mb_strtolower(trim($name))])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        $data = array_merge([$nameColumn => trim($name)], $extraData);

        if ($modelClass === \App\Models\Category::class && empty($data['slug'])) {
            $data['slug'] = Str::slug($name);
        }

        $instance = $modelClass::create($data);

        return $instance->id;
    }

    protected function resolveIdOrCreateByName(string $modelClass, string $nameColumn, mixed $value, array $extraData = []): int
    {
        $isIntegerId = is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)));

        if ($isIntegerId) {
            $existing = $modelClass::query()->find((int) $value);
            if ($existing) {
                return $existing->id;
            }

            throw new \RuntimeException('El registro seleccionado no existe.');
        }

        return $this->resolveOrCreate($modelClass, $nameColumn, (string) $value, $extraData);
    }

    protected function resolveExistingIdByNameOrCode(string $modelClass, mixed $value, array $columns = ['name', 'code']): int
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            throw new \RuntimeException('El registro seleccionado no existe.');
        }

        if (preg_match('/^\d+$/', $normalizedValue) === 1) {
            $existing = $modelClass::query()->find((int) $normalizedValue);
            if ($existing) {
                return $existing->id;
            }

            throw new \RuntimeException('El registro seleccionado no existe.');
        }

        $existing = $modelClass::query()
            ->where(function ($query) use ($columns, $normalizedValue) {
                foreach ($columns as $column) {
                    $query->orWhereRaw("LOWER({$column}) = ?", [mb_strtolower($normalizedValue)]);
                }
            })
            ->first();

        if (! $existing) {
            throw new \RuntimeException("No se encontro el registro '{$normalizedValue}'.");
        }

        return $existing->id;
    }

    protected function splitDelimitedValues(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => trim((string) $item) !== ''));
        }

        $normalized = str_replace(["\r\n", "\r", "\n", ';', '|'], ',', (string) $value);

        return array_values(array_filter(
            array_map('trim', explode(',', $normalized)),
            fn ($item) => $item !== ''
        ));
    }

    protected function parseBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $lower = Str::ascii(mb_strtolower(trim((string) $value)));
        $ascii = $lower;

        if (in_array($lower, ['si', 'yes', '1', 'true'], true) || in_array($ascii, ['si', 'yes', '1', 'true'], true)) {
            return true;
        }

        if (in_array($lower, ['no', '0', 'false'], true) || in_array($ascii, ['no', '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    protected function normalizeStatus(mixed $value, string $default = 'active'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return $default;
        }

        $normalized = Str::ascii(mb_strtolower($raw));

        return match ($normalized) {
            'active', 'activo', 'activa', 'enabled', 'habilitado', 'habilitada' => 'active',
            'inactive', 'inactivo', 'inactiva', 'disabled', 'deshabilitado', 'deshabilitada' => 'inactive',
            default => $raw,
        };
    }

    protected function parseKeyValuePairs(mixed $value): array
    {
        $pairs = [];

        foreach ($this->splitDelimitedValues($value) as $item) {
            if (preg_match('/^([^:=]+)\s*[:=]\s*(.*)$/u', (string) $item, $matches) === 1) {
                $pairs[] = [
                    'key' => trim($matches[1]),
                    'value' => trim($matches[2]),
                ];
            } else {
                $pairs[] = [
                    'key' => trim((string) $item),
                    'value' => null,
                ];
            }
        }

        return array_values(array_filter($pairs, fn ($pair) => $pair['key'] !== ''));
    }

    protected function parseDefaultAttributes(mixed $value): array
    {
        $decoded = $this->decodeJsonLikeValue($value);
        if ($decoded !== null) {
            return $this->normalizeDefaultAttributesFromArray($decoded);
        }

        return collect($this->parseKeyValuePairs($value))
            ->map(function (array $pair): ?array {
                $name = trim((string) $pair['key']);
                if ($name === '') {
                    return null;
                }

                $attribute = ['name' => $name];
                $rawValue = trim((string) ($pair['value'] ?? ''));

                if ($rawValue !== '') {
                    if ($this->isRequiredMarker($rawValue)) {
                        $attribute['required'] = true;
                    } else {
                        [$cleanValue, $unit] = $this->splitValueAndUnit($rawValue);
                        $attribute['default_value'] = $cleanValue;
                        $attribute['value'] = $cleanValue;
                        if ($unit !== null) {
                            $attribute['unit'] = $unit;
                        }
                    }
                }

                return $attribute;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function parseProductAttributes(mixed $value): array
    {
        $decoded = $this->decodeJsonLikeValue($value);
        if ($decoded !== null) {
            return $this->normalizeProductAttributesFromArray($decoded);
        }

        return collect($this->parseKeyValuePairs($value))
            ->map(function (array $pair): ?array {
                $name = trim((string) $pair['key']);
                $rawValue = trim((string) ($pair['value'] ?? ''));

                if ($name === '' || $rawValue === '') {
                    return null;
                }

                [$cleanValue, $unit] = $this->splitValueAndUnit($rawValue);
                $attribute = [
                    'name' => $name,
                    'value' => $cleanValue,
                ];

                if ($unit !== null) {
                    $attribute['unit'] = $unit;
                }

                return $attribute;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function decodeJsonLikeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (! str_starts_with($raw, '[') && ! str_starts_with($raw, '{')) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('El formato JSON no es valido.');
        }

        return $decoded;
    }

    private function normalizeDefaultAttributesFromArray(array $attributes): array
    {
        if ($this->isAssociativeArray($attributes)) {
            $attributes = collect($attributes)
                ->map(fn ($value, $name) => ['name' => $name, 'value' => $value])
                ->values()
                ->all();
        }

        return collect($attributes)
            ->map(function ($attribute): ?array {
                if (! is_array($attribute)) {
                    $attribute = ['name' => $attribute];
                }

                $name = trim((string) ($attribute['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                $normalized = ['name' => $name];
                if (array_key_exists('required', $attribute)) {
                    $normalized['required'] = (bool) $attribute['required'];
                }

                $rawValue = $attribute['default_value'] ?? $attribute['value'] ?? null;
                if ($rawValue !== null && trim((string) $rawValue) !== '') {
                    [$cleanValue, $unit] = $this->splitValueAndUnit($rawValue);
                    $normalized['default_value'] = $cleanValue;
                    $normalized['value'] = $cleanValue;
                    if ($unit !== null) {
                        $normalized['unit'] = $unit;
                    }
                } elseif (! empty($attribute['unit'])) {
                    $normalized['unit'] = trim((string) $attribute['unit']);
                }

                return $normalized;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeProductAttributesFromArray(array $attributes): array
    {
        if ($this->isAssociativeArray($attributes)) {
            $attributes = collect($attributes)
                ->map(fn ($value, $name) => ['name' => $name, 'value' => $value])
                ->values()
                ->all();
        }

        return collect($attributes)
            ->map(function ($attribute): ?array {
                if (! is_array($attribute)) {
                    return null;
                }

                $name = trim((string) ($attribute['name'] ?? ''));
                $value = trim((string) ($attribute['value'] ?? ''));
                if ($name === '' || $value === '') {
                    return null;
                }

                [$cleanValue, $unit] = $this->splitValueAndUnit($value);
                $normalized = [
                    'name' => $name,
                    'value' => $cleanValue,
                ];

                $explicitUnit = trim((string) ($attribute['unit'] ?? ''));
                if ($explicitUnit !== '') {
                    $normalized['unit'] = $explicitUnit;
                } elseif ($unit !== null) {
                    $normalized['unit'] = $unit;
                }

                return $normalized;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function splitValueAndUnit(mixed $value): array
    {
        $raw = trim((string) $value);

        if (preg_match('/^(-?\d+(?:[.,]\d+)?)\s*([[:alpha:]%]+)$/u', $raw, $matches) === 1) {
            return [str_replace(',', '.', $matches[1]), $matches[2]];
        }

        return [$raw, null];
    }

    private function isRequiredMarker(string $value): bool
    {
        $normalized = Str::ascii(mb_strtolower(trim($value)));

        return in_array($normalized, ['required', 'requerido', 'obligatorio', 'si', 'true', '1'], true);
    }

    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
