<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Illuminate\Support\Collection;

class TemplateConflictService
{
    public function check(Template $template): array
    {
        $template->loadMissing(['conditions', 'postType']);

        $candidates = $this->candidateTemplates($template);

        $conflicts = [];

        foreach ($candidates as $candidate) {
            $reason = $this->conflictReason($template, $candidate);

            if ($reason !== null) {
                $conflicts[] = [
                    'template_id' => $candidate->id,
                    'template_name' => $candidate->template_name,
                    'template_type' => $candidate->template_type,
                    'post_type_id' => $candidate->post_type_id,
                    'post_type_slug' => $candidate->post_type_slug,
                    'priority' => $candidate->priority,
                    'status' => $candidate->status,
                    'reason' => $reason,
                ];
            }
        }

        return [
            'valid' => empty($conflicts),
            'has_conflict' => ! empty($conflicts),
            'conflicts' => $conflicts,
        ];
    }

    protected function candidateTemplates(Template $template): Collection
    {
        $query = Template::query()
            ->with(['conditions', 'postType'])
            ->where('id', '!=', $template->id)
            ->where('template_type', $template->template_type)
            ->where(function ($q) {
                $q->whereIn('status', ['active', 'published'])
                    ->orWhere('status', 1)
                    ->orWhere('status', true);
            })
            ->where('priority', (int) ($template->priority ?? 0));

        if ($template->template_type === 'single_post') {
            $query->where(function ($q) use ($template) {
                if (! empty($template->post_type_id)) {
                    $q->orWhere('post_type_id', $template->post_type_id);
                }

                if (! empty($template->post_type_slug)) {
                    $q->orWhere('post_type_slug', $template->post_type_slug);
                }
            });
        }

        return $query->get();
    }

    protected function conflictReason(Template $template, Template $candidate): ?string
    {
        if (! $this->sameTemplateScope($template, $candidate)) {
            return null;
        }

        $templateIncludes = $this->includeConditions($template);
        $candidateIncludes = $this->includeConditions($candidate);

        if ($templateIncludes->isEmpty() || $candidateIncludes->isEmpty()) {
            return null;
        }

        foreach ($templateIncludes as $templateCondition) {
            foreach ($candidateIncludes as $candidateCondition) {
                $reason = $this->conditionsConflict(
                    $template,
                    $candidate,
                    $templateCondition,
                    $candidateCondition
                );

                if ($reason !== null) {
                    return $reason;
                }
            }
        }

        return null;
    }

    protected function sameTemplateScope(Template $template, Template $candidate): bool
    {
        if ($template->template_type !== $candidate->template_type) {
            return false;
        }

        if ((int) ($template->priority ?? 0) !== (int) ($candidate->priority ?? 0)) {
            return false;
        }

        if ($template->template_type === 'single_post') {
            return $this->samePostType($template, $candidate);
        }

        return true;
    }

    protected function samePostType(Template $template, Template $candidate): bool
    {
        if (
            ! empty($template->post_type_id)
            && ! empty($candidate->post_type_id)
            && (int) $template->post_type_id === (int) $candidate->post_type_id
        ) {
            return true;
        }

        if (
            ! empty($template->post_type_slug)
            && ! empty($candidate->post_type_slug)
            && $template->post_type_slug === $candidate->post_type_slug
        ) {
            return true;
        }

        return false;
    }

    protected function includeConditions(Template $template): Collection
    {
        return $template->conditions
            ->filter(function ($condition) {
                return ($condition->show_type ?? 'include') === 'include';
            })
            ->values();
    }

    protected function conditionsConflict(
        Template $template,
        Template $candidate,
        object $templateCondition,
        object $candidateCondition
    ): ?string {
        $templateSource = $templateCondition->source_type ?? 'post_type';
        $candidateSource = $candidateCondition->source_type ?? 'post_type';

        /*
         * If either template targets whole post type,
         * and both templates belong to same post type,
         * then conflict exists.
         */
        if ($templateSource === 'post_type' || $candidateSource === 'post_type') {
            if ($this->samePostType($template, $candidate)) {
                return 'Both templates target the same post type with the same priority.';
            }

            return null;
        }

        /*
         * Taxonomy conflict.
         */
        if ($templateSource === 'taxonomy' && $candidateSource === 'taxonomy') {
            if (! $this->sameTaxonomy($templateCondition, $candidateCondition)) {
                return null;
            }

            if ($this->taxonomyTermsOverlap($templateCondition, $candidateCondition)) {
                return 'Both templates target overlapping taxonomy terms with the same priority.';
            }
        }

        /*
         * Exact fallback conflict.
         */
        if ($this->conditionSignature($templateCondition) === $this->conditionSignature($candidateCondition)) {
            return 'Both templates have identical display conditions and priority.';
        }

        return null;
    }

    protected function sameTaxonomy(object $a, object $b): bool
    {
        if (
            ! empty($a->taxonomy_id)
            && ! empty($b->taxonomy_id)
            && (int) $a->taxonomy_id === (int) $b->taxonomy_id
        ) {
            return true;
        }

        if (
            ! empty($a->taxonomy_slug)
            && ! empty($b->taxonomy_slug)
            && $a->taxonomy_slug === $b->taxonomy_slug
        ) {
            return true;
        }

        return false;
    }

    protected function taxonomyTermsOverlap(object $a, object $b): bool
    {
        $aTerms = $this->normalizeTermIds($a->taxonomy_term_ids ?? []);
        $bTerms = $this->normalizeTermIds($b->taxonomy_term_ids ?? []);

        /*
         * Empty terms means whole taxonomy.
         */
        if (empty($aTerms) || empty($bTerms)) {
            return true;
        }

        return ! empty(array_intersect($aTerms, $bTerms));
    }

    protected function normalizeTermIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatten()
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function conditionSignature(object $condition): string
    {
        $data = [
            'source_type' => $condition->source_type ?? null,
            'post_type_id' => $condition->post_type_id ?? null,
            'post_type_slug' => $condition->post_type_slug ?? null,
            'taxonomy_id' => $condition->taxonomy_id ?? null,
            'taxonomy_slug' => $condition->taxonomy_slug ?? null,
            'taxonomy_term_ids' => $this->normalizeTermIds($condition->taxonomy_term_ids ?? []),
            'relation' => $condition->relation ?? null,
            'condition_value' => $condition->condition_value ?? null,
        ];

        return md5(json_encode($data));
    }
}