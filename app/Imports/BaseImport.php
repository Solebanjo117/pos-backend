<?php

namespace App\Imports;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

abstract class BaseImport
{
    public array $results = [];

    public array $errors = [];

    public array $validationRows = [];

    public int $successCount = 0;

    public int $errorCount = 0;

    public int $processedCount = 0;

    abstract public function getColumns(): array;

    abstract public function getExampleRow(): array;

    abstract public function validateRow(array $row): array;

    abstract public function processRow(array $row): mixed;

    abstract public function getEntityLabel(): string;

    public function preview(UploadedFile $file, int $maxRows = 200, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $rows = $this->extractRows($file, $sheetIndex, $sheetName, $mapping, $maxRows);

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
            $this->processedCount++;
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
                    'data' => $row,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    public function validateRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->processedCount++;
            $rowNum = (int) ($row['__row'] ?? 0);

            try {
                $rowErrors = $this->validateRow($row);
                $rowWarnings = $this->getWarningsForRow($row);

                $this->validationRows[] = [
                    'row' => $rowNum,
                    'data' => $this->mapRowToPreview($row, $rowErrors, $rowWarnings),
                    'is_valid' => empty($rowErrors),
                    'errors' => $rowErrors,
                    'warnings' => $rowWarnings,
                ];

                if (! empty($rowErrors)) {
                    throw new \RuntimeException(implode(' ', $rowErrors));
                }

                $this->successCount++;
                $this->results[] = [
                    'row' => $rowNum,
                    'status' => 'ok',
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'id' => null,
                ];
            } catch (\Throwable $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row' => $rowNum,
                    'label' => $row[$this->getLabelColumn()] ?? '',
                    'data' => $row,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    public function import(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): void
    {
        $this->importInChunks($file, 500, null, $sheetIndex, $sheetName, $mapping);
    }

    public function importInChunks(
        UploadedFile|string $file,
        int $chunkSize = 500,
        ?callable $afterChunk = null,
        ?int $sheetIndex = null,
        ?string $sheetName = null,
        array $mapping = []
    ): void {
        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);
        $totalRows = (int) ($selectedSheet['row_count'] ?? 0);
        $chunk = [];

        foreach ($this->extractRowsLazy($file, (int) $selectedSheet['index'], (string) $selectedSheet['name'], $mapping) as $row) {
            $chunk[] = $row;

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $this->execute($chunk);
            $chunk = [];

            if ($afterChunk !== null) {
                $shouldContinue = $afterChunk($this, $this->processedCount, $totalRows);
                if ($shouldContinue === false) {
                    return;
                }
            }
        }

        if ($chunk === []) {
            return;
        }

        $this->execute($chunk);

        if ($afterChunk !== null) {
            $afterChunk($this, $this->processedCount, $totalRows);
        }
    }

    public function validateInChunks(
        UploadedFile|string $file,
        int $chunkSize = 500,
        ?callable $afterChunk = null,
        ?int $sheetIndex = null,
        ?string $sheetName = null,
        array $mapping = []
    ): void {
        $selectedSheet = $this->resolveSelectedSheet($file, $sheetIndex, $sheetName, $mapping);
        $totalRows = (int) ($selectedSheet['row_count'] ?? 0);
        $chunk = [];

        foreach ($this->extractRowsLazy($file, (int) $selectedSheet['index'], (string) $selectedSheet['name'], $mapping) as $row) {
            $chunk[] = $row;

            if (count($chunk) < $chunkSize) {
                continue;
            }

            $this->validateRows($chunk);
            $chunk = [];

            if ($afterChunk !== null) {
                $shouldContinue = $afterChunk($this, $this->processedCount, $totalRows);
                if ($shouldContinue === false) {
                    return;
                }
            }
        }

        if ($chunk === []) {
            return;
        }

        $this->validateRows($chunk);

        if ($afterChunk !== null) {
            $afterChunk($this, $this->processedCount, $totalRows);
        }
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

    public function inspectSheets(UploadedFile|string $file, array $mapping = []): array
    {
        $sheetInfo = $this->sheetInfo($file);
        $sheets = [];

        foreach ($sheetInfo as $index => $info) {
            $rawHeaders = $this->sheetHeader($file, $info['worksheetName'] ?? null);
            $sourceColumns = collect($rawHeaders)
                ->map(function ($header): array {
                    $label = trim((string) $header);

                    return [
                        'key' => $this->normalizeRawHeader($label),
                        'label' => $label,
                    ];
                })
                ->filter(fn (array $column): bool => $column['key'] !== '')
                ->values();
            $sourceHeaders = $sourceColumns
                ->pluck('key')
                ->all();
            $sourceHeaderLabels = $sourceColumns
                ->mapWithKeys(fn (array $column): array => [$column['key'] => $column['label']])
                ->all();
            $headers = collect($sourceHeaders)
                ->map(fn (string $header) => $this->applyHeaderMapping($this->mapHeader($header), $header, $mapping))
                ->filter(fn (string $header) => $header !== '')
                ->values()
                ->all();
            $rowCount = max((int) ($info['totalRows'] ?? 0) - 1, 0);
            $analysis = $this->analyzeSheetHeaders($headers, $rowCount);

            $sheets[] = [
                'index' => $index,
                'name' => $info['worksheetName'] ?? 'Hoja '.($index + 1),
                'row_count' => $rowCount,
                'headers' => $headers,
                'source_headers' => $sourceHeaders,
                'source_header_labels' => $sourceHeaderLabels,
                'matched_headers' => $analysis['matched_headers'],
                'missing_required_headers' => $analysis['missing_required_headers'],
                'score' => $analysis['score'],
                'is_compatible' => $analysis['is_compatible'],
                'is_recommended' => false,
            ];
        }

        $recommendedIndex = $this->recommendedSheetIndex($sheets);
        foreach ($sheets as $position => $sheet) {
            $sheets[$position]['is_recommended'] = $sheet['index'] === $recommendedIndex;
        }

        return $sheets;
    }

    public function resolveSelectedSheet(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = []): array
    {
        $sheets = $this->inspectSheets($file, $mapping);
        $resolvedIndex = $this->resolveSheetIndex($sheets, $sheetIndex, $sheetName);

        return $sheets[$resolvedIndex] ?? throw new \RuntimeException('La hoja seleccionada no existe en el archivo.');
    }

    protected function extractRows(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = [], ?int $maxRows = null): Collection
    {
        return collect(iterator_to_array($this->extractRowsLazy($file, $sheetIndex, $sheetName, $mapping, $maxRows), false));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function extractRowsLazy(UploadedFile|string $file, ?int $sheetIndex = null, ?string $sheetName = null, array $mapping = [], ?int $maxRows = null): Generator
    {
        $sheets = $this->inspectSheets($file, $mapping);
        $resolvedIndex = $this->resolveSheetIndex($sheets, $sheetIndex, $sheetName);
        $resolvedSheetName = (string) ($sheets[$resolvedIndex]['name'] ?? '');
        $headers = null;
        $sourceHeaders = null;
        $yielded = 0;

        foreach ($this->sheetRows($file, $resolvedSheetName, $maxRows) as $physicalRow => $values) {
            if ($headers === null) {
                $sourceHeaders = collect($values ?? [])
                    ->map(fn ($header): string => $this->normalizeRawHeader((string) $header))
                    ->all();
                $headers = collect($sourceHeaders)
                    ->map(function ($header) use ($mapping): string {
                        $sourceHeader = (string) $header;

                        return $this->applyHeaderMapping($this->mapHeader($sourceHeader), $sourceHeader, $mapping);
                    })
                    ->all();

                continue;
            }

            $assoc = [];
            $sourceData = [];

            foreach ($headers as $position => $header) {
                $sourceHeader = $sourceHeaders[$position] ?? null;
                if (is_string($sourceHeader) && $sourceHeader !== '') {
                    $sourceData[$sourceHeader] = $this->cleanCell($values[$position] ?? null);
                }

                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $this->cleanCell($values[$position] ?? null);
            }

            $assoc['__row'] = $physicalRow;
            $assoc['__source_data'] = $sourceData;

            if ($this->isEmptyDataRow($assoc)) {
                continue;
            }

            yield $assoc;

            $yielded++;
            if ($maxRows !== null && $yielded >= $maxRows) {
                return;
            }
        }
    }

    /**
     * @return array{matched_headers: array<int, string>, missing_required_headers: array<int, string>, score: int, is_compatible: bool}
     */
    protected function analyzeSheetHeaders(array $headers, int $rowCount): array
    {
        return [
            'matched_headers' => $headers,
            'missing_required_headers' => [],
            'score' => $rowCount > 0 ? 1 : 0,
            'is_compatible' => $rowCount > 0,
        ];
    }

    protected function recommendedSheetIndex(array $sheets): int
    {
        return 0;
    }

    private function resolveSheetIndex(array $sheets, ?int $sheetIndex = null, ?string $sheetName = null): int
    {
        if ($sheetName !== null && trim($sheetName) !== '') {
            $normalizedSheetName = mb_strtolower(trim($sheetName));
            foreach ($sheets as $sheet) {
                if (mb_strtolower((string) $sheet['name']) === $normalizedSheetName) {
                    return (int) $sheet['index'];
                }
            }

            if ($this->hasSingleUnnamedSheet($sheets)) {
                return (int) $sheets[0]['index'];
            }

            throw new \RuntimeException("La hoja '{$sheetName}' no existe en el archivo.");
        }

        if ($sheetIndex !== null) {
            foreach ($sheets as $sheet) {
                if ((int) $sheet['index'] === $sheetIndex) {
                    return $sheetIndex;
                }
            }

            throw new \RuntimeException("La hoja seleccionada #{$sheetIndex} no existe en el archivo.");
        }

        return $this->recommendedSheetIndex($sheets);
    }

    /**
     * CSV/TXT readers expose one unnamed sheet because the format has no sheet metadata.
     *
     * @param  array<int, array<string, mixed>>  $sheets
     */
    private function hasSingleUnnamedSheet(array $sheets): bool
    {
        return count($sheets) === 1 && trim((string) ($sheets[0]['name'] ?? '')) === '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sheetInfo(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (! is_string($path) || $path === '') {
            return [];
        }

        if (! $this->supportsOpenSpout($path)) {
            return $this->legacySheetInfo($path);
        }

        $reader = null;

        try {
            $reader = $this->openSpoutReaderForFile($path);
            $reader->open($path);
            $sheets = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $totalRows = 0;
                $totalColumns = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $totalRows++;
                    $totalColumns = max($totalColumns, $row->getNumCells());
                }

                $sheets[] = [
                    'worksheetName' => $sheet->getName(),
                    'totalRows' => $totalRows,
                    'totalColumns' => $totalColumns,
                ];
            }

            return $sheets;
        } catch (\Throwable) {
            return [[
                'worksheetName' => 'Hoja 1',
                'totalRows' => 0,
                'totalColumns' => 0,
            ]];
        } finally {
            if ($reader !== null) {
                $reader->close();
                unset($reader);
            }
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function sheetHeader(UploadedFile|string $file, ?string $sheetName): array
    {
        foreach ($this->sheetRows($file, (string) $sheetName, 0) as $row) {
            return $row;
        }

        return [];
    }

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function sheetRows(UploadedFile|string $file, string $sheetName, ?int $maxDataRows = null): Generator
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (! is_string($path) || $path === '') {
            return;
        }

        if (! $this->supportsOpenSpout($path)) {
            foreach ($this->legacySheetRows($path, $sheetName, $maxDataRows) as $rowNumber => $row) {
                yield $rowNumber + 1 => $row;
            }

            return;
        }

        $reader = $this->openSpoutReaderForFile($path);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheetName !== '' && $sheet->getName() !== $sheetName) {
                    continue;
                }

                $dataRows = 0;
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    if (! $row instanceof Row) {
                        continue;
                    }

                    yield (int) $rowNumber => $this->rowValues($row);

                    if ($rowNumber === 1) {
                        continue;
                    }

                    $dataRows++;
                    if ($maxDataRows !== null && $dataRows >= $maxDataRows) {
                        return;
                    }
                }

                return;
            }
        } finally {
            $reader->close();
            unset($reader);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacySheetInfo(string $path): array
    {
        try {
            return $this->readerForFile($path)->listWorksheetInfo($path);
        } catch (\Throwable) {
            return [[
                'worksheetName' => 'Hoja 1',
                'totalRows' => 0,
                'totalColumns' => 0,
            ]];
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function legacySheetRows(string $path, string $sheetName, ?int $maxDataRows = null): array
    {
        $reader = $this->readerForFile($path);
        if ($sheetName !== '') {
            $reader->setLoadSheetsOnly([$sheetName]);
        }

        if ($maxDataRows !== null) {
            $reader->setReadFilter($this->rowLimitFilter($maxDataRows + 1));
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheet(0);
        $rows = $worksheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    private function openSpoutReaderForFile(string $path): ReaderInterface
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => new CsvReader,
            'ods' => new OdsReader,
            default => new XlsxReader,
        };
    }

    private function supportsOpenSpout(string $path): bool
    {
        return in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt', 'xlsx', 'ods'], true);
    }

    /**
     * @return array<int, mixed>
     */
    private function rowValues(Row $row): array
    {
        $values = [];

        foreach ($row->cells as $position => $cell) {
            $values[(int) $position] = $cell->getValue();
        }

        if ($values === []) {
            return [];
        }

        ksort($values);

        return $values;
    }

    private function readerForFile(string $path): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader;
    }

    private function rowLimitFilter(int $maxRow): IReadFilter
    {
        return new class($maxRow) implements IReadFilter
        {
            public function __construct(private readonly int $maxRow) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return (int) $row <= $this->maxRow;
            }
        };
    }

    protected function normalizeHeader(string $header): string
    {
        return $this->mapHeader($this->normalizeRawHeader($header));
    }

    protected function normalizeRawHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = Str::ascii($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    protected function applyHeaderMapping(string $mappedHeader, string $sourceHeader, array $mapping): string
    {
        $target = $mapping[$sourceHeader] ?? $mapping[$mappedHeader] ?? null;

        if ($target === null || $target === '') {
            return $mappedHeader;
        }

        if ($target === 'ignore') {
            return '';
        }

        return (string) $target;
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
            if ($key === '__source_data') {
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
