<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TemplateDuplicateService
{
    public function duplicate(Template $sourceTemplate, ?string $newName = null): Template
    {
        $sourceTemplate->loadMissing(['layout', 'conditions', 'postType']);

        return DB::transaction(function () use ($sourceTemplate, $newName) {
            $templateName = $newName ?: 'Copy of ' . $sourceTemplate->template_name;

            $newTemplate = Template::create([
                'template_type' => $sourceTemplate->template_type,
                'post_type_id' => $sourceTemplate->post_type_id,
                'post_type_slug' => $sourceTemplate->post_type_slug,
                'template_name' => $templateName,
                'slug' => $this->generateUniqueSlug($templateName),
                'shortcode' => null,
                'status' => 'draft',
                'priority' => $sourceTemplate->priority ?? 0,
                'created_by' => auth()->id(),
            ]);

            $newTemplate->update([
                'shortcode' => $this->generateShortcode((int) $newTemplate->id),
            ]);

            if ($sourceTemplate->layout) {
                $newTemplate->layout()->create([
                    'layout_json' => $sourceTemplate->layout->layout_json ?? [
                        'sections' => [],
                    ],
                ]);
            }

            foreach ($sourceTemplate->conditions as $condition) {
                $newTemplate->conditions()->create([
                    'show_type' => $condition->show_type,
                    'source_type' => $condition->source_type,

                    'post_type_id' => $condition->post_type_id,
                    'post_type_slug' => $condition->post_type_slug,

                    'taxonomy_id' => $condition->taxonomy_id,
                    'taxonomy_slug' => $condition->taxonomy_slug,

                    'taxonomy_term_ids' => $condition->taxonomy_term_ids,
                    'relation' => $condition->relation,
                    'condition_value' => $condition->condition_value,
                ]);
            }

            return $newTemplate->fresh(['layout', 'conditions', 'postType']);
        });
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