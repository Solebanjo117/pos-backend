<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CustomerType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'base_kind', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (CustomerType $type): void {
            $type->normalized_name = self::normalizeName($type->name);
        });
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
