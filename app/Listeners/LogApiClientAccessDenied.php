<?php

namespace App\Listeners;

use App\Events\ApiClientAccessDenied;
use App\Models\ApiAuthFailure;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LogApiClientAccessDenied
{
    public function handle(ApiClientAccessDenied $event): void
    {
        if (!Schema::hasTable('api_auth_failures')) {
            return;
        }

        ApiAuthFailure::create([
            'api_client_id' => $event->apiClientId,
            'reason' => $event->reason,
            'ip_address' => $event->request->ip(),
            'user_agent' => Str::limit((string) $event->request->userAgent(), 255, ''),
            'origin' => $event->request->header('Origin') ?: $event->request->header('X-App-Origin'),
            'path' => $event->request->path(),
            'method' => $event->request->method(),
            'token_prefix' => $event->plainToken ? substr($event->plainToken, 0, 12) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}