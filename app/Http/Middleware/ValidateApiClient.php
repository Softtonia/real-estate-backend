<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiClient
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next): Response
    // {
    //     $clientId = $request->header('X-Client-ID');
    //     $clientSecret = $request->header('X-Client-Secret');
    //     $origin = $request->headers->get('Origin'); // Domain

    //     \Log::info("Client ID: $clientId");
    //     \Log::info("Client Secret: $clientSecret");
    //     \Log::info("Origin: $origin");

    //     if (!$clientId || !$clientSecret || !$origin) {
    //         return response()->json(['message' => 'Missing credentials'], 401);
    //     }

    //     $client = ApiClient::where('client_id', $clientId)
    //         ->where('client_secret', $clientSecret)
    //         ->where('allowed_domain', $origin)
    //         ->first();

    //         \Log::info("Client: $client");
    //     if (!$client) {
    //         return response()->json(['message' => 'Unauthorized client'], 401);
    //     }

    //     return $next($request);
    // }

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->header('X-Client-ID');
        $clientSecret = $request->header('X-Client-Secret');
        $origin = $request->headers->get('Origin'); // e.g., http://localhost:5173/



        if (!$clientId || !$clientSecret || !$origin) {
            return response()->json(['message' => 'Missing credentials'], 401);
        }

        $client = ApiClient::where('client_id', $clientId)
            ->where('client_secret', $clientSecret)->where('status', '1')
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Unauthorized client credentials'], 401);
        }

        // allowed_domain is stored as JSON array in DB and casted to array in model
        $allowedDomains = $client->allowed_domain ?? [];

        if (!in_array($origin, $allowedDomains)) {
            return response()->json(['message' => 'Unauthorized origin'], 401);
        }

        return $next($request);
    }
}
