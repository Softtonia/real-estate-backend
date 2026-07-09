<?php

namespace App\Http\Middleware;

use App\Models\ApplicationPassword;
use App\Services\ApiAbuseProtectionService;
use App\Services\ApiClientOriginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiClient
{
    public function __construct(
        private readonly ApiAbuseProtectionService $apiAbuseProtectionService,
        private readonly ApiClientOriginService $originService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->originService->resolveRequestOrigin($request);

        $plainToken = $this->extractApplicationPassword($request);

        if (! $plainToken) {
            return $this->deny($request, 'missing_application_password', 403);
        }

        $applicationPassword = ApplicationPassword::query()
            ->with('apiClient')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $applicationPassword || ! $applicationPassword->isValid()) {
            return $this->deny(
                $request,
                'invalid_or_expired_application_password',
                403,
                $applicationPassword?->api_client_id,
                $plainToken
            );
        }

        $client = $applicationPassword->apiClient;

        if (! $client) {
            return $this->deny($request, 'api_client_not_found', 403, null, $plainToken);
        }

        if (! $client->isActive()) {
            return $this->deny($request, 'inactive_api_client', 403, $client->id, $plainToken);
        }

        $requestType = strtolower(trim((string) $request->header('X-App-Type')));
        $clientType = strtolower(trim((string) $client->type));

        if ($requestType === '') {
            return $this->deny(
                $request,
                'application_type_missing',
                403,
                $client->id,
                $plainToken
            );
        }

        if ($requestType !== $clientType) {
            return $this->deny(
                $request,
                'application_type_not_matched',
                403,
                $client->id,
                $plainToken,
                [
                    'received_type' => $requestType,
                    'client_type' => $clientType,
                ]
            );
        }

        if ($origin === '') {
            return $this->deny(
                $request,
                'origin_missing',
                403,
                $client->id,
                $plainToken
            );
        }

        if (! $this->originService->originAllowedForClient($client, $origin)) {
            return $this->deny(
                $request,
                'origin_not_allowed',
                403,
                $client->id,
                $plainToken,
                [
                    'received_origin' => $origin,
                    'allowed_origins' => $client->allowed_origins ?? [],
                ]
            );
        }

        $request->attributes->set('api_client', $client);
        $request->attributes->set('application_password', $applicationPassword);
        $request->attributes->set('application_password_plain_token', $plainToken);

        $this->updateLastUsed($request, $client, $applicationPassword);

        return $next($request);
    }

    private function extractApplicationPassword(Request $request): ?string
    {
        $token = trim((string) $request->header('X-Application-Password'));

        return $token !== '' ? $token : null;
    }

    private function updateLastUsed(Request $request, $client, ApplicationPassword $applicationPassword): void
    {
        $cacheKey = 'application-password-last-used:' . $applicationPassword->id;

        if (! Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return;
        }

        $applicationPassword->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'last_user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ])->saveQuietly();

        if (Schema::hasColumn('api_clients', 'last_used_at')) {
            $client->forceFill([
                'last_used_at' => now(),
            ])->saveQuietly();
        }
    }

    private function deny(
        Request $request,
        string $reason,
        int $status = 403,
        ?int $clientId = null,
        ?string $plainToken = null,
        array $debug = []
    ): Response {
        try {
            $this->apiAbuseProtectionService->logFailure(
                $request,
                $reason,
                $clientId,
                $plainToken
            );
        } catch (\Throwable) {
            //
        }

        $payload = [
            'success' => false,
            'message' => 'No access to API. Please contact administrator.',
        ];

        if (app()->environment('local') || $request->header('X-Debug-API-Client') === '1') {
            $payload['reason'] = $reason;
            $payload['debug'] = $debug;
        }

        return response()->json($payload, $status);
    }
}