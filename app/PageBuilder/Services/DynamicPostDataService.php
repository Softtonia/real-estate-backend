<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;


class DynamicPostDataService
{
    public function loadForResolvePayload(array $payload): array
    {
        $postType = $this->resolvePostType($payload);

        if (! $postType) {
            return [];
        }

        $post = $this->findDynamicPost($payload, $postType);

        if (! $post) {
            return [];
        }

        $system = $this->systemData($post);
        $custom = $this->customData($post, $postType);
        $taxonomyData = $this->taxonomyData($post);

        return [
            'id' => $system['id'] ?? null,
            'title' => $system['title'] ?? null,
            'slug' => $system['slug'] ?? null,
            'status' => $system['status'] ?? null,

            'post_type_id' => $postType->id,
            'post_type' => $postType->slug,

            'content_data' => [
                'system' => $system,
                'custom' => $custom,
            ],

            'fields' => [
                'system' => $system,
                'custom' => $custom,
            ],

            'taxonomy_term_ids' => $taxonomyData['taxonomy_term_ids'],
            'taxonomy_terms' => $taxonomyData['taxonomy_terms'],
            'taxonomies' => $taxonomyData['taxonomies'],
            'terms' => $taxonomyData['terms'],
        ];
    }

    private function resolvePostType(array $payload): ?PostType
    {
        if (! empty($payload['post_type_id'])) {
            return PostType::query()
                ->where('id', $payload['post_type_id'])
                ->first();
        }

        if (! empty($payload['post_type'])) {
            return PostType::query()
                ->where('slug', $payload['post_type'])
                ->first();
        }

        return null;
    }

    private function findDynamicPost(array $payload, PostType $postType): ?stdClass
    {
        if (! Schema::hasTable('dynamic_posts')) {
            return null;
        }

        $query = DB::table('dynamic_posts');

        $id = $payload['dynamic_post_id']
            ?? $payload['post_id']
            ?? $payload['id']
            ?? null;

        if ($id) {
            $query->where('id', $id);
        } elseif (! empty($payload['slug']) && Schema::hasColumn('dynamic_posts', 'slug')) {
            $query->where('slug', $payload['slug']);
        } else {
            return null;
        }

        if (Schema::hasColumn('dynamic_posts', 'post_type_id')) {
            $query->where('post_type_id', $postType->id);
        }

        if (Schema::hasColumn('dynamic_posts', 'post_type_slug')) {
            $query->where('post_type_slug', $postType->slug);
        }

        if (Schema::hasColumn('dynamic_posts', 'post_type')) {
            $query->where('post_type', $postType->slug);
        }

        return $query->first();
    }

    private function systemData(stdClass $post): array
    {
        $row = $this->rowToArray($post);

        return [
            'id' => $row['id'] ?? null,
            'title' => $row['title']
                ?? $row['post_title']
                ?? $row['name']
                ?? null,
            'slug' => $row['slug'] ?? null,
            'status' => $row['status'] ?? null,
            'content' => $row['content']
                ?? $row['description']
                ?? $row['post_content']
                ?? null,
            'excerpt' => $row['excerpt']
                ?? $row['short_description']
                ?? null,
            'featured_image' => $row['featured_image']
                ?? $row['thumbnail']
                ?? $row['image']
                ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    public function buildContext(int $postId, int $postTypeId): array
    {
        $postType = DB::table('post_types')->where('id', $postTypeId)->first();

        if (! $postType) {
            throw new \RuntimeException('Post type not found.');
        }

        $post = $this->findDynamicPostForContext($postId, $postType);

        if (! $post) {
            throw new \RuntimeException('Dynamic post not found.');
        }

        $taxonomyValues = $this->resolveTaxonomyValuesForContext($postId);

        $fields = [
            'system' => $this->resolveSystemFieldsForContext($post),
            'custom' => $this->resolveCustomFieldValuesForContext($postId, $postTypeId, $post),
            'taxonomy' => $taxonomyValues,
        ];

        return [
            'post_id' => $postId,
            'post_type_id' => $postTypeId,
            'post_type' => [
                'id' => $postType->id,
                'name' => $postType->name ?? null,
                'slug' => $postType->slug ?? null,
            ],
            'post' => [
                'id' => $post->id,
                'title' => $fields['system']['title'] ?? null,
                'slug' => $fields['system']['slug'] ?? null,
            ],
            'fields' => $fields,
            'taxonomies' => $taxonomyValues,
            'terms' => collect($taxonomyValues)->flatten(1)->values()->all(),
        ];
    }
    protected function findDynamicPostForContext(int $postId, object $postType): ?object
    {
        if (! Schema::hasTable('dynamic_posts')) {
            throw new \RuntimeException('dynamic_posts table not found.');
        }

        $query = DB::table('dynamic_posts')->where('id', $postId);

        if (Schema::hasColumn('dynamic_posts', 'post_type_id')) {
            $query->where('post_type_id', $postType->id);
        } elseif (Schema::hasColumn('dynamic_posts', 'post_type_slug')) {
            $query->where('post_type_slug', $postType->slug);
        } elseif (Schema::hasColumn('dynamic_posts', 'post_type')) {
            $query->where('post_type', $postType->slug);
        }

        return $query->first();
    }

    protected function resolveSystemFields(object $post): array
    {
        return [
            'id' => $post->id ?? null,
            'title' => $post->title
                ?? $post->name
                ?? $post->post_title
                ?? null,
            'slug' => $post->slug ?? null,
            'status' => $post->status ?? null,
            'content' => $post->content
                ?? $post->description
                ?? $post->post_content
                ?? null,
            'excerpt' => $post->excerpt
                ?? $post->short_description
                ?? null,
            'featured_image' => $post->featured_image
                ?? $post->image
                ?? $post->thumbnail
                ?? null,
            'created_at' => $post->created_at ?? null,
            'updated_at' => $post->updated_at ?? null,
        ];
    }

    protected function resolveCustomFieldValues(int $postId, int $postTypeId, object $post): array
    {
        $values = [];

        /*
     * Case 1:
     * Values stored as JSON inside dynamic_posts table.
     */
        foreach (['custom_fields', 'fields', 'field_values', 'meta', 'content_data'] as $column) {
            if (! property_exists($post, $column)) {
                continue;
            }

            $decoded = $this->decodeMaybeJson($post->{$column});

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['custom']) && is_array($decoded['custom'])) {
                $values = array_replace_recursive($values, $decoded['custom']);
            } else {
                $values = array_replace_recursive($values, $decoded);
            }
        }

        /*
     * Case 2:
     * Values stored in separate value table.
     */
        foreach ($this->customValueTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $postColumn = $this->firstExistingColumn($table, [
                'dynamic_post_id',
                'post_id',
                'model_id',
                'record_id',
            ]);

            if (! $postColumn) {
                continue;
            }

            $rows = DB::table($table)
                ->where($postColumn, $postId)
                ->get();

            foreach ($rows as $row) {
                $fieldKey = $this->resolveFieldKeyFromValueRow($table, $row);
                $value = $this->resolveFieldValueFromValueRow($table, $row);

                if ($fieldKey !== '') {
                    $values[$fieldKey] = $value;
                }
            }
        }

        /*
     * Remove accidental prefixes.
     * Backend widgets use custom.field_name.
     * Context array should store only field_name under custom.
     */
        $clean = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $key = str_starts_with($key, 'custom.')
                ? substr($key, 7)
                : $key;

            $clean[$key] = $value;
        }

        return $clean;
    }

    protected function resolveTaxonomyValues(int $postId): array
    {
        $result = [];

        foreach ($this->taxonomyPivotTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $postColumn = $this->firstExistingColumn($table, [
                'dynamic_post_id',
                'post_id',
                'model_id',
                'record_id',
            ]);

            $termColumn = $this->firstExistingColumn($table, [
                'taxonomy_term_id',
                'term_id',
            ]);

            if (! $postColumn || ! $termColumn) {
                continue;
            }

            $rows = DB::table($table)
                ->where($postColumn, $postId)
                ->get();

            foreach ($rows as $row) {
                $termId = $row->{$termColumn} ?? null;

                if (! $termId || ! Schema::hasTable('taxonomy_terms')) {
                    continue;
                }

                $term = DB::table('taxonomy_terms')->where('id', $termId)->first();

                if (! $term) {
                    continue;
                }

                $taxonomy = null;

                if (
                    isset($term->taxonomy_id)
                    && Schema::hasTable('taxonomies')
                ) {
                    $taxonomy = DB::table('taxonomies')->where('id', $term->taxonomy_id)->first();
                }

                $taxonomySlug = $taxonomy->slug
                    ?? $term->taxonomy_slug
                    ?? $term->taxonomy
                    ?? 'taxonomy';

                $result[$taxonomySlug][] = [
                    'id' => $term->id,
                    'name' => $term->name ?? $term->term_name ?? null,
                    'slug' => $term->slug ?? null,
                    'taxonomy_id' => $taxonomy->id ?? $term->taxonomy_id ?? null,
                    'taxonomy_slug' => $taxonomySlug,
                    'parent_id' => $term->parent_id ?? null,
                ];
            }
        }

        return $result;
    }

    protected function customValueTables(): array
    {
        return [
            'dynamic_post_field_values',
            'dynamic_post_custom_field_values',
            'custom_field_values',
            'post_custom_field_values',
            'post_meta',
        ];
    }

    protected function taxonomyPivotTables(): array
    {
        return [
            'post_taxonomy_terms',
            'dynamic_post_taxonomy_terms',
            'taxonomy_term_post',
            'post_terms',
        ];
    }

    protected function resolveFieldKeyFromValueRow(string $table, object $row): string
    {
        foreach (['field_key', 'field_name', 'field_slug', 'meta_key', 'key'] as $column) {
            if (Schema::hasColumn($table, $column) && ! empty($row->{$column})) {
                return (string) $row->{$column};
            }
        }

        if (
            Schema::hasColumn($table, 'custom_field_id')
            && ! empty($row->custom_field_id)
            && Schema::hasTable('custom_fields')
        ) {
            $field = DB::table('custom_fields')->where('id', $row->custom_field_id)->first();

            return (string) (
                $field->field_name_slug
                ?? $field->field_name
                ?? $field->slug
                ?? $field->name
                ?? ''
            );
        }

        if (
            Schema::hasColumn($table, 'field_id')
            && ! empty($row->field_id)
            && Schema::hasTable('custom_fields')
        ) {
            $field = DB::table('custom_fields')->where('id', $row->field_id)->first();

            return (string) (
                $field->field_name_slug
                ?? $field->field_name
                ?? $field->slug
                ?? $field->name
                ?? ''
            );
        }

        return '';
    }

    protected function resolveFieldValueFromValueRow(string $table, object $row): mixed
    {
        foreach (['field_value', 'value', 'meta_value', 'field_data', 'data'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $this->decodeMaybeJson($row->{$column} ?? null);
            }
        }

        return null;
    }

    protected function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    protected function decodeMaybeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
    private function customData(stdClass $post, PostType $postType): array
    {
        $row = $this->rowToArray($post);

        $custom = [];

        /*
         * Common JSON columns where dynamic/custom values may be stored.
         */
        foreach (
            [
                'content_data',
                'custom_fields',
                'field_values',
                'fields',
                'meta',
                'metadata',
                'data',
                'json_data',
            ] as $jsonColumn
        ) {
            if (! array_key_exists($jsonColumn, $row)) {
                continue;
            }

            $decoded = $this->decodeMaybeJson($row[$jsonColumn]);

            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['custom']) && is_array($decoded['custom'])) {
                $custom = array_replace_recursive($custom, $decoded['custom']);
            } else {
                $custom = array_replace_recursive($custom, $decoded);
            }
        }

        /*
         * If custom fields are stored as direct columns in dynamic_posts.
         */
        $ignoreColumns = [
            'id',
            'post_type_id',
            'post_type',
            'post_type_slug',
            'title',
            'post_title',
            'name',
            'slug',
            'status',
            'content',
            'description',
            'post_content',
            'excerpt',
            'short_description',
            'featured_image',
            'thumbnail',
            'image',
            'content_data',
            'custom_fields',
            'field_values',
            'fields',
            'meta',
            'metadata',
            'data',
            'json_data',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        foreach ($row as $key => $value) {
            if (in_array($key, $ignoreColumns, true)) {
                continue;
            }

            $custom[$key] = $this->decodeMaybeJson($value);
        }

        return $custom;
    }

    private function taxonomyData(stdClass $post): array
    {
        $row = $this->rowToArray($post);

        $termIds = [];
        $taxonomyTerms = [];
        $taxonomies = [];
        $terms = [];

        /*
         * If terms are stored directly in dynamic_posts JSON columns.
         */
        foreach (['taxonomy_term_ids', 'term_ids'] as $column) {
            if (isset($row[$column])) {
                $decoded = $this->decodeMaybeJson($row[$column]);

                if (is_array($decoded)) {
                    $termIds = array_merge($termIds, $decoded);
                }
            }
        }

        if (isset($row['taxonomy_terms'])) {
            $decoded = $this->decodeMaybeJson($row['taxonomy_terms']);

            if (is_array($decoded)) {
                $taxonomyTerms = $decoded;

                foreach ($decoded as $ids) {
                    if (is_array($ids)) {
                        $termIds = array_merge($termIds, $ids);
                    }
                }
            }
        }

        /*
         * If relations are stored in post_taxonomy_terms table.
         */
        if (Schema::hasTable('post_taxonomy_terms')) {
            $postColumn = $this->firstExistingColumn('post_taxonomy_terms', [
                'dynamic_post_id',
                'post_id',
                'content_id',
            ]);

            $termColumn = $this->firstExistingColumn('post_taxonomy_terms', [
                'taxonomy_term_id',
                'term_id',
            ]);

            if ($postColumn && $termColumn) {
                $relations = DB::table('post_taxonomy_terms')
                    ->where($postColumn, $row['id'])
                    ->get();

                foreach ($relations as $relation) {
                    $relationRow = $this->rowToArray($relation);

                    if (! empty($relationRow[$termColumn])) {
                        $termIds[] = $relationRow[$termColumn];
                    }
                }
            }
        }

        $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds))));

        if (! empty($termIds) && Schema::hasTable('taxonomy_terms')) {
            $termRows = DB::table('taxonomy_terms')
                ->whereIn('id', $termIds)
                ->get();

            foreach ($termRows as $term) {
                $termRow = $this->rowToArray($term);

                $terms[] = [
                    'id' => $termRow['id'] ?? null,
                    'name' => $termRow['name'] ?? $termRow['label'] ?? null,
                    'slug' => $termRow['slug'] ?? null,
                    'taxonomy_id' => $termRow['taxonomy_id'] ?? null,
                ];

                if (! empty($termRow['taxonomy_id'])) {
                    $taxonomyTerms[(string) $termRow['taxonomy_id']][] = $termRow['id'];
                }
            }
        }

        return [
            'taxonomy_term_ids' => $termIds,
            'taxonomy_terms' => $taxonomyTerms,
            'taxonomies' => $taxonomies,
            'terms' => $terms,
        ];
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    return $column;
                }
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    private function rowToArray(object $row): array
    {
        return json_decode(json_encode($row), true) ?: [];
    }

    private function decodeMaybeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}
