<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAllowedOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('api_client');

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'API client context missing.',
            ], 401);
        }

        $origin = $request->headers->get('origin');

        if (!$origin && $request->headers->get('referer')) {
            $origin = $request->headers->get('referer');
        }

        // Server-to-server requests may not send Origin.
        if (!$origin) {
            return $next($request);
        }

        $normalizedOrigin = $this->normalizeOrigin($origin);

        $allowedOrigins = $client->allowed_origins ?? [];

        if (app()->environment('local')) {
            $allowedOrigins = array_merge($allowedOrigins, config('api_security.allowed_dev_origins', []));
        }

        $allowedOrigins = array_map(
            fn ($item) => $this->normalizeOrigin($item),
            $allowedOrigins
        );

        if (!in_array($normalizedOrigin, $allowedOrigins, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Origin is not allowed for this API client.',
                'origin' => $normalizedOrigin,
            ], 403);
        }

        return $next($request);
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($origin, '/');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}