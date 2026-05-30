<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\ApiClientService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ValidateApiClient
{
    protected $apiClientService;

    public function __construct(ApiClientService $service)
    {
        $this->apiClientService = $service;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $debugMode = $request->header('X-Debug-API-Client') === '1';

        // Read headers
        $clientId     = $this->firstHeaderValue($request->header('X-Client-ID'));
        $clientSecret = preg_replace('/\s+/', '', $this->firstHeaderValue($request->header('X-Client-Secret')));
        $appType      = $this->firstHeaderValue($request->header('X-App-Type'));
        $origin       = rtrim($this->firstHeaderValue($request->headers->get('Origin')), '/');

        // Required fields
        $errors = [];
        if ($clientId === '') $errors['client_id'][] = 'The client id field is required.';
        if ($clientSecret === '') $errors['client_secret'][] = 'The client secret field is required.';
        if ($appType === '') $errors['app_type'][] = 'The app type field is required.';
        if ($origin === '' || strtolower($origin) === 'null') $errors['origin'][] = 'The origin field is required.';
        if (!empty($errors)) return $this->errorResponse('Validation failed.', $errors, 422, $debugMode);

        // Length validation
        if (strlen($clientId) !== 15) $errors['client_id'][] = 'The client id must be 15 characters.';
        if (strlen($clientSecret) !== 15) $errors['client_secret'][] = 'The client secret must be 15 characters.';
        if (!empty($errors)) return $this->errorResponse('Validation failed.', $errors, 422, $debugMode);

        // Fetch client using service (cached)
        $client = $this->apiClientService->getActiveClientById($clientId);
        if (!$client) return $this->errorResponse('Validation failed.', ['client_id'=>['Invalid client id']], 422, $debugMode);

        // Validate secret and app type
        if (trim($client->client_secret) !== $clientSecret) {
            return $this->errorResponse('Validation failed.', ['client_secret'=>['Invalid client secret']], 422, $debugMode);
        }
        if (trim($client->app_type) !== $appType) {
            return $this->errorResponse('Validation failed.', ['app_type'=>['Invalid app type']], 422, $debugMode);
        }

        // Allowed domains check with cache
        $allowedDomains = $this->apiClientService->getAllowedDomainsCached($client);
        if (!in_array($origin, $allowedDomains, true)) {
            return $this->errorResponse('Unauthorized origin', ['origin'=>['Origin not allowed']], 403, $debugMode);
        }

        // Lock first origin safely with transaction
        if (empty($client->used_by_origin)) {
            DB::transaction(function () use ($client, $origin) {
                $client->refresh();
                if (empty($client->used_by_origin)) {
                    $this->apiClientService->updateLastUsedOrigin($client, $origin);
                }
            });
        }

        // Block if locked to another origin
        if (rtrim($client->used_by_origin, '/') !== $origin) {
            return $this->errorResponse(
                'Client credentials locked to another origin.',
                ['origin'=>["Locked to {$client->used_by_origin}"]],
                409,
                $debugMode
            );
        }

        // Update last used timestamp
        $this->apiClientService->updateLastUsedAt($client);

        return $next($request);
    }

    private function firstHeaderValue($value): string
    {
        $value = trim((string) $value);
        if (str_contains($value, ',')) return trim(explode(',', $value)[0]);
        return $value;
    }

    private function errorResponse(string $message, array $errors, int $status, bool $debugMode = false, array $debug = [])
    {
        $response = ['message'=>$message,'errors'=>$errors];
        if ($debugMode) $response['debug'] = $debug;
        return response()->json($response, $status);
    }
}