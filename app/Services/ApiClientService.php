<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Repositories\ApiClientRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiClientService
{
    private const ALLOWED_TYPES = [
        'admin',
        'business',
        'website',
        'mobile',
        'mobile-app',
        'server',
        'custom',
    ];

    public function __construct(
        private readonly ApiClientRepository $apiClientRepository,
        private readonly ApiClientOriginService $apiClientOriginService
    ) {}

    public function paginate(int $perPage = 20)
    {
        return $this->apiClientRepository->paginate($perPage);
    }

    public function create(array $data): ApiClient
    {
        return DB::transaction(function () use ($data) {
            $payload = $this->prepareCreatePayload($data);

            $client = $this->apiClientRepository
                ->create($payload)
                ->loadCount('applicationPasswords');

            $this->apiClientOriginService->clearCache();

            return $client;
        });
    }

    public function update(ApiClient $apiClient, array $data): ApiClient
    {
        return DB::transaction(function () use ($apiClient, $data) {
            $payload = $this->prepareUpdatePayload($apiClient, $data);

            $client = $this->apiClientRepository
                ->update($apiClient, $payload)
                ->loadCount('applicationPasswords');

            $this->apiClientOriginService->clearCache();

            return $client;
        });
    }

    public function delete(ApiClient $apiClient): void
    {
        DB::transaction(function () use ($apiClient) {
            $this->apiClientRepository->delete($apiClient);

            $this->apiClientOriginService->clearCache();
        });
    }

    private function prepareCreatePayload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        $slug = $data['slug'] ?? Str::slug($name);

        $payload = [
            'name' => $name,
            'slug' => $this->uniqueSlug($slug ?: $name ?: 'api-client'),
            'type' => $this->normalizeType($data['type'] ?? 'custom'),

            'status' => array_key_exists('status', $data)
                ? $this->toBooleanInt($data['status'])
                : 1,

            'allowed_origins' => $this->normalizeAllowedOrigins($data['allowed_origins'] ?? []),

            'permissions' => $this->normalizePermissions($data['permissions'] ?? []),

            'rate_limit_per_minute' => $this->normalizeRateLimit(
                $data['rate_limit_per_minute'] ?? config('api_security.default_rate_limit_per_minute', 300)
            ),

            'requires_signature' => array_key_exists('requires_signature', $data)
                ? $this->toBooleanInt($data['requires_signature'])
                : 0,

            'description' => $this->cleanNullableString($data['description'] ?? null),
        ];

        return $this->addLegacyColumns($payload, true);
    }

    private function prepareUpdatePayload(ApiClient $apiClient, array $data): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('slug', $data)) {
            $slug = $this->cleanSlug($data['slug']);

            if ($slug !== null) {
                $payload['slug'] = $this->uniqueSlug($slug, $apiClient->id);
            }
        }

        if (! array_key_exists('slug', $data) && array_key_exists('name', $data)) {
            $payload['slug'] = $this->uniqueSlug(
                Str::slug((string) $data['name']),
                $apiClient->id
            );
        }

        if (array_key_exists('type', $data)) {
            $payload['type'] = $this->normalizeType($data['type']);
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $this->toBooleanInt($data['status']);
        }

        if (array_key_exists('allowed_origins', $data)) {
            $payload['allowed_origins'] = $this->normalizeAllowedOrigins($data['allowed_origins'] ?? []);
        }

        if (array_key_exists('permissions', $data)) {
            $payload['permissions'] = $this->normalizePermissions($data['permissions'] ?? []);
        }

        if (array_key_exists('rate_limit_per_minute', $data)) {
            $payload['rate_limit_per_minute'] = $this->normalizeRateLimit($data['rate_limit_per_minute']);
        }

        if (array_key_exists('requires_signature', $data)) {
            $payload['requires_signature'] = $this->toBooleanInt($data['requires_signature']);
        }

        if (array_key_exists('description', $data)) {
            $payload['description'] = $this->cleanNullableString($data['description']);
        }

        return $this->addLegacyColumns($payload, false);
    }

    private function normalizeAllowedOrigins($origins): array
    {
        if (is_string($origins)) {
            $decoded = json_decode($origins, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $origins = $decoded;
            } else {
                $origins = explode(',', $origins);
            }
        }

        if (! is_array($origins)) {
            return [];
        }

        return collect($origins)
            ->map(fn ($origin) => $this->normalizeAllowedOriginPattern($origin))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeAllowedOriginPattern(?string $origin): ?string
    {
        $origin = strtolower(trim((string) $origin));

        if ($origin === '' || $origin === '*') {
            return null;
        }

        $origin = rtrim($origin, '/');

        if (! preg_match('#^(https?)://([^/\s:]+|\*\.[^/\s:]+)(?::(\d+|\*))?$#i', $origin)) {
            return null;
        }

        return $origin;
    }

    private function normalizePermissions($permissions): array
    {
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $permissions = $decoded;
            } else {
                $permissions = explode(',', $permissions);
            }
        }

        if (! is_array($permissions)) {
            return [];
        }

        return collect($permissions)
            ->map(fn ($permission) => trim((string) $permission))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeRateLimit(mixed $rateLimit): int
    {
        $rateLimit = (int) $rateLimit;

        if ($rateLimit <= 0) {
            return 300;
        }

        return min($rateLimit, 10000);
    }

    private function addLegacyColumns(array $payload, bool $isCreate): array
    {
        if (array_key_exists('name', $payload) && Schema::hasColumn('api_clients', 'client_name')) {
            $payload['client_name'] = $payload['name'];
        }

        if (array_key_exists('type', $payload) && Schema::hasColumn('api_clients', 'app_type')) {
            $payload['app_type'] = $payload['type'];
        }

        if (array_key_exists('allowed_origins', $payload) && Schema::hasColumn('api_clients', 'allowed_domain')) {
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

        return in_array(strtolower(trim((string) $value)), [
            '1',
            'true',
            'yes',
            'on',
            'active',
            'enabled',
        ], true) ? 1 : 0;
    }

    private function normalizeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        if ($type === 'mobile_app') {
            $type = 'mobile-app';
        }

        if ($type === '') {
            return 'custom';
        }

        return in_array($type, self::ALLOWED_TYPES, true)
            ? $type
            : 'custom';
    }

    private function uniqueSlug(?string $slug, ?int $ignoreId = null): string
    {
        $slug = Str::slug((string) $slug);

        if ($slug === '') {
            $slug = 'api-client';
        }

        $originalSlug = $slug;
        $counter = 1;

        while ($this->apiClientRepository->existsBySlug($slug, $ignoreId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function cleanSlug(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Str::slug($value);
    }

    private function cleanNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}