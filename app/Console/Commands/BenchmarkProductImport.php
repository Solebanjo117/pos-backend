<?php

namespace App\Console\Commands;

use App\Imports\ProductImport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportRun;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BenchmarkProductImport extends Command
{
    protected $signature = 'products:import-benchmark {--rows=500,1500,5000 : Cantidades de filas separadas por coma}';

    protected $description = 'Mide validacion, queries, memoria y serializacion XLSX del importador de productos';

    public function handle(ProductService $productService): int
    {
        $category = Category::query()->first();
        $brand = Brand::query()->first();

        if ($category === null || $brand === null) {
            $this->error('Se requiere al menos una categoria y una marca existentes para ejecutar el benchmark.');

            return self::FAILURE;
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->table(
            ['Filas', 'Validacion ms', 'Queries', 'Memoria pico MB', 'XLSX ms'],
            collect(explode(',', (string) $this->option('rows')))
                ->map(fn (string $rows): int => max(1, (int) trim($rows)))
                ->map(function (int $rows) use ($productService, $category, $brand, &$queryCount): array {
                    $queryCount = 0;
                    $payload = collect(range(1, $rows))
                        ->map(fn (int $index): array => [
                            '__row' => $index + 1,
                            'nombre' => "Benchmark {$index}",
                            'sku' => "BENCH-{$rows}-{$index}",
                            'system_id' => "BENCH-SYS-{$rows}-{$index}",
                            'brand' => $brand->name,
                            'categoria' => $category->name,
                        ])
                        ->all();

                    $startedAt = microtime(true);
                    $import = (new ProductImport($productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);
                    $import->validateRows($payload);
                    $validationMilliseconds = (microtime(true) - $startedAt) * 1000;

                    $xlsxMilliseconds = $this->xlsxSerializationMilliseconds($import->validationRows);

                    return [
                        $rows,
                        number_format($validationMilliseconds, 2),
                        $queryCount,
                        number_format(memory_get_peak_usage(true) / 1024 / 1024, 2),
                        number_format($xlsxMilliseconds, 2),
                    ];
                })
                ->all()
        );

        return self::SUCCESS;
    }

    private function xlsxSerializationMilliseconds(array $rows): float
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Fila', 'Producto', 'Errores'], null, 'A1');

        foreach ($rows as $index => $row) {
            $sheet->fromArray([
                $row['row'] ?? '',
                $row['label'] ?? ($row['data']['nombre'] ?? ''),
                implode(' | ', $row['errors'] ?? []),
            ], null, 'A'.($index + 2));
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'product-import-benchmark-');
        $path = $temporaryPath.'.xlsx';
        @unlink($temporaryPath);
        $startedAt = microtime(true);
        (new Xlsx($spreadsheet))->save($path);
        $milliseconds = (microtime(true) - $startedAt) * 1000;
        @unlink($path);

        return $milliseconds;
    }
}
