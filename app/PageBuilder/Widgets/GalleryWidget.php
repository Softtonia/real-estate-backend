<?php

declare(strict_types=1);

namespace App\PageBuilder\Widgets;

use App\PageBuilder\Foundation\BaseWidget;
use App\PageBuilder\Foundation\WidgetContext;

class GalleryWidget extends BaseWidget
{
    protected static string $type = 'gallery';

    protected string $name = 'Gallery';

    protected string $category = 'Media';

    protected string $icon = 'images';

    protected string $description = 'Display static or dynamic image gallery.';

    public function defaultSettings(): array
    {
        return [
            'source' => 'static',
            'images' => [],
            'field' => null,
            'columns' => 3,
            'gap' => '16px',
            'image_size' => 'medium',
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
                'name' => 'images',
                'label' => 'Images',
                'type' => 'gallery',
                'show_when' => ['source' => 'static'],
            ],
            [
                'name' => 'field',
                'label' => 'Dynamic Gallery Field',
                'type' => 'dynamic_field',
                'accepted_types' => ['gallery', 'images', 'media', 'repeater'],
                'show_when' => ['source' => 'dynamic'],
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
                'name' => 'image_size',
                'label' => 'Image Size',
                'type' => 'select',
                'options' => [
                    ['label' => 'Thumbnail', 'value' => 'thumbnail'],
                    ['label' => 'Medium', 'value' => 'medium'],
                    ['label' => 'Large', 'value' => 'large'],
                    ['label' => 'Full', 'value' => 'full'],
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

        $settings['columns'] = max(1, min(6, (int) ($settings['columns'] ?? 3)));

        $settings['gap'] = preg_replace('/[^a-zA-Z0-9.%_\-\s]/', '', (string) ($settings['gap'] ?? '16px'));

        $settings['image_size'] = in_array($settings['image_size'], ['thumbnail', 'medium', 'large', 'full'], true)
            ? $settings['image_size']
            : 'medium';

        $settings['class'] = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string) ($settings['class'] ?? ''));

        if (! is_array($settings['images'] ?? null)) {
            $settings['images'] = [];
        }

        return $settings;
    }

    public function render(array $settings, WidgetContext $context): string
    {
        $settings = $this->validateSettings($settings);

        $images = $this->resolveImages($settings, $context);

        if (empty($images)) {
            return '';
        }

        $class = trim('pb-widget pb-gallery ' . ($settings['class'] ?? ''));

        $style = $this->styleAttributes($settings, [
            'display' => 'grid',
            'grid-template-columns' => 'repeat(' . (int) ($settings['columns'] ?? 3) . ',1fr)',
            'gap' => e((string) ($settings['gap'] ?? '16px')),
        ]);

        $items = collect($images)
            ->map(function ($image) {
                $url = $this->imageUrl($image);
                $alt = $this->imageAlt($image);

                if (! $url) {
                    return '';
                }

                $isVideo = false;
                if (is_array($image)) {
                    $mime = (string) ($image['mime_type'] ?? $image['mime'] ?? '');
                    $isVideo = ! empty($image['is_video']) || str_starts_with($mime, 'video/');
                }
                if (! $isVideo) {
                    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    $isVideo = in_array($ext, ['mp4', 'mov', 'webm', 'ogg', 'mkv', 'avi']);
                }

                if ($isVideo) {
                    return sprintf(
                        '<figure class="pb-gallery-item pb-gallery-video"><video src="%s" controls preload="metadata" playsinline></video></figure>',
                        e($url)
                    );
                }

                return sprintf(
                    '<figure class="pb-gallery-item"><img src="%s" alt="%s" loading="lazy" /></figure>',
                    e($url),
                    e($alt)
                );
            })
            ->filter()
            ->implode('');

        if ($items === '') {
            return '';
        }

        return sprintf(
            '<div class="%s"%s>%s</div>',
            e($class),
            $style,
            $items
        );
    }

    protected function resolveImages(array $settings, WidgetContext $context): array
    {
        if (($settings['source'] ?? 'static') === 'dynamic') {
            $field = $settings['field'] ?? null;

            if (! $field) {
                return [];
            }

            $value = $context->field($field, []);

            return $this->normalizeImages($value);
        }

        return $this->normalizeImages($settings['images'] ?? []);
    }

    protected function normalizeImages(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeImages($decoded);
            }

            return array_filter(array_map('trim', explode(',', $value)));
        }

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (! is_array($value)) {
            return [];
        }

        /*
         * Support:
         * { images: [...] }
         * { gallery: [...] }
         */
        if (isset($value['images']) && is_array($value['images'])) {
            return $this->normalizeImages($value['images']);
        }

        if (isset($value['gallery']) && is_array($value['gallery'])) {
            return $this->normalizeImages($value['gallery']);
        }

        return array_values($value);
    }

    protected function imageUrl(mixed $image): string
    {
        if (is_string($image)) {
            return $image;
        }

        if (is_object($image)) {
            $image = json_decode(json_encode($image), true);
        }

        if (! is_array($image)) {
            return '';
        }

        return (string) (
            $image['url']
            ?? $image['path']
            ?? $image['src']
            ?? $image['full_url']
            ?? ''
        );
    }

    protected function imageAlt(mixed $image): string
    {
        if (is_string($image)) {
            return '';
        }

        if (is_object($image)) {
            $image = json_decode(json_encode($image), true);
        }

        if (! is_array($image)) {
            return '';
        }

        return (string) (
            $image['alt']
            ?? $image['title']
            ?? $image['name']
            ?? ''
        );
    }
}
