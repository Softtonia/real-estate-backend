<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;

class DynamicPostFieldValueService
{
    public function resolveForPost(
        int $postId,
        ?int $postTypeId = null,
        ?string $postTypeSlug = null
    ): array {
        $post = $this->findPost($postId, $postTypeId, $postTypeSlug);

        if (! $post) {
            return $this->emptyResponse();
        }

        $postType = $this->resolvePostType($post, $postTypeId, $postTypeSlug);

        $resolvedPostTypeId = $postType?->id
            ?? $postTypeId
            ?? ($post->post_type_id ?? null);

        $resolvedPostTypeSlug = $postType?->slug
            ?? $postTypeSlug
            ?? ($post->post_type_slug ?? $post->post_type ?? null);

        $system = $this->systemFields($post, $postType);

        $taxonomyData = $this->taxonomyData($post);

        $assignedTermIds = collect($taxonomyData['taxonomy_term_ids'] ?? [])
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        /*
         * Existing data stored in dynamic_posts JSON/direct columns.
         */
        $baseCustomValues = $this->customValuesFromPost($post);

        /*
         * Custom field definitions filtered by post type + assigned taxonomy terms.
         */
        $applicableFields = $this->applicableCustomFields(
            $resolvedPostTypeId ? (int) $resolvedPostTypeId : null,
            $resolvedPostTypeSlug ? (string) $resolvedPostTypeSlug : null,
            $assignedTermIds
        );

        $custom = [];
        $customMeta = [];

        foreach ($applicableFields as $field) {
            $fieldKey = $this->fieldKey($field);

            if ($fieldKey === '') {
                continue;
            }

            $fieldType = $this->fieldType($field);

            $rawValue = $this->valueForField(
                (int) ($post->id ?? $postId),
                $field,
                $fieldKey
            );

            /*
             * Fallback to dynamic_posts JSON/direct column value.
             */
            if ($rawValue === null && array_key_exists($fieldKey, $baseCustomValues)) {
                $rawValue = $baseCustomValues[$fieldKey];
            }

            $value = $this->normalizeValue($rawValue, $fieldType);

            $custom[$fieldKey] = $value;

            $customMeta[$fieldKey] = [
                'field_id' => $field->id ?? null,
                'label' => $this->fieldLabel($field),
                'key' => $fieldKey,
                'type' => $fieldType,
                'widget_type' => $this->mapFieldTypeToWidgetType($fieldType),
                'value' => $value,
                'raw_value' => $rawValue,
                'display_value' => $this->displayValue($value, $fieldType),
            ];
        }

        /*
         * Keep old direct custom values also available.
         */
        foreach ($baseCustomValues as $key => $value) {
            if (! array_key_exists($key, $custom)) {
                $custom[$key] = $this->decodeMaybeJson($value);
            }
        }

        $fields = [
            'system' => $system,
            'custom' => $custom,
            'custom_meta' => $customMeta,
            'taxonomies' => $taxonomyData['taxonomies'] ?? [],
        ];

        return [
            'post' => $this->rowToArray($post),

            'post_type' => $postType ? [
                'id' => $postType->id ?? null,
                'name' => $postType->name ?? null,
                'slug' => $postType->slug ?? null,
            ] : [
                'id' => $resolvedPostTypeId,
                'name' => null,
                'slug' => $resolvedPostTypeSlug,
            ],

            'assigned_terms' => $taxonomyData['terms'] ?? [],

            'taxonomy_term_ids' => $taxonomyData['taxonomy_term_ids'] ?? [],
            'taxonomy_terms' => $taxonomyData['taxonomy_terms'] ?? [],
            'taxonomies' => $taxonomyData['taxonomies'] ?? [],
            'terms' => $taxonomyData['terms'] ?? [],

            'content_data' => $fields,
            'fields' => $fields,
        ];
    }

    protected function emptyResponse(): array
    {
        return [
            'post' => null,
            'post_type' => null,
            'assigned_terms' => [],
            'taxonomy_term_ids' => [],
            'taxonomy_terms' => [],
            'taxonomies' => [],
            'terms' => [],
            'content_data' => [
                'system' => [],
                'custom' => [],
                'custom_meta' => [],
                'taxonomies' => [],
            ],
            'fields' => [
                'system' => [],
                'custom' => [],
                'custom_meta' => [],
                'taxonomies' => [],
            ],
        ];
    }

    protected function findPost(
        int $postId,
        ?int $postTypeId = null,
        ?string $postTypeSlug = null
    ): ?object {
        $table = $this->postsTable();

        if (! $table || ! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table)->where('id', $postId);

        if ($postTypeId && Schema::hasColumn($table, 'post_type_id')) {
            $query->where('post_type_id', $postTypeId);
        }

        if ($postTypeSlug && Schema::hasColumn($table, 'post_type_slug')) {
            $query->where('post_type_slug', $postTypeSlug);
        }

        if ($postTypeSlug && Schema::hasColumn($table, 'post_type')) {
            $query->where('post_type', $postTypeSlug);
        }

        return $query->first();
    }

    protected function resolvePostType(
        object $post,
        ?int $postTypeId = null,
        ?string $postTypeSlug = null
    ): ?PostType {
        if ($postTypeId) {
            return PostType::query()->where('id', $postTypeId)->first();
        }

        if (! empty($post->post_type_id)) {
            return PostType::query()->where('id', $post->post_type_id)->first();
        }

        $slug = $postTypeSlug
            ?? $post->post_type_slug
            ?? $post->post_type
            ?? null;

        if ($slug) {
            return PostType::query()->where('slug', $slug)->first();
        }

        return null;
    }

    protected function systemFields(object $post, ?PostType $postType): array
    {
        $listingId = $this->firstValue($post, ['listing_id', 'listing_code']);

        return [
            'id' => $post->id ?? null,
            'title' => $this->firstValue($post, ['title', 'post_title', 'name']),
            'slug' => $this->firstValue($post, ['slug', 'post_slug']),
            'listing_id' => $listingId,
            'listing_code' => $listingId,
            'status' => $this->firstValue($post, ['status']),
            'content' => $this->firstValue($post, ['content', 'description', 'post_content']),
            'excerpt' => $this->firstValue($post, ['excerpt', 'short_description']),
            'featured_image' => $this->firstValue($post, ['featured_image', 'thumbnail', 'image']),
            'post_type_id' => $postType->id ?? $post->post_type_id ?? null,
            'post_type_slug' => $postType->slug ?? $post->post_type_slug ?? $post->post_type ?? null,
            'created_at' => !empty($post->created_at) ? (\Carbon\Carbon::tryParse((string) $post->created_at)?->format('Y-m-d') ?? (is_string($post->created_at) ? explode(' ', (string) $post->created_at)[0] : $post->created_at)) : null,
            'updated_at' => !empty($post->updated_at) ? (\Carbon\Carbon::tryParse((string) $post->updated_at)?->format('Y-m-d') ?? (is_string($post->updated_at) ? explode(' ', (string) $post->updated_at)[0] : $post->updated_at)) : null,
        ];
    }

    protected function customValuesFromPost(object $post): array
    {
        $row = $this->rowToArray($post);
        $custom = [];

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
            } elseif (isset($decoded['fields']['custom']) && is_array($decoded['fields']['custom'])) {
                $custom = array_replace_recursive($custom, $decoded['fields']['custom']);
            } else {
                $custom = array_replace_recursive($custom, $decoded);
            }
        }

        $ignoreColumns = [
            'id',
            'post_type_id',
            'post_type',
            'post_type_slug',
            'title',
            'post_title',
            'name',
            'slug',
            'listing_code',
            'listing_id',
            'status',
            'content',
            'description',
            'post_content',
            'excerpt',
            'short_description',
            'featured_image',
            'thumbnail',
            'image',
            'taxonomy_term_ids',
            'taxonomy_terms',
            'term_ids',
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

    protected function applicableCustomFields(
        ?int $postTypeId,
        ?string $postTypeSlug,
        array $assignedTermIds
    ): Collection {
        $table = $this->customFieldsTable();

        if (! $table || ! Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'post_type_id') && $postTypeId) {
            $query->where('post_type_id', $postTypeId);
        } elseif (Schema::hasColumn($table, 'post_type') && $postTypeSlug) {
            $query->where('post_type', $postTypeSlug);
        } elseif (Schema::hasColumn($table, 'post_type_slug') && $postTypeSlug) {
            $query->where('post_type_slug', $postTypeSlug);
        }

        if (Schema::hasColumn($table, 'status')) {
            $query->where(function ($q) {
                $q->where('status', true)
                    ->orWhere('status', 1)
                    ->orWhere('status', '1')
                    ->orWhere('status', 'active');
            });
        }

        if (Schema::hasColumn($table, 'sort_order')) {
            $query->orderBy('sort_order');
        } elseif (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        return $query->get()
            ->filter(function ($field) use ($assignedTermIds) {
                $fieldTermIds = $this->fieldAssignedTermIds($field);

                /*
                 * Empty assignment means global field for this post type.
                 */
                if (empty($fieldTermIds)) {
                    return true;
                }

                return ! empty(array_intersect($fieldTermIds, $assignedTermIds));
            })
            ->values();
    }

    protected function fieldAssignedTermIds(object $field): array
    {
        $directIds = $this->termIdsFromFieldColumns($field);

        if (! empty($directIds)) {
            return $directIds;
        }

        $assignmentTable = $this->firstExistingTable(
            config('page_builder_dynamic.tables.custom_field_term_assignments', [])
        );

        if (! $assignmentTable || empty($field->id)) {
            return [];
        }

        $fieldIdColumn = $this->firstExistingColumn(
            $assignmentTable,
            config('page_builder_dynamic.columns.custom_field_id', [])
        );

        $termIdColumn = $this->firstExistingColumn(
            $assignmentTable,
            config('page_builder_dynamic.columns.term_id', [])
        );

        if (! $fieldIdColumn || ! $termIdColumn) {
            return [];
        }

        return DB::table($assignmentTable)
            ->where($fieldIdColumn, $field->id)
            ->pluck($termIdColumn)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function termIdsFromFieldColumns(object $field): array
    {
        foreach (
            [
                'taxonomy_term_ids',
                'term_ids',
                'assigned_term_ids',
                'taxonomy_terms',
            ] as $column
        ) {
            if (! isset($field->{$column})) {
                continue;
            }

            return $this->normalizeIds($field->{$column});
        }

        return [];
    }

    protected function valueForField(int $postId, object $field, string $fieldKey): mixed
    {
        $valueTable = $this->firstExistingTable(
            config('page_builder_dynamic.tables.custom_field_values', [])
        );

        if (! $valueTable) {
            return null;
        }

        $postIdColumn = $this->firstExistingColumn(
            $valueTable,
            config('page_builder_dynamic.columns.post_id', [])
        );

        if (! $postIdColumn) {
            return null;
        }

        $query = DB::table($valueTable)
            ->where($postIdColumn, $postId);

        /*
     * If entity_type exists, restrict to dynamic_post where possible.
     */
        $entityTypeColumn = $this->firstExistingColumn(
            $valueTable,
            config('page_builder_dynamic.columns.entity_type', [])
        );

        if ($entityTypeColumn) {
            $query->where(function ($q) use ($entityTypeColumn) {
                $q->where($entityTypeColumn, 'dynamic_post')
                    ->orWhere($entityTypeColumn, 'dynamic_posts')
                    ->orWhereNull($entityTypeColumn);
            });
        }

        $query->where(function ($q) use ($valueTable, $field, $fieldKey) {
            $added = false;

            foreach (config('page_builder_dynamic.columns.custom_field_id', []) as $column) {
                if (Schema::hasColumn($valueTable, $column) && ! empty($field->id)) {
                    $q->orWhere($column, $field->id);
                    $added = true;
                }
            }

            foreach (config('page_builder_dynamic.columns.field_key', []) as $column) {
                if (Schema::hasColumn($valueTable, $column)) {
                    $q->orWhere($column, $fieldKey);
                    $added = true;
                }
            }

            if (! $added) {
                $q->whereRaw('1 = 0');
            }
        });

        if (Schema::hasColumn($valueTable, 'id')) {
            $query->orderByDesc('id');
        }

        $row = $query->first();

        if (! $row) {
            return null;
        }

        /*
     * Important:
     * custom_field_values can have value_text, value_number, value_json, etc.
     * We must return first non-null value, not only the first existing column.
     */
        foreach (config('page_builder_dynamic.columns.field_value', []) as $column) {
            if (! Schema::hasColumn($valueTable, $column)) {
                continue;
            }

            if (property_exists($row, $column) && $row->{$column} !== null && $row->{$column} !== '') {
                return $row->{$column};
            }
        }

        return null;
    }

    protected function taxonomyData(object $post): array
    {
        $row = $this->rowToArray($post);

        $termIds = [];

        foreach (['taxonomy_term_ids', 'term_ids'] as $column) {
            if (! isset($row[$column])) {
                continue;
            }

            $termIds = array_merge($termIds, $this->normalizeIds($row[$column]));
        }

        if (isset($row['taxonomy_terms'])) {
            $termIds = array_merge($termIds, $this->normalizeIds($row['taxonomy_terms']));
        }

        $relationTable = $this->firstExistingTable(
            config('page_builder_dynamic.tables.post_term_relations', [])
        );

        if ($relationTable && ! empty($row['id'])) {
            $postColumn = $this->firstExistingColumn(
                $relationTable,
                config('page_builder_dynamic.columns.post_id', [])
            );

            $termColumn = $this->firstExistingColumn(
                $relationTable,
                config('page_builder_dynamic.columns.term_id', [])
            );

            if ($postColumn && $termColumn) {
                $relationTermIds = DB::table($relationTable)
                    ->where($postColumn, $row['id'])
                    ->pluck($termColumn)
                    ->all();

                $termIds = array_merge($termIds, $this->normalizeIds($relationTermIds));
            }
        }

        $termIds = collect($termIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $terms = $this->loadTermDetails($termIds);

        return [
            'taxonomy_term_ids' => $termIds,
            'taxonomy_terms' => $this->groupTermIdsByTaxonomy($terms),
            'taxonomies' => $this->taxonomyFields($terms),
            'terms' => $terms,
        ];
    }

    protected function loadTermDetails(array $termIds): array
    {
        $termsTable = $this->firstExistingTable(
            config('page_builder_dynamic.tables.taxonomy_terms', [])
        );

        if (! $termsTable || empty($termIds)) {
            return [];
        }

        $termRows = DB::table($termsTable)
            ->whereIn('id', $termIds)
            ->get();

        $result = [];

        foreach ($termRows as $term) {
            $taxonomy = $this->resolveTaxonomyForTerm($term);

            $result[] = [
                'taxonomy_id' => $term->taxonomy_id ?? $taxonomy?->id ?? null,
                'taxonomy_slug' => $term->taxonomy_slug ?? $taxonomy?->slug ?? null,
                'taxonomy_name' => $taxonomy?->name ?? null,

                'term_id' => $term->id ?? null,
                'term_name' => $term->name ?? $term->label ?? $term->term_name ?? null,
                'term_slug' => $term->slug ?? $term->term_slug ?? null,
            ];
        }

        return $result;
    }

    protected function resolveTaxonomyForTerm(object $term): ?object
    {
        $taxonomyId = $term->taxonomy_id ?? null;

        if (! $taxonomyId) {
            return null;
        }

        $taxonomyTable = $this->firstExistingTable(
            config('page_builder_dynamic.tables.taxonomies', [])
        );

        if (! $taxonomyTable) {
            return null;
        }

        return DB::table($taxonomyTable)
            ->where('id', $taxonomyId)
            ->first();
    }

    protected function groupTermIdsByTaxonomy(array $terms): array
    {
        $grouped = [];

        foreach ($terms as $term) {
            if (! empty($term['taxonomy_id'])) {
                $grouped[(string) $term['taxonomy_id']][] = $term['term_id'];
            }

            if (! empty($term['taxonomy_slug'])) {
                $grouped[(string) $term['taxonomy_slug']][] = $term['term_id'];
            }
        }

        foreach ($grouped as $key => $ids) {
            $grouped[$key] = collect($ids)
                ->filter()
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $grouped;
    }

    protected function taxonomyFields(array $terms): array
    {
        $grouped = [];

        foreach ($terms as $term) {
            $taxonomyKey = $term['taxonomy_slug']
                ?: 'taxonomy_' . ($term['taxonomy_id'] ?? 'unknown');

            if (! isset($grouped[$taxonomyKey])) {
                $grouped[$taxonomyKey] = [
                    'taxonomy_id' => $term['taxonomy_id'] ?? null,
                    'taxonomy_slug' => $term['taxonomy_slug'] ?? null,
                    'taxonomy_name' => $term['taxonomy_name'] ?? null,
                    'terms' => [],
                ];
            }

            $grouped[$taxonomyKey]['terms'][] = [
                'id' => $term['term_id'] ?? null,
                'name' => $term['term_name'] ?? null,
                'slug' => $term['term_slug'] ?? null,
            ];
        }

        return $grouped;
    }

    protected function normalizeValue(mixed $value, string $fieldType): mixed
    {
        $value = $this->decodeMaybeJson($value);
        $fieldType = strtolower($fieldType);

        return match ($fieldType) {
            'image', 'media', 'file', 'featured_image' => $this->normalizeImageValue($value),
            'gallery', 'images' => $this->normalizeGalleryValue($value),
            'repeater', 'array', 'json', 'checkbox', 'multi_select', 'multiselect' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    protected function normalizeImageValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['url']
                ?? $value['full_url']
                ?? $value['src']
                ?? $value['path']
                ?? $value;
        }

        return $value;
    }

    protected function normalizeGalleryValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = array_map('trim', explode(',', $value));
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($item) {
                if (is_string($item)) {
                    return [
                        'url' => $item,
                        'alt' => '',
                    ];
                }

                if (is_array($item)) {
                    return [
                        'url' => $item['url']
                            ?? $item['full_url']
                            ?? $item['src']
                            ?? $item['path']
                            ?? '',
                        'alt' => $item['alt'] ?? $item['title'] ?? '',
                    ];
                }

                return null;
            })
            ->filter(fn($item) => is_array($item) && ! empty($item['url']))
            ->values()
            ->all();
    }

    protected function displayValue(mixed $value, string $fieldType): mixed
    {
        return $value;
    }

    protected function fieldKey(object $field): string
    {
        return (string) (
            $field->field_name_slug
            ?? $field->field_name
            ?? $field->slug
            ?? $field->name
            ?? ''
        );
    }

    protected function fieldLabel(object $field): string
    {
        return (string) (
            $field->field_label
            ?? $field->label
            ?? $field->name
            ?? $this->fieldKey($field)
        );
    }

    protected function fieldType(object $field): string
    {
        return strtolower((string) (
            $field->field_type
            ?? $field->type
            ?? 'text'
        ));
    }

    protected function mapFieldTypeToWidgetType(string $fieldType): string
    {
        return match ($fieldType) {
            'textarea', 'texteditor', 'editor', 'wysiwyg', 'richtext' => 'text',
            'image', 'media', 'file', 'featured_image' => 'image',
            'gallery', 'images' => 'gallery',
            'repeater', 'array', 'json' => 'repeater',
            'taxonomy', 'terms', 'taxonomy_terms' => 'taxonomy_terms',
            'html', 'custom_html', 'code' => 'html',
            'url', 'link' => 'button',
            default => 'text',
        };
    }

    protected function firstValue(object $object, array $columns): mixed
    {
        foreach ($columns as $column) {
            if (isset($object->{$column}) && $object->{$column} !== '') {
                return $object->{$column};
            }
        }

        return null;
    }

    protected function normalizeIds(mixed $value): array
    {
        $value = $this->decodeMaybeJson($value);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->filter(fn($id) => $id !== null && $id !== '')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function decodeMaybeJson(mixed $value): mixed
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

    protected function rowToArray(object $row): array
    {
        return json_decode(json_encode($row), true) ?: [];
    }

    protected function firstExistingTable(array|string $tables): ?string
    {
        foreach ((array) $tables as $table) {
            try {
                if (Schema::hasTable($table)) {
                    return $table;
                }
            } catch (Throwable) {
                //
            }
        }

        return null;
    }

    protected function firstExistingColumn(string $table, array $columns): ?string
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

    protected function postsTable(): ?string
    {
        return config('page_builder_dynamic.tables.posts', 'dynamic_posts');
    }

    protected function customFieldsTable(): ?string
    {
        return config('page_builder_dynamic.tables.custom_fields', 'custom_fields');
    }
}
