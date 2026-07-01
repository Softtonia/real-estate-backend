<?php

namespace App\Listeners;

use App\Events\ApiClientAccessGranted;
use App\Models\ApiRequestLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LogApiClientAccessGranted
{
    public function handle(ApiClientAccessGranted $event): void
    {
        if (!Schema::hasTable('api_request_logs')) {
            return;
        }

        ApiRequestLog::create([
            'api_client_id' => $event->apiClient->id,
            'application_password_id' => $event->applicationPassword->id,
            'method' => $event->request->method(),
            'path' => $event->request->path(),
            'ip_address' => $event->request->ip(),
            'user_agent' => Str::limit((string) $event->request->userAgent(), 255, ''),
            'origin' => $event->request->header('Origin') ?: $event->request->header('X-App-Origin'),
            'status_code' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}