<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class TaxonomyTermsWidget extends BaseWidget
{
    protected static string $type = 'taxonomy_terms';

    protected string $name = 'Taxonomy Terms';

    protected string $category = 'Dynamic';

    protected string $icon = 'tags';

    protected string $description = 'Display taxonomy terms from the current dynamic post.';

    public function defaultSettings(): array
    {
        return [
            /*
             * context = use terms from current post context
             * dynamic = use selected dynamic field
             * static  = manually entered terms
             */
            'source' => 'context',

            'field' => null,

            /*
             * Optional filters.
             */
            'taxonomy_id' => null,
            'taxonomy_slug' => null,

            /*
             * inline | list | badges
             */
            'layout' => 'badges',

            /*
             * name | slug | id | name_slug
             */
            'display' => 'name',

            'separator' => ', ',
            'empty_message' => '',
            'terms' => [],
            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name' => 'source',
                'label' => 'Source',
                'type' => 'select',
                'options' => [
                    ['label' => 'Current Post Terms', 'value' => 'context'],
                    ['label' => 'Dynamic Field', 'value' => 'dynamic'],
                    ['label' => 'Static', 'value' => 'static'],
                ],
            ],
            [
                'name' => 'field',
                'label' => 'Dynamic Taxonomy Field',
                'type' => 'dynamic_field',
                'accepted_types' => ['taxonomy', 'terms', 'array', 'json'],
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name' => 'taxonomy_id',
                'label' => 'Taxonomy ID Filter',
                'type' => 'number',
            ],
            [
                'name' => 'taxonomy_slug',
                'label' => 'Taxonomy Slug Filter',
                'type' => 'text',
            ],
            [
                'name' => 'layout',
                'label' => 'Layout',
                'type' => 'select',
                'options' => [
                    ['label' => 'Badges', 'value' => 'badges'],
                    ['label' => 'Inline', 'value' => 'inline'],
                    ['label' => 'List', 'value' => 'list'],
                ],
            ],
            [
                'name' => 'display',
                'label' => 'Display',
                'type' => 'select',
                'options' => [
                    ['label' => 'Name', 'value' => 'name'],
                    ['label' => 'Slug', 'value' => 'slug'],
                    ['label' => 'ID', 'value' => 'id'],
                    ['label' => 'Name + Slug', 'value' => 'name_slug'],
                ],
            ],
            [
                'name' => 'separator',
                'label' => 'Inline Separator',
                'type' => 'text',
            ],
            [
                'name' => 'empty_message',
                'label' => 'Empty Message',
                'type' => 'text',
            ],
            [
                'name' => 'terms',
                'label' => 'Static Terms',
                'type' => 'repeater',
                'show_when' => ['source' => 'static'],
            ],
            [
                'name' => 'class',
                'label' => 'CSS Class',
                'type' => 'text',
            ],
        ];
    }

    public function validateSettings(array $settings): array
    {
        $settings = parent::validateSettings($settings);

        $settings['source'] = in_array($settings['source'], ['context', 'dynamic', 'static'], true)
            ? $settings['source']
            : 'context';

        $settings['layout'] = in_array($settings['layout'], ['badges', 'inline', 'list'], true)
            ? $settings['layout']
            : 'badges';

        $settings['display'] = in_array($settings['display'], ['name', 'slug', 'id', 'name_slug'], true)
            ? $settings['display']
            : 'name';

        $settings['taxonomy_id'] = ! empty($settings['taxonomy_id'])
            ? (int) $settings['taxonomy_id']
            : null;

        $settings['taxonomy_slug'] = preg_replace(
            '/[^a-zA-Z0-9_\-]/',
            '',
            (string) ($settings['taxonomy_slug'] ?? '')
        );

        $settings['separator'] = strip_tags((string) ($settings['separator'] ?? ', '));

        $settings['class'] = preg_replace(
            '/[^a-zA-Z0-9_\-\s]/',
            '',
            (string) ($settings['class'] ?? '')
        );

        if (! is_array($settings['terms'] ?? null)) {
            $settings['terms'] = [];
        }

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $terms = $this->resolveTerms($settings, $context);

        $terms = $this->filterTerms($terms, $settings);

        if (empty($terms)) {
            return ! empty($settings['empty_message'])
                ? '<div class="pb-taxonomy-empty">' . e($settings['empty_message']) . '</div>'
                : '';
        }

        return match ($settings['layout']) {
            'list' => $this->renderList($terms, $settings),
            'inline' => $this->renderInline($terms, $settings),
            default => $this->renderBadges($terms, $settings),
        };
    }

    protected function resolveTerms(array $settings, WidgetContext $context): array
    {
        if ($settings['source'] === 'dynamic') {
            $field = $settings['field'] ?? null;

            if (! $field) {
                return [];
            }

            return $this->normalizeTerms(
                $context->field($field, [])
            );
        }

        if ($settings['source'] === 'static') {
            return $this->normalizeTerms($settings['terms'] ?? []);
        }

        /*
         * Default: current post context terms.
         */
        $terms = $context->terms();

        if (! empty($terms)) {
            return $this->normalizeTerms($terms);
        }

        /*
         * Fallback: request taxonomy_terms can contain grouped term IDs.
         */
        $requestTerms = $context->request('taxonomy_terms', []);

        if (! empty($requestTerms)) {
            return $this->normalizeTerms($requestTerms);
        }

        return [];
    }

    protected function normalizeTerms(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeTerms($decoded);
            }

            return array_map(function ($item) {
                return [
                    'id' => null,
                    'name' => trim($item),
                    'slug' => null,
                    'taxonomy_id' => null,
                    'taxonomy_slug' => null,
                ];
            }, array_filter(explode(',', $value)));
        }

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (! is_array($value)) {
            return [];
        }

        /*
         * Support:
         * { terms: [...] }
         */
        if (isset($value['terms']) && is_array($value['terms'])) {
            return $this->normalizeTerms($value['terms']);
        }

        /*
         * Support grouped terms:
         * {
         *   "purpose": [1,2],
         *   "property_type": [{"id": 3, "name": "Apartment"}]
         * }
         */
        if (! array_is_list($value)) {
            $flattened = [];

            foreach ($value as $taxonomyKey => $items) {
                foreach ($this->normalizeTerms($items) as $term) {
                    if (empty($term['taxonomy_slug']) && is_string($taxonomyKey)) {
                        $term['taxonomy_slug'] = $taxonomyKey;
                    }

                    $flattened[] = $term;
                }
            }

            return $flattened;
        }

        return collect($value)
            ->map(function ($term) {
                return $this->normalizeSingleTerm($term);
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeSingleTerm(mixed $term): ?array
    {
        if ($term === null || $term === '') {
            return null;
        }

        if (is_numeric($term)) {
            return [
                'id' => (int) $term,
                'name' => (string) $term,
                'slug' => null,
                'taxonomy_id' => null,
                'taxonomy_slug' => null,
            ];
        }

        if (is_string($term)) {
            return [
                'id' => null,
                'name' => $term,
                'slug' => null,
                'taxonomy_id' => null,
                'taxonomy_slug' => null,
            ];
        }

        if (is_object($term)) {
            $term = json_decode(json_encode($term), true);
        }

        if (! is_array($term)) {
            return null;
        }

        return [
            'id' => $term['id'] ?? $term['term_id'] ?? null,
            'name' => $term['name'] ?? $term['label'] ?? $term['title'] ?? $term['slug'] ?? '',
            'slug' => $term['slug'] ?? null,
            'taxonomy_id' => $term['taxonomy_id'] ?? null,
            'taxonomy_slug' => $term['taxonomy_slug'] ?? $term['taxonomy'] ?? null,
        ];
    }

    protected function filterTerms(array $terms, array $settings): array
    {
        $taxonomyId = $settings['taxonomy_id'] ?? null;
        $taxonomySlug = $settings['taxonomy_slug'] ?? null;

        if (! $taxonomyId && ! $taxonomySlug) {
            return $terms;
        }

        return collect($terms)
            ->filter(function (array $term) use ($taxonomyId, $taxonomySlug) {
                if ($taxonomyId && ! empty($term['taxonomy_id'])) {
                    return (int) $term['taxonomy_id'] === (int) $taxonomyId;
                }

                if ($taxonomySlug && ! empty($term['taxonomy_slug'])) {
                    return (string) $term['taxonomy_slug'] === (string) $taxonomySlug;
                }

                return false;
            })
            ->values()
            ->all();
    }

    protected function renderBadges(array $terms, array $settings): string
    {
        $class = trim('pb-widget pb-taxonomy-terms pb-taxonomy-badges ' . $settings['class']);

        $items = collect($terms)
            ->map(function (array $term) use ($settings) {
                return '<span class="pb-taxonomy-badge">' . e($this->termLabel($term, $settings)) . '</span>';
            })
            ->implode('');

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $items
        );
    }

    protected function renderInline(array $terms, array $settings): string
    {
        $class = trim('pb-widget pb-taxonomy-terms pb-taxonomy-inline ' . $settings['class']);

        $items = collect($terms)
            ->map(fn(array $term) => e($this->termLabel($term, $settings)))
            ->implode(e($settings['separator']));

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $items
        );
    }

    protected function renderList(array $terms, array $settings): string
    {
        $class = trim('pb-widget pb-taxonomy-terms pb-taxonomy-list ' . $settings['class']);

        $items = collect($terms)
            ->map(function (array $term) use ($settings) {
                return '<li>' . e($this->termLabel($term, $settings)) . '</li>';
            })
            ->implode('');

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<ul class="%s"%s>%s</ul>',
            e($class),
            $style,
            $items
        );
    }

    protected function termLabel(array $term, array $settings): string
    {
        $name = (string) ($term['name'] ?? '');
        $slug = (string) ($term['slug'] ?? '');
        $id = (string) ($term['id'] ?? '');

        return match ($settings['display']) {
            'slug' => $slug !== '' ? $slug : $name,
            'id' => $id,
            'name_slug' => trim($name . ($slug ? ' (' . $slug . ')' : '')),
            default => $name !== '' ? $name : ($slug !== '' ? $slug : $id),
        };
    }
}
