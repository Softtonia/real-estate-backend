<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use App\Models\TemplateRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TemplateRevisionService
{
    public function createSnapshot(
        Template $template,
        string $revisionType = 'layout_save',
        ?string $note = null
    ): ?TemplateRevision {
        $template->loadMissing(['layout', 'conditions']);

        $hasLayout = $template->layout && is_array($template->layout->layout_json);
        $hasConditions = $template->conditions && $template->conditions->isNotEmpty();

        if (! $hasLayout && ! $hasConditions) {
            return null;
        }

        return TemplateRevision::create([
            'template_id' => $template->id,
            'layout_json' => $template->layout?->layout_json,
            'conditions_json' => $template->conditions
                ? $template->conditions->map(function ($condition) {
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
                })->values()->all()
                : [],
            'revision_type' => $revisionType,
            'note' => $note,
            'created_by' => auth()->id(),
        ]);
    }

    public function list(int $templateId, int $perPage = 20): LengthAwarePaginator
    {
        return TemplateRevision::query()
            ->where('template_id', $templateId)
            ->latest()
            ->paginate($perPage);
    }

    public function findForTemplate(int $templateId, int $revisionId): TemplateRevision
    {
        return TemplateRevision::where('template_id', $templateId)
            ->where('id', $revisionId)
            ->firstOrFail();
    }

    public function restoreLayout(Template $template, TemplateRevision $revision): Template
    {
        return DB::transaction(function () use ($template, $revision) {
            $this->createSnapshot(
                $template,
                'before_restore',
                'Snapshot before restoring revision #' . $revision->id
            );

            $template->layout()->updateOrCreate(
                ['template_id' => $template->id],
                ['layout_json' => $revision->layout_json ?? ['sections' => []]]
            );

            return $template->fresh(['layout', 'conditions', 'postType']);
        });
    }
}