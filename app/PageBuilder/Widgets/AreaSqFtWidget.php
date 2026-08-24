<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class AreaSqFtWidget extends BaseWidget
{
    protected static string $type = 'area_sqft';

    protected string $name = 'Area Sq.Ft';

    protected string $category = 'Basic';

    protected string $icon = 'ruler';

    protected string $description = 'Display formatted area square footage values dynamically.';

    public function defaultSettings(): array
    {
        return [
            'value_source' => 'dynamic',
            'field' => null,
            'field_id' => null,
            'field_key' => null,
            'prefix' => '',
            'suffix' => 'sq.ft',
            'decimal_places' => 0,
            'hide_if_empty' => true,
            'fallback_value' => null,
            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name' => 'value_source',
                'label' => 'Value Source',
                'type' => 'select',
                'options' => [
                    ['label' => 'Dynamic', 'value' => 'dynamic'],
                    ['label' => 'Static', 'value' => 'static'],
                ],
            ],
            [
                'name' => 'field',
                'label' => 'Dynamic Area Field',
                'type' => 'dynamic_field',
            ],
            [
                'name' => 'prefix',
                'label' => 'Prefix',
                'type' => 'text',
            ],
            [
                'name' => 'suffix',
                'label' => 'Suffix',
                'type' => 'text',
            ],
            [
                'name' => 'decimal_places',
                'label' => 'Decimal Places',
                'type' => 'number',
            ],
            [
                'name' => 'hide_if_empty',
                'label' => 'Hide If Empty',
                'type' => 'boolean',
            ],
            [
                'name' => 'fallback_value',
                'label' => 'Fallback Value',
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

        $settings['value_source'] = in_array($settings['value_source'] ?? 'dynamic', ['dynamic', 'static'], true)
            ? $settings['value_source']
            : 'dynamic';

        $settings['prefix'] = (string) ($settings['prefix'] ?? '');
        $settings['suffix'] = (string) ($settings['suffix'] ?? 'sq.ft');
        $settings['decimal_places'] = max(0, (int) ($settings['decimal_places'] ?? 0));
        $settings['hide_if_empty'] = (bool) ($settings['hide_if_empty'] ?? true);
        $settings['fallback_value'] = $settings['fallback_value'] !== null ? (string) $settings['fallback_value'] : null;

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) ($settings['class'] ?? ''));

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $settings = $this->validateSettings($settings);

        $rawValue = $this->resolveRawValue($settings, $context);

        if ($rawValue === null || $rawValue === '') {
            if ($settings['hide_if_empty']) {
                return '';
            }
            $rawValue = $settings['fallback_value'];
            if ($rawValue === null || $rawValue === '') {
                return '';
            }
        }

        $formattedValue = $this->formatAreaValue($rawValue, $settings);

        if ($formattedValue === '') {
            return '';
        }

        $class = trim('pb-widget pb-area-sqft ' . ($settings['class'] ?? ''));
        $style = $this->styleAttributes($settings);

        return sprintf(
            '<div class="%s"%s><span class="pb-area-value">%s</span></div>',
            e($class),
            $style,
            e($formattedValue)
        );
    }

    public function formatAreaValue(mixed $value, array $settings): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            $num = (float) $value;
            $decimals = (int) ($settings['decimal_places'] ?? 0);
            $formattedNumber = number_format($num, $decimals, '.', ',');
        } else {
            $formattedNumber = (string) $value;
        }

        $prefix = trim((string) ($settings['prefix'] ?? ''));
        $suffix = trim((string) ($settings['suffix'] ?? 'sq.ft'));

        $parts = [];
        if ($prefix !== '') {
            $parts[] = $prefix;
        }
        $parts[] = $formattedNumber;
        if ($suffix !== '') {
            $parts[] = $suffix;
        }

        return implode(' ', $parts);
    }

    protected function resolveRawValue(array $settings, WidgetContext $context): mixed
    {
        $fieldKey = $settings['field']
            ?? $settings['field_key']
            ?? null;

        if (!$fieldKey) {
            return null;
        }

        return $context->field($fieldKey, null);
    }

    public function sidebarItem(): array
    {
        return [
            'label' => 'Area Sq.Ft',
            'key' => 'area_sqft',
            'source' => 'basic_widget',
            'type' => 'area_sqft',
            'component_key' => 'area_sqft',
            'field_value' => '',
            'value' => '',
            'has_value' => false,
            'settings' => $this->defaultSettings(),
        ];
    }
}
