<?php

namespace App\Http\Middleware;

use App\Models\ApplicationPassword;
use App\Services\ApiAbuseProtectionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyApplicationPassword
{
    public function __construct(
        private readonly ApiAbuseProtectionService $apiAbuseProtectionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $this->extractApplicationPassword($request);

        if (!$plainToken) {
            $this->apiAbuseProtectionService->logFailure(
                $request,
                'missing_application_password'
            );

            return response()->json([
                'success' => false,
                'message' => 'Missing application password.',
            ], 401);
        }

        $password = ApplicationPassword::query()
            ->with('apiClient')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (!$password || !$password->isValid()) {
            $this->apiAbuseProtectionService->logFailure(
                $request,
                'invalid_or_expired_application_password',
                $password?->api_client_id,
                $plainToken
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired application password.',
            ], 401);
        }

        $client = $password->apiClient;

        if (!$client || !$client->isActive()) {
            $this->apiAbuseProtectionService->logFailure(
                $request,
                'inactive_api_client',
                $client?->id,
                $plainToken
            );

            return response()->json([
                'success' => false,
                'message' => 'API client is inactive.',
            ], 403);
        }

        $request->attributes->set('api_client', $client);
        $request->attributes->set('application_password', $password);
        $request->attributes->set('application_password_plain_token', $plainToken);

        $this->updateLastUsed($request, $password);

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (!$header || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
    private function extractApplicationPassword(Request $request): ?string
    {
        $token = trim((string) $request->header('X-Application-Password'));

        if ($token !== '') {
            return $token;
        }

        $header = $request->header('Authorization');

        if ($header && preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
    private function updateLastUsed(Request $request, ApplicationPassword $password): void
    {
        $cacheKey = 'application-password-last-used:' . $password->id;

        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return;
        }

        $password->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'last_user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ])->saveQuietly();
    }
}
