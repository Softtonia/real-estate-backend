<?php

declare(strict_types=1);

namespace App\PageBuilder\Services;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class TemplateRenderCacheService
{
    public function rememberResolve(array $payload, Closure $callback): array
    {
        if (! $this->enabled()) {
            return [
                'hit' => false,
                'enabled' => false,
                'requested_store' => $this->store(),
                'fallback_store' => $this->fallbackStore(),
                'data' => $callback(),
            ];
        }

        $key = $this->resolveCacheKey($payload);

        if ($this->safeHas($key)) {
            return [
                'hit' => true,
                'enabled' => true,
                'requested_store' => $this->store(),
                'fallback_store' => $this->fallbackStore(),
                'data' => $this->safeGet($key),
            ];
        }

        /*
         * Important:
         * If cache fails, only cache will fail.
         * Template resolve callback will still run.
         */
        $data = $callback();

        $this->safePut($key, $data, $this->ttl());

        $this->indexTemplateCacheKey($data, $key);

        return [
            'hit' => false,
            'enabled' => true,
            'requested_store' => $this->store(),
            'fallback_store' => $this->fallbackStore(),
            'data' => $data,
        ];
    }

    public function clearTemplate(int $templateId): array
    {
        $indexKey = $this->templateIndexKey($templateId);

        $keys = $this->safeGet($indexKey, []);

        if (! is_array($keys)) {
            $keys = [];
        }

        $deleted = [];

        foreach ($keys as $key) {
            $this->safeForget((string) $key);
            $deleted[] = $key;
        }

        $this->safeForget($indexKey);

        return [
            'template_id' => $templateId,
            'requested_store' => $this->store(),
            'fallback_store' => $this->fallbackStore(),
            'deleted_count' => count($deleted),
        ];
    }

    public function clearAll(): array
    {
        $versionKey = $this->globalVersionKey();

        $current = (int) $this->safeGet($versionKey, 1);
        $newVersion = $current + 1;

        $stored = $this->safeForever($versionKey, $newVersion);

        return [
            'requested_store' => $this->store(),
            'fallback_store' => $this->fallbackStore(),
            'old_version' => $current,
            'new_version' => $newVersion,
            'stored' => $stored,
        ];
    }

    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'requested_store' => $this->store(),
            'fallback_store' => $this->fallbackStore(),
            'ttl' => $this->ttl(),
            'prefix' => $this->prefix(),
            'version' => $this->globalVersion(),
            'failsafe' => true,
        ];
    }

    protected function resolveCacheKey(array $payload): string
    {
        $payload = $this->normalizePayload($payload);

        return implode(':', [
            $this->prefix(),
            'resolve',
            'v' . $this->globalVersion(),
            sha1(json_encode($payload)),
        ]);
    }

    protected function indexTemplateCacheKey(mixed $data, string $cacheKey): void
    {
        $templateId = $this->extractTemplateId($data);

        if (! $templateId) {
            return;
        }

        $indexKey = $this->templateIndexKey($templateId);

        $keys = $this->safeGet($indexKey, []);

        if (! is_array($keys)) {
            $keys = [];
        }

        $keys[] = $cacheKey;
        $keys = array_values(array_unique($keys));

        $this->safePut($indexKey, $keys, $this->ttl());
    }

    protected function extractTemplateId(mixed $data): ?int
    {
        if (! is_array($data)) {
            return null;
        }

        $id = data_get($data, 'template.id')
            ?? data_get($data, 'template_id')
            ?? data_get($data, 'data.template.id')
            ?? data_get($data, 'data.template_id');

        return $id ? (int) $id : null;
    }

    protected function normalizePayload(array $payload): array
    {
        unset(
            $payload['_token'],
            $payload['timestamp'],
            $payload['cache_bust'],
            $payload['_']
        );

        $this->recursiveSort($payload);

        return $payload;
    }

    protected function recursiveSort(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveSort($value);
            }
        }

        ksort($array);
    }

    protected function templateIndexKey(int $templateId): string
    {
        return implode(':', [
            $this->prefix(),
            'template_index',
            $templateId,
        ]);
    }

    protected function globalVersionKey(): string
    {
        return $this->prefix() . ':global_version';
    }

    protected function globalVersion(): int
    {
        $key = $this->globalVersionKey();

        $version = $this->safeGet($key, null);

        if (! $version) {
            $this->safeForever($key, 1);
            return 1;
        }

        return (int) $version;
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Cache Methods
    |--------------------------------------------------------------------------
    |
    | These methods guarantee that Redis/cache errors never break
    | template create/update/save/conditions/resolve flow.
    |
    */

    protected function safeHas(string $key): bool
    {
        return (bool) $this->safeOperation(
            fn (Repository $cache) => $cache->has($key),
            false
        );
    }

    protected function safeGet(string $key, mixed $default = null): mixed
    {
        return $this->safeOperation(
            fn (Repository $cache) => $cache->get($key, $default),
            $default
        );
    }

    protected function safePut(string $key, mixed $value, int $ttl): bool
    {
        return (bool) $this->safeOperation(
            fn (Repository $cache) => $cache->put($key, $value, $ttl),
            false
        );
    }

    protected function safeForever(string $key, mixed $value): bool
    {
        return (bool) $this->safeOperation(
            fn (Repository $cache) => $cache->forever($key, $value),
            false
        );
    }

    protected function safeForget(string $key): bool
    {
        return (bool) $this->safeOperation(
            fn (Repository $cache) => $cache->forget($key),
            false
        );
    }


    protected function safeOperation(Closure $operation, mixed $default = null): mixed
    {
        foreach ($this->cacheStores() as $store) {
            try {
                return $operation(Cache::store($store));
            } catch (Throwable $e) {
                $this->handleCacheError($e, $store);
                continue;
            }
        }

        return $default;
    }

    protected function cacheStores(): array
    {
        $stores = [];

        $mainStore = $this->store();
        $fallbackStore = $this->fallbackStore();

        if ($mainStore !== '') {
            $stores[] = $mainStore;
        }

        if ($fallbackStore !== '' && $fallbackStore !== $mainStore) {
            $stores[] = $fallbackStore;
        }

        /*
         * Final emergency fallback.
         */
        if (! in_array('file', $stores, true)) {
            $stores[] = 'file';
        }

        return array_values(array_unique($stores));
    }

    protected function handleCacheError(Throwable $e, string $store): void
    {
        /*
         * Do not throw.
         * Do not expose Redis error to API response.
         */
        if ((bool) config('page_builder_cache.log_errors', false)) {
            Log::warning('PageBuilder cache store failed.', [
                'store' => $store,
                'exception' => get_class($e),
            ]);
        }
    }

    protected function enabled(): bool
    {
        return (bool) config('page_builder_cache.enabled', true);
    }

    protected function store(): string
    {
        return (string) config('page_builder_cache.store', 'redis');
    }

    protected function fallbackStore(): string
    {
        return (string) config('page_builder_cache.fallback_store', 'file');
    }

    protected function ttl(): int
    {
        return (int) config('page_builder_cache.ttl', 3600);
    }

    protected function prefix(): string
    {
        return (string) config('page_builder_cache.prefix', 'page_builder');
    }
}