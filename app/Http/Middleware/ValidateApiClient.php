<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\ApiClientService;
use Illuminate\Support\Facades\DB;

class ValidateApiClient
{
    protected ApiClientService $apiClientService;

    public function __construct(ApiClientService $service)
    {
        $this->apiClientService = $service;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $debugMode = $request->header('X-Debug-API-Client') === '1';

        $clientId     = $this->firstHeaderValue($request->header('X-Client-ID'));
        $clientSecret = preg_replace('/\s+/', '', $this->firstHeaderValue($request->header('X-Client-Secret')));
        $appType      = $this->firstHeaderValue($request->header('X-App-Type'));

        /*
        |--------------------------------------------------------------------------
        | Resolve Origin
        |--------------------------------------------------------------------------
        | First Origin header check karega.
        | Agar Origin missing hai to Referer se origin resolve karega.
        */
        $origin = $this->resolveRequestOrigin($request);

        /*
        |--------------------------------------------------------------------------
        | Handle Browser Preflight Request
        |--------------------------------------------------------------------------
        */
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse([], 204, $origin);
        }

        /*
        |--------------------------------------------------------------------------
        | Required Header Validation
        |--------------------------------------------------------------------------
        */
        $errors = [];

        if ($clientId === '') {
            $errors['client_id'][] = 'The client id field is required.';
        }

        if ($clientSecret === '') {
            $errors['client_secret'][] = 'The client secret field is required.';
        }

        if ($appType === '') {
            $errors['app_type'][] = 'The app type field is required.';
        }

        if ($origin === '' || strtolower($origin) === 'null') {
            $errors['origin'][] = 'The origin field is required.';
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                'Validation failed.',
                $errors,
                422,
                $debugMode,
                [
                    'received_origin' => $origin,
                    'received_referer' => $request->headers->get('Referer'),
                    'received_client_id' => $clientId,
                    'received_app_type' => $appType,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Length Validation
        |--------------------------------------------------------------------------
        */
        if (strlen($clientId) !== 15) {
            $errors['client_id'][] = 'The client id must be 15 characters.';
        }

        if (strlen($clientSecret) !== 15) {
            $errors['client_secret'][] = 'The client secret must be 15 characters.';
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                'Validation failed.',
                $errors,
                422,
                $debugMode,
                [
                    'received_client_id_length' => strlen($clientId),
                    'received_client_secret_length' => strlen($clientSecret),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get Active API Client
        |--------------------------------------------------------------------------
        */
        $client = $this->apiClientService->getActiveClientById($clientId);

        if (!$client) {
            return $this->errorResponse(
                'Validation failed.',
                ['client_id' => ['Invalid client id']],
                422,
                $debugMode,
                [
                    'received_client_id' => $clientId,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Secret and App Type
        |--------------------------------------------------------------------------
        */
        if (!hash_equals(trim((string) $client->client_secret), $clientSecret)) {
            return $this->errorResponse(
                'Validation failed.',
                ['client_secret' => ['Invalid client secret']],
                422,
                $debugMode
            );
        }

        if (trim((string) $client->app_type) !== $appType) {
            return $this->errorResponse(
                'Validation failed.',
                ['app_type' => ['Invalid app type']],
                422,
                $debugMode,
                [
                    'db_app_type' => $client->app_type,
                    'received_app_type' => $appType,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Allowed Domain Check
        |--------------------------------------------------------------------------
        */
        $allowedDomains = $this->apiClientService->getAllowedDomainsCached($client);

        $allowedDomains = array_map(function ($domain) {
            return $this->normalizeOrigin($domain);
        }, $allowedDomains);

        if (!in_array($origin, $allowedDomains, true)) {
            return $this->errorResponse(
                'Unauthorized origin',
                ['origin' => ['Origin not allowed']],
                403,
                $debugMode,
                [
                    'received_origin' => $origin,
                    'received_referer' => $request->headers->get('Referer'),
                    'allowed_domains' => $allowedDomains,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Lock First Origin
        |--------------------------------------------------------------------------
        */
        if (empty($client->used_by_origin)) {
            DB::transaction(function () use ($client, $origin) {
                $client->refresh();

                if (empty($client->used_by_origin)) {
                    $this->apiClientService->updateLastUsedOrigin($client, $origin);
                }
            });

            $client->refresh();
        }

        /*
        |--------------------------------------------------------------------------
        | Block Different Origin
        |--------------------------------------------------------------------------
        */
        $lockedOrigin = $this->normalizeOrigin($client->used_by_origin);

        if ($lockedOrigin !== $origin) {
            return $this->errorResponse(
                'Client credentials locked to another origin.',
                ['origin' => ["Locked to {$client->used_by_origin}"]],
                409,
                $debugMode,
                [
                    'received_origin' => $origin,
                    'received_referer' => $request->headers->get('Referer'),
                    'locked_origin' => $lockedOrigin,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Last Used
        |--------------------------------------------------------------------------
        */
        $this->apiClientService->updateLastUsedAt($client);

        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
    }

    private function firstHeaderValue($value): string
    {
        $value = trim((string) $value);

        if (str_contains($value, ',')) {
            return trim(explode(',', $value)[0]);
        }

        return $value;
    }

    private function normalizeOrigin($origin): string
    {
        $origin = trim((string) $origin);

        if ($origin === '') {
            return '';
        }

        return strtolower(rtrim($origin, '/'));
    }

    private function resolveRequestOrigin(Request $request): string
    {
        /*
        |--------------------------------------------------------------------------
        | 1. First priority: Origin header
        |--------------------------------------------------------------------------
        */
        $origin = $this->firstHeaderValue($request->headers->get('Origin'));

        if (!empty($origin) && strtolower($origin) !== 'null') {
            return $this->normalizeOrigin($origin);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Fallback: Referer header
        |--------------------------------------------------------------------------
        | Example:
        | Referer: http://localhost:3000/
        | Resolved origin: http://localhost:3000
        */
        $referer = $this->firstHeaderValue($request->headers->get('Referer'));

        if (!empty($referer)) {
            $parts = parse_url($referer);

            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $resolvedOrigin = $parts['scheme'] . '://' . $parts['host'];

                if (!empty($parts['port'])) {
                    $resolvedOrigin .= ':' . $parts['port'];
                }

                return $this->normalizeOrigin($resolvedOrigin);
            }
        }

        return '';
    }

    private function errorResponse(
        string $message,
        array $errors,
        int $status,
        bool $debugMode = false,
        array $debug = []
    ): Response {
        $origin = $this->resolveRequestOrigin(request());

        $response = [
            'message' => $message,
            'errors' => $errors,
        ];

        if ($debugMode) {
            $response['debug'] = $debug;
        }

        return $this->corsResponse($response, $status, $origin);
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
            'Content-Type, Authorization, X-Requested-With, X-Client-ID, X-Client-Secret, X-App-Type, X-Debug-API-Client, X-Nextjs-Build-Key, X-Forwarded-For'
        );

        $response->headers->set(
            'Access-Control-Expose-Headers',
            'Content-Type'
        );

        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        /*
        |--------------------------------------------------------------------------
        | Required only if frontend axios uses withCredentials: true
        |--------------------------------------------------------------------------
        */
        $response->headers->set(
            'Access-Control-Allow-Credentials',
            'true'
        );

        return $response;
    }
}