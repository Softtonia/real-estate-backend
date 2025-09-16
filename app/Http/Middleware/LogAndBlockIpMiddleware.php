<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserIpLog;
use App\Models\ApiClient;
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
    //     $authHeader = $request->header('Authorization');
    //     $user = null;
    //     $userId = null;

    //     // 🔐 Extract and validate Bearer token
    //     if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
    //         $token = str_replace('Bearer ', '', $authHeader);
    //         $user = User::where('api_token', $token)->with('role')->first();
    //         $userId = $user?->id;
    //     }

    //     // 🛡️ Admin bypass
    //     if ($user && $user->role && $user->role->name === 'admin') {
    //         \Log::info("Admin IP bypassed: {$ip}");
    //         return $next($request);
    //     }

    //     // 🔓 Allow login routes to bypass IP block
    //     $routePath = $request->path();
    //     $loginRoutes = ['api/login', 'api/admin/login', 'login'];

    //     if (in_array($routePath, $loginRoutes)) {
    //         \Log::info("Bypassing IP block for login route: {$routePath} from IP: {$ip}");
    //         return $next($request);
    //     }

    //     // 🔒 Blocked IP check
    //     $ipLog = UserIpLog::where('ip_address', $ip)->first();
    //     if ($ipLog && $ipLog->status === 'blocked') {
    //         return response()->json([
    //             'error' => 'Your IP address has been blocked. Please contact the administrator.'
    //         ], 403);
    //     }

    //     // 📝 Update user_id if available but missing for this IP
    //     if ($ipLog && !$ipLog->user_id && $userId) {
    //         $ipLog->user_id = $userId;
    //         $ipLog->save();
    //     }

    //     // 🔁 If user exists and logged in from a different IP earlier, update old entry
    //     if ($userId) {
    //         $userIpLog = UserIpLog::where('user_id', $userId)->first();

    //         if ($userIpLog) {
    //             if ($userIpLog->ip_address !== $ip) {
    //                 // 🌐 Update existing user entry with new IP
    //                 $location = Location::get($ip);
    //                 $userIpLog->update([
    //                     'ip_address' => $ip,
    //                     'country' => $location?->countryName ?? 'Localhost',
    //                     'city' => $location?->cityName ?? 'Unknown',
    //                     'region' => $location?->regionName ?? 'Local Network',
    //                     'country_code' => $location?->countryCode ?? null,
    //                     'region_code' => $location?->regionCode ?? null,
    //                     'lat' => $location?->latitude ?? null,
    //                     'lon' => $location?->longitude ?? null,
    //                     'timezone' => $location?->timezone ?? null,
    //                     'isp' => $location?->isp ?? null,
    //                     'org' => $location?->organization ?? null,
    //                     'as' => $location?->asn ?? null,
    //                     'query' => $location?->ip ?? $ip,
    //                     'status' => 'active',
    //                 ]);
    //             }
    //         } elseif (!$ipLog) {
    //             // ➕ No IP log for current IP, create a new one
    //             $location = Location::get($ip);
    //             UserIpLog::create([
    //                 'user_id' => $userId,
    //                 'ip_address' => $ip,
    //                 'country' => $location?->countryName ?? 'Localhost',
    //                 'city' => $location?->cityName ?? 'Unknown',
    //                 'region' => $location?->regionName ?? 'Local Network',
    //                 'country_code' => $location?->countryCode ?? null,
    //                 'region_code' => $location?->regionCode ?? null,
    //                 'lat' => $location?->latitude ?? null,
    //                 'lon' => $location?->longitude ?? null,
    //                 'timezone' => $location?->timezone ?? null,
    //                 'isp' => $location?->isp ?? null,
    //                 'org' => $location?->organization ?? null,
    //                 'as' => $location?->asn ?? null,
    //                 'query' => $location?->ip ?? $ip,
    //                 'status' => 'active',
    //             ]);
    //         }
    //     } elseif (!$ipLog) {
    //         // 👤 Anonymous (no user_id) logging if not already recorded
    //         $location = Location::get($ip);
    //         UserIpLog::create([
    //             'user_id' => null,
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

    //     // 🚦 Proceed to next middleware
    //     $response = $next($request);

    //     // ⛔ Auto-block if too many requests (Rate Limit)
    //     if ($response->getStatusCode() === 429) {
    //         $ipToBlock = UserIpLog::where('ip_address', $ip)->first();
    //         if ($ipToBlock && $ipToBlock->status !== 'blocked') {
    //             $ipToBlock->status = 'blocked';
    //             $ipToBlock->save();
    //             \Log::info("IP auto-blocked due to too many requests: {$ip}");
    //         }
    //     }

    //     return $response;
    // }


    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $authHeader = $request->header('Authorization');
        $user = null;
        $userId = null;

        // ----- NEW: trusted build key header (case-insensitive) -----
        // Configure this secret in your Laravel .env as NEXTJS_INTERNAL_KEY
        // $buildKeyHeader = $request->header('x-nextjs-build-key') ?? $request->header('X-Nextjs-Build-Key') ?? $request->header('X-NextJS-Build-Key');
        // $trustedBuildKey = env('NEXTJS_INTERNAL_KEY');

        // $isInternalBuildCall = false;
        // if ($buildKeyHeader && $trustedBuildKey) {
        //     // use hash_equals to mitigate timing attacks
        //     try {
        //         if (is_string($buildKeyHeader) && is_string($trustedBuildKey) && hash_equals((string)$trustedBuildKey, (string)$buildKeyHeader)) {
        //             $isInternalBuildCall = true;
        //         }
        //     } catch (\Throwable $e) {
        //         // if hash_equals not applicable for some reason, fallback safe compare
        //         $isInternalBuildCall = ((string)$trustedBuildKey === (string)$buildKeyHeader);
        //     }
        // }

         // ----- NEW: trusted build key header (case-insensitive) -----
        $buildKeyHeader = $request->header('x-nextjs-build-key')
            ?? $request->header('X-Nextjs-Build-Key')
            ?? $request->header('X-NextJS-Build-Key');

        $isInternalBuildCall = false;

        if ($buildKeyHeader) {
            // check api_clients table for active client with this key
            $trustedClient = ApiClient::where('nextjs_internal_key', $buildKeyHeader)
                ->Active()
                ->first();

            if ($trustedClient && is_string($trustedClient->nextjs_internal_key) && is_string($buildKeyHeader)) {
                try {
                    if (hash_equals((string) $trustedClient->nextjs_internal_key, (string) $buildKeyHeader)) {
                        $isInternalBuildCall = true;
                    }
                } catch (\Throwable $e) {
                    $isInternalBuildCall = ((string) $trustedClient->nextjs_internal_key === (string) $buildKeyHeader);
                }
            }
        }


        //  Extract and validate Bearer token
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = str_replace('Bearer ', '', $authHeader);
            $user = User::where('api_token', $token)->with('role')->first();
            $userId = $user?->id;
        }

        // 🛡️ Admin bypass
        if ($user && $user->role && $user->role->name === 'admin') {
            \Log::info("Admin IP bypassed: {$ip}");
            return $next($request);
        }

        // 🔓 Allow login routes to bypass IP block
        $routePath = $request->path();
        $loginRoutes = ['api/login', 'api/admin/login', 'login'];

        if (in_array($routePath, $loginRoutes)) {
            \Log::info("Bypassing IP block for login route: {$routePath} from IP: {$ip}");
            return $next($request);
        }

        // ----- NEW: If it's an internal build call, bypass rate-limit & blocking logic -----
        if ($isInternalBuildCall) {
            \Log::info("Bypassing IP block for internal build call from IP: {$ip}");
            // Optionally create or update a non-blocking log entry
            $ipLog = UserIpLog::firstOrCreate(
                ['ip_address' => $ip],
                [
                    'user_id' => $userId ?? null,
                    'country' => null,
                    'city' => null,
                    'region' => null,
                    'country_code' => null,
                    'region_code' => null,
                    'lat' => null,
                    'lon' => null,
                    'timezone' => null,
                    'isp' => null,
                    'org' => null,
                    'as' => null,
                    'query' => $ip,
                    'status' => 'active',
                ]
            );
            if ($userId && $ipLog && !$ipLog->user_id) {
                $ipLog->user_id = $userId;
                $ipLog->save();
            }
            return $next($request);
        }

        // 🔒 Blocked IP check
        $ipLog = UserIpLog::where('ip_address', $ip)->first();
        if ($ipLog && $ipLog->status === 'blocked') {
            return response()->json([
                'error' => 'Your IP address has been blocked. Please contact the administrator.'
            ], 403);
        }

        // 📝 Update user_id if available but missing for this IP
        if ($ipLog && !$ipLog->user_id && $userId) {
            $ipLog->user_id = $userId;
            $ipLog->save();
        }

        // 🔁 If user exists and logged in from a different IP earlier, update old entry
        if ($userId) {
            $userIpLog = UserIpLog::where('user_id', $userId)->first();

            if ($userIpLog) {
                if ($userIpLog->ip_address !== $ip) {
                    // 🌐 Update existing user entry with new IP
                    $location = Location::get($ip);
                    $userIpLog->update([
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
            } elseif (!$ipLog) {
                // ➕ No IP log for current IP, create a new one
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
        } elseif (!$ipLog) {
            // 👤 Anonymous (no user_id) logging if not already recorded
            $location = Location::get($ip);
            UserIpLog::create([
                'user_id' => null,
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

        // 🚦 Proceed to next middleware
        $response = $next($request);

        // ⛔ Auto-block if too many requests (Rate Limit)
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
