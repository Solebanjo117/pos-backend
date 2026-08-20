<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OperationalExpenseBeneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_key',
        'user_id',
        'beneficiary_kind',
        'type',
        'email',
        'phone',
        'notes',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = [
        'beneficiary_kind' => 'other',
        'type' => 'individual',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $beneficiary): void {
            $beneficiary->name = trim($beneficiary->name);
            $beneficiary->name_key = self::normalizeNameKey($beneficiary->name);
        });
    }

    public static function normalizeNameKey(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

        return Str::lower(Str::ascii($normalized));
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OperationalExpense::class, 'beneficiary_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
