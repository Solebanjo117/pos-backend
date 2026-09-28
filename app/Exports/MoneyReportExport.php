<?php

namespace App\Exports;

use Generator;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class MoneyReportExport extends DefaultValueBinder implements FromGenerator, WithCustomValueBinder, WithHeadings
{
    public function __construct(private Builder $query, private array $columns, private array $summary, private ?\Closure $map = null) {}

    public function headings(): array
    {
        return array_values($this->columns);
    }

    public function generator(): Generator
    {
        foreach ($this->query->cursor() as $record) {
            $row = $this->map ? ($this->map)($record) : (array) $record;
            yield array_map(fn ($key) => $this->safeValue($row[$key] ?? null), array_keys($this->columns));
        }
        yield [];
        yield ['RESUMEN COMPLETO (mismos filtros)'];
        foreach ($this->summary as $key => $value) {
            if (is_scalar($value) || $value === null) {
                yield [$this->safeValue($key), $this->safeValue($value)];
            }
        }
    }

    private function safeValue(mixed $value): mixed
    {
        return is_string($value) && ! is_numeric($value) && preg_match('/^\s*[=+@\-\t\r]/', $value) ? "'".$value : $value;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
