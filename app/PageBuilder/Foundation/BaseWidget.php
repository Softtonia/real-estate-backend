<?php

declare(strict_types=1);

namespace App\PageBuilder\Foundation;

use App\PageBuilder\Contracts\WidgetInterface;

abstract class BaseWidget implements WidgetInterface
{
    protected static string $type = '';

    protected string $name = '';

    protected string $category = 'General';

    protected string $icon = 'square';

    protected string $description = '';

    protected bool $dynamicData = true;

    public static function type(): string
    {
        return static::$type;
    }

    public function getType(): string
    {
        return static::$type;
    }

    public function getName(): string
    {
        return $this->name ?: static::$type;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function supportsDynamicData(): bool
    {
        return $this->dynamicData;
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function schema(): array
    {
        return [];
    }

    public function validateSettings(array $settings): array
    {
        $settings = array_replace_recursive(
            $this->defaultSettings(),
            $settings
        );

        $settings['style'] = array_replace_recursive(
            $this->styleDefaults(),
            is_array($settings['style'] ?? null) ? $settings['style'] : []
        );

        return $settings;
    }

    public function assets(): array
    {
        return [
            'styles' => [],
            'scripts' => [],
        ];
    }

    public function styleDefaults(): array
    {
        return [
            'margin' => null,
            'padding' => null,

            'color' => '',
            'background_color' => '',

            'font_size' => '',
            'font_weight' => '',
            'line_height' => '',
            'letter_spacing' => '',
            'text_align' => '',

            'width' => '',
            'max_width' => '',
            'min_height' => '',

            'border_width' => '',
            'border_style' => '',
            'border_color' => '',
            'border_radius' => '',

            'box_shadow' => '',
            'opacity' => '',
        ];
    }

    public function styleSchema(): array
    {
        return [
            [
                'name' => 'style.margin',
                'label' => 'Margin',
                'type' => 'spacing',
            ],
            [
                'name' => 'style.padding',
                'label' => 'Padding',
                'type' => 'spacing',
            ],
            [
                'name' => 'style.color',
                'label' => 'Text Color',
                'type' => 'color',
            ],
            [
                'name' => 'style.background_color',
                'label' => 'Background Color',
                'type' => 'color',
            ],
            [
                'name' => 'style.font_size',
                'label' => 'Font Size',
                'type' => 'text',
            ],
            [
                'name' => 'style.font_weight',
                'label' => 'Font Weight',
                'type' => 'select',
                'options' => [
                    ['label' => 'Normal', 'value' => '400'],
                    ['label' => 'Medium', 'value' => '500'],
                    ['label' => 'Semi Bold', 'value' => '600'],
                    ['label' => 'Bold', 'value' => '700'],
                    ['label' => 'Extra Bold', 'value' => '800'],
                ],
            ],
            [
                'name' => 'style.line_height',
                'label' => 'Line Height',
                'type' => 'text',
            ],
            [
                'name' => 'style.text_align',
                'label' => 'Text Align',
                'type' => 'select',
                'options' => [
                    ['label' => 'Left', 'value' => 'left'],
                    ['label' => 'Center', 'value' => 'center'],
                    ['label' => 'Right', 'value' => 'right'],
                    ['label' => 'Justify', 'value' => 'justify'],
                ],
            ],
            [
                'name' => 'style.width',
                'label' => 'Width',
                'type' => 'text',
            ],
            [
                'name' => 'style.max_width',
                'label' => 'Max Width',
                'type' => 'text',
            ],
            [
                'name' => 'style.border_radius',
                'label' => 'Border Radius',
                'type' => 'text',
            ],
            [
                'name' => 'style.box_shadow',
                'label' => 'Box Shadow',
                'type' => 'text',
            ],
        ];
    }

    protected function setting(array $settings, string $key, mixed $default = null): mixed
    {
        return data_get($settings, $key, $default);
    }

    protected function escape(mixed $value): string
    {
        return e((string) $value);
    }

    protected function styleAttributes(array $settings, array $extraStyles = []): string
    {
        $style = is_array($settings['style'] ?? null)
            ? $settings['style']
            : [];

        $styles = array_replace(
            $extraStyles,
            $this->styleArray($style)
        );

        return $this->buildStyleAttribute($styles);
    }

    protected function styleArray(array $style): array
    {
        $styles = [];

        $this->resolveBoxStyles($style, 'margin', $styles);
        $this->resolveBoxStyles($style, 'padding', $styles);

        if (! empty($style['color'])) {
            $styles['color'] = $this->safeColor($style['color']);
        }

        if (! empty($style['background_color'])) {
            $styles['background-color'] = $this->safeColor($style['background_color']);
        }

        if (! empty($style['font_size'])) {
            $styles['font-size'] = $this->cssLength($style['font_size']);
        }

        if (! empty($style['font_weight'])) {
            $styles['font-weight'] = $this->safeCssValue($style['font_weight']);
        }

        if (! empty($style['line_height'])) {
            $styles['line-height'] = $this->safeCssValue($style['line_height']);
        }

        if (! empty($style['letter_spacing'])) {
            $styles['letter-spacing'] = $this->cssLength($style['letter_spacing']);
        }

        if (! empty($style['text_align'])) {
            $styles['text-align'] = $this->safeCssValue($style['text_align']);
        }

        if (! empty($style['width'])) {
            $styles['width'] = $this->cssLength($style['width']);
        }

        if (! empty($style['max_width'])) {
            $styles['max-width'] = $this->cssLength($style['max_width']);
        }

        if (! empty($style['min_height'])) {
            $styles['min-height'] = $this->cssLength($style['min_height']);
        }

        if (! empty($style['border_width'])) {
            $styles['border-width'] = $this->cssLength($style['border_width']);
        }

        if (! empty($style['border_style'])) {
            $styles['border-style'] = $this->safeCssValue($style['border_style']);
        }

        if (! empty($style['border_color'])) {
            $styles['border-color'] = $this->safeColor($style['border_color']);
        }

        if (! empty($style['border_radius'])) {
            $styles['border-radius'] = $this->cssLength($style['border_radius']);
        }

        if (! empty($style['box_shadow'])) {
            $styles['box-shadow'] = $this->safeCssValue($style['box_shadow']);
        }

        if (! empty($style['opacity'])) {
            $styles['opacity'] = $this->safeCssValue($style['opacity']);
        }

        return $styles;
    }

    protected function buildStyleAttribute(array $styles): string
    {
        $styles = array_filter($styles, fn ($value) => $value !== null && $value !== '');

        if (empty($styles)) {
            return '';
        }

        $styleString = collect($styles)
            ->map(fn ($value, $property) => $property . ':' . $value)
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

    protected function resolveBoxStyles(array $settings, string $prefix, array &$styles): void
    {
        $camelTop = $prefix . 'Top';
        $camelRight = $prefix . 'Right';
        $camelBottom = $prefix . 'Bottom';
        $camelLeft = $prefix . 'Left';

        $snakeTop = $prefix . '_top';
        $snakeRight = $prefix . '_right';
        $snakeBottom = $prefix . '_bottom';
        $snakeLeft = $prefix . '_left';

        $hasDirectional = (isset($settings[$camelTop]) && $settings[$camelTop] !== '' && $settings[$camelTop] !== null)
            || (isset($settings[$camelRight]) && $settings[$camelRight] !== '' && $settings[$camelRight] !== null)
            || (isset($settings[$camelBottom]) && $settings[$camelBottom] !== '' && $settings[$camelBottom] !== null)
            || (isset($settings[$camelLeft]) && $settings[$camelLeft] !== '' && $settings[$camelLeft] !== null)
            || (isset($settings[$snakeTop]) && $settings[$snakeTop] !== '' && $settings[$snakeTop] !== null)
            || (isset($settings[$snakeRight]) && $settings[$snakeRight] !== '' && $settings[$snakeRight] !== null)
            || (isset($settings[$snakeBottom]) && $settings[$snakeBottom] !== '' && $settings[$snakeBottom] !== null)
            || (isset($settings[$snakeLeft]) && $settings[$snakeLeft] !== '' && $settings[$snakeLeft] !== null);

        if ($hasDirectional) {
            $top = $settings[$camelTop] ?? $settings[$snakeTop] ?? null;
            $right = $settings[$camelRight] ?? $settings[$snakeRight] ?? null;
            $bottom = $settings[$camelBottom] ?? $settings[$snakeBottom] ?? null;
            $left = $settings[$camelLeft] ?? $settings[$snakeLeft] ?? null;

            if ($top !== null && $top !== '') $styles["{$prefix}-top"] = $this->cssLength($top);
            if ($right !== null && $right !== '') $styles["{$prefix}-right"] = $this->cssLength($right);
            if ($bottom !== null && $bottom !== '') $styles["{$prefix}-bottom"] = $this->cssLength($bottom);
            if ($left !== null && $left !== '') $styles["{$prefix}-left"] = $this->cssLength($left);
        } elseif (! empty($settings[$prefix])) {
            $styles[$prefix] = $this->cssBoxValue($settings[$prefix]);
        }
    }

    protected function safeColor(mixed $value): string
    {
        return preg_replace(
            '/[^a-zA-Z0-9#(),.%\s]/',
            '',
            trim((string) $value)
        ) ?: '';
    }

    protected function safeCssValue(mixed $value): string
    {
        return preg_replace(
            '/[^a-zA-Z0-9#(),.%_\-\s]/',
            '',
            trim((string) $value)
        ) ?: '';
    }

    abstract public function render(array $settings, WidgetContext $context): string;
}