<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportFile implements ValidationRule
{
    private const CSV_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    private const XLS_MIME_TYPES = [
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    private const XLSX_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('El archivo enviado no es valido.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $mimeType = strtolower((string) $value->getMimeType());

        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $fail('El archivo debe ser xlsx, xls o csv.');

            return;
        }

        if ($extension === 'csv') {
            if (! in_array($mimeType, self::CSV_MIME_TYPES, true)) {
                $fail('El contenido del archivo no coincide con un CSV valido.');
            }

            return;
        }

        $allowedMimeTypes = $extension === 'xlsx'
            ? self::XLSX_MIME_TYPES
            : self::XLS_MIME_TYPES;

        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            $fail('El contenido del archivo no coincide con un Excel valido.');

            return;
        }

        if (! $this->spreadsheetTypeMatchesExtension($value, $extension)) {
            $fail('El contenido del archivo no coincide con un Excel valido.');
        }
    }

    private function spreadsheetTypeMatchesExtension(UploadedFile $file, string $extension): bool
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return false;
        }

        try {
            $readerType = IOFactory::identify($path);
        } catch (\Throwable) {
            return false;
        }

        return match ($extension) {
            'xlsx' => $readerType === 'Xlsx',
            'xls' => $readerType === 'Xls',
            default => false,
        };
    }
}
