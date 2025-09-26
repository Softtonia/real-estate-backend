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

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;


class LogAndBlockIpMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */


    


    // public function handle(Request $request, Closure $next): Response
    // {
    //     $ip = $request->header('X-Forwarded-For') ?? $request->ip();
    //     $authHeader = $request->header('Authorization');
    //     $user = null;
    //     $userId = null;

       

    //      // ----- NEW: trusted build key header (case-insensitive) -----
    //     $buildKeyHeader = $request->header('x-nextjs-build-key')
    //         ?? $request->header('X-Nextjs-Build-Key')
    //         ?? $request->header('X-NextJS-Build-Key');

    //     $isInternalBuildCall = false;

    //     if ($buildKeyHeader) {
    //         // check api_clients table for active client with this key
    //         $trustedClient = ApiClient::where('nextjs_internal_key', $buildKeyHeader)
    //             ->Active()
    //             ->first();

    //         if ($trustedClient && is_string($trustedClient->nextjs_internal_key) && is_string($buildKeyHeader)) {
    //             try {
    //                 if (hash_equals((string) $trustedClient->nextjs_internal_key, (string) $buildKeyHeader)) {
    //                     $isInternalBuildCall = true;
    //                 }
    //             } catch (\Throwable $e) {
    //                 $isInternalBuildCall = ((string) $trustedClient->nextjs_internal_key === (string) $buildKeyHeader);
    //             }
    //         }
    //     }


    //     //  Extract and validate Bearer token
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

    //     // ----- NEW: If it's an internal build call, bypass rate-limit & blocking logic -----
    //     if ($isInternalBuildCall) {
    //         \Log::info("Bypassing IP block for internal build call from IP: {$ip}");
    //         // Optionally create or update a non-blocking log entry
    //         $ipLog = UserIpLog::firstOrCreate(
    //             ['ip_address' => $ip],
    //             [
    //                 'user_id' => $userId ?? null,
    //                 'country' => null,
    //                 'city' => null,
    //                 'region' => null,
    //                 'country_code' => null,
    //                 'region_code' => null,
    //                 'lat' => null,
    //                 'lon' => null,
    //                 'timezone' => null,
    //                 'isp' => null,
    //                 'org' => null,
    //                 'as' => null,
    //                 'query' => $ip,
    //                 'status' => 'active',
    //             ]
    //         );
    //         if ($userId && $ipLog && !$ipLog->user_id) {
    //             $ipLog->user_id = $userId;
    //             $ipLog->save();
    //         }
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
    //             $ipToBlock->blocked_at = now();
    //             $ipToBlock->blocked_reason = 'Too many requests (rate limit exceeded)';
    //             $ipToBlock->save();
    //             \Log::info("IP auto-blocked due to too many requests: {$ip}");
    //         }
    //     }

    //     return $response;
    // }



    /**
     * Handle an incoming request.
     *
     * Implements rate limiting: max 60 requests per minute per IP and per User.
     * If a limit is exceeded the offending entity is blocked (cache flag + UserIpLog status).
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->header('X-Forwarded-For') ?? $request->ip();
        $authHeader = $request->header('Authorization');
        $user = null;
        $userId = null;

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
            Log::info("Admin IP bypassed: {$ip}");
            return $next($request);
        }

        // 🔓 Allow login routes to bypass IP block
        $routePath = $request->path();
        $loginRoutes = ['api/login', 'api/admin/login', 'login'];

        if (in_array($routePath, $loginRoutes)) {
            Log::info("Bypassing IP block for login route: {$routePath} from IP: {$ip}");
            return $next($request);
        }

        // ----- NEW: If it's an internal build call, bypass rate-limit & blocking logic -----
        if ($isInternalBuildCall) {
            Log::info("Bypassing IP block for internal build call from IP: {$ip}");
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

        // ----- BLOCK CHECKS: cache-based blocked flags + DB check -----
        if ($userId && Cache::has($this->blockedUserCacheKey($userId))) {
            Log::warning("Blocked user attempted request: user_id={$userId}, ip={$ip}");
            return response()->json([
                'error' => 'Your account has been blocked due to abuse. Please contact the administrator.'
            ], 403);
        }

        $ipLog = UserIpLog::where('ip_address', $ip)->first();
        if ($ipLog && $ipLog->status === 'blocked') {
            Log::warning("Blocked IP attempted request: ip={$ip}");
            return response()->json([
                'error' => 'Your IP address has been blocked. Please contact the administrator.'
            ], 403);
        }

        // 🔁 Update user_id if available but missing for this IP
        if ($ipLog && !$ipLog->user_id && $userId) {
            $ipLog->user_id = $userId;
            $ipLog->save();
        }

        // Before proceeding, perform rate limit checks and increment counters.
        // We use minute-bucket keys so counts reset each minute.
        $nowMinute = (int) floor(time() / 60);
        $ipKey = $this->rateIpCacheKey($ip, $nowMinute);
        $userKey = $userId ? $this->rateUserCacheKey($userId, $nowMinute) : null;

        // Initialize counters (if not present) and increment atomically.
        // Cache::increment works with Redis. If not supported, we fall back to a simple get/put (non-atomic).
        $ipCount = $this->incrementCacheKey($ipKey, 60);
        $userCount = null;
        if ($userKey) {
            $userCount = $this->incrementCacheKey($userKey, 60);
        }

        $limit = 60;

        // If either exceeds the limit, block and return 403
        if ($ipCount > $limit) {
            Log::warning("Rate limit exceeded for IP: {$ip} (count={$ipCount}) - blocking.");
            $this->blockIp($ip, $userId, 'Too many requests (rate limit exceeded)');
            return response()->json([
                'error' => 'Your IP address has been blocked due to excessive requests. Please contact the administrator.'
            ], 403);
        }

        if ($userCount !== null && $userCount > $limit) {
            Log::warning("Rate limit exceeded for User: {$userId} (count={$userCount}) - blocking.");
            $this->blockUser($userId, $ip, 'Too many requests (rate limit exceeded)');
            // Also ensure IP is blocked to prevent continued access from same IP
            $this->blockIp($ip, $userId, 'Blocked because associated user exceeded rate limits');
            return response()->json([
                'error' => 'Your account has been blocked due to excessive requests. Please contact the administrator.'
            ], 403);
        }

        // At this point counters are within limits; continue with logging/updating IP records.

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

        // ⛔ Auto-block if too many requests (Rate Limit) detected downstream (e.g. 429 from throttler)
        if ($response->getStatusCode() === 429) {
            $ipToBlock = UserIpLog::where('ip_address', $ip)->first();
            if ($ipToBlock && $ipToBlock->status !== 'blocked') {
                $ipToBlock->status = 'blocked';
                $ipToBlock->blocked_at = now();
                $ipToBlock->blocked_reason = 'Too many requests (rate limit exceeded)';
                $ipToBlock->save();
                Log::info("IP auto-blocked due to too many requests: {$ip}");
            }
        }

        return $response;
    }

    /**
     * Increment a cache key and ensure TTL (seconds).
     * Returns the new counter value.
     * Uses Cache::increment when available (Redis) for atomic increments.
     */
    protected function incrementCacheKey(string $key, int $ttlSeconds = 60): int
    {
        try {
            // Attempt atomic increment
            if (Cache::has($key)) {
                $count = Cache::increment($key);
            } else {
                // add the key with initial 1 and TTL
                Cache::put($key, 1, $ttlSeconds);
                $count = 1;
            }
            return (int) $count;
        } catch (\Throwable $e) {
            // Fallback non-atomic (less ideal)
            $count = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $count, $ttlSeconds);
            return $count;
        }
    }

    protected function rateIpCacheKey(string $ip, int $minuteBucket): string
    {
        $safeIp = str_replace([':', '.'], '_', $ip);
        return "rate:ip:{$safeIp}:{$minuteBucket}";
    }

    protected function rateUserCacheKey(int $userId, int $minuteBucket): string
    {
        return "rate:user:{$userId}:{$minuteBucket}";
    }

    protected function blockedUserCacheKey(int $userId): string
    {
        return "blocked:user:{$userId}";
    }

    /**
     * Block an IP: update/create UserIpLog record and set blocked flag in cache (no TTL by default).
     */
    protected function blockIp(string $ip, ?int $userId = null, string $reason = 'Rate limit exceeded'): void
    {
        try {
            $ipLog = UserIpLog::firstOrCreate(
                ['ip_address' => $ip],
                [
                    'user_id' => $userId,
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
                    'status' => 'blocked',
                ]
            );

            $ipLog->status = 'blocked';
            $ipLog->blocked_at = now();
            $ipLog->blocked_reason = $reason;
            if ($userId && !$ipLog->user_id) {
                $ipLog->user_id = $userId;
            }
            $ipLog->save();
        } catch (\Throwable $e) {
            Log::error("Failed to block IP {$ip}: {$e->getMessage()}");
        }
    }

    /**
     * Block a user: set a cache "blocked" flag and attempt to annotate UserIpLog.
     * We avoid modifying user table structure directly to keep compatibility; blocking is enforced via cache.
     */
    protected function blockUser(int $userId, string $ip = null, string $reason = 'Rate limit exceeded'): void
    {
        try {
            // Persist block flag in cache indefinitely (until admin clears it)
            Cache::forever($this->blockedUserCacheKey($userId), true);

            // Update associated IP log if provided
            if ($ip) {
                $this->blockIp($ip, $userId, $reason);
            }

            // Additionally update any UserIpLog rows for this user
            try {
                UserIpLog::where('user_id', $userId)
                    ->update([
                        'status' => 'blocked',
                        'blocked_at' => now(),
                        'blocked_reason' => $reason,
                    ]);
            } catch (\Throwable $e) {
                // swallow DB update errors but log them
                Log::error("Failed to update UserIpLog for blocked user {$userId}: {$e->getMessage()}");
            }
        } catch (\Throwable $e) {
            Log::error("Failed to set block for user {$userId}: {$e->getMessage()}");
        }
    }





}
