<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAUSED = 'paused';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const STARTABLE_STATUSES = [
        self::STATUS_PENDING,
    ];

    public const CANCELLABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
    ];

    public const TYPE_IMPORT = 'import';

    public const TYPE_VALIDATION = 'validation';

    public const MODE_VALIDATE_ONLY = 'validate_only';

    public const MODE_CREATE_ONLY = 'create_only';

    public const MODE_CREATE_UPDATE = 'create_update';

    public const MODE_UPDATE_STOCK_PRICE = 'update_stock_price';

    public const MODE_RETRY_FAILED = 'retry_failed';

    protected $fillable = [
        'user_id',
        'entity',
        'import_profile_id',
        'parent_import_run_id',
        'original_filename',
        'stored_path',
        'sheet_index',
        'sheet_name',
        'run_type',
        'mode',
        'status',
        'total_rows',
        'processed_rows',
        'success_count',
        'error_count',
        'mapping',
        'failed_rows',
        'results',
        'errors',
        'summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'results' => 'array',
        'errors' => 'array',
        'summary' => 'array',
        'mapping' => 'array',
        'failed_rows' => 'array',
        'sheet_index' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ImportProfile::class, 'import_profile_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_import_run_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportRunRecord::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canStart(): bool
    {
        return in_array($this->status, self::STARTABLE_STATUSES, true);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES, true);
    }

    public function shouldProcessFailedRows(): bool
    {
        return $this->mode === self::MODE_RETRY_FAILED && ! empty($this->failed_rows);
    }
}
