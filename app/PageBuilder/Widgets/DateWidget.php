<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;
use Carbon\Carbon;
use Throwable;

class DateWidget extends BaseWidget
{
    protected static string $type = 'date';

    protected string $name = 'Date';

    protected string $category = 'Basic';

    protected string $icon = 'calendar';

    protected string $description = 'Display formatted static or dynamic date/time values.';

    public function defaultSettings(): array
    {
        return [
            'source' => 'dynamic',
            'field' => 'system.created_at',
            'date' => null,
            'format' => 'd-m-Y',
            'custom_format' => '',
            'prefix' => '',
            'suffix' => '',
            'tag' => 'div',
            'hide_if_empty' => true,
            'fallback_value' => null,
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
                    ['label' => 'Dynamic', 'value' => 'dynamic'],
                    ['label' => 'Static', 'value' => 'static'],
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
                'name' => 'date',
                'label' => 'Static Date',
                'type' => 'text',
                'show_when' => [
                    'source' => 'static',
                ],
            ],
            [
                'name' => 'format',
                'label' => 'Date Format',
                'type' => 'select',
                'options' => [
                    ['label' => '02-09-2026 (d-m-Y)', 'value' => 'd-m-Y'],
                    ['label' => '02/09/2026 (d/m/Y)', 'value' => 'd/m/Y'],
                    ['label' => '02 Sep 2026 (d M Y)', 'value' => 'd M Y'],
                    ['label' => 'Sep 02, 2026 (M d, Y)', 'value' => 'M d, Y'],
                    ['label' => 'September 2, 2026 (F j, Y)', 'value' => 'F j, Y'],
                    ['label' => '2026-09-02 (Y-m-d)', 'value' => 'Y-m-d'],
                    ['label' => '09/02/2026 (m/d/Y)', 'value' => 'm/d/Y'],
                    ['label' => '02-09-2026 10:30 AM (d-m-Y g:i A)', 'value' => 'd-m-Y g:i A'],
                    ['label' => 'Relative (e.g. 2 hours ago)', 'value' => 'relative'],
                    ['label' => 'Custom Format', 'value' => 'custom'],
                ],
            ],
            [
                'name' => 'custom_format',
                'label' => 'Custom PHP Date Format',
                'type' => 'text',
                'show_when' => [
                    'format' => 'custom',
                ],
            ],
            [
                'name' => 'prefix',
                'label' => 'Prefix Text',
                'type' => 'text',
            ],
            [
                'name' => 'suffix',
                'label' => 'Suffix Text',
                'type' => 'text',
            ],
            [
                'name' => 'tag',
                'label' => 'HTML Tag',
                'type' => 'select',
                'options' => [
                    ['label' => 'Div', 'value' => 'div'],
                    ['label' => 'Span', 'value' => 'span'],
                    ['label' => 'Paragraph', 'value' => 'p'],
                    ['label' => 'Time', 'value' => 'time'],
                ],
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

        $settings['source'] = in_array($settings['source'] ?? 'dynamic', ['dynamic', 'static'], true)
            ? $settings['source']
            : 'dynamic';

        $settings['format'] = (string) ($settings['format'] ?? 'M d, Y');
        $settings['custom_format'] = (string) ($settings['custom_format'] ?? '');
        $settings['prefix'] = (string) ($settings['prefix'] ?? '');
        $settings['suffix'] = (string) ($settings['suffix'] ?? '');

        $settings['tag'] = in_array($settings['tag'] ?? 'div', ['div', 'span', 'p', 'time'], true)
            ? $settings['tag']
            : 'div';

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

        $formattedDate = $this->formatDateValue($rawValue, $settings);

        if ($formattedDate === '') {
            if ($settings['hide_if_empty']) {
                return '';
            }
            $formattedDate = (string) ($settings['fallback_value'] ?? '');
            if ($formattedDate === '') {
                return '';
            }
        }

        $prefix = trim((string) ($settings['prefix'] ?? ''));
        $suffix = trim((string) ($settings['suffix'] ?? ''));

        $output = '';
        if ($prefix !== '') {
            $output .= '<span class="pb-date-prefix">' . e($prefix) . ' </span>';
        }
        $output .= '<span class="pb-date-value">' . e($formattedDate) . '</span>';
        if ($suffix !== '') {
            $output .= '<span class="pb-date-suffix"> ' . e($suffix) . '</span>';
        }

        $tag = $settings['tag'];
        $class = trim('pb-widget pb-date ' . ($settings['class'] ?? ''));
        $style = $this->styleAttributes($settings);

        $datetimeAttr = '';
        if ($tag === 'time') {
            try {
                $iso = Carbon::parse($rawValue)->toIso8601String();
                $datetimeAttr = ' datetime="' . e($iso) . '"';
            } catch (Throwable) {
                // Ignore parsing errors for time attribute
            }
        }

        return sprintf(
            '<%1$s class="%2$s"%3$s%4$s>%5$s</%1$s>',
            $tag,
            e($class),
            $datetimeAttr,
            $style,
            $output
        );
    }

    public function formatDateValue(mixed $value, array $settings): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $carbon = is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);

            $format = $settings['format'] ?? 'M d, Y';

            if ($format === 'custom' && ! empty($settings['custom_format'])) {
                return $carbon->format($settings['custom_format']);
            }

            if (in_array($format, ['relative', 'human', 'diffForHumans'], true)) {
                return $carbon->diffForHumans();
            }

            return $carbon->format($format ?: 'M d, Y');
        } catch (Throwable) {
            return is_string($value) ? $value : '';
        }
    }

    protected function resolveRawValue(array $settings, WidgetContext $context): mixed
    {
        if (($settings['source'] ?? 'dynamic') === 'static') {
            return $settings['date'] ?? null;
        }

        $fieldKey = $settings['field']
            ?? $settings['field_key']
            ?? 'system.created_at';

        if (! $fieldKey) {
            return null;
        }

        // Try direct field resolution
        $value = $context->field($fieldKey, null);

        if ($value !== null && $value !== '') {
            return $value;
        }

        // If fieldKey starts with system., try without system. prefix as fallback
        if (str_starts_with($fieldKey, 'system.')) {
            $stripped = substr($fieldKey, 7);
            $value = $context->field($stripped, null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        // Fallback to static date if set
        return $settings['date'] ?? null;
    }

    public function sidebarItem(): array
    {
        return [
            'label' => 'Date',
            'key' => 'date',
            'source' => 'basic_widget',
            'type' => 'date',
            'component_key' => 'date',
            'field_value' => '',
            'value' => '',
            'has_value' => false,
            'settings' => $this->defaultSettings(),
            'settings_schema' => $this->schema(),
        ];
    }
}
