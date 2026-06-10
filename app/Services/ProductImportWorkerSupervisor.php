<?php

namespace App\Services;

use App\Models\ImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class ProductImportWorkerSupervisor
{
    private const QUEUE_CONNECTION = 'product_imports';

    private const DEFAULT_QUEUE = 'product-imports';

    /**
     * @return array{worker_auto_start_enabled: bool, worker_auto_start_status: string, worker_command: string, queue: string, queue_connection: string, worker_working_directory: string, worker_php_binary?: string, worker_process_id?: int|null, worker_start_verified?: bool, worker_auto_start_error?: string}
     */
    public function ensureRunning(): array
    {
        $metadata = $this->baseMetadata();

        if (! $this->autoStartEnabled()) {
            return $metadata + [
                'worker_auto_start_enabled' => false,
                'worker_auto_start_status' => 'disabled',
            ];
        }

        if ($this->workerAppearsActive()) {
            return $metadata + [
                'worker_auto_start_enabled' => true,
                'worker_auto_start_status' => 'already_active',
            ];
        }

        $lockHandle = $this->openLockHandle();
        if (! is_resource($lockHandle)) {
            return $metadata + [
                'worker_auto_start_enabled' => true,
                'worker_auto_start_status' => 'lock_unavailable',
            ];
        }

        try {
            if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'already_requested',
                ];
            }

            if ($this->recentAutoStartRequested($lockHandle)) {
                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'already_requested',
                ];
            }

            if ($this->workerAppearsActive()) {
                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'already_active',
                ];
            }

            $workingDirectory = $this->workingDirectory();
            if (! is_dir($workingDirectory)) {
                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'failed',
                    'worker_start_verified' => false,
                    'worker_auto_start_error' => "No se encontro el directorio de trabajo {$workingDirectory}.",
                ];
            }

            $phpBinary = $this->resolvePhpBinary();
            if ($phpBinary === null) {
                $this->clearAutoStartRequest($lockHandle);

                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'failed',
                    'worker_start_verified' => false,
                    'worker_auto_start_error' => 'No se encontro el binario PHP CLI para iniciar product-imports.',
                ];
            }

            $process = null;
            try {
                $process = Process::path($workingDirectory)
                    ->forever()
                    ->quietly()
                    ->start($this->workerCommandArguments($phpBinary));
            } catch (\Throwable $exception) {
                $this->clearAutoStartRequest($lockHandle);

                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'failed',
                    'worker_php_binary' => $phpBinary,
                    'worker_start_verified' => false,
                    'worker_auto_start_error' => $exception->getMessage(),
                ];
            }

            $processId = $this->processId($process);
            if (! $this->workerStartVerified($process)) {
                $this->clearAutoStartRequest($lockHandle);
                Log::warning('product_import_worker_start_unconfirmed', [
                    'queue' => $this->queueName(),
                    'queue_connection' => self::QUEUE_CONNECTION,
                    'worker_php_binary' => $phpBinary,
                    'worker_process_id' => $processId,
                ]);

                return $metadata + [
                    'worker_auto_start_enabled' => true,
                    'worker_auto_start_status' => 'startup_unconfirmed',
                    'worker_php_binary' => $phpBinary,
                    'worker_process_id' => $processId,
                    'worker_start_verified' => false,
                    'worker_auto_start_error' => 'El proceso del worker se solicito, pero no se confirmo que quedara activo.',
                ];
            }

            $this->recordAutoStartRequest($lockHandle);

            return $metadata + [
                'worker_auto_start_enabled' => true,
                'worker_auto_start_status' => 'started',
                'worker_php_binary' => $phpBinary,
                'worker_process_id' => $processId,
                'worker_start_verified' => true,
            ];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function ensureRunningAfterCommit(): void
    {
        DB::afterCommit(fn (): array => $this->ensureRunning());
    }

    public function commandDisplay(): string
    {
        return sprintf(
            'php artisan queue:work %s --queue=%s --sleep=1 --tries=1 --timeout=%d --stop-when-empty',
            self::QUEUE_CONNECTION,
            $this->queueName(),
            $this->timeout()
        );
    }

    /**
     * @return array{worker_command: string, queue: string, queue_connection: string, worker_working_directory: string}
     */
    private function baseMetadata(): array
    {
        return [
            'worker_command' => $this->commandDisplay(),
            'queue' => $this->queueName(),
            'queue_connection' => self::QUEUE_CONNECTION,
            'worker_working_directory' => $this->workingDirectory(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function workerCommandArguments(string $phpBinary): array
    {
        return [
            $phpBinary,
            base_path('artisan'),
            'queue:work',
            self::QUEUE_CONNECTION,
            '--queue='.$this->queueName(),
            '--sleep=1',
            '--tries=1',
            '--timeout='.$this->timeout(),
            '--stop-when-empty',
        ];
    }

    private function resolvePhpBinary(): ?string
    {
        $configuredPath = config('queue.connections.product_imports.worker_php_binary');
        $candidate = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : PHP_BINARY;

        if ($this->isPhpCgiBinary($candidate)) {
            $cliCandidate = dirname($candidate).DIRECTORY_SEPARATOR.$this->phpExecutableName();
            if ($this->isUsablePhpBinary($cliCandidate)) {
                return $cliCandidate;
            }
        }

        return $this->isUsablePhpBinary($candidate) ? $candidate : null;
    }

    private function isPhpCgiBinary(string $path): bool
    {
        return in_array(strtolower(basename($path)), ['php-cgi.exe', 'php-cgi'], true);
    }

    private function phpExecutableName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    }

    private function isUsablePhpBinary(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return PHP_OS_FAMILY === 'Windows' || is_executable($path);
    }

    private function processId(mixed $process): ?int
    {
        try {
            $processId = $process?->id();

            return is_int($processId) ? $processId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function workerStartVerified(mixed $process): bool
    {
        $deadline = microtime(true) + $this->startVerificationSeconds();

        do {
            if ($this->workerAppearsActive() || $this->processStillRunning($process)) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(100_000);
        } while (true);

        return false;
    }

    private function processStillRunning(mixed $process): bool
    {
        try {
            return (bool) $process?->running();
        } catch (\Throwable) {
            return false;
        }
    }

    private function workingDirectory(): string
    {
        return base_path();
    }

    private function workerAppearsActive(): bool
    {
        return $this->hasReservedDatabaseJob()
            || ImportRun::query()
                ->where('entity', 'products')
                ->where('status', ImportRun::STATUS_RUNNING)
                ->where(function ($query): void {
                    $query->where('heartbeat_at', '>=', now()->subMinutes(2))
                        ->orWhere(function ($query): void {
                            $query->whereNull('heartbeat_at')
                                ->where('updated_at', '>=', now()->subMinutes(2));
                        });
                })
                ->exists();
    }

    private function hasReservedDatabaseJob(): bool
    {
        if ((string) config('queue.connections.product_imports.driver') !== 'database') {
            return false;
        }

        $table = (string) config('queue.connections.product_imports.table', 'jobs');
        $connection = config('queue.connections.product_imports.connection');
        $schema = is_string($connection) && $connection !== ''
            ? Schema::connection($connection)
            : Schema::getFacadeRoot();

        if ($table === '' || ! $schema->hasTable($table)) {
            return false;
        }

        return DB::connection($connection)
            ->table($table)
            ->where('queue', $this->queueName())
            ->whereNotNull('reserved_at')
            ->exists();
    }

    private function openLockHandle(): mixed
    {
        $path = $this->lockPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return false;
        }

        return @fopen($path, 'c+');
    }

    private function recentAutoStartRequested(mixed $lockHandle): bool
    {
        rewind($lockHandle);
        $value = trim((string) stream_get_contents($lockHandle));
        if (! str_starts_with($value, 'verified:')) {
            return false;
        }

        return ((int) substr($value, 9)) >= now()->subSeconds($this->startupGraceSeconds())->timestamp;
    }

    private function recordAutoStartRequest(mixed $lockHandle): void
    {
        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fwrite($lockHandle, 'verified:'.now()->timestamp);
        fflush($lockHandle);
    }

    private function clearAutoStartRequest(mixed $lockHandle): void
    {
        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fflush($lockHandle);
    }

    private function lockPath(): string
    {
        $configuredPath = config('queue.connections.product_imports.worker_lock_path');

        return is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path('framework/cache/product-import-worker.lock');
    }

    private function autoStartEnabled(): bool
    {
        return (bool) config('queue.connections.product_imports.auto_start_worker', true);
    }

    private function queueName(): string
    {
        return (string) config('queue.connections.product_imports.queue', self::DEFAULT_QUEUE);
    }

    private function timeout(): int
    {
        return (int) config('queue.connections.product_imports.retry_after', 1800);
    }

    private function startupGraceSeconds(): int
    {
        return max(1, (int) config('queue.connections.product_imports.worker_startup_grace_seconds', 60));
    }

    private function startVerificationSeconds(): float
    {
        return max(0, (float) config('queue.connections.product_imports.worker_start_verification_seconds', 2));
    }
}
