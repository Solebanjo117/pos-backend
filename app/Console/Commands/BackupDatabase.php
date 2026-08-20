<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Create a database backup. Supports SQLite, MySQL, and PostgreSQL.';

    public function handle(): int
    {
        $connection = config('database.default');
        $timestamp = now()->format('Y-m-d_His');
        $backupDir = storage_path('app/backups');

        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        try {
            $filePath = match ($connection) {
                'sqlite' => $this->backupSqlite($backupDir, $timestamp),
                'mysql' => $this->backupMysql($backupDir, $timestamp),
                'pgsql' => $this->backupPgsql($backupDir, $timestamp),
                default => throw new \RuntimeException("Backup not supported for connection: {$connection}"),
            };

            // Compress to ZIP
            $zipPath = $filePath.'.zip';
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                $zip->addFile($filePath, basename($filePath));
                $zip->close();
                File::delete($filePath);
                $this->info("Backup created: {$zipPath}");
            } else {
                $this->warn("Could not create ZIP. Uncompressed backup at: {$filePath}");
            }

            // Retain only the last N backups (default 30)
            $this->pruneOldBackups($backupDir, config('backup.keep_last', 30));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function backupSqlite(string $dir, string $timestamp): string
    {
        $dbPath = config('database.connections.sqlite.database');
        $destPath = "{$dir}/backup_{$timestamp}.sqlite";
        File::copy($dbPath, $destPath);

        return $destPath;
    }

    private function backupMysql(string $dir, string $timestamp): string
    {
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $destPath = "{$dir}/backup_{$timestamp}.sql";

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($destPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("mysqldump failed with exit code {$returnCode}.");
        }

        return $destPath;
    }

    private function backupPgsql(string $dir, string $timestamp): string
    {
        $host = config('database.connections.pgsql.host', '127.0.0.1');
        $port = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');
        $destPath = "{$dir}/backup_{$timestamp}.dump";

        // Use -F c (custom format) for selective restore with pg_restore
        $command = sprintf(
            'pg_dump --host=%s --port=%s --username=%s --format=custom --file=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($destPath),
            escapeshellarg($database)
        );

        // Pass password via environment variable (more secure than CLI argument)
        putenv("PGPASSWORD={$password}");
        exec($command, $output, $returnCode);
        putenv('PGPASSWORD=');

        if ($returnCode !== 0) {
            throw new \RuntimeException("pg_dump failed with exit code {$returnCode}.");
        }

        return $destPath;
    }

    private function pruneOldBackups(string $dir, int $keepLast): void
    {
        $files = collect(File::files($dir))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        foreach ($files->slice($keepLast) as $old) {
            File::delete($old->getRealPath());
            $this->line('Pruned old backup: '.$old->getFilename());
        }
    }
}
