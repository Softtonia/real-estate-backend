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
        $invalid = [];

        foreach ($items as $item) {
            $postType = $this->resolvePostType($item);

            if (! $postType) {
                $invalid[] = $item;
                continue;
            }

            $ids[] = $postType->id;
        }

        if (! empty($invalid)) {
            throw new \RuntimeException(
                "Invalid keyword_type value(s): " . implode(', ', $invalid) .
                ". Expected post_types.id, post_types.slug, or post_types.name. " .
                "Available examples: " . $this->postTypeExamples()
            );
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
        $errors = [];

        foreach ($items as $item) {
            $dynamicPost = $this->resolveDynamicPost($item, $allowedPostTypeIds);

            if ($dynamicPost) {
                $ids[] = $dynamicPost->id;
                continue;
            }

            $existingAnywhere = $this->findDynamicPostAnywhere($item);

            if ($existingAnywhere) {
                $actualPostType = PostType::find($existingAnywhere->post_type_id);

                $errors[] =
                    "Invalid post_type '{$item}'. It exists in dynamic_posts " .
                    "as id={$existingAnywhere->id}, slug='{$existingAnywhere->slug}', title='{$existingAnywhere->title}', " .
                    "but it belongs to post_type_id={$existingAnywhere->post_type_id}" .
                    ($actualPostType ? " ({$actualPostType->slug})" : "") .
                    ". Selected keyword_type is: " . $this->selectedPostTypesText($allowedPostTypeIds) . ".";
            } else {
                $errors[] =
                    "Invalid post_type '{$item}'. No dynamic_posts record found by id, slug, or title " .
                    "under selected keyword_type: " . $this->selectedPostTypesText($allowedPostTypeIds) . ". " .
                    "Available post_type examples for selected keyword_type: " . $this->dynamicPostExamples($allowedPostTypeIds);
            }
        }

        if (! empty($errors)) {
            throw new \RuntimeException(implode(' ', $errors));
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

    private function findDynamicPostAnywhere(mixed $value): ?DynamicPost
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if (is_numeric($value)) {
            return DynamicPost::query()->whereKey((int) $value)->first();
        }

        return DynamicPost::query()
            ->where(function ($q) use ($value) {
                $q->where('slug', $value)
                    ->orWhere('title', $value);
            })
            ->first();
    }

    private function selectedPostTypesText(array $ids): string
    {
        $items = PostType::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'slug']);

        if ($items->isEmpty()) {
            return 'none';
        }

        return $items
            ->map(fn ($item) => "#{$item->id} {$item->slug} ({$item->name})")
            ->implode(', ');
    }

    private function postTypeExamples(): string
    {
        $items = PostType::query()
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'name', 'slug']);

        if ($items->isEmpty()) {
            return 'No post_types found.';
        }

        return $items
            ->map(fn ($item) => "#{$item->id} slug='{$item->slug}' name='{$item->name}'")
            ->implode('; ');
    }

    private function dynamicPostExamples(array $allowedPostTypeIds): string
    {
        $items = DynamicPost::query()
            ->whereIn('post_type_id', $allowedPostTypeIds)
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'post_type_id', 'title', 'slug']);

        if ($items->isEmpty()) {
            return 'No dynamic_posts found for selected keyword_type.';
        }

        return $items
            ->map(fn ($item) => "#{$item->id} post_type_id={$item->post_type_id} slug='{$item->slug}' title='{$item->title}'")
            ->implode('; ');
    }
}