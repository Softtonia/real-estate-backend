<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TemplateListService
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Template::query()
            ->with(['layout', 'conditions', 'postType'])
            ->withCount(['conditions']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('template_name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('shortcode', 'like', "%{$search}%")
                    ->orWhere('template_type', 'like', "%{$search}%")
                    ->orWhere('post_type_slug', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['template_type'])) {
            $query->where('template_type', $filters['template_type']);
        }

        if (! empty($filters['post_type_id'])) {
            $query->where('post_type_id', (int) $filters['post_type_id']);
        }

        if (! empty($filters['post_type_slug'])) {
            $query->where('post_type_slug', $filters['post_type_slug']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $sortBy = $filters['sort_by'] ?? 'updated_at';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc'));

        $allowedSorts = [
            'id',
            'template_name',
            'template_type',
            'status',
            'priority',
            'created_at',
            'updated_at',
        ];

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'updated_at';
        }

        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $query->orderBy($sortBy, $sortDir);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }

    public function stats(array $filters = []): array
    {
        return [
            'total' => Template::count(),
            'active' => Template::whereIn('status', ['active', 'published', 1, true])->count(),
            'draft' => Template::whereIn('status', ['draft', 'inactive', 0, false])->count(),
            'by_template_type' => Template::query()
                ->selectRaw('template_type, COUNT(*) as total')
                ->groupBy('template_type')
                ->pluck('total', 'template_type'),
            'by_status' => Template::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ];
    }

    public function transformPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->getCollection()->transform(function (Template $template) {
            return $this->transformTemplate($template);
        });

        return $paginator;
    }

    public function transformTemplate(Template $template): array
    {
        $layoutJson = $template->layout?->layout_json ?? [
            'sections' => [],
        ];

        return [
            'id' => $template->id,
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

            'has_layout' => ! empty($template->layout),
            'widget_count' => $this->countWidgets($layoutJson),
            'section_count' => count($layoutJson['sections'] ?? []),
            'condition_count' => $template->conditions_count
                ?? $template->conditions?->count()
                ?? 0,

            'created_by' => $template->created_by ?? null,
            'created_at' => optional($template->created_at)->toDateTimeString(),
            'updated_at' => optional($template->updated_at)->toDateTimeString(),
            'deleted_at' => optional($template->deleted_at)->toDateTimeString(),
        ];
    }

    protected function countWidgets(mixed $node): int
    {
        if (! is_array($node)) {
            return 0;
        }

        $count = 0;

        if (
            isset($node['type'])
            || isset($node['widget'])
            || isset($node['widget_type'])
            || isset($node['component_key'])
        ) {
            $count++;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $count += $this->countWidgets($value);
            }
        }

        return $count;
    }
}