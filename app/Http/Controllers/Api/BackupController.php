<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\BackupDatabase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    private string $backupDir;

    public function __construct()
    {
        $this->backupDir = storage_path('app/backups');
    }

    /**
     * List available backup files.
     */
    public function index(): JsonResponse
    {
        if (! File::exists($this->backupDir)) {
            return response()->json([]);
        }

        $files = collect(File::files($this->backupDir))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->map(fn ($f) => [
                'filename'  => $f->getFilename(),
                'size_kb'   => round($f->getSize() / 1024, 2),
                'created_at' => date('Y-m-d H:i:s', $f->getMTime()),
            ])
            ->values();

        return response()->json($files);
    }

    /**
     * Trigger an immediate backup.
     */
    public function trigger(): JsonResponse
    {
        try {
            $exitCode = \Artisan::call('db:backup');

            if ($exitCode === 0) {
                return response()->json([
                    'message' => 'Backup created successfully.',
                    'output'  => trim(\Artisan::output()),
                ]);
            }

            return response()->json([
                'message' => 'Backup command returned an error.',
                'output'  => trim(\Artisan::output()),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a specific backup file.
     */
    public function download(string $filename): BinaryFileResponse|JsonResponse
    {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);
        $filePath = $this->backupDir . '/' . $filename;

        if (! File::exists($filePath)) {
            return response()->json([
                'message' => 'Backup file not found.',
            ], 404);
        }

        return response()->download($filePath);
    }
}
