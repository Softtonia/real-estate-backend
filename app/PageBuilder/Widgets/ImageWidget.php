<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class ImageWidget extends BaseWidget
{
    protected static string $type = 'image';

    protected string $name = 'Image';

    protected string $category = 'Media';

    protected string $icon = 'image';

    protected string $description = 'Display a static or dynamic image.';

    public function defaultSettings(): array
    {
        return [
            'source' => 'static',
            'url' => '',
            'field' => null,
            'alt' => '',
            'width' => '',
            'height' => '',
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
                    ['label' => 'Static', 'value' => 'static'],
                    ['label' => 'Dynamic', 'value' => 'dynamic'],
                ],
            ],
            [
                'name' => 'url',
                'label' => 'Image URL',
                'type' => 'media',
                'show_when' => [
                    'source' => 'static',
                ],
            ],
            [
                'name' => 'field',
                'label' => 'Dynamic Image Field',
                'type' => 'dynamic_field',
                'accepted_types' => ['image', 'media', 'file'],
                'show_when' => [
                    'source' => 'dynamic',
                ],
            ],
            [
                'name' => 'alt',
                'label' => 'Alt Text',
                'type' => 'text',
            ],
            [
                'name' => 'width',
                'label' => 'Width',
                'type' => 'text',
            ],
            [
                'name' => 'height',
                'label' => 'Height',
                'type' => 'text',
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

        $settings['source'] = in_array($settings['source'], ['static', 'dynamic'], true)
            ? $settings['source']
            : 'static';

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) $settings['class']);

        $settings['width'] = preg_replace('/[^a-zA-Z0-9.%_\-\s]/', '', (string) $settings['width']);

        $settings['height'] = preg_replace('/[^a-zA-Z0-9.%_\-\s]/', '', (string) $settings['height']);

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $url = $this->resolveImageUrl($settings, $context);

        if (! $url) {
            return '';
        }

        $class = trim('pb-widget pb-image ' . $settings['class']);

        $attributes = [
            'src' => $url,
            'alt' => $settings['alt'] ?? '',
            'class' => $class,
        ];

        if (! empty($settings['width'])) {
            $attributes['width'] = $settings['width'];
        }

        if (! empty($settings['height'])) {
            $attributes['height'] = $settings['height'];
        }

        $style = $this->styleAttributes($settings);

        return '<img ' . $this->buildAttributes($attributes) . $style . ' />';
    }

    protected function resolveImageUrl(array $settings, WidgetContext $context): string
    {
        if (($settings['source'] ?? 'static') === 'dynamic') {
            $field = $settings['field'] ?? null;

            if (! $field) {
                return '';
            }

            $value = $context->field($field, '');

            if (is_array($value)) {
                return (string) ($value['url'] ?? $value['path'] ?? '');
            }

            return (string) $value;
        }

        return (string) ($settings['url'] ?? '');
    }

    protected function buildAttributes(array $attributes): string
    {
        return collect($attributes)
            ->filter(fn($value) => $value !== null && $value !== '')
            ->map(fn($value, $key) => e($key) . '="' . e((string) $value) . '"')
            ->implode(' ');
    }
}
