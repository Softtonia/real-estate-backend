<?php

namespace App\Services;

use App\Models\ApiAuthFailure;
use App\Models\BlockedApiIp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiAbuseProtectionService
{
    public function logFailure(
        Request $request,
        string $reason,
        ?int $apiClientId = null,
        ?string $plainToken = null
    ): void {
        ApiAuthFailure::create([
            'api_client_id' => $apiClientId,
            'reason' => $reason,
            'token_prefix' => $plainToken ? substr($plainToken, 0, 18) : null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'method' => $request->method(),
            'path' => $request->getRequestUri(),
            'origin' => $request->headers->get('origin'),
            'created_at' => now(),
        ]);

        $this->blockIpIfThresholdExceeded($request->ip());
    }

    public function activeBlockForIp(?string $ip): ?BlockedApiIp
    {
        if (!$ip) {
            return null;
        }

        return BlockedApiIp::query()
            ->where('ip_address', $ip)
            ->where(function ($query) {
                $query->where('permanent', true)
                    ->orWhere('blocked_until', '>', now());
            })
            ->first();
    }

    private function blockIpIfThresholdExceeded(?string $ip): void
    {
        if (!$ip) {
            return;
        }

        $windowMinutes = (int) config('api_security.failed_auth_window_minutes', 10);
        $threshold = (int) config('api_security.failed_auth_threshold', 20);
        $blockMinutes = (int) config('api_security.failed_auth_block_minutes', 60);

        $failureCount = ApiAuthFailure::query()
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($failureCount < $threshold) {
            return;
        }

        BlockedApiIp::updateOrCreate(
            [
                'ip_address' => $ip,
            ],
            [
                'reason' => "Too many failed API authentication attempts in {$windowMinutes} minutes.",
                'permanent' => false,
                'blocked_until' => now()->addMinutes($blockMinutes),
            ]
        );
    }
}