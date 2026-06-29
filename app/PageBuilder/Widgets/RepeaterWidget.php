<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;
use App\PageBuilder\Foundation\WidgetManager;
use Throwable;

class RepeaterWidget extends BaseWidget
{
    protected static string $type = 'repeater';

    protected string $name = 'Repeater';

    protected string $category = 'Dynamic';

    protected string $icon = 'repeat';

    protected string $description = 'Display repeated dynamic field items.';

    public function __construct(
        protected WidgetManager $widgetManager
    ) {}

    public function defaultSettings(): array
    {
        return [
            'source' => 'dynamic',
            'field' => null,

            /*
             * card | list | grid
             */
            'layout' => 'grid',
            'columns' => 3,
            'gap' => '16px',

            /*
             * Child widgets rendered for each repeater item.
             * Use dynamic fields like item.title, item.image, item.description.
             */
            'item_template' => [
                [
                    'type' => 'heading',
                    'settings' => [
                        'source' => 'dynamic',
                        'field' => 'item.title',
                        'tag' => 'h3',
                    ],
                ],
                [
                    'type' => 'text',
                    'settings' => [
                        'source' => 'dynamic',
                        'field' => 'item.description',
                        'tag' => 'p',
                    ],
                ],
            ],

            'empty_message' => '',
            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name' => 'field',
                'label' => 'Repeater Field',
                'type' => 'dynamic_field',
                'accepted_types' => ['repeater', 'array', 'json'],
            ],
            [
                'name' => 'layout',
                'label' => 'Layout',
                'type' => 'select',
                'options' => [
                    ['label' => 'Grid', 'value' => 'grid'],
                    ['label' => 'List', 'value' => 'list'],
                    ['label' => 'Card', 'value' => 'card'],
                ],
            ],
            [
                'name' => 'columns',
                'label' => 'Columns',
                'type' => 'number',
                'min' => 1,
                'max' => 6,
            ],
            [
                'name' => 'gap',
                'label' => 'Gap',
                'type' => 'text',
            ],
            [
                'name' => 'item_template',
                'label' => 'Item Template',
                'type' => 'widget_list',
            ],
            [
                'name' => 'empty_message',
                'label' => 'Empty Message',
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

        $settings['source'] = 'dynamic';

        $settings['layout'] = in_array($settings['layout'], ['grid', 'list', 'card'], true)
            ? $settings['layout']
            : 'grid';

        $settings['columns'] = max(1, min(6, (int) ($settings['columns'] ?? 3)));

        $settings['gap'] = preg_replace('/[^a-zA-Z0-9.%_\-\s]/', '', (string) ($settings['gap'] ?? '16px'));

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) ($settings['class'] ?? ''));

        if (! is_array($settings['item_template'] ?? null)) {
            $settings['item_template'] = [];
        }

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $field = $settings['field'] ?? null;

        if (! $field) {
            return '';
        }

        $items = $this->normalizeItems(
            $context->field($field, [])
        );

        if (empty($items)) {
            return ! empty($settings['empty_message'])
                ? '<div class="pb-repeater-empty">' . e($settings['empty_message']) . '</div>'
                : '';
        }

        $class = trim(
            'pb-widget pb-repeater pb-repeater-' . $settings['layout'] . ' ' . $settings['class']
        );

        $style = $this->styleAttributes(
            $settings,
            $this->containerStyleArray($settings)
        );

        $html = collect($items)
            ->map(function ($item, int $index) use ($settings, $context) {
                return $this->renderItem($item, $index, $settings, $context);
            })
            ->implode('');

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $html
        );
    }

    protected function renderItem(
        mixed $item,
        int $index,
        array $settings,
        WidgetContext $context
    ): string {
        $item = $this->normalizeItem($item);

        $itemContext = $context->withFields([
            'item' => $item,
            'repeater' => [
                'index' => $index,
                'number' => $index + 1,
                'item' => $item,
            ],
        ]);

        $content = collect($settings['item_template'] ?? [])
            ->filter(fn($node) => is_array($node))
            ->map(function (array $node) use ($itemContext) {
                return $this->renderChildWidget($node, $itemContext);
            })
            ->implode('');

        if ($content === '') {
            $content = $this->fallbackItemHtml($item);
        }

        return sprintf(
            '<div class="pb-repeater-item">%s</div>',
            $content
        );
    }

    protected function renderChildWidget(array $node, WidgetContext $context): string
    {
        $type = $node['type']
            ?? $node['widget']
            ?? $node['component_key']
            ?? null;

        if (! $type || ! $this->widgetManager->has((string) $type)) {
            return '';
        }

        try {
            return $this->widgetManager->render(
                (string) $type,
                is_array($node['settings'] ?? null) ? $node['settings'] : [],
                $context
            );
        } catch (Throwable) {
            return '';
        }
    }

    protected function normalizeItems(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeItems($decoded);
            }

            return [];
        }

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (! is_array($value)) {
            return [];
        }

        if (isset($value['items']) && is_array($value['items'])) {
            return $this->normalizeItems($value['items']);
        }

        if (isset($value['rows']) && is_array($value['rows'])) {
            return $this->normalizeItems($value['rows']);
        }

        return array_values($value);
    }

    protected function normalizeItem(mixed $item): array
    {
        if (is_object($item)) {
            $item = json_decode(json_encode($item), true);
        }

        if (is_array($item)) {
            return $item;
        }

        return [
            'value' => $item,
        ];
    }

    protected function fallbackItemHtml(array $item): string
    {
        return collect($item)
            ->map(function ($value, $key) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                return '<div class="pb-repeater-field"><strong>' . e((string) $key) . ':</strong> ' . e((string) $value) . '</div>';
            })
            ->implode('');
    }

    protected function containerStyleArray(array $settings): array
    {
        if ($settings['layout'] !== 'grid') {
            return [];
        }

        return [
            'display' => 'grid',
            'grid-template-columns' => 'repeat(' . (int) $settings['columns'] . ',1fr)',
            'gap' => e((string) $settings['gap']),
        ];
    }
}
