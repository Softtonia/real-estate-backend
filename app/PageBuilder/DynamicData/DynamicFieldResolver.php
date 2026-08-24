<?php

declare(strict_types=1);

namespace App\PageBuilder\DynamicData;

use App\PageBuilder\Contracts\DynamicResolverInterface;
use App\PageBuilder\Foundation\WidgetContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DynamicFieldResolver implements DynamicResolverInterface
{
    public function availableFields(?int $postTypeId = null, array $context = []): array
    {
        $postType = $postTypeId ? $this->findPostType($postTypeId) : null;

        $custom = $this->customFields($postType, $context);

        $areaSqft = array_values(array_filter($custom, function ($field) {
            $type = strtolower((string) ($field['type'] ?? 'text'));
            return in_array($type, ['number', 'integer', 'decimal', 'float', 'text'], true);
        }));

        return [
            'post_type_id' => $postTypeId,
            'system' => $this->systemFields($postType),
            'custom' => $custom,
            'area_sqft' => $areaSqft,
            'repeaters' => $this->repeaterFields($postType, $context),
            'taxonomies' => $this->taxonomyFields($postType),
            'relationships' => [],
        ];
    }

    public function resolveField(
        string $fieldKey,
        WidgetContext $context,
        mixed $default = null
    ): mixed {
        return $context->field($fieldKey, $default);
    }

    public function resolveMany(
        array $fieldKeys,
        WidgetContext $context
    ): array {
        $resolved = [];

        foreach ($fieldKeys as $fieldKey) {
            $resolved[$fieldKey] = $this->resolveField($fieldKey, $context);
        }

        return $resolved;
    }

    protected function findPostType(int $postTypeId): ?Model
    {
        $class = $this->modelClass('PostType');

        if (! $class || ! class_exists($class)) {
            return null;
        }

        try {
            return $class::query()->find($postTypeId);
        } catch (Throwable) {
            return null;
        }
    }

    protected function systemFields(?Model $postType): array
    {
        $supports = $this->normalizeSupports(
            $postType ? $this->attribute($postType, ['supports'], []) : []
        );

        $fields = [
            [
                'key' => 'system.id',
                'label' => 'ID',
                'type' => 'number',
                'source' => 'system',
            ],
            [
                'key' => 'system.title',
                'label' => 'Title',
                'type' => 'text',
                'source' => 'system',
            ],
            [
                'key' => 'system.slug',
                'label' => 'Slug',
                'type' => 'text',
                'source' => 'system',
            ],
            [
                'key' => 'system.status',
                'label' => 'Status',
                'type' => 'text',
                'source' => 'system',
            ],
            [
                'key' => 'system.created_at',
                'label' => 'Created At',
                'type' => 'datetime',
                'source' => 'system',
            ],
            [
                'key' => 'system.updated_at',
                'label' => 'Updated At',
                'type' => 'datetime',
                'source' => 'system',
            ],
        ];

        if (in_array('editor', $supports, true) || in_array('content', $supports, true)) {
            $fields[] = [
                'key' => 'system.content',
                'label' => 'Content',
                'type' => 'texteditor',
                'source' => 'system',
            ];
        }

        if (in_array('excerpt', $supports, true)) {
            $fields[] = [
                'key' => 'system.excerpt',
                'label' => 'Excerpt',
                'type' => 'textarea',
                'source' => 'system',
            ];
        }

        if (in_array('featured_image', $supports, true) || in_array('thumbnail', $supports, true)) {
            $fields[] = [
                'key' => 'system.featured_image',
                'label' => 'Featured Image',
                'type' => 'media',
                'source' => 'system',
            ];
        }

        return $fields;
    }

    protected function customFields(?Model $postType, array $context = []): array
    {
        if (! $postType) {
            return [];
        }

        $fields = [];

        foreach ($this->fieldGroups($postType) as $group) {
            if (! $this->groupIsActive($group)) {
                continue;
            }

            foreach ($this->fieldsFromGroup($group) as $field) {
                $normalized = $this->normalizeField($field, $group);

                if (! $normalized) {
                    continue;
                }

                if ($normalized['type'] === 'repeater') {
                    continue;
                }

                $fields[] = $normalized;
            }
        }

        return array_values($fields);
    }

    protected function repeaterFields(?Model $postType, array $context = []): array
    {
        if (! $postType) {
            return [];
        }

        $repeaters = [];

        foreach ($this->fieldGroups($postType) as $group) {
            if (! $this->groupIsActive($group)) {
                continue;
            }

            foreach ($this->fieldsFromGroup($group) as $field) {
                $normalized = $this->normalizeField($field, $group);

                if (! $normalized || $normalized['type'] !== 'repeater') {
                    continue;
                }

                $normalized['sub_fields'] = $this->subFields($field);

                $repeaters[] = $normalized;
            }
        }

        return array_values($repeaters);
    }

    protected function taxonomyFields(?Model $postType): array
    {
        if (! $postType) {
            return [];
        }

        $taxonomies = [];

        foreach ($this->taxonomiesFromPostType($postType) as $taxonomy) {
            $slug = (string) $this->attribute($taxonomy, ['slug'], '');

            if ($slug === '') {
                continue;
            }

            $taxonomies[] = [
                'key' => 'taxonomy.'.$slug,
                'label' => (string) $this->attribute($taxonomy, ['name', 'label'], Str::headline($slug)),
                'slug' => $slug,
                'type' => 'taxonomy',
                'source' => 'taxonomy',
                'hierarchical' => (bool) $this->attribute($taxonomy, ['hierarchical', 'is_hierarchical'], false),
                'terms' => $this->termsFromTaxonomy($taxonomy),
            ];
        }

        return $taxonomies;
    }

    protected function fieldGroups(Model $postType): array
    {
        $groups = [];

        foreach (['customFieldGroups', 'fieldGroups', 'custom_field_groups'] as $relation) {
            if (method_exists($postType, $relation)) {
                try {
                    $related = $postType->{$relation}()->with(['fields'])->get();

                    if ($related instanceof EloquentCollection) {
                        return $related->all();
                    }
                } catch (Throwable) {
                    //
                }
            }
        }

        $groupClass = $this->modelClass('CustomFieldGroup');

        if (! $groupClass || ! class_exists($groupClass)) {
            return $groups;
        }

        try {
            $instance = new $groupClass();
            $table = $instance->getTable();

            if (Schema::hasColumn($table, 'post_type_id')) {
                return $groupClass::query()
                    ->where('post_type_id', $postType->getKey())
                    ->with(['fields'])
                    ->get()
                    ->all();
            }
        } catch (Throwable) {
            //
        }

        return $groups;
    }

    protected function fieldsFromGroup(Model $group): array
    {
        foreach (['fields', 'customFields', 'custom_fields'] as $relation) {
            if (method_exists($group, $relation)) {
                try {
                    $fields = $group->{$relation}()->get();

                    if ($fields instanceof EloquentCollection) {
                        return $fields->all();
                    }
                } catch (Throwable) {
                    //
                }
            }
        }

        if ($group->relationLoaded('fields')) {
            $fields = $group->getRelation('fields');

            if ($fields instanceof EloquentCollection) {
                return $fields->all();
            }
        }

        return [];
    }

    protected function subFields(Model $field): array
    {
        $subFields = [];

        foreach (['subFields', 'repeaterFields', 'children', 'fields'] as $relation) {
            if (method_exists($field, $relation)) {
                try {
                    $items = $field->{$relation}()->get();

                    foreach ($items as $item) {
                        $normalized = $this->normalizeField($item);

                        if ($normalized) {
                            $subFields[] = $normalized;
                        }
                    }

                    return $subFields;
                } catch (Throwable) {
                    //
                }
            }
        }

        $raw = $this->attribute($field, ['sub_fields', 'repeater_fields'], []);

        foreach ($this->toArray($raw) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = (string) data_get($item, 'slug', data_get($item, 'name', ''));

            if ($slug === '') {
                continue;
            }

            $subFields[] = [
                'key' => 'custom.'.$slug,
                'label' => (string) data_get($item, 'label', Str::headline($slug)),
                'slug' => $slug,
                'type' => (string) data_get($item, 'type', 'text'),
                'source' => 'custom',
            ];
        }

        return $subFields;
    }

    protected function taxonomiesFromPostType(Model $postType): array
    {
        foreach (['taxonomies', 'taxonomy'] as $relation) {
            if (method_exists($postType, $relation)) {
                try {
                    $taxonomies = $postType->{$relation}()->get();

                    if ($taxonomies instanceof EloquentCollection) {
                        return $taxonomies->all();
                    }
                } catch (Throwable) {
                    //
                }
            }
        }

        $taxonomyClass = $this->modelClass('Taxonomy');

        if (! $taxonomyClass || ! class_exists($taxonomyClass)) {
            return [];
        }

        try {
            $instance = new $taxonomyClass();
            $table = $instance->getTable();

            if (Schema::hasColumn($table, 'post_type_id')) {
                return $taxonomyClass::query()
                    ->where('post_type_id', $postType->getKey())
                    ->get()
                    ->all();
            }
        } catch (Throwable) {
            //
        }

        return [];
    }

    protected function termsFromTaxonomy(Model $taxonomy): array
    {
        foreach (['terms', 'taxonomyTerms'] as $relation) {
            if (method_exists($taxonomy, $relation)) {
                try {
                    $terms = $taxonomy->{$relation}()->get();

                    return $terms
                        ->map(function ($term) {
                            $slug = (string) $this->attribute($term, ['slug'], '');

                            return [
                                'id' => $term->getKey(),
                                'name' => (string) $this->attribute($term, ['name', 'label'], ''),
                                'slug' => $slug,
                                'parent_id' => $this->attribute($term, ['parent_id'], null),
                            ];
                        })
                        ->values()
                        ->all();
                } catch (Throwable) {
                    //
                }
            }
        }

        return [];
    }

    protected function normalizeField(Model $field, ?Model $group = null): ?array
    {
        $slug = (string) $this->attribute($field, ['slug', 'name', 'key'], '');

        if ($slug === '') {
            return null;
        }

        $type = (string) $this->attribute($field, ['type', 'field_type', 'input_type'], 'text');

        $source = $type === 'repeater' ? 'repeater' : 'custom';

        return [
            'key' => $source.'.'.$slug,
            'label' => (string) $this->attribute($field, ['label', 'title', 'name'], Str::headline($slug)),
            'slug' => $slug,
            'type' => $type,
            'source' => $source,
            'required' => (bool) $this->attribute($field, ['required', 'is_required'], false),
            'default_value' => $this->attribute($field, ['default_value', 'default'], null),
            'options' => $this->toArray($this->attribute($field, ['options'], [])),
            'group' => $group ? [
                'id' => $group->getKey(),
                'name' => (string) $this->attribute($group, ['name', 'title'], ''),
            ] : null,
        ];
    }

    protected function groupIsActive(Model $group): bool
    {
        $status = $this->attribute($group, ['status', 'is_active'], null);

        if ($status === null) {
            return true;
        }

        if (is_bool($status)) {
            return $status;
        }

        return in_array((string) $status, ['1', 'active', 'published', 'enabled'], true);
    }

    protected function normalizeSupports(mixed $supports): array
    {
        $supports = $this->toArray($supports);

        return array_values(
            array_filter(
                array_map('strval', $supports)
            )
        );
    }

    protected function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof EloquentCollection) {
            return $value->toArray();
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function attribute(object $model, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            try {
                if (method_exists($model, 'getAttribute')) {
                    $value = $model->getAttribute($key);

                    if ($value !== null) {
                        return $value;
                    }
                }

                if (isset($model->{$key})) {
                    return $model->{$key};
                }
            } catch (Throwable) {
                //
            }
        }

        return $default;
    }

    protected function modelClass(string $name): ?string
    {
        $class = 'App\\Models\\'.$name;

        return class_exists($class) ? $class : null;
    }
}