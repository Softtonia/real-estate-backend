<?php

namespace App\Repositories;

use App\Models\ApiClient;

class ApiClientRepository
{
    public function paginate(int $perPage = 20)
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

    public function update(ApiClient $apiClient, array $data): ApiClient
    {
        $apiClient->update($data);

        return $apiClient->fresh()->loadCount('applicationPasswords');
    }

    public function delete(ApiClient $apiClient): void
    {
        $apiClient->delete();
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
}