<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Keyword extends Model
{
    protected $fillable = [
        'csv_row_id',
        'keyword',
        'status',
        'avg_search_volume',
        'avg_ranking',
    ];

    protected $hidden = [
        'csv_row_id',
    ];

    protected $casts = [
        'avg_search_volume' => 'integer',
        'avg_ranking' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Keyword $keyword) {
            if (empty($keyword->csv_row_id)) {
                $keyword->csv_row_id = (string) Str::uuid();
            }
        });
    }

    public function postTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            PostType::class,
            'keyword_post_type',
            'keyword_id',
            'post_type_id'
        );
    }

    public function dynamicPosts(): BelongsToMany
    {
        return $this->belongsToMany(
            DynamicPost::class,
            'keyword_dynamic_post',
            'keyword_id',
            'dynamic_post_id'
        );
    }

    public static function normalizeKeyword(mixed $value): string
    {
        return trim((string) $value);
    }
}