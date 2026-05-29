<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class ValidateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
        |--------------------------------------------------------------------------
        | Debug Mode
        |--------------------------------------------------------------------------
        | Add this header in Postman only when you want debug:
        | X-Debug-API-Client: 1
        */

        $debugMode = $request->header('X-Debug-API-Client') === '1';

        /*
        |--------------------------------------------------------------------------
        | Read Raw Headers
        |--------------------------------------------------------------------------
        */

        $rawClientId     = $request->header('X-Client-ID');
        $rawClientSecret = $request->header('X-Client-Secret');
        $rawAppType      = $request->header('X-App-Type');
        $rawOrigin       = $request->headers->get('Origin');

        /*
        |--------------------------------------------------------------------------
        | Clean Headers
        |--------------------------------------------------------------------------
        | Laravel may merge duplicate headers like:
        | abc,abc
        |
        | So we take the first value.
        */

        $clientId     = $this->firstHeaderValue($rawClientId);
        $clientSecret = $this->firstHeaderValue($rawClientSecret);
        $appType      = $this->firstHeaderValue($rawAppType);
        $origin       = $this->firstHeaderValue($rawOrigin);

        /*
        |--------------------------------------------------------------------------
        | Remove Spaces From ID And Secret
        |--------------------------------------------------------------------------
        | Example:
        | OPU VNR3XN CXPDL becomes OPUVNR3XNCXPDL
        */

        $clientId     = preg_replace('/\s+/', '', $clientId);
        $clientSecret = preg_replace('/\s+/', '', $clientSecret);

        /*
        |--------------------------------------------------------------------------
        | Normalize Origin
        |--------------------------------------------------------------------------
        */

        $origin = rtrim($origin, '/');

        /*
        |--------------------------------------------------------------------------
        | Prepare Debug Data
        |--------------------------------------------------------------------------
        */

        $debug = [
            'raw_client_id'            => $rawClientId,
            'clean_client_id'          => $clientId,
            'clean_client_id_length'   => strlen($clientId),

            'raw_client_secret_length' => strlen((string) $rawClientSecret),
            'clean_secret_length'      => strlen($clientSecret),

            'raw_app_type'             => $rawAppType,
            'clean_app_type'           => $appType,

            'raw_origin'               => $rawOrigin,
            'clean_origin'             => $origin,
        ];

        /*
        |--------------------------------------------------------------------------
        | Required Header Validation
        |--------------------------------------------------------------------------
        */

        $errors = [];

        if ($clientId === '') {
            $errors['client_id'] = [
                'The client id field is required.'
            ];
        }

        if ($clientSecret === '') {
            $errors['client_secret'] = [
                'The client secret field is required.'
            ];
        }

        if ($appType === '') {
            $errors['app_type'] = [
                'The app type field is required.'
            ];
        }

        if ($origin === '' || strtolower($origin) === 'null') {
            $errors['origin'] = [
                'The origin field is required.'
            ];
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                'Validation failed.',
                $errors,
                422,
                $debugMode,
                $debug
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Length Validation
        |--------------------------------------------------------------------------
        */

        $errors = [];

        if (strlen($clientId) !== 15) {
            $errors['client_id'] = [
                'The client id field must be 15 characters.'
            ];
        }

        if (strlen($clientSecret) !== 15) {
            $errors['client_secret'] = [
                'The client secret field must be 15 characters.'
            ];
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                'Validation failed.',
                $errors,
                422,
                $debugMode,
                $debug
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find Active Client By Client ID
        |--------------------------------------------------------------------------
        */

        $client = ApiClient::where('client_id', $clientId)
            ->Active()
            ->first();

        if (!$client) {
            return $this->errorResponse(
                'Validation failed.',
                [
                    'client_id' => [
                        'The selected client id is invalid.'
                    ]
                ],
                422,
                $debugMode,
                $debug
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Client Secret And App Type
        |--------------------------------------------------------------------------
        */

        $dbClientSecret = preg_replace('/\s+/', '', trim((string) $client->client_secret));
        $dbAppType      = trim((string) $client->app_type);

        $debug['db_client_id']            = $client->client_id;
        $debug['db_secret_length']        = strlen($dbClientSecret);
        $debug['db_app_type']             = $dbAppType;
        $debug['db_used_by_origin']       = $client->used_by_origin;
        $debug['db_allowed_domain_raw']   = $client->allowed_domain;

        $errors = [];

        if ($dbClientSecret !== $clientSecret) {
            $errors['client_secret'] = [
                'The client secret is invalid.'
            ];
        }

        if ($dbAppType !== $appType) {
            $errors['app_type'] = [
                'The selected app type is invalid.'
            ];
        }

        if (!empty($errors)) {
            return $this->errorResponse(
                'Validation failed.',
                $errors,
                422,
                $debugMode,
                $debug
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Allowed Domains Check
        |--------------------------------------------------------------------------
        */

        $allowedDomains = $this->getAllowedDomains($client->allowed_domain);

        $debug['allowed_domains_clean'] = $allowedDomains;

        if (!in_array($origin, $allowedDomains, true)) {
            return $this->errorResponse(
                'Unauthorized origin',
                [
                    'origin' => [
                        'The origin is not allowed for this client.'
                    ]
                ],
                403,
                $debugMode,
                array_merge($debug, [
                    'origin'          => $origin,
                    'allowed_domains' => $allowedDomains,
                ])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Lock Client To First Used Origin
        |--------------------------------------------------------------------------
        */

        if (empty($client->used_by_origin)) {
            $client->update([
                'used_by_origin' => $origin,
                'last_used_at'   => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]);

            $client->refresh();
        }

        /*
        |--------------------------------------------------------------------------
        | Block If Client Is Already Locked To Another Origin
        |--------------------------------------------------------------------------
        */

        $usedByOrigin = rtrim(trim((string) $client->used_by_origin), '/');

        if ($usedByOrigin !== $origin) {
            return $this->errorResponse(
                'Client credentials already locked to another origin.',
                [
                    'origin' => [
                        'Client credentials already locked to another origin: ' . $client->used_by_origin
                    ]
                ],
                409,
                $debugMode,
                array_merge($debug, [
                    'current_origin' => $origin,
                    'locked_origin'  => $client->used_by_origin,
                ])
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Update Last Used Time
        |--------------------------------------------------------------------------
        */

        $client->update([
            'last_used_at' => Carbon::now(),
        ]);

        return $next($request);
    }

    private function firstHeaderValue($value): string
    {
        $value = trim((string) $value);

        if (str_contains($value, ',')) {
            $parts = explode(',', $value);
            return trim((string) $parts[0]);
        }

        return $value;
    }

    private function getAllowedDomains($allowedDomains): array
    {
        if (is_string($allowedDomains)) {
            $decoded = json_decode($allowedDomains, true);

            if (is_array($decoded)) {
                $allowedDomains = $decoded;
            } else {
                $allowedDomains = [$allowedDomains];
            }
        }

        if (!is_array($allowedDomains)) {
            $allowedDomains = [];
        }

        return array_values(array_filter(array_map(function ($domain) {
            return rtrim(trim((string) $domain), '/');
        }, $allowedDomains)));
    }

    private function errorResponse(
        string $message,
        array $errors,
        int $status,
        bool $debugMode = false,
        array $debug = []
    ) {
        $response = [
            'message' => $message,
            'errors'  => $errors,
        ];

        if ($debugMode) {
            $response['debug'] = $debug;
        }

        return response()->json($response, $status);
    }
}