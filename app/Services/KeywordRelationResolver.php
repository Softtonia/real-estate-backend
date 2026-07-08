<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\PostType;
use RuntimeException;

class KeywordRelationResolver
{
    public function resolveKeywordType(mixed $value): PostType
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new RuntimeException('keyword_type is required.');
        }

        if (is_numeric($value)) {
            $postType = PostType::query()
                ->whereKey((int) $value)
                ->first();
        } else {
            $postType = PostType::query()
                ->where('slug', $value)
                ->orWhere('name', $value)
                ->first();
        }

        if (! $postType) {
            throw new RuntimeException("Invalid keyword_type '{$value}'.");
        }

        return $postType;
    }

    public function resolvePostType(mixed $value, int $keywordTypeId): DynamicPost
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new RuntimeException('post_type is required.');
        }

        $query = DynamicPost::query()
            ->where('post_type_id', $keywordTypeId);

        if (is_numeric($value)) {
            $dynamicPost = $query
                ->whereKey((int) $value)
                ->first();
        } else {
            $dynamicPost = $query
                ->where(function ($q) use ($value) {
                    $q->where('slug', $value)
                        ->orWhere('title', $value);
                })
                ->first();
        }

        if (! $dynamicPost) {
            throw new RuntimeException("Invalid post_type '{$value}' for selected keyword_type.");
        }

        return $dynamicPost;
    }
}