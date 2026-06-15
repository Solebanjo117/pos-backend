<?php

namespace App\Console\Commands;

use App\Imports\ProductImport;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductImportRunChunkProcessor;
use App\Services\ProductImportRunRowService;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BenchmarkProductImport extends Command
{
    protected $signature = 'products:import-benchmark
        {--rows=500,1500,5000 : Cantidades de filas separadas por coma}
        {--with-import : Mide importacion all-valid dentro de una transaccion revertida}
        {--with-persisted-validation : Mide validacion paralela persistida por chunks}';

    protected $description = 'Mide fases de validacion/importacion, queries, memoria y serializacion XLSX del importador de productos';

    public function handle(
        ProductService $productService,
        ProductImportRunRowService $rowService,
        ProductImportRunChunkProcessor $chunkProcessor,
    ): int {
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
            ['Filas', 'Validacion archivo ms', 'Validacion persistida ms', 'Importacion ms', 'Queries', 'Memoria pico MB', 'Filas/s validacion', 'Filas/s persistida', 'Filas/s importacion'],
            collect(explode(',', (string) $this->option('rows')))
                ->map(fn (string $rows): int => max(1, (int) trim($rows)))
                ->map(function (int $rows) use ($productService, $rowService, $chunkProcessor, $category, $brand, &$queryCount): array {
                    $queryCount = 0;
                    $this->cleanupBenchmarkRows();
                    $path = $this->temporaryCsvPath($rows, $category->name, $brand->name);

                    $fileValidationMilliseconds = $this->fileValidationMilliseconds($productService, $path);
                    $persistedValidationMilliseconds = $this->option('with-persisted-validation')
                        ? $this->persistedValidationMilliseconds($productService, $rowService, $chunkProcessor, $path, $rows)
                        : null;
                    $importMilliseconds = $this->option('with-import')
                        ? $this->importMilliseconds($productService, $path, $rows)
                        : null;
                    $fileValidationSeconds = max(0.001, $fileValidationMilliseconds / 1000);
                    $persistedValidationSeconds = $persistedValidationMilliseconds === null ? null : max(0.001, $persistedValidationMilliseconds / 1000);
                    $importSeconds = $importMilliseconds === null ? null : max(0.001, $importMilliseconds / 1000);
                    @unlink($path);

                    return [
                        $rows,
                        number_format($fileValidationMilliseconds, 2),
                        $persistedValidationMilliseconds === null ? '-' : number_format($persistedValidationMilliseconds, 2),
                        $importMilliseconds === null ? '-' : number_format($importMilliseconds, 2),
                        $queryCount,
                        number_format(memory_get_peak_usage(true) / 1024 / 1024, 2),
                        number_format($rows / $fileValidationSeconds, 2),
                        $persistedValidationSeconds === null ? '-' : number_format($rows / $persistedValidationSeconds, 2),
                        $importSeconds === null ? '-' : number_format($rows / $importSeconds, 2),
                    ];
                })
                ->all()
        );

        return self::SUCCESS;
    }

    private function fileValidationMilliseconds(ProductService $productService, string $path): float
    {
        $import = (new ProductImport($productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);

        $startedAt = microtime(true);
        $import->validateFileInChunks($path, 500, function (ProductImport $chunkImport): void {
            $chunkImport->validationRows = [];
            $chunkImport->results = [];
            $chunkImport->errors = [];
        });

        return (microtime(true) - $startedAt) * 1000;
    }

    private function importMilliseconds(ProductService $productService, string $path, int $rows): float
    {
        $startedAt = microtime(true);
        (new ProductImport($productService))
            ->forMode(ImportRun::MODE_CREATE_UPDATE)
            ->importInChunks($path, 500, function (ProductImport $chunkImport): void {
                $chunkImport->results = [];
                $chunkImport->errors = [];
            });

        $elapsed = (microtime(true) - $startedAt) * 1000;
        $this->cleanupBenchmarkRows($rows);

        return $elapsed;
    }

    private function persistedValidationMilliseconds(
        ProductService $productService,
        ProductImportRunRowService $rowService,
        ProductImportRunChunkProcessor $chunkProcessor,
        string $path,
        int $rows,
    ): float {
        $storedPath = 'imports/product-import-benchmark.csv';
        Storage::disk('local')->put($storedPath, (string) file_get_contents($path));

        $importRun = ImportRun::query()->create([
            'entity' => 'products',
            'run_type' => ImportRun::TYPE_VALIDATION,
            'mode' => ImportRun::MODE_VALIDATE_ONLY,
            'original_filename' => 'product-import-benchmark.csv',
            'stored_path' => $storedPath,
            'status' => ImportRun::STATUS_RUNNING,
            'phase' => ImportRun::PHASE_VALIDATING,
            'total_rows' => $rows,
            'processed_rows' => 0,
            'results' => [],
            'errors' => [],
            'summary' => [
                'chunk_processing' => true,
                'parallel_validation' => true,
                'use_persisted_rows_snapshot' => true,
                'staging_status' => 'completed',
                'staged_rows_count' => $rows,
            ],
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        try {
            $import = (new ProductImport($productService))->forMode(ImportRun::MODE_VALIDATE_ONLY);
            $offset = 0;

            do {
                $chunk = $import->fileRowsChunk($path, $offset, ProductImportRunChunkProcessor::CHUNK_SIZE);
                if ($chunk['rows'] === []) {
                    break;
                }

                $rowService->appendPendingValidationRows(
                    $importRun,
                    $import->stagingRowsForValidation($chunk['rows'])
                );

                $offset += count($chunk['rows']);
            } while ((bool) ($chunk['has_more'] ?? false));

            $startedAt = microtime(true);
            do {
                $result = $chunkProcessor->process($importRun->refresh());
            } while ($result['has_more']);

            return (microtime(true) - $startedAt) * 1000;
        } finally {
            $importRun->delete();
            Storage::disk('local')->delete($storedPath);
        }
    }

    private function cleanupBenchmarkRows(?int $rows = null): void
    {
        $productSystemIdPrefix = $rows === null ? 'BENCH-SYS-' : "BENCH-SYS-{$rows}-";
        $productSkuPrefix = $rows === null ? 'BENCH-' : "BENCH-{$rows}-";

        $hasProducts = Product::query()
            ->where('sku_base', 'like', $productSkuPrefix.'%')
            ->exists();

        if (! $hasProducts && ! ProductVariant::query()->where('system_id', 'like', $productSystemIdPrefix.'%')->exists()) {
            return;
        }

        if ($hasProducts) {
            DB::table('products')->where('sku_base', 'like', $productSkuPrefix.'%')->delete();
        }

        DB::table('product_variants')->where('system_id', 'like', $productSystemIdPrefix.'%')->delete();
    }

    private function temporaryCsvPath(int $rows, string $categoryName, string $brandName): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'product-import-benchmark-');
        $path = $temporaryPath.'.csv';
        @unlink($temporaryPath);

        $handle = fopen($path, 'wb');
        fputcsv($handle, ['Description', 'Custom SKU', 'System ID', 'Brand', 'Category']);

        for ($index = 1; $index <= $rows; $index++) {
            fputcsv($handle, [
                "Benchmark {$index}",
                "BENCH-{$rows}-{$index}",
                "BENCH-SYS-{$rows}-{$index}",
                $brandName,
                $categoryName,
            ]);
        }

        fclose($handle);

        return $path;
    }
}
