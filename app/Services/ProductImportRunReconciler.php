<?php

namespace App\Services;

use App\Jobs\ProcessProductImportRun;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProductImportRunReconciler
{
    public const DEFAULT_ORPHANED_PENDING_MINUTES = 15;

    public const DEFAULT_ORPHANED_RUNNING_MINUTES = 60;

    public const DEFAULT_STALE_CANCEL_REQUESTED_MINUTES = 10;

    public function __construct(private readonly ProductImportWorkerSupervisor $workerSupervisor) {}

    /**
     * @param  array{pending_minutes?: int, running_minutes?: int, cancel_requested_minutes?: int, reconciled_by_command?: bool}  $options
     * @return array{checked: int, reconciled: int, failed: int, cancelled: int, requeued: int}
     */
    public function reconcileOrphaned(?User $user = null, array $options = []): array
    {
        $summary = [
            'checked' => 0,
            'reconciled' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'requeued' => 0,
        ];

        $query = ImportRun::query()
            ->where('entity', 'products')
            ->whereIn('status', [
                ImportRun::STATUS_PENDING,
                ImportRun::STATUS_RUNNING,
                ImportRun::STATUS_CANCEL_REQUESTED,
            ]);

        if ($user !== null) {
            $query->visibleTo($user);
        }

        $this->orphanedScope($query, $options)
            ->orderBy('id')
            ->chunkById(100, function ($importRuns) use (&$summary, $options): void {
                foreach ($importRuns as $importRun) {
                    $summary['checked']++;
                    $action = $this->reconcile($importRun, $options);

                    if ($action === null) {
                        continue;
                    }

                    $summary['reconciled']++;
                    $summary[$action]++;
                }
            });

        return $summary;
    }

    /**
     * @param  array{pending_minutes?: int, running_minutes?: int, cancel_requested_minutes?: int, reconciled_by_command?: bool}  $options
     */
    public function reconcile(ImportRun $importRun, array $options = []): ?string
    {
        if ($importRun->status === ImportRun::STATUS_CANCEL_REQUESTED) {
            if (($importRun->summary['chunk_processing'] ?? false) !== true && ! $this->isStaleCancelRequested($importRun, $options)) {
                return null;
            }

            $importRun->update([
                'status' => ImportRun::STATUS_CANCELLED,
                'finished_at' => $importRun->finished_at ?? now(),
                'summary' => array_merge($importRun->summary ?? [], [
                    'cancel_reconciled' => true,
                    'source_file_deleted' => false,
                ], $this->reconciliationSummary($options)),
            ]);

            return 'cancelled';
        }

        $lastHeartbeatAt = $importRun->heartbeat_at ?? $importRun->updated_at;
        $isOrphanedPending = $this->isOrphanedPending($importRun, $options);
        $isOrphanedRunning = $this->isOrphanedRunning($importRun, $options);

        if (! $isOrphanedPending && ! $isOrphanedRunning) {
            return null;
        }

        if (
            $isOrphanedRunning
            && $importRun->run_type === ImportRun::TYPE_VALIDATION
            && $importRun->rows()->exists()
        ) {
            $importRun->update([
                'status' => ImportRun::STATUS_PENDING,
                'phase' => ImportRun::PHASE_QUEUED,
                'started_at' => null,
                'heartbeat_at' => null,
                'summary' => array_merge($importRun->summary ?? [], [
                    'orphaned_requeued' => true,
                    'source_file_deleted' => false,
                ], $this->reconciliationSummary($options)),
            ]);

            $importRun->refresh();
            $this->dispatchProductImportRun($importRun);

            return 'requeued';
        }

        $importRun->update([
            'status' => ImportRun::STATUS_FAILED,
            'phase' => ImportRun::PHASE_FINALIZING,
            'finished_at' => $importRun->finished_at ?? now(),
            'errors' => $importRun->errors ?: [[
                'error' => 'No se detecto actividad del worker product-imports para este import. Inicia el worker dedicado y vuelve a intentar el archivo.',
            ]],
            'error_count' => max(1, (int) $importRun->error_count),
            'summary' => array_merge($importRun->summary ?? [], [
                'orphaned_recovered' => true,
                'last_heartbeat_at' => $lastHeartbeatAt?->toISOString(),
                'source_file_deleted' => false,
            ], $this->reconciliationSummary($options)),
        ]);

        return 'failed';
    }

    /**
     * @param  array{pending_minutes?: int, running_minutes?: int, cancel_requested_minutes?: int, reconciled_by_command?: bool}  $options
     */
    private function orphanedScope(Builder $query, array $options): Builder
    {
        return $query->where(function (Builder $query) use ($options): void {
            $query->where(function (Builder $query) use ($options): void {
                $query->where('status', ImportRun::STATUS_CANCEL_REQUESTED)
                    ->where(function (Builder $query) use ($options): void {
                        $query->where('summary->chunk_processing', true)
                            ->orWhere('updated_at', '<', now()->subMinutes($this->cancelRequestedMinutes($options)));
                    });
            })
                ->orWhere(function (Builder $query) use ($options): void {
                    $query->where('status', ImportRun::STATUS_PENDING)
                        ->whereNull('started_at')
                        ->whereNull('heartbeat_at')
                        ->where(function (Builder $query): void {
                            $query->whereNull('phase')
                                ->orWhere('phase', ImportRun::PHASE_QUEUED);
                        })
                        ->where('updated_at', '<', now()->subMinutes($this->pendingMinutes($options)));
                })
                ->orWhere(function (Builder $query) use ($options): void {
                    $query->where('status', ImportRun::STATUS_RUNNING)
                        ->where(function (Builder $query) use ($options): void {
                            $query->where('heartbeat_at', '<', now()->subMinutes($this->runningMinutes($options)))
                                ->orWhere(function (Builder $query) use ($options): void {
                                    $query->whereNull('heartbeat_at')
                                        ->where('updated_at', '<', now()->subMinutes($this->runningMinutes($options)));
                                });
                        });
                });
        });
    }

    /**
     * @param  array{pending_minutes?: int}  $options
     */
    private function isOrphanedPending(ImportRun $importRun, array $options): bool
    {
        $isQueued = $importRun->phase === null || $importRun->phase === ImportRun::PHASE_QUEUED;

        return $importRun->status === ImportRun::STATUS_PENDING
            && $isQueued
            && $importRun->started_at === null
            && $importRun->heartbeat_at === null
            && $importRun->updated_at?->lt(now()->subMinutes($this->pendingMinutes($options)));
    }

    /**
     * @param  array{running_minutes?: int}  $options
     */
    private function isOrphanedRunning(ImportRun $importRun, array $options): bool
    {
        $lastHeartbeatAt = $importRun->heartbeat_at ?? $importRun->updated_at;

        return $importRun->status === ImportRun::STATUS_RUNNING
            && $lastHeartbeatAt?->lt(now()->subMinutes($this->runningMinutes($options)));
    }

    /**
     * @param  array{cancel_requested_minutes?: int}  $options
     */
    private function isStaleCancelRequested(ImportRun $importRun, array $options): bool
    {
        return $importRun->updated_at?->lt(now()->subMinutes($this->cancelRequestedMinutes($options))) === true;
    }

    /**
     * @param  array{reconciled_by_command?: bool}  $options
     * @return array{queue: string, queue_connection: string, queue_driver: mixed, reconciled_by_command?: bool}
     */
    private function reconciliationSummary(array $options): array
    {
        return array_merge([
            'queue' => (string) config('queue.connections.product_imports.queue', 'product-imports'),
            'queue_connection' => 'product_imports',
            'queue_driver' => config('queue.connections.product_imports.driver'),
            'worker_required' => true,
            'worker_command' => $this->workerSupervisor->commandDisplay(),
            'worker_message' => 'Esperando worker de importacion. Inicia la cola product-imports para procesar este archivo.',
        ], ($options['reconciled_by_command'] ?? false) === true ? [
            'reconciled_by_command' => true,
        ] : []);
    }

    private function dispatchProductImportRun(ImportRun $importRun): void
    {
        ProcessProductImportRun::dispatch($importRun->id)
            ->onConnection('product_imports')
            ->onQueue((string) config('queue.connections.product_imports.queue', 'product-imports'))
            ->afterCommit();

        $this->workerSupervisor->ensureRunningAfterCommit();
    }

    /**
     * @param  array{pending_minutes?: int}  $options
     */
    private function pendingMinutes(array $options): int
    {
        return max(1, (int) ($options['pending_minutes'] ?? self::DEFAULT_ORPHANED_PENDING_MINUTES));
    }

    /**
     * @param  array{running_minutes?: int}  $options
     */
    private function runningMinutes(array $options): int
    {
        return max(1, (int) ($options['running_minutes'] ?? self::DEFAULT_ORPHANED_RUNNING_MINUTES));
    }

    /**
     * @param  array{cancel_requested_minutes?: int}  $options
     */
    private function cancelRequestedMinutes(array $options): int
    {
        return max(1, (int) ($options['cancel_requested_minutes'] ?? self::DEFAULT_STALE_CANCEL_REQUESTED_MINUTES));
    }
}
