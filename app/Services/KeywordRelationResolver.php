<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\PostType;

class KeywordRelationResolver
{
    public function parseList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->parseList($decoded);
        }

        return collect(explode(',', $value))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    public function resolvePostTypeIds(mixed $value): array
    {
        $items = $this->parseList($value);

        if (empty($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $postType = $this->resolvePostType($item);

            if (! $postType) {
                throw new \RuntimeException("Invalid keyword_type '{$item}'. Use post_types id, slug, or name.");
            }

            $ids[] = $postType->id;
        }

        return collect($ids)->unique()->values()->toArray();
    }

    public function resolveDynamicPostIds(mixed $value, array $allowedPostTypeIds): array
    {
        $items = $this->parseList($value);

        if (empty($items)) {
            return [];
        }

        if (empty($allowedPostTypeIds)) {
            throw new \RuntimeException('keyword_type is required before selecting post_type.');
        }

        $ids = [];

        foreach ($items as $item) {
            $dynamicPost = $this->resolveDynamicPost($item, $allowedPostTypeIds);

            if (! $dynamicPost) {
                throw new \RuntimeException("Invalid post_type '{$item}'. Use dynamic_posts id, slug, or title belonging to selected keyword_type.");
            }

            $ids[] = $dynamicPost->id;
        }

        return collect($ids)->unique()->values()->toArray();
    }

    public function resolvePostType(mixed $value): ?PostType
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (is_numeric($value)) {
            return PostType::query()->whereKey((int) $value)->first();
        }

        return PostType::query()
            ->where('slug', $value)
            ->orWhere('name', $value)
            ->first();
    }

    public function resolveDynamicPost(mixed $value, array $allowedPostTypeIds): ?DynamicPost
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        $query = DynamicPost::query()
            ->whereIn('post_type_id', $allowedPostTypeIds);

        if (is_numeric($value)) {
            return $query->whereKey((int) $value)->first();
        }

        return $query->where(function ($q) use ($value) {
            $q->where('slug', $value)
                ->orWhere('title', $value);
        })->first();
    }
}