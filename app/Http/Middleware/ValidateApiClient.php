<?php

namespace App\Http\Middleware;

use App\Models\ApplicationPassword;
use App\Services\ApiAbuseProtectionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiClient
{
    public function __construct(
        private readonly ApiAbuseProtectionService $apiAbuseProtectionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->resolveRequestOrigin($request);

        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse([], 204, $origin);
        }

        $plainToken = $this->extractApplicationPassword($request);

        if (!$plainToken) {
            return $this->deny(
                $request,
                'missing_application_password',
                401
            );
        }

        $applicationPassword = ApplicationPassword::query()
            ->with('apiClient')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (!$applicationPassword || !$applicationPassword->isValid()) {
            return $this->deny(
                $request,
                'invalid_or_expired_application_password',
                401,
                $applicationPassword?->api_client_id,
                $plainToken
            );
        }

        $client = $applicationPassword->apiClient;

        if (!$client) {
            return $this->deny(
                $request,
                'api_client_not_found',
                403,
                null,
                $plainToken
            );
        }

        if (!$client->isActive()) {
            return $this->deny(
                $request,
                'inactive_api_client',
                403,
                $client->id,
                $plainToken
            );
        }

        $requestType = $this->normalizeType(
            $this->firstHeaderValue($request->header('X-App-Type'))
        );

        if ($requestType === '') {
            return $this->deny(
                $request,
                'application_type_missing',
                403,
                $client->id,
                $plainToken,
                [
                    'received_type' => $request->header('X-App-Type'),
                    'client_type' => $client->type,
                ]
            );
        }

        $clientType = $this->normalizeType(
            (string) ($client->type ?: $client->getAttribute('app_type'))
        );

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

        if ($origin === '' || strtolower($origin) === 'null') {
            return $this->deny(
                $request,
                'origin_missing',
                403,
                $client->id,
                $plainToken
            );
        }

        if (!$this->originIsAllowed($client, $origin)) {
            return $this->deny(
                $request,
                'origin_not_allowed',
                403,
                $client->id,
                $plainToken,
                [
                    'received_origin' => $origin,
                    'allowed_origins' => $this->getAllowedOrigins($client),
                ]
            );
        }

        $request->attributes->set('api_client', $client);
        $request->attributes->set('application_password', $applicationPassword);
        $request->attributes->set('application_password_plain_token', $plainToken);

        $this->updateLastUsed($request, $client, $applicationPassword);

        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
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
    private function originIsAllowed($client, string $origin): bool
    {
        $allowedOrigins = $this->getAllowedOrigins($client);

        if (app()->environment('local')) {
            $devOrigins = config('api_security.allowed_dev_origins', []);

            if (is_string($devOrigins)) {
                $devOrigins = explode(',', $devOrigins);
            }

            $allowedOrigins = array_merge($allowedOrigins, $devOrigins);
        }

        $allowedOrigins = array_map(function ($allowedOrigin) {
            return $this->normalizeOrigin($allowedOrigin);
        }, $allowedOrigins);

        $allowedOrigins = array_values(array_filter(array_unique($allowedOrigins)));

        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        return in_array($origin, $allowedOrigins, true);
    }

    private function getAllowedOrigins($client): array
    {
        $allowedOrigins = $client->allowed_origins ?? [];

        if (is_string($allowedOrigins)) {
            $decoded = json_decode($allowedOrigins, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $allowedOrigins = $decoded;
            } else {
                $allowedOrigins = explode(',', $allowedOrigins);
            }
        }

        if (!is_array($allowedOrigins)) {
            $allowedOrigins = [];
        }

        if (empty($allowedOrigins) && Schema::hasColumn('api_clients', 'allowed_domain')) {
            $legacyAllowedDomain = $client->getAttribute('allowed_domain');

            if (!empty($legacyAllowedDomain)) {
                $allowedOrigins = explode(',', $legacyAllowedDomain);
            }
        }

        return array_values(array_filter(array_map('trim', $allowedOrigins)));
    }

    private function resolveRequestOrigin(Request $request): string
    {
        $origin = $this->firstHeaderValue($request->headers->get('Origin'));

        if (!empty($origin) && strtolower($origin) !== 'null') {
            return $this->normalizeOrigin($origin);
        }

        $appOrigin = $this->firstHeaderValue($request->headers->get('X-App-Origin'));

        if (!empty($appOrigin) && strtolower($appOrigin) !== 'null') {
            return $this->normalizeOrigin($appOrigin);
        }

        $referer = $this->firstHeaderValue($request->headers->get('Referer'));

        if (!empty($referer)) {
            return $this->normalizeOrigin($referer);
        }

        return '';
    }

    private function normalizeOrigin(?string $origin): string
    {
        $origin = trim((string) $origin);

        if ($origin === '') {
            return '';
        }

        $origin = rtrim($origin, '/');

        $parts = parse_url($origin);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return strtolower($origin);
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function normalizeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        $allowedTypes = [
            'admin',
            'business',
            'website',
            'custom',
        ];

        return in_array($type, $allowedTypes, true) ? $type : '';
    }

    private function firstHeaderValue($value): string
    {
        $value = trim((string) $value);

        if (str_contains($value, ',')) {
            return trim(explode(',', $value)[0]);
        }

        return $value;
    }

    private function updateLastUsed(Request $request, $client, ApplicationPassword $applicationPassword): void
    {
        $cacheKey = 'application-password-last-used:' . $applicationPassword->id;

        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
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

        return $this->corsResponse(
            $payload,
            $status,
            $this->resolveRequestOrigin($request)
        );
    }

    private function corsResponse(array $data, int $status, string $origin): Response
    {
        $response = response()->json($data, $status);

        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Vary', 'Origin');

        $response->headers->set(
            'Access-Control-Allow-Methods',
            'GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );

        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Accept, Content-Type, Authorization, Origin, Referer, X-Requested-With, X-App-Type, X-App-Origin, X-Debug-API-Client, X-Timestamp, X-Nonce, X-Signature'
        );

        $response->headers->set(
            'Access-Control-Expose-Headers',
            'Content-Type'
        );

        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        $response->headers->set(
            'Access-Control-Allow-Credentials',
            'true'
        );

        return $response;
    }
}
