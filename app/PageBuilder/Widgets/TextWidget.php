<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class TextWidget extends BaseWidget
{
    protected static string $type = 'text';

    protected string $name = 'Text';

    protected string $category = 'Basic';

    protected string $icon = 'text';

    protected string $description = 'Display static or dynamic text content.';

    public function defaultSettings(): array
    {
        return [
            'source' => 'static',
            'text' => 'Text content',
            'field' => null,
            'tag' => 'div',
            'allow_html' => false,
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
                'type' => 'textarea',
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
                    ['label' => 'Div', 'value' => 'div'],
                    ['label' => 'Paragraph', 'value' => 'p'],
                    ['label' => 'Span', 'value' => 'span'],
                ],
            ],
            [
                'name' => 'allow_html',
                'label' => 'Allow Basic HTML',
                'type' => 'boolean',
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

        $settings['tag'] = in_array($settings['tag'], ['div', 'p', 'span'], true)
            ? $settings['tag']
            : 'div';

        $settings['allow_html'] = (bool) ($settings['allow_html'] ?? false);

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
        $class = trim('pb-widget pb-text ' . $settings['class']);

        $html = $settings['allow_html']
            ? $this->sanitizeHtml($content)
            : nl2br($this->escape($content));

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<%1$s class="%2$s"%3$s>%4$s</%1$s>',
            $tag,
            e($class),
            $style,
            $html
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

    protected function sanitizeHtml(string $html): string
    {
        return strip_tags(
            $html,
            '<p><br><strong><b><em><i><u><ul><ol><li><span>'
        );
    }
}
