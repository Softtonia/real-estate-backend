<?php

namespace App\Http\Middleware;

use App\Models\UserIpLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Stevebauman\Location\Facades\Location;

class LogAndBlockIpMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    // public function handle(Request $request, Closure $next): Response
    // {
    //     $ip = $request->ip();

    //     // Check if IP exists in DB
    //     $ipLog = UserIpLog::where('ip_address', $ip)->first();

    //     if ($ipLog && $ipLog->status === 'blocked') {
    //         return response()->json(['error' => 'Your IP is blocked.'], 403);
    //     }

    //     // Log if not exists
    //     if (!$ipLog) {
    //         $location = Location::get($ip);

    //         // Fallback for localhost
    //         if (!$location || in_array($ip, ['127.0.0.1', '::1'])) {
    //             $country = 'Localhost';
    //             $region = 'Local Network';
    //         } else {
    //             $country = $location->countryName;
    //             $region = $location->regionName;
    //         }
    //         UserIpLog::create([
    //             'ip_address' => $ip,
    //             'country' => $country,
    //             'region' => $region,
    //             'status' => 'active',
    //         ]);
    //     }

    //     return $next($request);
    // }

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        // Check if IP is blocked
        $ipLog = UserIpLog::where('ip_address', $ip)->first();
        if ($ipLog && $ipLog->status === 'blocked') {
            return response()->json(['error' => 'Your IP is blocked.'], 403);
        }

        // Log IP if not already logged
        if (!$ipLog) {
            $location = Location::get($ip);

            // Use defaults if location is unavailable (like on localhost)
            UserIpLog::create([
                'user_id'       => auth()->id(), // Optional: logged-in user ID
                'ip_address'    => $ip,
                'country'       => $location?->countryName ?? 'Localhost',
                'city'          => $location?->cityName ?? 'Unknown',
                'region'        => $location?->regionName ?? 'Local Network',
                'country_code'  => $location?->countryCode ?? null,
                'region_code'   => $location?->regionCode ?? null,
                'lat'           => $location?->latitude ?? null,
                'lon'           => $location?->longitude ?? null,
                'timezone'      => $location?->timezone ?? null,
                'isp'           => $location?->isp ?? null,
                'org'           => $location?->organization ?? null,
                'as'            => $location?->asn ?? null,
                'query'         => $location?->ip ?? $ip,
                'status'        => 'active',
            ]);
        }

        return $next($request);
    }
}
