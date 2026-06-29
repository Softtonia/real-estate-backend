<?php

namespace App\Listeners;

use App\Events\ApplicationPasswordCreated;
use App\Events\ApplicationPasswordRevoked;
use App\Models\ApiSecurityAuditLog;
use Illuminate\Support\Str;

class WriteApplicationPasswordAuditLog
{
    public function handle(object $event): void
    {
        $eventName = match (true) {
            $event instanceof ApplicationPasswordCreated => 'application_password.created',
            $event instanceof ApplicationPasswordRevoked => 'application_password.revoked',
            default => 'application_password.unknown',
        };

        $request = request();

        ApiSecurityAuditLog::create([
            'api_client_id' => $event->apiClient->id,
            'application_password_id' => $event->applicationPassword->id,
            'event' => $eventName,
            'actor_user_id' => auth()->id(),
            'actor_type' => auth()->check() ? get_class(auth()->user()) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 1000, ''),
            'metadata' => $event->metadata,
            'created_at' => now(),
        ]);
    }
}