<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class BreadcrumbWidget extends BaseWidget
{
    protected static string $type = 'breadcrumb';

    protected string $name = 'Breadcrumb';

    protected string $category = 'Navigation';

    protected string $icon = 'breadcrumb';

    protected string $description = 'Display a dynamic or static breadcrumb trail.';

    public function key(): string
    {
        return 'breadcrumb';
    }

    public function sidebarItem(): array
    {
        return [
            'label' => 'Breadcrumb',
            'key' => 'breadcrumb',
            'source' => 'basic_widget',
            'type' => 'breadcrumb',
            'component_key' => 'breadcrumb',
            'field_value' => '',
            'value' => '',
            'has_value' => false,
            'settings' => $this->defaultSettings(),
            'settings_schema' => $this->schema(),
        ];
    }

    public function defaultSettings(): array
    {
        return [
            /*
             * static  = manually defined items only
             * dynamic = auto-build from post context + optional appended items
             */
            'source' => 'dynamic',

            /*
             * Separator character/string shown between crumbs.
             */
            'separator' => '/',

            /*
             * Whether to show the current page (last crumb) as a link.
             */
            'link_current' => false,

            /*
             * Static items: each item has 'label' and 'url'.
             * Used when source = 'static', or appended after dynamic crumbs.
             */
            'items' => [],

            /*
             * Dynamic configuration – which crumbs to auto-generate.
             */
            'show_home'        => true,
            'home_label'       => 'Home',
            'home_url'         => '/',

            'show_post_type'   => true,
            'post_type_label'  => '',    // empty = use post type name from context
            'post_type_url'    => '',

            /*
             * Optionally pull the current post title from a dynamic field.
             * Leave null to fall back to system.title.
             */
            'current_field'    => null,

            'schema_markup'    => true,  // emit JSON-LD BreadcrumbList

            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name'    => 'source',
                'label'   => 'Source',
                'type'    => 'select',
                'options' => [
                    ['label' => 'Dynamic (from context)', 'value' => 'dynamic'],
                    ['label' => 'Static (manual items)',  'value' => 'static'],
                ],
            ],

            // ── Dynamic options ──────────────────────────────────────────────
            [
                'name'      => 'show_home',
                'label'     => 'Show Home Crumb',
                'type'      => 'toggle',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'home_label',
                'label'     => 'Home Label',
                'type'      => 'text',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'home_url',
                'label'     => 'Home URL',
                'type'      => 'text',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'show_post_type',
                'label'     => 'Show Post Type Crumb',
                'type'      => 'toggle',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'post_type_label',
                'label'     => 'Post Type Label (leave blank to auto-detect)',
                'type'      => 'text',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'post_type_url',
                'label'     => 'Post Type URL',
                'type'      => 'text',
                'show_when' => ['source' => 'dynamic'],
            ],
            [
                'name'      => 'current_field',
                'label'     => 'Current Page Title Field',
                'type'      => 'dynamic_field',
                'show_when' => ['source' => 'dynamic'],
            ],

            // ── Static items ─────────────────────────────────────────────────
            [
                'name'      => 'items',
                'label'     => 'Breadcrumb Items',
                'type'      => 'repeater',
                'show_when' => ['source' => 'static'],
            ],

            // ── Shared options ───────────────────────────────────────────────
            [
                'name'  => 'separator',
                'label' => 'Separator',
                'type'  => 'text',
            ],
            [
                'name'  => 'link_current',
                'label' => 'Link Current Page',
                'type'  => 'toggle',
            ],
            [
                'name'  => 'schema_markup',
                'label' => 'Emit Schema.org JSON-LD',
                'type'  => 'toggle',
            ],
            [
                'name'  => 'class',
                'label' => 'CSS Class',
                'type'  => 'text',
            ],
        ];
    }

    public function validateSettings(array $settings): array
    {
        $settings = parent::validateSettings($settings);

        $settings['source'] = in_array($settings['source'], ['dynamic', 'static'], true)
            ? $settings['source']
            : 'dynamic';

        $settings['separator'] = strip_tags((string) ($settings['separator'] ?? '/'));

        $settings['link_current']   = (bool) ($settings['link_current'] ?? false);
        $settings['show_home']      = (bool) ($settings['show_home'] ?? true);
        $settings['show_post_type'] = (bool) ($settings['show_post_type'] ?? true);
        $settings['schema_markup']  = (bool) ($settings['schema_markup'] ?? true);

        $settings['home_label']      = strip_tags((string) ($settings['home_label'] ?? 'Home'));
        $settings['home_url']        = filter_var((string) ($settings['home_url'] ?? '/'), FILTER_SANITIZE_URL) ?: '/';
        $settings['post_type_label'] = strip_tags((string) ($settings['post_type_label'] ?? ''));
        $settings['post_type_url']   = filter_var((string) ($settings['post_type_url'] ?? ''), FILTER_SANITIZE_URL) ?: '';

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) ($settings['class'] ?? ''));

        if (! is_array($settings['items'] ?? null)) {
            $settings['items'] = [];
        }

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $crumbs = $this->resolveCrumbs($settings, $context);

        if (empty($crumbs)) {
            return '';
        }

        $html  = $this->renderNav($crumbs, $settings);
        $html .= $settings['schema_markup'] ? $this->renderSchemaMarkup($crumbs) : '';

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Crumb resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the ordered list of breadcrumb items.
     * Each item: ['label' => string, 'url' => string|null]
     */
    protected function resolveCrumbs(array $settings, WidgetContext $context): array
    {
        if ($settings['source'] === 'static') {
            return $this->resolveStaticCrumbs($settings);
        }

        return $this->resolveDynamicCrumbs($settings, $context);
    }

    protected function resolveStaticCrumbs(array $settings): array
    {
        $crumbs = [];

        foreach ((array) ($settings['items'] ?? []) as $item) {
            $label = trim(strip_tags((string) ($item['label'] ?? '')));
            $url   = filter_var((string) ($item['url'] ?? ''), FILTER_SANITIZE_URL);

            if ($label === '') {
                continue;
            }

            $crumbs[] = ['label' => $label, 'url' => $url ?: null];
        }

        return $crumbs;
    }

    protected function resolveDynamicCrumbs(array $settings, WidgetContext $context): array
    {
        $crumbs = [];

        // 1. Home crumb
        if (! empty($settings['show_home'])) {
            $crumbs[] = [
                'label' => ! empty($settings['home_label']) ? $settings['home_label'] : 'Home',
                'url'   => ! empty($settings['home_url']) ? $settings['home_url'] : '/',
            ];
        }

        // 2. Post-type (archive / listing) crumb
        if (! empty($settings['show_post_type'])) {
            $postTypeLabel = (string) ($settings['post_type_label'] ?? '');
            $postTypeUrl   = (string) ($settings['post_type_url'] ?? '');

            // Auto-detect post type from context when not manually specified
            if ($postTypeLabel === '') {
                $postType = $context->postType();

                if (! $postType && $context->post()) {
                    $post = $context->post();
                    if (method_exists($post, 'relationLoaded') && $post->relationLoaded('postType') && $post->postType) {
                        $postType = $post->postType;
                    } elseif (isset($post->post_type_id)) {
                        $postType = \App\Models\PostType::find((int) $post->post_type_id);
                    }
                }

                if (! $postType && $context->postTypeId()) {
                    $postType = \App\Models\PostType::find((int) $context->postTypeId());
                }

                if ($postType) {
                    foreach (['name', 'label', 'plural_name', 'title'] as $attr) {
                        $val = $this->objectAttribute($postType, $attr);

                        if ($val !== null && (string) $val !== '') {
                            $postTypeLabel = (string) $val;
                            break;
                        }
                    }

                    if ($postTypeUrl === '' && ! empty($postType->slug)) {
                        $postTypeUrl = '/' . ltrim((string) $postType->slug, '/');
                    }
                }
            }

            if ($postTypeLabel !== '') {
                $crumbs[] = [
                    'label' => $postTypeLabel,
                    'url'   => $postTypeUrl ?: null,
                ];
            }
        }

        // 3. Current page title (deepest crumb)
        $currentTitle = $this->resolveCurrentTitle($settings, $context);

        if ($currentTitle !== '') {
            $crumbs[] = [
                'label' => $currentTitle,
                'url'   => null, // current page; linking controlled by link_current setting
            ];
        }

        return $crumbs;
    }

    protected function resolveCurrentTitle(array $settings, WidgetContext $context): string
    {
        // Try the explicitly chosen dynamic field first
        $fieldKey = $settings['current_field'] ?? null;

        if ($fieldKey) {
            $value = (string) $context->field($fieldKey, '');

            if ($value !== '') {
                return $value;
            }
        }

        // Fall back to system.title
        $title = (string) $context->field('system.title', '');

        if ($title !== '') {
            return $title;
        }

        // Last resort: attributes on the post object itself
        $post = $context->post();

        if ($post) {
            foreach (['title', 'name', 'label'] as $attr) {
                $value = $this->objectAttribute($post, $attr);

                if ($value !== null && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML rendering
    // ─────────────────────────────────────────────────────────────────────────

    protected function renderNav(array $crumbs, array $settings): string
    {
        $class = trim('pb-widget pb-breadcrumb ' . ($settings['class'] ?? ''));

        $extraStyles = [];

        $fontSize = $settings['fontSize'] ?? $settings['style']['font_size'] ?? '';
        if ($fontSize !== '') {
            $extraStyles['font-size'] = $this->cssLength($fontSize);
        }

        $fontWeight = $settings['fontWeight'] ?? $settings['style']['font_weight'] ?? '';
        if ($fontWeight !== '') {
            $extraStyles['font-weight'] = $this->safeCssValue($fontWeight);
        }

        $textTransform = $settings['textTransform'] ?? $settings['style']['text_transform'] ?? '';
        if ($textTransform !== '') {
            $extraStyles['text-transform'] = $this->safeCssValue($textTransform);
        }

        $color = $settings['color'] ?? $settings['style']['color'] ?? '';
        if ($color !== '') {
            $extraStyles['color'] = $this->safeColor($color);
        }

        $linkColor = $settings['linkColorNormal'] ?? $settings['link_color'] ?? '';
        $linkStyleAttr = '';
        if ($linkColor !== '') {
            $linkStyleAttr = ' style="color:' . e($this->safeColor($linkColor)) . ';"';
        }

        $style     = $this->styleAttributes($settings, $extraStyles);
        $sep       = e($settings['separator'] ?? '/');
        $lastIndex = count($crumbs) - 1;
        $items     = '';

        foreach ($crumbs as $index => $crumb) {
            $isCurrent = ($index === $lastIndex);
            $label     = e($crumb['label']);
            $url       = $crumb['url'];

            if ($isCurrent) {
                $inner = ($settings['link_current'] && $url)
                    ? sprintf('<a href="%s"%s>%s</a>', e($url), $linkStyleAttr, $label)
                    : sprintf('<span aria-current="page">%s</span>', $label);

                $items .= sprintf(
                    '<li class="pb-breadcrumb__item pb-breadcrumb__item--current" aria-current="page">%s</li>',
                    $inner
                );
            } else {
                $inner = $url
                    ? sprintf('<a href="%s"%s>%s</a>', e($url), $linkStyleAttr, $label)
                    : sprintf('<span>%s</span>', $label);

                $items .= sprintf(
                    '<li class="pb-breadcrumb__item">%s<span class="pb-breadcrumb__sep" aria-hidden="true">%s</span></li>',
                    $inner,
                    $sep
                );
            }
        }

        return sprintf(
            '<nav class="%s" aria-label="Breadcrumb"%s><ol class="pb-breadcrumb__list">%s</ol></nav>',
            e($class),
            $style,
            $items
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Schema.org JSON-LD
    // ─────────────────────────────────────────────────────────────────────────

    protected function renderSchemaMarkup(array $crumbs): string
    {
        $listItems = [];

        foreach ($crumbs as $position => $crumb) {
            $item = [
                '@type'    => 'ListItem',
                'position' => $position + 1,
                'name'     => $crumb['label'],
            ];

            if (! empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }

            $listItems[] = $item;
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];

        return sprintf(
            '<script type="application/ld+json">%s</script>',
            json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Safely read an attribute from a plain object or Eloquent model.
     */
    protected function objectAttribute(object $obj, string $key): mixed
    {
        try {
            if (method_exists($obj, 'getAttribute')) {
                $value = $obj->getAttribute($key);

                if ($value !== null) {
                    return $value;
                }
            }

            if (isset($obj->{$key})) {
                return $obj->{$key};
            }
        } catch (\Throwable) {
            //
        }

        return null;
    }
}
