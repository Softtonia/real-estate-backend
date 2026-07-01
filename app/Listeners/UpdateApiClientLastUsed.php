<?php

namespace App\Listeners;

use App\Events\ApiClientAccessGranted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UpdateApiClientLastUsed
{
    public function handle(ApiClientAccessGranted $event): void
    {
        $cacheKey = 'application-password-last-used:' . $event->applicationPassword->id;

        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return;
        }

        if (Schema::hasColumn('application_passwords', 'last_used_at')) {
            $event->applicationPassword->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => $event->request->ip(),
                'last_user_agent' => Str::limit((string) $event->request->userAgent(), 255, ''),
            ])->saveQuietly();
        }

        if (Schema::hasColumn('api_clients', 'last_used_at')) {
            $event->apiClient->forceFill([
                'last_used_at' => now(),
            ])->saveQuietly();
        }
    }
}