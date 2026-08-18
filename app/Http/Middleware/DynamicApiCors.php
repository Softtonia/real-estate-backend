<?php

namespace App\Http\Middleware;

use App\Services\ApiClientOriginService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DynamicApiCors
{
    public function __construct(
        private readonly ApiClientOriginService $originService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $origin = $this->originService->resolveRequestOrigin($request);

            if ($origin === '') {
                return $next($request);
            }

            $originAllowed = $this->originService->originExistsInAnyActiveClient($origin);

            if (! $originAllowed) {
                if ($request->isMethod('OPTIONS')) {
                    return response('', 403);
                }

                return $next($request);
            }

            if ($request->isMethod('OPTIONS')) {
                return $this->addCorsHeaders(response('', 204), $request, $origin);
            }

            $response = $next($request);

            return $this->addCorsHeaders($response, $request, $origin);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DynamicApiCors exception: ' . $e->getMessage());

            if ($request->isMethod('OPTIONS')) {
                return response('', 200);
            }

            return $next($request);
        }
    }

    private function addCorsHeaders(Response $response, Request $request, string $origin): Response
    {
        $requestedHeaders = $request->headers->get('Access-Control-Request-Headers');

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

        $response->headers->set(
            'Access-Control-Allow-Headers',
            $requestedHeaders ?: implode(', ', [
                'Accept',
                'Content-Type',
                'Authorization',
                'Origin',
                'Referer',
                'X-Requested-With',
                'X-CSRF-TOKEN',
                'X-XSRF-TOKEN',
                'X-Application-Password',
                'X-App-Type',
                'X-App-Origin',
                'X-Debug-API-Client',
                'X-Timestamp',
                'X-Nonce',
                'X-Signature',
            ])
        );

        $response->headers->set('Access-Control-Expose-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}