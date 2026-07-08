<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Keyword extends Model
{
    protected $fillable = [
        'keyword',
        'status',
        'avg_search_volume',
        'avg_ranking',
    ];

    protected $casts = [
        'avg_search_volume' => 'integer',
        'avg_ranking' => 'decimal:2',
    ];

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
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }
}