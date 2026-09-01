<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\DynamicPost;
use App\Models\Template;
use App\PageBuilder\Widgets\RelatedPostsWidget;
use App\PageBuilder\Foundation\WidgetContext;
use App\PageBuilder\Foundation\WidgetManager;
use Illuminate\Support\Str;
use Throwable;

class TemplateRenderService
{
    public function __construct(
        protected WidgetManager $widgetManager,
        protected PageBuilderStyleService $styleService,
        protected RelatedPostsWidget $relatedPostsWidget
    ) {}

    public function preview(Template $template, array $payload = []): array
    {
        $layoutJson = $template->layout?->layout_json ?? [
            'sections' => [],
        ];

        if (is_string($layoutJson)) {
            $decoded = json_decode($layoutJson, true);

            $layoutJson = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : ['sections' => []];
        }

        if (! is_array($layoutJson)) {
            $layoutJson = ['sections' => []];
        }

        $fields = $this->normalizeFieldData(
            $payload['fields']
                ?? $payload['content_data']
                ?? []
        );
        $currentPost = $this->resolveCurrentPostFromPayload(
            $payload,
            $template->post_type_id ? (int) $template->post_type_id : null
        );

        $postType = null;
        if ($template->relationLoaded('postType') && $template->postType) {
            $postType = $template->postType;
        } elseif ($currentPost && method_exists($currentPost, 'relationLoaded') && $currentPost->relationLoaded('postType') && $currentPost->postType) {
            $postType = $currentPost->postType;
        } elseif ($template->post_type_id) {
            $postType = \App\Models\PostType::find((int) $template->post_type_id);
        }

        $context = WidgetContext::preview([
            'post_type_id' => $template->post_type_id,
            'post_type' => $postType,
            'fields' => $fields,
            'request' => $payload,
            'taxonomies' => $payload['taxonomies'] ?? [],
            'terms' => $payload['terms'] ?? [],

            'current_post' => $currentPost,
            'dynamic_post' => $currentPost,
            'post' => $currentPost,

            'entity_id' => $currentPost?->id
                ?? $payload['entity_id']
                ?? $payload['post_id']
                ?? $payload['dynamic_post_id']
                ?? $payload['current_post_id']
                ?? null,

            'post_id' => $currentPost?->id,
            'dynamic_post_id' => $currentPost?->id,
            'current_post_id' => $currentPost?->id,

            'selected_taxonomy_term_ids' => $payload['selected_taxonomy_term_ids'] ?? [],
            'preview_values' => $payload['preview_values'] ?? [],
        ]);

        $normalizedLayoutJson = $this->normalizeLayoutJsonForRender($layoutJson);
        $resolvedLayoutJson = $this->hydrateLayoutValues($normalizedLayoutJson, $fields);

        $html = $this->renderLayout($resolvedLayoutJson, $context);

        return [
            'template' => [
                'id' => $template->id,
                'template_type' => $template->template_type,
                'template_name' => $template->template_name,
                'post_type_id' => $template->post_type_id,
                'post_type_slug' => $template->post_type_slug,
                'status' => $template->status,
            ],
            'layout_json' => $layoutJson,
            'resolved_layout_json' => $resolvedLayoutJson,

            /*
     * Raw HTML without CSS.
     */
            'html' => $this->styleService->wrapHtml($html),

            /*
     * CSS separately for frontend apps.
     */
            'css' => $this->styleService->defaultCss(),

            /*
     * Ready-to-render HTML with style tag.
     */
            'html_with_styles' => $this->styleService->renderFullHtml($html),
        ];
    }
    protected function normalizeLayoutJsonForRender(array $layoutJson): array
    {
        if (
            empty($layoutJson['sections'])
            && ! empty($layoutJson['components'])
            && is_array($layoutJson['components'])
        ) {
            $layoutJson['sections'] = [
                [
                    'id' => 'section-root',
                    'type' => 'section',
                    'settings' => [],
                    'rows' => [
                        [
                            'id' => 'row-root',
                            'type' => 'row',
                            'settings' => [],
                            'columns' => [
                                [
                                    'id' => 'column-root',
                                    'type' => 'column',
                                    'width' => 12,
                                    'settings' => [],
                                    'components' => $layoutJson['components'],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $layoutJson;
    }

    protected function hydrateLayoutValues(array $layoutJson, array $fields): array
    {
        if (! empty($layoutJson['sections']) && is_array($layoutJson['sections'])) {
            $layoutJson['sections'] = array_map(function ($section) use ($fields) {
                return $this->hydrateComponentValue($section, $fields);
            }, $layoutJson['sections']);
        }

        if (! empty($layoutJson['components']) && is_array($layoutJson['components'])) {
            $layoutJson['components'] = array_map(function ($component) use ($fields) {
                return $this->hydrateComponentValue($component, $fields);
            }, $layoutJson['components']);
        }

        return $layoutJson;
    }

    protected function hydrateComponentValue(array $component, array $fields): array
    {
        $path = $component['binding']['path']
            ?? $component['settings']['field']
            ?? $component['field_path']
            ?? null;

        $source = $component['source']
            ?? $component['binding']['source']
            ?? $component['settings']['source']
            ?? null;

        if ($path && ! str_contains((string) $path, '.')) {
            if ($source === 'custom_field') {
                $path = 'custom.' . $path;
            } elseif ($source === 'system') {
                $path = 'system.' . $path;
            }
        }

        if (! $path && ! empty($component['binding']['field_key'])) {
            $path = 'custom.' . $component['binding']['field_key'];
        }

        if ($path) {
            $value = data_get($fields, $path);

            $component['field_value'] = $value;
            $component['value'] = $value;
            $component['has_value'] = $this->hasRealValue($value);

            $component['settings'] = is_array($component['settings'] ?? null)
                ? $component['settings']
                : [];

            $component['settings']['source'] = 'dynamic';
            $component['settings']['field'] = $path;

            $type = $component['component_key']
                ?? $component['type']
                ?? null;

            $type = $this->resolveWidgetType([
                'type' => $type,
            ]);

            if (in_array($type, ['heading', 'text'], true)) {
                $textValue = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_SLASHES)
                    : $value;

                if ($textValue !== null) {
                    $component['settings']['text'] = $textValue;
                    $component['settings']['content'] = $textValue;
                }
            }

            if (in_array($type, ['image', 'gallery'], true)) {
                $url = $this->extractFirstUrl($value);

                if ($url) {
                    $component['type'] = $type === 'gallery' ? 'gallery' : 'image';
                    $component['component_key'] = $type === 'gallery' ? 'gallery' : 'image';
                    $component['settings']['url'] = $url;
                }
            }

            if ($type === 'button') {
                if (is_string($value)) {
                    $component['settings']['url'] = $value;
                }

                if (is_array($value) && ! empty($value['url'])) {
                    $component['settings']['url'] = $value['url'];
                }
            }
        }

        foreach (['sections', 'rows', 'columns', 'components', 'children', 'items', 'widgets'] as $childKey) {
            if (! empty($component[$childKey]) && is_array($component[$childKey])) {
                $component[$childKey] = array_map(function ($child) use ($fields) {
                    return is_array($child)
                        ? $this->hydrateComponentValue($child, $fields)
                        : $child;
                }, $component[$childKey]);
            }
        }

        return $component;
    }

    protected function extractFirstUrl(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if (! empty($value['url'])) {
            return (string) $value['url'];
        }

        if (! empty($value[0]) && is_array($value[0]) && ! empty($value[0]['url'])) {
            return (string) $value[0]['url'];
        }

        if (! empty($value['path'])) {
            return (string) $value['path'];
        }

        return null;
    }

    protected function hasRealValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }
    public function renderLayout(array $layoutJson, WidgetContext $context): string
    {
        if (array_is_list($layoutJson)) {
            return collect($layoutJson)
                ->filter(fn($node) => is_array($node))
                ->map(fn(array $node) => $this->renderWidgetNode($node, $context))
                ->implode('');
        }

        $sections = $layoutJson['sections'] ?? [];

        if (! is_array($sections)) {
            return '';
        }

        return collect($sections)
            ->filter(fn($section) => is_array($section))
            ->map(fn(array $section, int $index) => $this->renderSection($section, $context, $index))
            ->implode('');
    }

    protected function renderSection(array $section, WidgetContext $context, int $index): string
    {
        $rows = $section['rows'] ?? [];

        if (is_array($rows) && ! empty($rows)) {
            $content = collect($rows)
                ->filter(fn($row) => is_array($row))
                ->map(fn(array $row, int $rowIndex) => $this->renderRow($row, $context, $rowIndex))
                ->implode('');
        } else {
            $content = $this->renderNodeChildren($section, $context);
        }

        $settings = is_array($section['settings'] ?? null)
            ? $section['settings']
            : [];

        $class = $this->sanitizeClass(
            'pb-section ' . ($settings['class'] ?? '')
        );

        $id = $this->sanitizeId(
            (string) ($settings['id'] ?? $section['id'] ?? 'section_' . ($index + 1))
        );

        $style = $this->buildStyleAttribute(
            $this->sectionStyles($settings)
        );

        return sprintf(
            '<section id="%s" class="%s"%s>%s</section>',
            e($id),
            e($class),
            $style,
            $content
        );
    }

    protected function renderRow(array $row, WidgetContext $context, int $index): string
    {
        $columns = $row['columns'] ?? [];

        if (is_array($columns) && ! empty($columns)) {
            $content = collect($columns)
                ->filter(fn($column) => is_array($column))
                ->map(fn(array $column, int $columnIndex) => $this->renderColumn($column, $context, $columnIndex))
                ->implode('');
        } else {
            $content = $this->renderNodeChildren($row, $context);
        }

        $settings = is_array($row['settings'] ?? null)
            ? $row['settings']
            : [];

        $class = $this->sanitizeClass(
            'pb-row ' . ($settings['class'] ?? '')
        );

        $style = $this->buildStyleAttribute(
            $this->rowStyles($settings)
        );

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $content
        );
    }

    protected function renderColumn(array $column, WidgetContext $context, int $index): string
    {
        $content = $this->renderNodeChildren($column, $context);

        $settings = is_array($column['settings'] ?? null)
            ? $column['settings']
            : [];

        $class = $this->sanitizeClass(
            'pb-column ' . ($settings['class'] ?? '')
        );

        $styleData = $this->columnStyles($settings);

        $width = $column['width']
            ?? $settings['width']
            ?? null;

        if ($width !== null && $width !== '') {
            $parsedWidth = $this->resolveColumnWidth($width);
            if ($parsedWidth !== '') {
                $styleData['width'] = $parsedWidth;
                $styleData['flex-basis'] = $parsedWidth;
            }
        }

        $style = $this->buildStyleAttribute($styleData);

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $content
        );
    }

    protected function resolveColumnWidth(mixed $width): string
    {
        $widthStr = trim((string) $width);

        if ($widthStr === '') {
            return '';
        }

        if (is_numeric($widthStr)) {
            $val = (float) $widthStr;
            if ($val >= 1 && $val <= 12) {
                return round(($val / 12) * 100, 4) . '%';
            }
            return $val . 'px';
        }

        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $widthStr, $m)) {
            $num = (float) $m[1];
            $den = (float) $m[2];
            if ($den > 0) {
                return round(($num / $den) * 100, 4) . '%';
            }
        }

        return $this->cssLength($widthStr);
    }

    protected function renderNodeChildren(array $node, WidgetContext $context): string
    {
        $children = [];

        foreach (['widgets', 'components', 'children', 'items'] as $key) {
            if (! empty($node[$key]) && is_array($node[$key])) {
                $children = $node[$key];
                break;
            }
        }

        if (empty($children) && $this->looksLikeWidgetNode($node)) {
            return $this->renderWidgetNode($node, $context);
        }

        return collect($children)
            ->filter(fn($child) => is_array($child))
            ->map(fn(array $child) => $this->renderAnyNode($child, $context))
            ->implode('');
    }

    protected function renderAnyNode(array $node, WidgetContext $context): string
    {
        $type = $node['type']
            ?? $node['component_key']
            ?? null;

        if (in_array($type, ['container', 'layout'], true)) {
            $content = $this->renderNodeChildren($node, $context);

            $settings = is_array($node['settings'] ?? null)
                ? $node['settings']
                : [];

            $style = $this->buildStyleAttribute($this->componentStyles($settings));

            return sprintf(
                '<div class="pb-container"%s>%s</div>',
                $style,
                $content
            );
        }

        if (! empty($node['components']) || ! empty($node['children'])) {
            $content = $this->renderNodeChildren($node, $context);

            $settings = is_array($node['settings'] ?? null)
                ? $node['settings']
                : [];

            $style = $this->buildStyleAttribute($this->componentStyles($settings));

            return sprintf(
                '<div class="pb-component-wrapper"%s>%s</div>',
                $style,
                $content
            );
        }

        return $this->renderWidgetNode($node, $context);
    }

    protected function componentStyles(array $settings): array
    {
        $styles = [];

        if (! empty($settings['height'])) {
            $styles['height'] = $this->cssLength($settings['height']);
        }

        if (! empty($settings['width'])) {
            $styles['width'] = $this->cssLength($settings['width']);
        }

        if (! empty($settings['padding'])) {
            $styles['padding'] = $this->cssBoxValue($settings['padding']);
        }

        if (! empty($settings['margin'])) {
            $styles['margin'] = $this->cssBoxValue($settings['margin']);
        }

        if (! empty($settings['flexDirection'])) {
            $styles['display'] = 'flex';
            $styles['flex-direction'] = $this->safeCssKeyword($settings['flexDirection']);
        }

        if (! empty($settings['borderWidth'])) {
            $styles['border-width'] = $this->cssLength($settings['borderWidth']);
        }

        if (! empty($settings['borderType'])) {
            $styles['border-style'] = $this->safeCssKeyword($settings['borderType']);
        }

        if (! empty($settings['borderColor'])) {
            $styles['border-color'] = $this->safeColor($settings['borderColor']);
        }

        if (! empty($settings['backgroundColor'])) {
            $styles['background-color'] = $this->safeColor($settings['backgroundColor']);
        }

        return $styles;
    }

    protected function renderWidgetNode(array $node, WidgetContext $context): string
    {
        $type = $this->resolveWidgetType($node);

        if (! $type) {
            return $this->previewPlaceholder('Missing widget type.', $context);
        }

        $settings = $this->normalizeSettings($type, $node);

        if ($type === 'related_posts') {
            try {
                return $this->relatedPostsWidget->render(
                    settings: $settings,
                    context: $context,
                    currentPost: $this->resolveCurrentPostFromContext($context)
                );
            } catch (Throwable $e) {
                return $this->previewPlaceholder($e->getMessage(), $context);
            }
        }

        if (! $this->widgetManager->has($type)) {
            return $this->previewPlaceholder("Widget [{$type}] is not registered.", $context);
        }

        try {
            return $this->widgetManager->render($type, $settings, $context);
        } catch (Throwable $e) {
            return $this->previewPlaceholder($e->getMessage(), $context);
        }
    }
    protected function sectionStyles(array $settings): array
    {
        return $this->commonStyles($settings, [
            'padding',
            'margin',
            'background_color',
            'background_image',
            'background_size',
            'background_position',
            'background_repeat',
            'min_height',
            'text_align',
        ]);
    }

    protected function rowStyles(array $settings): array
    {
        $styles = $this->commonStyles($settings, [
            'padding',
            'margin',
            'background_color',
            'background_image',
            'background_size',
            'background_position',
            'background_repeat',
            'max_width',
            'width',
            'gap',
            'align_items',
            'justify_content',
        ]);

        if (! empty($settings['max_width'])) {
            $styles['max-width'] = $this->cssLength($settings['max_width']);
        }

        if (! empty($settings['gap'])) {
            $styles['gap'] = $this->cssLength($settings['gap']);
        }

        if (! empty($settings['align_items'])) {
            $styles['align-items'] = $this->safeCssKeyword($settings['align_items']);
        }

        if (! empty($settings['justify_content'])) {
            $styles['justify-content'] = $this->safeCssKeyword($settings['justify_content']);
        }

        return $styles;
    }

    protected function columnStyles(array $settings): array
    {
        return $this->commonStyles($settings, [
            'padding',
            'margin',
            'background_color',
            'background_image',
            'background_size',
            'background_position',
            'background_repeat',
            'width',
            'min_height',
            'text_align',
            'align_self',
        ]);
    }

    protected function commonStyles(array $settings, array $allowedKeys): array
    {
        $styles = [];

        if (in_array('padding', $allowedKeys, true) && ! empty($settings['padding'])) {
            $styles['padding'] = $this->cssBoxValue($settings['padding']);
        }

        if (in_array('margin', $allowedKeys, true) && ! empty($settings['margin'])) {
            $styles['margin'] = $this->cssBoxValue($settings['margin']);
        }

        if (in_array('background_color', $allowedKeys, true) && ! empty($settings['background_color'])) {
            $styles['background-color'] = $this->safeColor($settings['background_color']);
        }

        if (in_array('background_image', $allowedKeys, true) && ! empty($settings['background_image'])) {
            $url = $this->safeUrl($settings['background_image']);

            if ($url !== '') {
                $styles['background-image'] = 'url(' . $url . ')';
            }
        }

        if (in_array('background_size', $allowedKeys, true) && ! empty($settings['background_size'])) {
            $styles['background-size'] = $this->safeCssKeyword($settings['background_size']);
        }

        if (in_array('background_position', $allowedKeys, true) && ! empty($settings['background_position'])) {
            $styles['background-position'] = $this->safeCssKeyword($settings['background_position']);
        }

        if (in_array('background_repeat', $allowedKeys, true) && ! empty($settings['background_repeat'])) {
            $styles['background-repeat'] = $this->safeCssKeyword($settings['background_repeat']);
        }

        if (in_array('width', $allowedKeys, true) && ! empty($settings['width'])) {
            $styles['width'] = $this->cssLength($settings['width']);
        }

        if (in_array('min_height', $allowedKeys, true) && ! empty($settings['min_height'])) {
            $styles['min-height'] = $this->cssLength($settings['min_height']);
        }

        if (in_array('text_align', $allowedKeys, true) && ! empty($settings['text_align'])) {
            $styles['text-align'] = $this->safeCssKeyword($settings['text_align']);
        }

        if (in_array('align_self', $allowedKeys, true) && ! empty($settings['align_self'])) {
            $styles['align-self'] = $this->safeCssKeyword($settings['align_self']);
        }

        return $styles;
    }

    protected function buildStyleAttribute(array $styles): string
    {
        $styles = array_filter($styles, fn($value) => $value !== null && $value !== '');

        if (empty($styles)) {
            return '';
        }

        $styleString = collect($styles)
            ->map(fn($value, $property) => $property . ':' . $value)
            ->implode(';');

        return ' style="' . e($styleString) . '"';
    }

    protected function cssLength(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return $value . 'px';
        }

        return preg_replace('/[^a-zA-Z0-9.%_\-\s]/', '', $value) ?: '';
    }

    protected function cssBoxValue(mixed $value): string
    {
        if (is_array($value)) {
            $top = $this->cssLength($value['top'] ?? 0);
            $right = $this->cssLength($value['right'] ?? 0);
            $bottom = $this->cssLength($value['bottom'] ?? 0);
            $left = $this->cssLength($value['left'] ?? 0);

            return trim("{$top} {$right} {$bottom} {$left}");
        }

        return $this->cssLength($value);
    }

    protected function safeColor(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_replace('/[^a-zA-Z0-9#(),.%\s]/', '', $value) ?: '';
    }

    protected function safeCssKeyword(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_replace('/[^a-zA-Z0-9_\-\s.%]/', '', $value) ?: '';
    }

    protected function safeUrl(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9:\/._\-\?\=&%#]/', '', $value) ?: '';
    }
    protected function resolveWidgetType(array $node): ?string
    {
        $type = $node['type']
            ?? $node['widget']
            ?? $node['widget_type']
            ?? $node['component_key']
            ?? null;

        if (! $type) {
            return null;
        }

        $type = (string) $type;

        return match ($type) {
            'title_widget', 'title' => 'heading',
            'text_editor', 'editor', 'textarea', 'texteditor' => 'text',
            'media', 'featured_image' => 'image',
            'gallery', 'images' => 'gallery',
            'repeater', 'array', 'json' => 'repeater',
            'taxonomy', 'terms', 'taxonomy_terms' => 'taxonomy_terms',
            'html', 'custom_html', 'code' => 'html',
            'link', 'url' => 'button',
            default => $type,
        };
    }

    protected function normalizeSettings(string $type, array $node): array
    {
        $settings = $node['settings'] ?? [];

        if (! is_array($settings)) {
            $settings = [];
        }

        $field = $settings['field']
            ?? $node['field']
            ?? $node['binding']['field_key']
            ?? null;

        if ($field) {
            $settings['source'] = $settings['source'] ?? 'dynamic';
            $settings['field'] = $field;
        }

        if ($type === 'heading') {
            if (isset($settings['alignment']) && ! isset($settings['align'])) {
                $settings['align'] = $settings['alignment'];
            }

            if (isset($settings['content']) && ! isset($settings['text'])) {
                $settings['text'] = $settings['content'];
            }
        }

        if ($type === 'text') {
            if (isset($settings['content']) && ! isset($settings['text'])) {
                $settings['text'] = $settings['content'];
            }
        }

        if ($type === 'button') {
            if (isset($settings['text']) && ! isset($settings['label'])) {
                $settings['label'] = $settings['text'];
            }
        }

        return $settings;
    }

    protected function looksLikeWidgetNode(array $node): bool
    {
        return isset($node['type'])
            || isset($node['widget'])
            || isset($node['widget_type'])
            || isset($node['component_key']);
    }

    protected function normalizeFieldData(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $key => $value) {
            if (is_string($key) && str_contains($key, '.')) {
                data_set($normalized, $key, $value);
            } else {
                $normalized[$key] = $value;
            }
        }

        return array_replace_recursive($fields, $normalized);
    }

    protected function previewPlaceholder(string $message, WidgetContext $context): string
    {
        if (! $context->isPreview()) {
            return '';
        }

        return '<div class="pb-widget-error">' . e($message) . '</div>';
    }

    protected function sanitizeClass(string $class): string
    {
        $class = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $class);

        return trim((string) $class);
    }

    protected function sanitizeId(string $id): string
    {
        $id = Str::slug($id, '_');

        return $id !== '' ? $id : uniqid('pb_', false);
    }
    protected function resolveCurrentPostFromPayload(array $payload, ?int $templatePostTypeId = null): ?DynamicPost
    {
        $postId = $payload['current_post_id']
            ?? $payload['dynamic_post_id']
            ?? $payload['post_id']
            ?? $payload['entity_id']
            ?? $payload['id']
            ?? null;

        if (! $postId) {
            return null;
        }

        $query = DynamicPost::query()->whereKey((int) $postId);

        if ($templatePostTypeId) {
            $query->where('post_type_id', $templatePostTypeId);
        }

        return $query->first();
    }
    protected function resolveCurrentPostFromContext(WidgetContext $context): ?DynamicPost
    {
        foreach (['current_post', 'dynamic_post', 'post'] as $key) {
            $value = $this->contextValue($context, $key);

            if ($value instanceof DynamicPost) {
                return $value;
            }

            if (is_object($value) && isset($value->id)) {
                return DynamicPost::find((int) $value->id);
            }

            if (is_array($value) && ! empty($value['id'])) {
                return DynamicPost::find((int) $value['id']);
            }
        }

        $postId = $this->contextValue($context, 'current_post_id')
            ?? $this->contextValue($context, 'dynamic_post_id')
            ?? $this->contextValue($context, 'post_id');

        if ($postId) {
            return DynamicPost::find((int) $postId);
        }

        $request = $this->contextValue($context, 'request');

        if (is_array($request)) {
            $requestPostId = $request['current_post_id']
                ?? $request['dynamic_post_id']
                ?? $request['post_id']
                ?? $request['id']
                ?? null;

            if ($requestPostId) {
                return DynamicPost::find((int) $requestPostId);
            }
        }

        return null;
    }

    protected function contextValue(WidgetContext $context, string $key): mixed
    {
        if (method_exists($context, 'get')) {
            try {
                return $context->get($key);
            } catch (Throwable) {
            }
        }

        if (method_exists($context, 'data')) {
            try {
                $data = $context->data();

                if (is_array($data)) {
                    return data_get($data, $key);
                }
            } catch (Throwable) {
            }
        }

        if (method_exists($context, 'toArray')) {
            try {
                $data = $context->toArray();

                if (is_array($data)) {
                    return data_get($data, $key);
                }
            } catch (Throwable) {
            }
        }

        try {
            if (isset($context->{$key})) {
                return $context->{$key};
            }
        } catch (Throwable) {
        }

        return null;
    }
}
