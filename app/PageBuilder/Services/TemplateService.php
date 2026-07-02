<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TemplateListService
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Template::query()
            ->with(['layout', 'conditions', 'postType'])
            ->withCount(['conditions']);

        $this->applyTrashFilter($query, $filters);
        $this->applySearch($query, $filters);
        $this->applyBasicFilters($query, $filters);
        $this->applyDateFilters($query, $filters);
        $this->applyLayoutFilters($query, $filters);
        $this->applySorting($query, $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }

    public function stats(array $filters = []): array
    {
        $baseQuery = Template::query();

        $this->applyTrashFilter($baseQuery, $filters);

        return [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)
                ->whereIn('status', ['active', 'published', 1, true])
                ->count(),
            'draft' => (clone $baseQuery)
                ->whereIn('status', ['draft', 'inactive', 0, false])
                ->count(),
            'trash' => method_exists(Template::class, 'bootSoftDeletes')
                ? Template::onlyTrashed()->count()
                : 0,
            'by_template_type' => (clone $baseQuery)
                ->selectRaw('template_type, COUNT(*) as total')
                ->groupBy('template_type')
                ->pluck('total', 'template_type'),
            'by_status' => (clone $baseQuery)
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

    protected function applyTrashFilter(Builder $query, array $filters): void
    {
        $trash = $filters['trash'] ?? 'without';

        if ($trash === 'with') {
            $query->withTrashed();
        }

        if ($trash === 'only') {
            $query->onlyTrashed();
        }
    }

    protected function applySearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $q) use ($search) {
            $q->where('template_name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('shortcode', 'like', "%{$search}%")
                ->orWhere('template_type', 'like', "%{$search}%")
                ->orWhere('post_type_slug', 'like', "%{$search}%");
        });
    }

    protected function applyBasicFilters(Builder $query, array $filters): void
    {
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
            $status = $filters['status'];

            if (is_array($status)) {
                $query->whereIn('status', $status);
            } else {
                $query->where('status', $status);
            }
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }
    }

    protected function applyDateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['updated_from'])) {
            $query->whereDate('updated_at', '>=', $filters['updated_from']);
        }

        if (! empty($filters['updated_to'])) {
            $query->whereDate('updated_at', '<=', $filters['updated_to']);
        }
    }

    protected function applyLayoutFilters(Builder $query, array $filters): void
    {
        if (array_key_exists('has_layout', $filters)) {
            $hasLayout = filter_var($filters['has_layout'], FILTER_VALIDATE_BOOLEAN);

            if ($hasLayout) {
                $query->whereHas('layout');
            } else {
                $query->whereDoesntHave('layout');
            }
        }

        if (array_key_exists('has_conditions', $filters)) {
            $hasConditions = filter_var($filters['has_conditions'], FILTER_VALIDATE_BOOLEAN);

            if ($hasConditions) {
                $query->whereHas('conditions');
            } else {
                $query->whereDoesntHave('conditions');
            }
        }
    }

    protected function applySorting(Builder $query, array $filters): void
    {
        $allowedSorts = [
            'id',
            'template_name',
            'template_type',
            'status',
            'priority',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        $sortBy = $filters['sort_by'] ?? 'updated_at';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc'));

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'updated_at';
        }

        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        if ($sortBy === 'deleted_at' && ! Schema::hasColumn('templates', 'deleted_at')) {
            $sortBy = 'updated_at';
        }

        $query->orderBy($sortBy, $sortDir);
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