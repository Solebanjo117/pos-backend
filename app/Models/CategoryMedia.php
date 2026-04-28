<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryMedia extends Model
{
    use HasFactory;

    protected $table = 'category_media';

    protected $fillable = [
        'category_id',
        'media_type',
        'category',
        'file_path',
        'original_filename',
        'file_size',
        'display_order',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'media_type' => 'string',
        'category' => 'string',
        'file_size' => 'integer',
        'display_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }
}
