<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TemplateTrashService
{
    public function trash(Template $template): Template
    {
        return DB::transaction(function () use ($template) {
            if ($template->trashed()) {
                return $this->freshTrashed($template->id);
            }

            $template->update([
                'status' => 'trash',
            ]);

            $template->delete();

            return $this->freshTrashed($template->id);
        });
    }

    public function restore(Template $template, string $status = 'draft'): Template
    {
        return DB::transaction(function () use ($template, $status) {
            if ($template->trashed()) {
                $template->restore();
            }

            $template->update([
                'status' => $status,
            ]);

            return $this->freshTemplate($template->id);
        });
    }

    public function forceDelete(Template $template): bool
    {
        return DB::transaction(function () use ($template) {
            /*
             * Delete child records safely before permanent delete.
             */
            if (method_exists($template, 'layout')) {
                $template->layout()->delete();
            }

            if (method_exists($template, 'conditions')) {
                $template->conditions()->delete();
            }

            if (method_exists($template, 'revisions')) {
                $template->revisions()->delete();
            }

            return (bool) $template->forceDelete();
        });
    }

    public function bulkTrash(array $ids): array
    {
        $templates = Template::withTrashed()
            ->whereIn('id', $ids)
            ->get();

        $processed = [];

        foreach ($templates as $template) {
            $this->trash($template);
            $processed[] = $template->id;
        }

        return $this->bulkResponse($ids, $processed);
    }

    public function bulkRestore(array $ids, string $status = 'draft'): array
    {
        $templates = Template::withTrashed()
            ->whereIn('id', $ids)
            ->get();

        $processed = [];

        foreach ($templates as $template) {
            $this->restore($template, $status);
            $processed[] = $template->id;
        }

        return $this->bulkResponse($ids, $processed);
    }

    public function bulkForceDelete(array $ids): array
    {
        $templates = Template::withTrashed()
            ->whereIn('id', $ids)
            ->get();

        $processed = [];

        foreach ($templates as $template) {
            $this->forceDelete($template);
            $processed[] = $template->id;
        }

        return $this->bulkResponse($ids, $processed);
    }

    public function emptyTrash(?int $olderThanDays = null, int $limit = 500): array
    {
        $query = Template::onlyTrashed();

        if ($olderThanDays !== null && $olderThanDays > 0) {
            $query->where('deleted_at', '<=', now()->subDays($olderThanDays));
        }

        $templates = $query
            ->limit($limit)
            ->get();

        $processed = [];

        foreach ($templates as $template) {
            $this->forceDelete($template);
            $processed[] = $template->id;
        }

        return [
            'processed_count' => count($processed),
            'processed_ids' => $processed,
            'limit' => $limit,
        ];
    }

    private function freshTemplate(int $id): Template
    {
        return Template::with(['layout', 'conditions', 'postType'])
            ->findOrFail($id);
    }

    private function freshTrashed(int $id): Template
    {
        return Template::withTrashed()
            ->with(['layout', 'conditions', 'postType'])
            ->findOrFail($id);
    }

    private function bulkResponse(array $requestedIds, array $processedIds): array
    {
        $requestedIds = collect($requestedIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $processedIds = collect($processedIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'requested_count' => count($requestedIds),
            'processed_count' => count($processedIds),
            'processed_ids' => $processedIds,
            'missing_ids' => array_values(array_diff($requestedIds, $processedIds)),
        ];
    }
}