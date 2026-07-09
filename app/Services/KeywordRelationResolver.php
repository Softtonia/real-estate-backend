<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\PostType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class KeywordRelationResolver
{
    public function resolveKeywordType(mixed $value): PostType
    {
        $value = $this->cleanValue($value);

        if ($value === '') {
            throw new RuntimeException('keyword_type is required.');
        }

        if (is_numeric($value)) {
            $postType = PostType::query()
                ->whereKey((int) $value)
                ->first();

            if ($postType) {
                return $postType;
            }
        }

        $slugValue = Str::slug($value);
        $lowerValue = mb_strtolower($value);

        $query = PostType::query();

        $query->where(function ($q) use ($value, $slugValue) {
            if (Schema::hasColumn('post_types', 'slug')) {
                $q->orWhere('slug', $value)
                  ->orWhere('slug', $slugValue);
            }

            if (Schema::hasColumn('post_types', 'name')) {
                $q->orWhere('name', $value);
            }
        });

        $postType = $query->first();

        if ($postType) {
            return $postType;
        }

        /*
         * Fallback:
         * This handles cases where DB name is "Property Listing"
         * and CSV value is "property-listing".
         */
        $postType = PostType::query()
            ->get()
            ->first(function ($row) use ($slugValue, $lowerValue) {
                $rowSlug = isset($row->slug) ? mb_strtolower((string) $row->slug) : '';
                $rowName = isset($row->name) ? mb_strtolower((string) $row->name) : '';

                return $rowSlug === $lowerValue
                    || Str::slug($rowSlug) === $slugValue
                    || $rowName === $lowerValue
                    || Str::slug($rowName) === $slugValue;
            });

        if ($postType) {
            return $postType;
        }

        $available = PostType::query()
            ->get(['id', 'name', 'slug'])
            ->map(fn ($row) => "{$row->id}:{$row->slug}:{$row->name}")
            ->implode(', ');

        throw new RuntimeException(
            "Invalid keyword_type '{$value}'. Available keyword types: {$available}"
        );
    }

    public function resolvePostType(mixed $value, int $keywordTypeId): DynamicPost
    {
        $value = $this->cleanValue($value);

        if ($value === '') {
            throw new RuntimeException('post_type is required.');
        }

        $query = DynamicPost::query()
            ->where('post_type_id', $keywordTypeId);

        if (is_numeric($value)) {
            $dynamicPost = (clone $query)
                ->whereKey((int) $value)
                ->first();

            if ($dynamicPost) {
                return $dynamicPost;
            }
        }

        $slugValue = Str::slug($value);
        $lowerValue = mb_strtolower($value);

        $dynamicPost = (clone $query)
            ->where(function ($q) use ($value, $slugValue) {
                if (Schema::hasColumn('dynamic_posts', 'slug')) {
                    $q->orWhere('slug', $value)
                      ->orWhere('slug', $slugValue);
                }

                if (Schema::hasColumn('dynamic_posts', 'title')) {
                    $q->orWhere('title', $value);
                }

                if (Schema::hasColumn('dynamic_posts', 'name')) {
                    $q->orWhere('name', $value);
                }
            })
            ->first();

        if ($dynamicPost) {
            return $dynamicPost;
        }

        /*
         * Fallback:
         * This handles title like "Property Testing"
         * and CSV listing as "property-testing".
         */
        $dynamicPost = (clone $query)
            ->get()
            ->first(function ($row) use ($slugValue, $lowerValue) {
                $rowSlug = isset($row->slug) ? mb_strtolower((string) $row->slug) : '';
                $rowTitle = isset($row->title) ? mb_strtolower((string) $row->title) : '';
                $rowName = isset($row->name) ? mb_strtolower((string) $row->name) : '';

                return $rowSlug === $lowerValue
                    || Str::slug($rowSlug) === $slugValue
                    || $rowTitle === $lowerValue
                    || Str::slug($rowTitle) === $slugValue
                    || $rowName === $lowerValue
                    || Str::slug($rowName) === $slugValue;
            });

        if ($dynamicPost) {
            return $dynamicPost;
        }

        $available = (clone $query)
            ->limit(20)
            ->get(['id', 'title', 'slug'])
            ->map(fn ($row) => "{$row->id}:{$row->slug}:{$row->title}")
            ->implode(', ');

        throw new RuntimeException(
            "Invalid post_type '{$value}' for selected keyword_type. Available listings: {$available}"
        );
    }

    private function cleanValue(mixed $value): string
    {
        $value = trim((string) $value);

        // remove BOM
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        // remove quotes from CSV cells
        $value = trim((string) $value, " \t\n\r\0\x0B\"'");

        // normalize spaces
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }
}