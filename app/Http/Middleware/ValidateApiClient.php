<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class ValidateApiClient
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */


   

    public function handle(Request $request, Closure $next): Response
    {
        $clientId     = $request->header('X-Client-ID');
        $clientSecret = $request->header('X-Client-Secret');
        $origin       = $request->headers->get('Origin');
        $appType      = $request->header('X-App-Type'); // e.g. website, admin, business

        if (!$clientId || !$clientSecret || !$origin || !$appType) {
            return response()->json([
                'message'    => 'Missing required headers: X-Client-ID, X-Client-Secret, X-App-Type, or Origin.'
            ], 400);
        }

        // Get client directly without scope
        $client = ApiClient::where('client_id', $clientId)
            ->Active()
            ->where('client_secret', $clientSecret)
            ->where('app_type', $appType)
            ->first();

        if (!$client) {
            return response()->json(['message' => 'Unauthorized client credentials'], 401);
        }


        if (is_null($client->used_by_origin)) {
            $updated = ApiClient::where('id', $client->id)
                ->whereNull('used_by_origin')
                ->update([
                    'used_by_origin' => $origin,
                    'last_used_at'   => Carbon::now(),
                    'updated_at'     => Carbon::now(),
                ]);

            if ($updated) {
                $client->refresh(); // reload with new values
            }
        }



        // If origin mismatch → block
        if ($client->used_by_origin !== $origin) {
            return response()->json([
                'message' => 'Client credentials already locked to another origin: ' . $client->used_by_origin
            ], 409);
        }

        // Allowed domains check
        $allowedDomains = $client->allowed_domain ?? [];
        if (!in_array($origin, $allowedDomains)) {
            return response()->json(['message' => 'Unauthorized origin'], 403);
        }



        return $next($request);
    }
}
