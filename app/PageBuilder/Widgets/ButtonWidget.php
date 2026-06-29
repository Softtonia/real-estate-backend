<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class ButtonWidget extends BaseWidget
{
    protected static string $type = 'button';

    protected string $name = 'Button';

    protected string $category = 'Basic';

    protected string $icon = 'button';

    protected string $description = 'Display a static or dynamic button.';

    public function defaultSettings(): array
    {
        return [
            'label_source' => 'static',
            'label' => 'Click Here',
            'label_field' => null,
            'url_source' => 'static',
            'url' => '#',
            'url_field' => null,
            'target' => '_self',
            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name' => 'label_source',
                'label' => 'Label Source',
                'type' => 'select',
                'options' => [
                    ['label' => 'Static', 'value' => 'static'],
                    ['label' => 'Dynamic', 'value' => 'dynamic'],
                ],
            ],
            [
                'name' => 'label',
                'label' => 'Button Label',
                'type' => 'text',
                'show_when' => [
                    'label_source' => 'static',
                ],
            ],
            [
                'name' => 'label_field',
                'label' => 'Dynamic Label Field',
                'type' => 'dynamic_field',
                'show_when' => [
                    'label_source' => 'dynamic',
                ],
            ],
            [
                'name' => 'url_source',
                'label' => 'URL Source',
                'type' => 'select',
                'options' => [
                    ['label' => 'Static', 'value' => 'static'],
                    ['label' => 'Dynamic', 'value' => 'dynamic'],
                ],
            ],
            [
                'name' => 'url',
                'label' => 'URL',
                'type' => 'text',
                'show_when' => [
                    'url_source' => 'static',
                ],
            ],
            [
                'name' => 'url_field',
                'label' => 'Dynamic URL Field',
                'type' => 'dynamic_field',
                'show_when' => [
                    'url_source' => 'dynamic',
                ],
            ],
            [
                'name' => 'target',
                'label' => 'Open In',
                'type' => 'select',
                'options' => [
                    ['label' => 'Same Tab', 'value' => '_self'],
                    ['label' => 'New Tab', 'value' => '_blank'],
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

        $settings['label_source'] = in_array($settings['label_source'], ['static', 'dynamic'], true)
            ? $settings['label_source']
            : 'static';

        $settings['url_source'] = in_array($settings['url_source'], ['static', 'dynamic'], true)
            ? $settings['url_source']
            : 'static';

        $settings['target'] = in_array($settings['target'], ['_self', '_blank'], true)
            ? $settings['target']
            : '_self';

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) $settings['class']);

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $label = $this->resolveLabel($settings, $context);
        $url = $this->resolveUrl($settings, $context);

        if ($label === '') {
            return '';
        }

        $class = trim('pb-widget pb-button ' . $settings['class']);

        $rel = $settings['target'] === '_blank'
            ? ' rel="noopener noreferrer"'
            : '';

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<a href="%1$s" target="%2$s" class="%3$s"%4$s%5$s>%6$s</a>',
            e($url ?: '#'),
            e($settings['target']),
            e($class),
            $rel,
            $style,
            $this->escape($label)
        );
    }

    protected function resolveLabel(array $settings, WidgetContext $context): string
    {
        if (($settings['label_source'] ?? 'static') === 'dynamic') {
            $field = $settings['label_field'] ?? null;

            if (! $field) {
                return '';
            }

            return (string) $context->field($field, '');
        }

        return (string) ($settings['label'] ?? '');
    }

    protected function resolveUrl(array $settings, WidgetContext $context): string
    {
        if (($settings['url_source'] ?? 'static') === 'dynamic') {
            $field = $settings['url_field'] ?? null;

            if (! $field) {
                return '#';
            }

            return (string) $context->field($field, '#');
        }

        return (string) ($settings['url'] ?? '#');
    }
}
