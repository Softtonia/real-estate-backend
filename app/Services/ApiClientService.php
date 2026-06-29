<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Repositories\ApiClientRepository;
use Illuminate\Support\Str;

class ApiClientService
{
    public function __construct(
        private readonly ApiClientRepository $apiClientRepository
    ) {}

    public function paginate(int $perPage = 20)
    {
        return $this->apiClientRepository->paginate($perPage);
    }

    public function create(array $data): ApiClient
    {
        $data = $this->prepareCreatePayload($data);

        return $this->apiClientRepository->create($data);
    }

    public function update(ApiClient $client, array $data): ApiClient
    {
        $data = $this->prepareUpdatePayload($client, $data);

        return $this->apiClientRepository->update($client, $data);
    }

    public function delete(ApiClient $client): void
    {
        $this->apiClientRepository->delete($client);
    }

    private function prepareCreatePayload(array $data): array
    {
        $name = $data['name'];
        $type = $data['type'];

        $allowedOrigins = $data['allowed_origins'] ?? [];
        $permissions = $data['permissions'] ?? ['*'];

        $data['slug'] = $data['slug'] ?? $this->generateUniqueSlug($name);

        $data['status'] = array_key_exists('status', $data)
            ? ((bool) $data['status'] ? '1' : '0')
            : '1';

        $data['allowed_origins'] = $allowedOrigins;
        $data['permissions'] = $permissions;

        $data['rate_limit_per_minute'] = $data['rate_limit_per_minute']
            ?? config('api_security.default_rate_limit_per_minute', 300);

        $data['requires_signature'] = (bool) ($data['requires_signature'] ?? false);

        /*
         * Legacy columns required by your existing api_clients table.
         * These are not used for new secure authentication.
         */
        $data['client_name'] = $name;
        $data['client_id'] = $this->generateLegacyClientId();
        $data['client_secret'] = $this->generateLegacyClientSecret();
        $data['app_type'] = $this->mapLegacyAppType($type);
        $data['allowed_domain'] = json_encode($allowedOrigins);

        return $data;
    }

    private function prepareUpdatePayload(ApiClient $client, array $data): array
    {
        if (isset($data['name'])) {
            $data['client_name'] = $data['name'];
        }

        if (isset($data['type'])) {
            $data['app_type'] = $this->mapLegacyAppType($data['type']);
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = (bool) $data['status'] ? '1' : '0';
        }

        if (array_key_exists('allowed_origins', $data)) {
            $data['allowed_origins'] = $data['allowed_origins'] ?? [];
            $data['allowed_domain'] = json_encode($data['allowed_origins']);
        }

        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = $data['permissions'] ?? ['*'];
        }

        if (array_key_exists('requires_signature', $data)) {
            $data['requires_signature'] = (bool) $data['requires_signature'];
        }

        if (
            isset($data['name'])
            && empty($data['slug'])
            && empty($client->slug)
        ) {
            $data['slug'] = $this->generateUniqueSlug($data['name'], $client->id);
        }

        return $data;
    }

    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'api-client';
        $slug = $base;
        $counter = 1;

        while ($this->apiClientRepository->existsBySlug($slug, $ignoreId)) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    private function generateLegacyClientId(): string
    {
        do {
            $value = strtoupper(substr(bin2hex(random_bytes(16)), 0, 30));
        } while ($this->apiClientRepository->existsByLegacyClientId($value));

        return $value;
    }

    private function generateLegacyClientSecret(): string
    {
        do {
            $value = 'legacy_' . bin2hex(random_bytes(32));
        } while ($this->apiClientRepository->existsByLegacyClientSecret($value));

        return $value;
    }

    private function mapLegacyAppType(string $type): string
    {
        return match ($type) {
            'admin' => 'admin',
            'business' => 'business',
            'website' => 'website',
            'mobile-app' => 'mobile-app',
            default => 'custom',
        };
    }
}