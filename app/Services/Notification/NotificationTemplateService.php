<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationBatch;
use App\Models\Notification\NotificationTemplate;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationTemplateService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = NotificationTemplate::query()
            ->with([
                'createdBy:id,first_name,last_name,email',
                'updatedBy:id,first_name,last_name,email',
            ])
            ->select([
                'id',
                'template_key',
                'title',
                'body',
                'image_url',
                'data',
                'channel',
                'status',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ])
            ->latest('id');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('template_key', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', strtolower((string) $filters['channel']));
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function create(array $data, ?User $admin = null): NotificationTemplate
    {
        return DB::transaction(function () use ($data, $admin) {
            $template = NotificationTemplate::query()->create([
                'template_key' => $data['template_key'],
                'title' => $data['title'],
                'body' => $data['body'],
                'image_url' => $data['image_url'] ?? null,
                'data' => $data['data'] ?? null,
                'channel' => $data['channel'] ?? NotificationTemplate::CHANNEL_PUSH_DATABASE,
                'status' => array_key_exists('status', $data)
                    ? (bool) $data['status']
                    : true,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]);

            return $template->load([
                'createdBy:id,first_name,last_name,email',
                'updatedBy:id,first_name,last_name,email',
            ]);
        });
    }

    public function update(NotificationTemplate $template, array $data, ?User $admin = null): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $data, $admin) {
            $payload = [];

            foreach ([
                'template_key',
                'title',
                'body',
                'image_url',
                'data',
                'channel',
                'status',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }

            if (array_key_exists('status', $payload)) {
                $payload['status'] = (bool) $payload['status'];
            }

            $payload['updated_by'] = $admin?->id;

            $template->forceFill($payload)->save();

            return $template->refresh()->load([
                'createdBy:id,first_name,last_name,email',
                'updatedBy:id,first_name,last_name,email',
            ]);
        });
    }

    public function delete(NotificationTemplate $template): void
    {
        $processingBatchExists = NotificationBatch::query()
            ->where('template_id', $template->id)
            ->whereIn('status', [
                NotificationBatch::STATUS_PENDING,
                NotificationBatch::STATUS_PROCESSING,
                NotificationBatch::STATUS_SCHEDULED,
            ])
            ->exists();

        if ($processingBatchExists) {
            throw ValidationException::withMessages([
                'template' => ['This template is used in a pending or processing notification batch.'],
            ]);
        }

        $template->delete();
    }

    public function options(): array
    {
        return [
            'channels' => [
                [
                    'label' => 'Push',
                    'value' => NotificationTemplate::CHANNEL_PUSH,
                ],
                [
                    'label' => 'Database',
                    'value' => NotificationTemplate::CHANNEL_DATABASE,
                ],
                [
                    'label' => 'Push + Database',
                    'value' => NotificationTemplate::CHANNEL_PUSH_DATABASE,
                ],
            ],
        ];
    }
}