<?php

namespace App\Repositories;

use App\Models\ApiClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiClientRepository
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ApiClient::query()
            ->withCount('applicationPasswords')
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $data): ApiClient
    {
        return ApiClient::create($data);
    }

    public function update(ApiClient $client, array $data): ApiClient
    {
        $client->update($data);

        return $client->fresh();
    }

    public function delete(ApiClient $client): void
    {
        $client->delete();
    }

    public function existsBySlug(string $slug, ?int $ignoreId = null): bool
    {
        return ApiClient::query()
            ->where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->exists();
    }

    public function existsByLegacyClientId(string $clientId): bool
    {
        return ApiClient::query()
            ->where('client_id', $clientId)
            ->exists();
    }

    public function existsByLegacyClientSecret(string $clientSecret): bool
    {
        return ApiClient::query()
            ->where('client_secret', $clientSecret)
            ->exists();
    }
}