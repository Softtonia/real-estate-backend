<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_request_start_time', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startTime = $request->attributes->get('api_request_start_time', microtime(true));

        $client = $request->attributes->get('api_client');
        $password = $request->attributes->get('application_password');

        ApiRequestLog::create([
            'api_client_id' => $client?->id,
            'application_password_id' => $password?->id,
            'method' => $request->method(),
            'path' => $request->getRequestUri(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
            'created_at' => now(),
        ]);
    }
}