<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class HtmlWidget extends BaseWidget
{
    protected static string $type = 'html';

    protected string $name = 'HTML';

    protected string $category = 'Advanced';

    protected string $icon = 'code';

    protected string $description = 'Display custom HTML content.';

    protected bool $dynamicData = false;

    public function defaultSettings(): array
    {
        return [
            'html' => '<div>Custom HTML</div>',
            'allow_scripts' => false,
            'class' => '',
        ];
    }

    public function schema(): array
    {
        return [
            [
                'name' => 'html',
                'label' => 'HTML Code',
                'type' => 'code',
                'language' => 'html',
            ],
            [
                'name' => 'allow_scripts',
                'label' => 'Allow Script Tags',
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

        $settings['html'] = (string) ($settings['html'] ?? '');
        $settings['allow_scripts'] = (bool) ($settings['allow_scripts'] ?? false);

        $settings['class'] = preg_replace(
            '/[^a-zA-Z0-9_\-\s]/',
            '',
            (string) ($settings['class'] ?? '')
        );

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $html = $settings['html'] ?? '';

        if ($html === '') {
            return '';
        }

        $html = $this->sanitizeHtml(
            $html,
            (bool) ($settings['allow_scripts'] ?? false)
        );

        if ($html === '') {
            return '';
        }

        $class = trim('pb-widget pb-html ' . ($settings['class'] ?? ''));

        $style = $this->styleAttributes($settings);

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $html
        );
    }

    protected function sanitizeHtml(string $html, bool $allowScripts = false): string
    {
        $allowedTags = '<div><section><article><header><footer><main><aside>'
            . '<h1><h2><h3><h4><h5><h6>'
            . '<p><br><hr><span><strong><b><em><i><u><small>'
            . '<ul><ol><li>'
            . '<a><img><figure><figcaption>'
            . '<table><thead><tbody><tr><th><td>'
            . '<iframe>'
            . '<svg><path><circle><rect><line><polyline><polygon><g>'
            . '<blockquote><pre><code>';

        if ($allowScripts) {
            $allowedTags .= '<script><noscript>';
        }

        $html = strip_tags($html, $allowedTags);

        if (! $allowScripts) {
            $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
            $html = preg_replace('/on[a-z]+\s*=\s*["\'][^"\']*["\']/i', '', $html);
            $html = preg_replace('/javascript:/i', '', $html);
        }

        return (string) $html;
    }
}
