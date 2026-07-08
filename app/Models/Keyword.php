<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Keyword extends Model
{
    protected $fillable = [
        'slug',
        'keyword_list',
        'status',
        'search_volume',
        'ranking',
    ];

    protected $casts = [
        'keyword_list' => 'array',
        'search_volume' => 'integer',
        'ranking' => 'integer',
    ];

    public function postTypes(): BelongsToMany
    {
        return $this->belongsToMany(PostType::class, 'keyword_post_type', 'keyword_id', 'post_type_id');
    }

    public function dynamicPosts(): BelongsToMany
    {
        return $this->belongsToMany(DynamicPost::class, 'keyword_dynamic_post', 'keyword_id', 'dynamic_post_id');
    }

    public function setKeywordListAttribute(mixed $value): void
    {
        $this->attributes['keyword_list'] = json_encode(
            self::normalizeKeywords($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function normalizeKeywords(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = explode(',', $value);
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->map(fn($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
