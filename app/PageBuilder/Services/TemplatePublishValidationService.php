<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Throwable;

class TemplatePublishValidationService
{
    public function __construct(
        protected LayoutValidationService $layoutValidationService
    ) {
    }

    public function validate(Template $template): array
    {
        $template->loadMissing(['layout', 'conditions', 'postType']);

        $errors = [];
        $warnings = [];

        if ($template->template_type === 'single_post') {
            if (empty($template->post_type_id) && empty($template->post_type_slug)) {
                $errors[] = 'Post type is required for single post template.';
            }
        }

        $layoutJson = $this->normalizeLayoutJson($template->layout?->layout_json);

        if (! $template->layout || empty($layoutJson)) {
            $errors[] = 'Template layout is required before publishing.';
        } elseif (! $this->hasRenderableWidget($layoutJson)) {
            $errors[] = 'Template layout must contain at least one widget.';
        } else {
            try {
                $layoutValidation = $this->layoutValidationService->validate($layoutJson);

                if (! $layoutValidation['valid']) {
                    foreach ($layoutValidation['errors'] as $error) {
                        $errors[] = $error;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Layout validation failed: ' . $e->getMessage();
            }
        }

        $includeConditions = $template->conditions
            ->filter(fn ($condition) => ($condition->show_type ?? null) === 'include');

        if ($includeConditions->isEmpty()) {
            $errors[] = 'At least one include display condition is required before publishing.';
        }

        foreach ($includeConditions as $condition) {
            if (($condition->source_type ?? null) === 'post_type') {
                if (
                    empty($condition->post_type_id)
                    && empty($condition->post_type_slug)
                    && empty($template->post_type_id)
                    && empty($template->post_type_slug)
                ) {
                    $errors[] = 'Include condition for post type is incomplete.';
                }
            }

            if (($condition->source_type ?? null) === 'taxonomy') {
                if (empty($condition->taxonomy_id) && empty($condition->taxonomy_slug)) {
                    $errors[] = 'Include condition for taxonomy is incomplete.';
                }
            }
        }

        if ($template->conditions->isEmpty()) {
            $warnings[] = 'No display conditions found.';
        }

        return [
            'valid' => empty($errors),
            'can_publish' => empty($errors),
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    protected function normalizeLayoutJson(mixed $layoutJson): array
    {
        if (is_string($layoutJson)) {
            $decoded = json_decode($layoutJson, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        return is_array($layoutJson) ? $layoutJson : [];
    }

    protected function hasRenderableWidget(array $node): bool
    {
        if (
            isset($node['type'])
            || isset($node['widget'])
            || isset($node['widget_type'])
            || isset($node['component_key'])
        ) {
            return true;
        }

        foreach ($node as $value) {
            if (is_array($value) && $this->hasRenderableWidget($value)) {
                return true;
            }
        }

        return false;
    }
}