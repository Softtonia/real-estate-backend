<?php

namespace App\Services;

use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ApiClientOriginService
{
    private const CACHE_KEY = 'api_client_allowed_origins';

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function originExistsInAnyActiveClient(?string $origin): bool
    {
        $origin = $this->normalizeRequestOrigin($origin);

        if ($origin === '') {
            return false;
        }

        foreach ($this->getAllActiveAllowedOrigins() as $allowedOrigin) {
            if ($this->originMatches($origin, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    public function originAllowedForClient($client, ?string $origin): bool
    {
        $origin = $this->normalizeRequestOrigin($origin);

        if ($origin === '') {
            return false;
        }

        foreach ($this->extractAllowedOrigins($client->allowed_origins ?? []) as $allowedOrigin) {
            if ($this->originMatches($origin, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    public function resolveRequestOrigin(Request $request): string
    {
        $origin = $this->firstHeaderValue($request->headers->get('Origin'));

        if ($origin !== '' && strtolower($origin) !== 'null') {
            return $this->normalizeRequestOrigin($origin);
        }

        $appOrigin = $this->firstHeaderValue($request->headers->get('X-App-Origin'));

        if ($appOrigin !== '' && strtolower($appOrigin) !== 'null') {
            return $this->normalizeRequestOrigin($appOrigin);
        }

        $referer = $this->firstHeaderValue($request->headers->get('Referer'));

        if ($referer !== '') {
            return $this->normalizeRequestOrigin($referer);
        }

        return '';
    }

    public function normalizeRequestOrigin(?string $origin): string
    {
        $origin = trim((string) $origin);

        if ($origin === '' || strtolower($origin) === 'null') {
            return '';
        }

        $origin = rtrim($origin, '/');

        $parts = parse_url($origin);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function getAllActiveAllowedOrigins(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function () {
                if (! Schema::hasTable('api_clients')) {
                    return [];
                }

                return ApiClient::query()
                    ->where('status', 1)
                    ->get(['id', 'allowed_origins'])
                    ->flatMap(function (ApiClient $client) {
                        return $this->extractAllowedOrigins($client->allowed_origins ?? []);
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ApiClientOriginService error: ' . $e->getMessage());
            return [];
        }
    }

    private function extractAllowedOrigins($allowedOrigins): array
    {
        if (is_string($allowedOrigins)) {
            $decoded = json_decode($allowedOrigins, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $allowedOrigins = $decoded;
            } else {
                $allowedOrigins = explode(',', $allowedOrigins);
            }
        }

        if (! is_array($allowedOrigins)) {
            return [];
        }

        return collect($allowedOrigins)
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

    private function originMatches(string $requestOrigin, string $allowedOrigin): bool
    {
        if ($allowedOrigin === '') {
            return false;
        }

        if ($requestOrigin === $allowedOrigin) {
            return true;
        }

        if (! str_contains($allowedOrigin, '*')) {
            return false;
        }

        $pattern = preg_quote($allowedOrigin, '#');

        $pattern = str_replace('\*', '[^/:]+', $pattern);

        return (bool) preg_match('#^' . $pattern . '$#i', $requestOrigin);
    }

    private function firstHeaderValue($value): string
    {
        $value = trim((string) $value);

        if (str_contains($value, ',')) {
            return trim(explode(',', $value)[0]);
        }

        return $value;
    }
}