<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\PageBuilder\Foundation\WidgetManager;
use Throwable;

class LayoutValidationService
{
    protected array $errors = [];

    public function __construct(
        protected WidgetManager $widgetManager
    ) {
    }

    public function validate(array $layoutJson): array
    {
        $this->errors = [];

        if (array_is_list($layoutJson)) {
            $layoutJson = [
                'sections' => [
                    [
                        'id' => 'section_1',
                        'rows' => [
                            [
                                'columns' => [
                                    [
                                        'widgets' => $layoutJson,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if (! isset($layoutJson['sections']) || ! is_array($layoutJson['sections'])) {
            $layoutJson['sections'] = [];
        }

        $layoutJson['sections'] = collect($layoutJson['sections'])
            ->filter(fn ($section) => is_array($section))
            ->map(fn (array $section, int $index) => $this->validateSection($section, $index))
            ->values()
            ->all();

        return [
            'valid' => empty($this->errors),
            'layout_json' => $layoutJson,
            'errors' => $this->errors,
        ];
    }

    protected function validateSection(array $section, int $index): array
    {
        $section['id'] = $section['id'] ?? 'section_' . ($index + 1);

        if (! isset($section['settings']) || ! is_array($section['settings'])) {
            $section['settings'] = [];
        }

        if (! isset($section['rows']) || ! is_array($section['rows'])) {
            $section['rows'] = [];
        }

        $section['rows'] = collect($section['rows'])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row, int $rowIndex) => $this->validateRow($row, $rowIndex, $index))
            ->values()
            ->all();

        return $section;
    }

    protected function validateRow(array $row, int $rowIndex, int $sectionIndex): array
    {
        $row['id'] = $row['id'] ?? 'row_' . ($sectionIndex + 1) . '_' . ($rowIndex + 1);

        if (! isset($row['settings']) || ! is_array($row['settings'])) {
            $row['settings'] = [];
        }

        if (! isset($row['columns']) || ! is_array($row['columns'])) {
            $row['columns'] = [];
        }

        $row['columns'] = collect($row['columns'])
            ->filter(fn ($column) => is_array($column))
            ->map(fn (array $column, int $columnIndex) => $this->validateColumn(
                $column,
                $columnIndex,
                $rowIndex,
                $sectionIndex
            ))
            ->values()
            ->all();

        return $row;
    }

    protected function validateColumn(
        array $column,
        int $columnIndex,
        int $rowIndex,
        int $sectionIndex
    ): array {
        $column['id'] = $column['id']
            ?? 'column_' . ($sectionIndex + 1) . '_' . ($rowIndex + 1) . '_' . ($columnIndex + 1);

        if (! isset($column['settings']) || ! is_array($column['settings'])) {
            $column['settings'] = [];
        }

        foreach (['widgets', 'components', 'children', 'items'] as $childKey) {
            if (! isset($column[$childKey])) {
                continue;
            }

            if (! is_array($column[$childKey])) {
                $this->errors[] = "Column [{$column['id']}] {$childKey} must be an array.";
                $column[$childKey] = [];
                continue;
            }

            $column[$childKey] = collect($column[$childKey])
                ->filter(fn ($node) => is_array($node))
                ->map(fn (array $node, int $nodeIndex) => $this->validateWidgetNode(
                    $node,
                    $nodeIndex,
                    $column['id']
                ))
                ->values()
                ->all();
        }

        if (
            ! isset($column['widgets'])
            && ! isset($column['components'])
            && ! isset($column['children'])
            && ! isset($column['items'])
        ) {
            $column['widgets'] = [];
        }

        return $column;
    }

    protected function validateWidgetNode(array $node, int $index, string $parentId): array
    {
        $originalType = $node['type']
            ?? $node['widget']
            ?? $node['widget_type']
            ?? $node['component_key']
            ?? null;

        if (! $originalType) {
            $this->errors[] = "Widget at {$parentId} index {$index} is missing type.";
            return $node;
        }

        $type = $this->resolveWidgetType((string) $originalType);

        if (! $this->widgetManager->has($type)) {
            $this->errors[] = "Widget type [{$originalType}] is not registered.";
            return $node;
        }

        $settings = $node['settings'] ?? [];

        if (! is_array($settings)) {
            $this->errors[] = "Widget [{$type}] settings must be an array.";
            $settings = [];
        }

        $settings = $this->normalizeWidgetSettings($type, $settings, $node);

        try {
            $settings = $this->widgetManager->validateSettings($type, $settings);
        } catch (Throwable $e) {
            $this->errors[] = "Widget [{$type}] settings validation failed: " . $e->getMessage();
        }

        $node['type'] = $type;
        $node['settings'] = $settings;

        unset($node['widget'], $node['widget_type']);

        return $node;
    }

    protected function normalizeWidgetSettings(string $type, array $settings, array $node): array
    {
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

    protected function resolveWidgetType(string $type): string
    {
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
}