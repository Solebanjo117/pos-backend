<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class CleanOldBackups extends Command
{
    protected $signature = 'db:clean-backups {--days=30 : Delete backups older than this many days}';

    protected $description = 'Delete database backups older than the specified number of days.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $backupDir = storage_path('app/backups');

        if (! File::exists($backupDir)) {
            $this->info('No backup directory found. Nothing to clean.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        foreach (File::files($backupDir) as $file) {
            $modifiedAt = Carbon::createFromTimestamp($file->getMTime());
            if ($modifiedAt->lt($cutoff)) {
                File::delete($file->getRealPath());
                $this->line('Deleted: '.$file->getFilename());
                $deleted++;
            }
        }

        $this->info("Cleanup complete. {$deleted} backup(s) deleted.");

        return self::SUCCESS;
    }
}
