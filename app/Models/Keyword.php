<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Keyword extends Model
{
    use HasFactory;

    protected $table = 'keywords';

    protected $fillable = [
        'slug',
        'keyword_type',
        'post_type_id',
        'dynamic_post_id',
        'keyword_list',
        'source_row_number',
    ];

    protected $hidden = [
        'source_row_number',
    ];

    protected $casts = [
        'keyword_type' => 'string',
        'post_type_id' => 'integer',
        'dynamic_post_id' => 'integer',
        'keyword_list' => 'array',
        'source_row_number' => 'integer',
    ];

    /**
     * A keyword group belongs to a post type (when keyword_type = 'posttype').
     */
    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    /**
     * A keyword group belongs to a specific dynamic post / listing (when keyword_type = 'listing').
     */
    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'dynamic_post_id');
    }

    public function scopeForPostType($query, int $postTypeId)
    {
        return $query->where('keyword_type', 'posttype')
            ->where('post_type_id', $postTypeId);
    }

    public function scopeForListing($query, int $dynamicPostId)
    {
        return $query->where('keyword_type', 'listing')
            ->where('dynamic_post_id', $dynamicPostId);
    }

    /**
     * Normalize keyword_list: trim spaces, remove empties, remove duplicates.
     */
    public static function normalizeKeywordList(mixed $input): array
    {
        if (is_string($input)) {
            $items = explode(',', $input);
        } elseif (is_array($input)) {
            $items = $input;
        } else {
            return [];
        }

        $items = array_map('trim', $items);
        $items = array_filter($items, fn($v) => $v !== '');
        $items = array_unique($items);

        return array_values($items);
    }

    /**
     * Generate a unique slug from post_type + source_row_number.
     */
    public static function generateSlugFromPostType(string $postType, int $sourceRowNumber): string
    {
        $base = Str::slug($postType) . '-' . $sourceRowNumber;
        $slug = $base;
        $counter = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}