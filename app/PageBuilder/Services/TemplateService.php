<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use App\Models\PostType;
use App\Models\Template;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TemplateService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Template::with(['conditions', 'layout'])
            ->latest();

        if (! empty($filters['template_type'])) {
            $query->where('template_type', $filters['template_type']);
        }

        if (! empty($filters['post_type_id'])) {
            $query->where('post_type_id', $filters['post_type_id']);
        }

        if (! empty($filters['post_type_slug'])) {
            $query->where('post_type_slug', $filters['post_type_slug']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('template_name', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%')
                    ->orWhere('shortcode', 'like', '%' . $search . '%')
                    ->orWhere('post_type_slug', 'like', '%' . $search . '%');
            });
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function create(array $payload): Template
    {
        return DB::transaction(function () use ($payload) {
            $postType = $this->resolvePostType(
                $payload['template_type'],
                $payload['post_type_id'] ?? null
            );

            $template = Template::create([
                'template_type' => $payload['template_type'],
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'template_name' => $payload['template_name'],
                'slug' => $this->generateUniqueSlug($payload['template_name']),
                'created_by' => auth()->id(),
                'status' => 'draft',
                'priority' => 0,
            ]);

            $template->update([
                'shortcode' => $this->generateShortcode((int) $template->id),
            ]);

            $template->layout()->create([
                'layout_json' => $this->defaultLayout(),
            ]);

            return $template->fresh(['conditions', 'layout', 'postType']);
        });
    }

    public function find(int $id): ?Template
    {
        return Template::with(['conditions', 'layout', 'postType'])->find($id);
    }

    public function update(Template $template, array $payload): Template
    {
        return DB::transaction(function () use ($template, $payload) {
            $postType = $this->resolvePostType(
                $payload['template_type'],
                $payload['post_type_id'] ?? null
            );

            $updateData = [
                'template_type' => $payload['template_type'],
                'template_name' => $payload['template_name'],
                'post_type_id' => $postType?->id,
                'post_type_slug' => $postType?->slug,
                'status' => $payload['status'] ?? $template->status,
                'priority' => $payload['priority'] ?? $template->priority,
            ];

            if (! empty($payload['regenerate_slug'])) {
                $updateData['slug'] = $this->generateUniqueSlug(
                    $payload['template_name'],
                    (int) $template->id
                );
            }

            if (empty($template->shortcode)) {
                $updateData['shortcode'] = $this->generateShortcode((int) $template->id);
            }

            $template->update($updateData);

            if (! $template->layout) {
                $template->layout()->create([
                    'layout_json' => $this->defaultLayout(),
                ]);
            }

            return $template->fresh(['conditions', 'layout', 'postType']);
        });
    }

    public function updateStatus(Template $template, string $status): Template
    {
        $template->update([
            'status' => $status,
        ]);

        return $template->fresh(['conditions', 'layout', 'postType']);
    }

    public function delete(Template $template): void
    {
        DB::transaction(function () use ($template) {
            $template->conditions()->delete();

            if ($template->layout) {
                $template->layout()->delete();
            }

            $template->delete();
        });
    }

    public function options(): array
    {
        return [
            'template_types' => [
                [
                    'label' => 'Single Post',
                    'value' => 'single_post',
                ],
                [
                    'label' => 'Page',
                    'value' => 'page',
                ],
                [
                    'label' => 'Section',
                    'value' => 'section',
                ],
            ],
            'post_types' => $this->postTypesForDropdown(),
        ];
    }

    public function shortcodes(): Collection
    {
        return Template::query()
            ->select([
                'id',
                'template_type',
                'post_type_id',
                'post_type_slug',
                'template_name',
                'slug',
                'shortcode',
                'status',
                'priority',
            ])
            ->latest()
            ->get();
    }

    public function defaultLayout(): array
    {
        return [
            'sections' => [],
        ];
    }

    private function resolvePostType(string $templateType, mixed $postTypeId = null): ?PostType
    {
        if ($templateType !== 'single_post') {
            return null;
        }

        if (empty($postTypeId)) {
            throw new InvalidArgumentException('Post type is required for single post template.');
        }

        $postType = PostType::query()
            ->where('id', $postTypeId)
            ->where('status', true)
            ->first();

        if (! $postType) {
            throw new InvalidArgumentException('Selected post type is inactive or not found.');
        }

        return $postType;
    }

    private function generateUniqueSlug(string $templateName, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($templateName);

        if ($baseSlug === '') {
            $baseSlug = 'template';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Template::query()
                ->where('slug', $slug)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateShortcode(int $templateId): string
    {
        return '[template id="' . $templateId . '"]';
    }

    private function postTypesForDropdown(): array
    {
        return PostType::query()
            ->where('status', true)
            ->orderBy('name')
            ->get()
            ->map(function (PostType $postType) {
                return [
                    'label' => $postType->name,
                    'value' => $postType->id,
                    'slug' => $postType->slug,
                ];
            })
            ->values()
            ->all();
    }
}