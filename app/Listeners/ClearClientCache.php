<?php

namespace App\Listeners;

use App\Events\ApplicationPasswordCreated;
use App\Events\ApplicationPasswordRevoked;
use Illuminate\Support\Facades\Cache;

class ClearClientCache
{
    public function handle(ApplicationPasswordCreated|ApplicationPasswordRevoked $event): void
    {
        Cache::forget('api-client:' . $event->apiClient->id);
        Cache::forget('api-client-slug:' . $event->apiClient->slug);
        Cache::forget('application-password:' . $event->applicationPassword->id);
    }
}