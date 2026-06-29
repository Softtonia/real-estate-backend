<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TemplateExportImportService
{
    public function export(Template $template): array
    {
        $template->load(['layout', 'conditions', 'postType']);

        return [
            'export_type' => 'page_builder_template',
            'version' => '1.0',
            'exported_at' => now()->toISOString(),

            'template' => [
                'template_type' => $template->template_type,
                'template_name' => $template->template_name,
                'slug' => $template->slug,
                'shortcode' => $template->shortcode,
                'status' => $template->status,
                'priority' => $template->priority,
                'post_type_id' => $template->post_type_id,
                'post_type_slug' => $template->post_type_slug,
                'post_type' => $template->postType ? [
                    'id' => $template->postType->id,
                    'name' => $template->postType->name,
                    'slug' => $template->postType->slug,
                ] : null,
            ],

            'layout' => $template->layout?->layout_json ?? [
                'sections' => [],
            ],

            'conditions' => $template->conditions
                ->map(function ($condition) {
                    return [
                        'show_type' => $condition->show_type,
                        'source_type' => $condition->source_type,

                        'post_type_id' => $condition->post_type_id,
                        'post_type_slug' => $condition->post_type_slug,

                        'taxonomy_id' => $condition->taxonomy_id,
                        'taxonomy_slug' => $condition->taxonomy_slug,

                        'taxonomy_term_ids' => $condition->taxonomy_term_ids,
                        'relation' => $condition->relation,
                        'condition_value' => $condition->condition_value,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function import(array $payload, array $options = []): Template
    {
        $payload = $this->normalizeImportPayload($payload);

        if (($payload['export_type'] ?? '') !== 'page_builder_template') {
            throw new InvalidArgumentException('Invalid template export file.');
        }

        $templateData = $payload['template'] ?? [];

        if (empty($templateData['template_type'])) {
            throw new InvalidArgumentException('Template type is missing.');
        }

        if (empty($templateData['template_name'])) {
            throw new InvalidArgumentException('Template name is missing.');
        }

        return DB::transaction(function () use ($payload, $templateData, $options) {
            $postType = $this->resolvePostTypeForImport($templateData);

            $templateName = $options['template_name']
                ?? $templateData['template_name'];

            $status = ! empty($options['publish'])
                ? 'active'
                : 'draft';

            $template = Template::create([
                'template_type' => $templateData['template_type'],
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'template_name' => $templateName,
                'slug' => $this->generateUniqueSlug($templateName),
                'shortcode' => null,
                'status' => $status,
                'priority' => (int) ($options['priority'] ?? $templateData['priority'] ?? 0),
                'created_by' => auth()->id(),
            ]);

            $template->update([
                'shortcode' => $this->generateShortcode((int) $template->id),
            ]);

            $template->layout()->create([
                'layout_json' => $payload['layout'] ?? [
                    'sections' => [],
                ],
            ]);

            foreach (($payload['conditions'] ?? []) as $condition) {
                $this->createCondition($template, $condition);
            }

            return $template->fresh(['layout', 'conditions', 'postType']);
        });
    }

    private function normalizeImportPayload(array $payload): array
    {
        /*
         * Support direct export JSON.
         */
        if (($payload['export_type'] ?? null) === 'page_builder_template') {
            return $payload;
        }

        /*
         * Support wrapped API body:
         * {
         *   "template_json": {...}
         * }
         */
        $templateJson = $payload['template_json'] ?? null;

        if (is_string($templateJson)) {
            $decoded = json_decode($templateJson, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw new InvalidArgumentException('Invalid template_json.');
            }

            return $decoded;
        }

        if (is_array($templateJson)) {
            return $templateJson;
        }

        throw new InvalidArgumentException('template_json is required.');
    }

    private function resolvePostTypeForImport(array $templateData): ?PostType
    {
        if (($templateData['template_type'] ?? '') !== 'single_post') {
            return null;
        }

        $postTypeSlug = $templateData['post_type_slug']
            ?? data_get($templateData, 'post_type.slug');

        if ($postTypeSlug) {
            $postType = PostType::where('slug', $postTypeSlug)->first();

            if ($postType) {
                return $postType;
            }
        }

        if (! empty($templateData['post_type_id'])) {
            $postType = PostType::find($templateData['post_type_id']);

            if ($postType) {
                return $postType;
            }
        }

        throw new InvalidArgumentException(
            'Post type not found. Please create matching post type before importing this template.'
        );
    }

    private function createCondition(Template $template, array $condition): void
    {
        $template->conditions()->create([
            'show_type' => $condition['show_type'] ?? 'include',
            'source_type' => $condition['source_type'] ?? 'post_type',

            'post_type_id' => $condition['post_type_id'] ?? null,
            'post_type_slug' => $condition['post_type_slug'] ?? null,

            'taxonomy_id' => $condition['taxonomy_id'] ?? null,
            'taxonomy_slug' => $condition['taxonomy_slug'] ?? null,

            'taxonomy_term_ids' => $condition['taxonomy_term_ids'] ?? null,
            'relation' => $condition['relation'] ?? 'and',
            'condition_value' => $condition['condition_value'] ?? null,
        ]);
    }

    private function generateUniqueSlug(string $templateName): string
    {
        $baseSlug = Str::slug($templateName);

        if ($baseSlug === '') {
            $baseSlug = 'template';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (Template::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateShortcode(int $templateId): string
    {
        return '[template id="' . $templateId . '"]';
    }
}