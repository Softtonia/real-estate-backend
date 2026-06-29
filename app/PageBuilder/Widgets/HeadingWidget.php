<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class HeadingWidget extends BaseWidget
{
    protected static string $type = 'heading';

    protected string $name = 'Heading';

    protected string $category = 'Basic';

    protected string $icon = 'heading';

    protected string $description = 'Display a static or dynamic heading.';

    public function defaultSettings(): array
    {
        return [
            'source' => 'static',
            'text' => 'Heading Text',
            'field' => null,
            'tag' => 'h2',
            'align' => 'left',
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
                'name' => 'text',
                'label' => 'Text',
                'type' => 'text',
                'show_when' => [
                    'source' => 'static',
                ],
            ],
            [
                'name' => 'field',
                'label' => 'Dynamic Field',
                'type' => 'dynamic_field',
                'show_when' => [
                    'source' => 'dynamic',
                ],
            ],
            [
                'name' => 'tag',
                'label' => 'HTML Tag',
                'type' => 'select',
                'options' => [
                    ['label' => 'H1', 'value' => 'h1'],
                    ['label' => 'H2', 'value' => 'h2'],
                    ['label' => 'H3', 'value' => 'h3'],
                    ['label' => 'H4', 'value' => 'h4'],
                    ['label' => 'H5', 'value' => 'h5'],
                    ['label' => 'H6', 'value' => 'h6'],
                ],
            ],
            [
                'name' => 'align',
                'label' => 'Alignment',
                'type' => 'select',
                'options' => [
                    ['label' => 'Left', 'value' => 'left'],
                    ['label' => 'Center', 'value' => 'center'],
                    ['label' => 'Right', 'value' => 'right'],
                ],
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

        $settings['tag'] = in_array($settings['tag'], ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
            ? $settings['tag']
            : 'h2';

        $settings['align'] = in_array($settings['align'], ['left', 'center', 'right'], true)
            ? $settings['align']
            : 'left';

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) $settings['class']);

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $content = $this->resolveContent($settings, $context);

        if ($content === '') {
            return '';
        }

        $tag = $settings['tag'];
        $align = $settings['align'];
        $class = trim('pb-widget pb-heading ' . $settings['class']);

        $style = $this->styleAttributes($settings, [
            'text-align' => $settings['align'],
        ]);

        return sprintf(
            '<%1$s class="%2$s"%3$s>%4$s</%1$s>',
            $tag,
            e($class),
            $style,
            $this->escape($content)
        );
    }

    protected function resolveContent(array $settings, WidgetContext $context): string
    {
        if (($settings['source'] ?? 'static') === 'dynamic') {
            $field = $settings['field'] ?? null;

            if (! $field) {
                return '';
            }

            return (string) $context->field($field, '');
        }

        return (string) ($settings['text'] ?? '');
    }
}
