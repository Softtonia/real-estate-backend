<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use App\PageBuilder\Foundation\WidgetContext;
use App\PageBuilder\Foundation\WidgetManager;
use Illuminate\Support\Str;
use Throwable;

class TemplateRenderService
{
    public function __construct(
        protected WidgetManager $widgetManager,
        protected PageBuilderStyleService $styleService
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

        $context = WidgetContext::preview([
            'post_type_id' => $template->post_type_id,
            'fields' => $fields,
            'request' => $payload,
            'taxonomies' => $payload['taxonomies'] ?? [],
            'terms' => $payload['terms'] ?? [],
        ]);

        $html = $this->renderLayout($layoutJson, $context);

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
            $styleData['width'] = $this->cssLength($width);
            $styleData['flex-basis'] = $this->cssLength($width);
        }

        $style = $this->buildStyleAttribute($styleData);

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $content
        );
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
            ->map(fn(array $child) => $this->renderWidgetNode($child, $context))
            ->implode('');
    }

    protected function renderWidgetNode(array $node, WidgetContext $context): string
    {
        $type = $this->resolveWidgetType($node);

        if (! $type) {
            return $this->previewPlaceholder('Missing widget type.', $context);
        }

        if (! $this->widgetManager->has($type)) {
            return $this->previewPlaceholder("Widget [{$type}] is not registered.", $context);
        }

        $settings = $this->normalizeSettings($type, $node);

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
}
