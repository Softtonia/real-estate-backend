<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Repositories\ApiClientRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiClientService
{
    private const ALLOWED_TYPES = [
        'admin',
        'business',
        'website',
        'mobile-app',
        'custom',
    ];

    public function __construct(
        private readonly ApiClientRepository $apiClientRepository
    ) {}

    public function paginate(int $perPage = 20)
    {
        return $this->apiClientRepository->paginate($perPage);
    }

    public function create(array $data): ApiClient
    {
        $payload = $this->prepareCreatePayload($data);

        return $this->apiClientRepository
            ->create($payload)
            ->loadCount('applicationPasswords');
    }

    public function update(ApiClient $apiClient, array $data): ApiClient
    {
        $payload = $this->prepareUpdatePayload($apiClient, $data);

        return $this->apiClientRepository->update($apiClient, $payload);
    }

    public function delete(ApiClient $apiClient): void
    {
        $this->apiClientRepository->delete($apiClient);
    }

    private function prepareCreatePayload(array $data): array
    {
        $slug = $data['slug'] ?? Str::slug($data['name']);

        $payload = [
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($slug),
            'type' => $this->normalizeType($data['type']),
            'status' => array_key_exists('status', $data)
                ? $this->toBooleanInt($data['status'])
                : 1,
            'allowed_origins' => $data['allowed_origins'] ?? [],
            'permissions' => $data['permissions'] ?? [],
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? config('api_security.default_rate_limit_per_minute', 300),
            'requires_signature' => array_key_exists('requires_signature', $data)
                ? $this->toBooleanInt($data['requires_signature'])
                : 0,
            'description' => $data['description'] ?? null,
        ];

        return $this->addLegacyColumns($payload, true);
    }

    private function prepareUpdatePayload(ApiClient $apiClient, array $data): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }

        if (array_key_exists('slug', $data)) {
            $payload['slug'] = $this->uniqueSlug($data['slug'], $apiClient->id);
        }

        if (!array_key_exists('slug', $data) && array_key_exists('name', $data)) {
            $payload['slug'] = $this->uniqueSlug(Str::slug($data['name']), $apiClient->id);
        }

        if (array_key_exists('type', $data)) {
            $payload['type'] = $this->normalizeType($data['type']);
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $this->toBooleanInt($data['status']);
        }

        if (array_key_exists('allowed_origins', $data)) {
            $payload['allowed_origins'] = $data['allowed_origins'] ?? [];
        }

        if (array_key_exists('permissions', $data)) {
            $payload['permissions'] = $data['permissions'] ?? [];
        }

        if (array_key_exists('rate_limit_per_minute', $data)) {
            $payload['rate_limit_per_minute'] = $data['rate_limit_per_minute'];
        }

        if (array_key_exists('requires_signature', $data)) {
            $payload['requires_signature'] = $this->toBooleanInt($data['requires_signature']);
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $data['description'];
        }

        return $this->addLegacyColumns($payload, false);
    }

    private function addLegacyColumns(array $payload, bool $isCreate): array
    {
        if (isset($payload['name']) && Schema::hasColumn('api_clients', 'client_name')) {
            $payload['client_name'] = $payload['name'];
        }

        if (isset($payload['type']) && Schema::hasColumn('api_clients', 'app_type')) {
            $payload['app_type'] = $payload['type'];
        }

        if (isset($payload['allowed_origins']) && Schema::hasColumn('api_clients', 'allowed_domain')) {
            $payload['allowed_domain'] = implode(',', $payload['allowed_origins']);
        }

        if ($isCreate && Schema::hasColumn('api_clients', 'client_id')) {
            $payload['client_id'] = Str::upper(Str::random(16));
        }

        if ($isCreate && Schema::hasColumn('api_clients', 'client_secret')) {
            $payload['client_secret'] = Str::upper(Str::random(32));
        }

        if ($isCreate && Schema::hasColumn('api_clients', 'nextjs_internal_key')) {
            $payload['nextjs_internal_key'] = Str::random(48);
        }

        return $payload;
    }

    private function toBooleanInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value === 1 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true)
            ? 1
            : 0;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, self::ALLOWED_TYPES, true)
            ? $type
            : 'custom';
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $slug = Str::slug($slug);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->apiClientRepository->existsBySlug($slug, $ignoreId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}