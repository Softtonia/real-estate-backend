<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserIpLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    // public function handle(Request $request, Closure $next): Response
    // {
    //     $ip = $request->ip();

    //     // Check if IP is blocked
    //     $ipLog = UserIpLog::where('ip_address', $ip)->first();
    //     if ($ipLog && $ipLog->status === 'blocked') {
    //         return response()->json([
    //            'error' => 'Your IP address has been blocked. Please contact the administrator for assistance.',
    //         ], 403);
    //     }

    //     // Log IP if not already logged
    //     if (!$ipLog) {
    //         $location = Location::get($ip);

    //         // Use defaults if location is unavailable (like on localhost)
    //         UserIpLog::create([
    //             'user_id'       => auth()->id(), // Optional: logged-in user ID
    //             'ip_address'    => $ip,
    //             'country'       => $location?->countryName ?? 'Localhost',
    //             'city'          => $location?->cityName ?? 'Unknown',
    //             'region'        => $location?->regionName ?? 'Local Network',
    //             'country_code'  => $location?->countryCode ?? null,
    //             'region_code'   => $location?->regionCode ?? null,
    //             'lat'           => $location?->latitude ?? null,
    //             'lon'           => $location?->longitude ?? null,
    //             'timezone'      => $location?->timezone ?? null,
    //             'isp'           => $location?->isp ?? null,
    //             'org'           => $location?->organization ?? null,
    //             'as'            => $location?->asn ?? null,
    //             'query'         => $location?->ip ?? $ip,
    //             'status'        => 'active',
    //         ]);
    //     }

    //     return $next($request);


    // }

    // public function handle(Request $request, Closure $next): Response
    // {
    //     $ip = $request->ip();

    //     // Manual auth via Bearer token
    //     $authorizationHeader = $request->header('Authorization');
    //     if (str_starts_with($authorizationHeader, 'Bearer ')) {
    //         $token = substr($authorizationHeader, 7);
    //         $user = \App\Models\User::where('api_token', $token)->first();
    //         if ($user) {
    //             Auth::setUser($user);
    //         }
    //     }

    //     $user = Auth::user();
    //     $userId = $user?->id;
    //     \Log::info("User ID: " . $userId);

    //     if ($user && $user->role === 'admin') {
    //         \Log::info("Admin IP bypassed: {$ip}");
    //         return $next($request);
    //     }

    //     // 1. Block IP if already blocked
    //     $ipLog = UserIpLog::where('ip_address', $ip)->first();
    //     if ($ipLog && $ipLog->status === 'blocked') {
    //         return response()->json([
    //             'error' => 'Your IP address has been blocked. Please contact the administrator.',
    //         ], 403);
    //     }

    //     // 👤 Update user_id if missing and user is authenticated
    //     if ($ipLog && !$ipLog->user_id && $userId) {
    //         $ipLog->user_id = $userId;
    //         $ipLog->save();
    //     }


    //     // 2. Log IP if not already logged
    //     if (!$ipLog) {
    //         $location = Location::get($ip);

    //         UserIpLog::create([
    //             'user_id' => $userId,
    //             'ip_address' => $ip,
    //             'country' => $location?->countryName ?? 'Localhost',
    //             'city' => $location?->cityName ?? 'Unknown',
    //             'region' => $location?->regionName ?? 'Local Network',
    //             'country_code' => $location?->countryCode ?? null,
    //             'region_code' => $location?->regionCode ?? null,
    //             'lat' => $location?->latitude ?? null,
    //             'lon' => $location?->longitude ?? null,
    //             'timezone' => $location?->timezone ?? null,
    //             'isp' => $location?->isp ?? null,
    //             'org' => $location?->organization ?? null,
    //             'as' => $location?->asn ?? null,
    //             'query' => $location?->ip ?? $ip,
    //             'status' => 'active',
    //         ]);
    //     }

    //     // 3. Let request continue and capture response
    //     $response = $next($request);

    //     // 4. If response status is 429 (Too Many Requests), block the IP
    //     if ($response->getStatusCode() === 429) {

    //         if ($user && $user->role === 'admin') {
    //             \Log::info("Admin IP bypassed: {$ip}");
    //             return $next($request);
    //         } else {
    //             $ipToBlock = UserIpLog::where('ip_address', $ip)->first();
    //             if ($ipToBlock && $ipToBlock->status !== 'blocked') {
    //                 $ipToBlock->status = 'blocked';
    //                 $ipToBlock->save();
    //                 \Log::info("IP auto-blocked due to too many requests: {$ip}");
    //             }
    //         }
    //     }

    //     return $response;
    // }


    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $user = Auth::user();
        $userId = $user?->id;

        // 🛡️ If user is logged in and has admin role, bypass IP check
        if ($user && $user->role && $user->role->name === 'admin') {
            \Log::info("Admin IP bypassed: {$ip}");
            return $next($request);
        }

        // 🔒 Check if IP is blocked
        $ipLog = UserIpLog::where('ip_address', $ip)->first();

        if ($ipLog && $ipLog->status === 'blocked') {
            return response()->json([
                'error' => 'Your IP address has been blocked. Please contact the administrator.'
            ], 403);
        }

        // 📝 Update user_id if missing
        if ($ipLog && !$ipLog->user_id && $userId) {
            $ipLog->user_id = $userId;
            $ipLog->save();
        }

        // 📌 Log new IP entry if not present
        if (!$ipLog) {
            $location = Location::get($ip);
            UserIpLog::create([
                'user_id' => $userId,
                'ip_address' => $ip,
                'country' => $location?->countryName ?? 'Localhost',
                'city' => $location?->cityName ?? 'Unknown',
                'region' => $location?->regionName ?? 'Local Network',
                'country_code' => $location?->countryCode ?? null,
                'region_code' => $location?->regionCode ?? null,
                'lat' => $location?->latitude ?? null,
                'lon' => $location?->longitude ?? null,
                'timezone' => $location?->timezone ?? null,
                'isp' => $location?->isp ?? null,
                'org' => $location?->organization ?? null,
                'as' => $location?->asn ?? null,
                'query' => $location?->ip ?? $ip,
                'status' => 'active',
            ]);
        }

        // ⏱️ Allow request and check for too many attempts (429)
        $response = $next($request);

        if ($response->getStatusCode() === 429) {
            $ipToBlock = UserIpLog::where('ip_address', $ip)->first();
            if ($ipToBlock && $ipToBlock->status !== 'blocked') {
                $ipToBlock->status = 'blocked';
                $ipToBlock->save();
                \Log::info("IP auto-blocked due to too many requests: {$ip}");
            }
        }

        return $response;
    }


}
