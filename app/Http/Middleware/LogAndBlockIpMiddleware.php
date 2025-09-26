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
     * Behavior:
     * - IP-level limit: configurable via env IP_RATE_LIMIT (default 60 req/min)
     * - User-level limit: configurable via env USER_RATE_LIMIT (default 20 req/min)
     * - Block duration: configurable via env BLOCK_DURATION_MINUTES (default 0 => permanent)
     * - Automatic unblock: if BLOCK_DURATION_MINUTES > 0, blocked IPs/users are auto-unblocked after that duration.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->header('X-Forwarded-For') ?? $request->ip();
        $authHeader = $request->header('Authorization');
        $user = null;
        $userId = null;

        // Configurable limits
        $ipLimit = (int) env('IP_RATE_LIMIT', 60);
        $userLimit = (int) env('USER_RATE_LIMIT', 20);
        $blockDurationMinutes = (int) env('BLOCK_DURATION_MINUTES', 0); // 0 means permanent

        // ----- trusted build key header (case-insensitive) -----
        $buildKeyHeader = $request->header('x-nextjs-build-key')
            ?? $request->header('X-Nextjs-Build-Key')
            ?? $request->header('X-NextJS-Build-Key');

        $isInternalBuildCall = false;

        if ($buildKeyHeader) {
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

        // Extract and validate Bearer token
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = str_replace('Bearer ', '', $authHeader);
            $user = User::where('api_token', $token)->with('role')->first();
            $userId = $user?->id;
        }

        // Admin bypass
        if ($user && $user->role && $user->role->name === 'admin') {
            Log::info("Admin IP bypassed: {$ip}");
            return $next($request);
        }

        // Allow login routes to bypass IP block
        $routePath = $request->path();
        $loginRoutes = ['api/login', 'api/admin/login', 'login'];

        if (in_array($routePath, $loginRoutes)) {
            Log::info("Bypassing IP block for login route: {$routePath} from IP: {$ip}");
            return $next($request);
        }

        // If internal build call, bypass rate-limit & blocking logic
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

        // Prepare cache keys
        $nowMinute = (int) floor(time() / 60);
        $ipCountKey = $this->rateIpCacheKey($ip, $nowMinute);
        $userCountKey = $userId ? $this->rateUserCacheKey($userId, $nowMinute) : null;
        $blockedIpCacheKey = $this->blockedIpCacheKey($ip);
        $blockedUserCacheKey = $this->blockedUserCacheKey($userId);

        // Clean up/block checks with auto-unblock if configured
        // 1) IP-level check
        $ipLog = UserIpLog::where('ip_address', $ip)->first();
        if ($ipLog && $ipLog->status === 'blocked') {
            if ($blockDurationMinutes > 0 && $ipLog->blocked_at) {
                $expiresAt = Carbon::parse($ipLog->blocked_at)->addMinutes($blockDurationMinutes);
                if (Carbon::now()->greaterThanOrEqualTo($expiresAt)) {
                    // Auto-unblock
                    $this->unblockIpRecord($ipLog);
                    // continue processing (no 403)
                } else {
                    Log::warning("Blocked IP attempted request still in block window: ip={$ip}");
                    return response()->json([
                        'error' => 'Your IP address has been blocked. Please contact the administrator.'
                    ], 403);
                }
            } else {
                // Permanent block
                Log::warning("Blocked IP attempted request: ip={$ip}");
                return response()->json([
                    'error' => 'Your IP address has been blocked. Please contact the administrator.'
                ], 403);
            }
        } else {
            // If cache indicates blocked (faster check), respect it and optionally sync DB
            if (Cache::has($blockedIpCacheKey)) {
                // If block duration configured, check TTL; Cache will remove key when expired automatically.
                Log::warning("Blocked IP attempted request (cache): ip={$ip}");
                return response()->json([
                    'error' => 'Your IP address has been blocked. Please contact the administrator.'
                ], 403);
            }
        }

        // 2) User-level check (if authenticated)
        if ($userId) {
            // If user is recorded blocked in cache, enforce block (cache TTL will auto-expire)
            if ($blockDurationMinutes > 0) {
                if (Cache::has($blockedUserCacheKey)) {
                    Log::warning("Blocked user attempted request (cache): user_id={$userId}, ip={$ip}");
                    return response()->json([
                        'error' => 'Your account has been blocked due to abuse. Please contact the administrator.'
                    ], 403);
                }

                // Also check DB user_ip_logs - if status blocked and still within duration, enforce
                $userIpLog = UserIpLog::where('user_id', $userId)->first();
                if ($userIpLog && $userIpLog->status === 'blocked') {
                    if ($userIpLog->blocked_at) {
                        $expiresAt = Carbon::parse($userIpLog->blocked_at)->addMinutes($blockDurationMinutes);
                        if (Carbon::now()->lessThan($expiresAt)) {
                            Log::warning("Blocked user attempted request (db within duration): user_id={$userId}, ip={$ip}");
                            return response()->json([
                                'error' => 'Your account has been blocked due to abuse. Please contact the administrator.'
                            ], 403);
                        } else {
                            // Auto-unblock user logs
                            $this->unblockUserRecords($userId);
                        }
                    }
                }
            } else {
                // Permanent blocks: check DB entries for this user's ip logs
                $userIpLog = UserIpLog::where('user_id', $userId)->first();
                if ($userIpLog && $userIpLog->status === 'blocked') {
                    Log::warning("Blocked user attempted request (permanent): user_id={$userId}, ip={$ip}");
                    return response()->json([
                        'error' => 'Your account has been blocked due to abuse. Please contact the administrator.'
                    ], 403);
                }
            }
        }

        // Update user_id on IP log if available (and ipLog exists)
        if ($ipLog && !$ipLog->user_id && $userId) {
            $ipLog->user_id = $userId;
            $ipLog->save();
        }

        // Rate limiting counters increment (minute bucket)
        $ipCount = $this->incrementCacheKey($ipCountKey, 60);
        $userCount = null;
        if ($userCountKey) {
            $userCount = $this->incrementCacheKey($userCountKey, 60);
        }

        // If IP exceeds ipLimit -> block IP
        if ($ipCount > $ipLimit) {
            Log::warning("Rate limit exceeded for IP: {$ip} (count={$ipCount}) - blocking for {$blockDurationMinutes} minute(s).");
            $this->blockIp($ip, $userId, 'Too many requests (rate limit exceeded - ip)', $blockDurationMinutes);
            return response()->json([
                'error' => 'Your IP address has been blocked due to excessive requests. Please contact the administrator.'
            ], 403);
        }

        // If user exceeds userLimit -> block user's IP (per requirement)
        if ($userCount !== null && $userCount > $userLimit) {
            Log::warning("User exceeded request threshold: user_id={$userId}, count={$userCount} - blocking their IP {$ip} for {$blockDurationMinutes} minute(s).");
            $this->blockIp($ip, $userId, 'Blocked because user exceeded configured user request threshold', $blockDurationMinutes);

            // Also mark user's ip logs as blocked (so admin tools show it)
            try {
                UserIpLog::where('user_id', $userId)
                    ->update([
                        'status' => 'blocked',
                        'blocked_at' => now(),
                        'blocked_reason' => 'User exceeded request threshold',
                    ]);
            } catch (\Throwable $e) {
                Log::error("Failed to update UserIpLog for user {$userId}: {$e->getMessage()}");
            }

            // Set cache flag for user block as well (so checks are fast)
            if ($blockDurationMinutes > 0) {
                Cache::put($this->blockedUserCacheKey($userId), true, $blockDurationMinutes * 60);
            } else {
                Cache::forever($this->blockedUserCacheKey($userId), true);
            }

            return response()->json([
                'error' => 'Your IP address has been blocked due to excessive requests by this account. Please contact the administrator.'
            ], 403);
        }

        // Continue updating/creating IP logs as before
        if ($userId) {
            $userIpLog = UserIpLog::where('user_id', $userId)->first();

            if ($userIpLog) {
                if ($userIpLog->ip_address !== $ip) {
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

        // Proceed to next middleware / request handling
        $response = $next($request);

        // note: removed downstream 429 -> block logic per requirement

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
            if (Cache::has($key)) {
                $count = Cache::increment($key);
            } else {
                Cache::put($key, 1, $ttlSeconds);
                $count = 1;
            }
            return (int) $count;
        } catch (\Throwable $e) {
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

    protected function blockedIpCacheKey(string $ip): string
    {
        $safeIp = str_replace([':', '.'], '_', $ip);
        return "blocked:ip:{$safeIp}";
    }

    protected function blockedUserCacheKey(?int $userId): string
    {
        return $userId ? "blocked:user:{$userId}" : "blocked:user:unknown";
    }

    /**
     * Block an IP: update/create UserIpLog record and set blocked flag in DB and cache.
     * $durationMinutes = 0 means permanent (forever).
     */
    protected function blockIp(string $ip, ?int $userId = null, string $reason = 'Rate limit exceeded', int $durationMinutes = 0): void
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

            $cacheKey = $this->blockedIpCacheKey($ip);
            if ($durationMinutes > 0) {
                Cache::put($cacheKey, true, $durationMinutes * 60);
            } else {
                Cache::forever($cacheKey, true);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to block IP {$ip}: {$e->getMessage()}");
        }
    }

    /**
     * Unblock IP DB record and clear cache flag.
     */
    protected function unblockIpRecord(UserIpLog $ipLog): void
    {
        try {
            $ipLog->status = 'active';
            $ipLog->blocked_at = null;
            $ipLog->blocked_reason = null;
            $ipLog->save();

            Cache::forget($this->blockedIpCacheKey($ipLog->ip_address));
        } catch (\Throwable $e) {
            Log::error("Failed to unblock IP record {$ipLog->ip_address}: {$e->getMessage()}");
        }
    }

    /**
     * Unblock all UserIpLog records for a user and clear cache flag for the user.
     */
    protected function unblockUserRecords(int $userId): void
    {
        try {
            UserIpLog::where('user_id', $userId)
                ->update([
                    'status' => 'active',
                    'blocked_at' => null,
                    'blocked_reason' => null,
                ]);
            Cache::forget($this->blockedUserCacheKey($userId));
        } catch (\Throwable $e) {
            Log::error("Failed to unblock user records for user {$userId}: {$e->getMessage()}");
        }
    }



}
