<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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

                return [
                    'row' => $row['__row'],
                    'data' => $this->mapRowToPreview($row),
                    'is_valid' => empty($rowErrors),
                    'errors' => $rowErrors,
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
                $rowErrors = $this->validateRow($row);
                if (! empty($rowErrors)) {
                    throw new \RuntimeException(implode(' ', $rowErrors));
                }

                $result = $this->processRow($row);
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

    protected function getLabelColumn(): string
    {
        return 'name';
    }

    protected function mapRowToPreview(array $row): array
    {
        $columns = $this->getColumns();
        $mapped = [];

        foreach ($columns as $col) {
            $key = $col['key'];
            $mapped[$key] = $row[$key] ?? '';
        }

        $mapped['__row'] = $row['__row'] ?? 0;
        $mapped['__valid'] = empty($this->validateRow($row));
        $mapped['__errors'] = $this->validateRow($row);

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

        if ($modelClass === \App\Models\Brand::class && empty($data['prefix'])) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
            $basePrefix = $prefix;
            $suffix = 1;
            while ($modelClass::where('prefix', $prefix)->exists()) {
                $prefix = substr($basePrefix, 0, 2).$suffix;
                $suffix++;
            }
            $data['prefix'] = $prefix;
        }

        if ($modelClass === \App\Models\Category::class && empty($data['slug'])) {
            $data['slug'] = Str::slug($name);
        }

        $instance = $modelClass::create($data);

        return $instance->id;
    }

    protected function parseBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $lower = mb_strtolower(trim((string) $value));

        return in_array($lower, ['si', 'sí', 'yes', '1', 'true'], true);
    }
}
