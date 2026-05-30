<?php

namespace App\Services;

use App\Models\ApiClient;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ApiClientService
{
    /**
     * Fetch active client by client_id (with cache)
     */
    public function getActiveClientById(string $clientId): ?ApiClient
    {
        return Cache::remember("api_client_{$clientId}", 300, function () use ($clientId) {
            return ApiClient::where('client_id', $clientId)
                ->where('status', '1')
                ->first();
        });
    }

    /**
     * Get allowed domains from client (cached)
     */
    public function getAllowedDomainsCached(ApiClient $client): array
    {
        return Cache::remember("api_client_allowed_domains_{$client->client_id}", 300, function () use ($client) {
            $allowedDomains = $client->allowed_domain;

            if (is_string($allowedDomains)) {
                $decoded = json_decode($allowedDomains, true);
                $allowedDomains = is_array($decoded) ? $decoded : [$allowedDomains];
            }

            if (!is_array($allowedDomains)) $allowedDomains = [];

            return array_values(array_map(fn($d) => rtrim(trim((string)$d), '/'), $allowedDomains));
        });
    }

    /**
     * Update the last used origin for a client
     */
    public function updateLastUsedOrigin(ApiClient $client, string $origin)
    {
        $client->update(['used_by_origin' => $origin]);
        Cache::forget("api_client_{$client->client_id}");
        Cache::forget("api_client_allowed_domains_{$client->client_id}");
    }

    /**
     * Update last used timestamp
     */
    public function updateLastUsedAt(ApiClient $client)
    {
        $client->update(['last_used_at' => Carbon::now()]);
        Cache::forget("api_client_{$client->client_id}");
    }

    /**
     * Invalidate cache manually (for create/update/delete)
     */
    public function invalidateCache(ApiClient|string $client)
    {
        $clientId = $client instanceof ApiClient ? $client->client_id : $client;

        Cache::forget("api_client_{$clientId}");
        Cache::forget("api_client_allowed_domains_{$clientId}");
    }
}
