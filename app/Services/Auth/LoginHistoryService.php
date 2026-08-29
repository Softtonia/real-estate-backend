<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserIpLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Stevebauman\Location\Facades\Location;
use Throwable;

class LoginHistoryService
{
    /**
     * Record a user login event into user_ip_logs.
     *
     * @param User|int $user
     * @param Request|null $request
     * @param string $loginMethod
     * @return UserIpLog|null
     */
    public function recordLogin(User|int $user, ?Request $request = null, string $loginMethod = 'Password'): ?UserIpLog
    {
        try {
            $request = $request ?? request();
            $userId = $user instanceof User ? $user->id : (int) $user;

            $ip = $this->resolveClientIp($request);
            $userAgent = (string) $request->userAgent();

            $deviceInfo = $this->parseUserAgent($userAgent);
            $locationData = $this->resolveLocation($ip);

            $payload = array_merge([
                'user_id' => $userId,
                'ip_address' => $ip,
                'status' => 'active',
                'query' => $ip,
            ], $locationData);

            if (Schema::hasColumn('user_ip_logs', 'user_agent')) {
                $payload['user_agent'] = $userAgent;
            }
            if (Schema::hasColumn('user_ip_logs', 'device')) {
                $payload['device'] = $deviceInfo['device'];
            }
            if (Schema::hasColumn('user_ip_logs', 'browser')) {
                $payload['browser'] = $deviceInfo['browser'];
            }
            if (Schema::hasColumn('user_ip_logs', 'os')) {
                $payload['os'] = $deviceInfo['os'];
            }
            if (Schema::hasColumn('user_ip_logs', 'login_method')) {
                $payload['login_method'] = $loginMethod;
            }

            $log = UserIpLog::create($payload);

            $this->clearUserIpLogsCache($userId, $ip);

            return $log;
        } catch (Throwable $e) {
            Log::error('Failed to record login history: ' . $e->getMessage(), [
                'user_id' => $user instanceof User ? $user->id : $user,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Resolve actual client IP from proxy/Cloudflare headers.
     */
    public function resolveClientIp(Request $request): string
    {
        $cfIp = $request->header('CF-Connecting-IP');
        if (!empty($cfIp)) {
            return trim($cfIp);
        }

        $forwarded = $request->header('X-Forwarded-For');
        if (!empty($forwarded)) {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }

        $realIp = $request->header('X-Real-IP');
        if (!empty($realIp)) {
            return trim($realIp);
        }

        return (string) ($request->ip() ?? '127.0.0.1');
    }

    /**
     * Parse User Agent into Device, Browser, and OS.
     */
    public function parseUserAgent(?string $userAgent): array
    {
        $userAgent = (string) $userAgent;

        // 1. Detect OS
        $os = 'Unknown OS';
        if (preg_match('/windows nt 10/i', $userAgent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/windows nt 6.3/i', $userAgent)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6.2/i', $userAgent)) {
            $os = 'Windows 8';
        } elseif (preg_match('/windows nt 6.1/i', $userAgent)) {
            $os = 'Windows 7';
        } elseif (preg_match('/windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/postman/i', $userAgent)) {
            $os = 'Postman';
        }

        // 2. Detect Browser
        $browser = 'Unknown Browser';
        if (preg_match('/edg\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Edge ' . explode('.', $matches[1])[0];
        } elseif (preg_match('/opr\/([\d.]+)/i', $userAgent, $matches) || preg_match('/opera\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Opera ' . explode('.', $matches[1])[0];
        } elseif (preg_match('/chrome\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Chrome ' . explode('.', $matches[1])[0];
        } elseif (preg_match('/firefox\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Firefox ' . explode('.', $matches[1])[0];
        } elseif (preg_match('/version\/([\d.]+).*safari/i', $userAgent, $matches)) {
            $browser = 'Safari ' . explode('.', $matches[1])[0];
        } elseif (preg_match('/safari\/([\d.]+)/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/postman/i', $userAgent)) {
            $browser = 'Postman Runtime';
        }

        // 3. Detect Device
        $device = 'Desktop';
        if (preg_match('/ipad|tablet/i', $userAgent)) {
            $device = 'Tablet';
        } elseif (preg_match('/iphone/i', $userAgent)) {
            $device = 'iPhone';
        } elseif (preg_match('/mobile|android|phone/i', $userAgent)) {
            $device = 'Mobile';
        }

        return [
            'device' => $device,
            'browser' => $browser,
            'os' => $os,
            'device_browser' => $device . ' / ' . $browser,
        ];
    }

    /**
     * Resolve Geo Location safely.
     */
    public function resolveLocation(string $ip): array
    {
        $default = [
            'country' => 'Localhost',
            'city' => 'Local Network',
            'region' => 'Local',
            'country_code' => null,
            'region_code' => null,
            'lat' => null,
            'lon' => null,
            'timezone' => null,
            'isp' => null,
            'org' => null,
            'as' => null,
        ];

        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return $default;
        }

        try {
            $location = Location::get($ip);
            if ($location) {
                return [
                    'country' => $location->countryName ?? 'Unknown',
                    'city' => $location->cityName ?? 'Unknown',
                    'region' => $location->regionName ?? 'Unknown',
                    'country_code' => $location->countryCode ?? null,
                    'region_code' => $location->regionCode ?? null,
                    'lat' => $location->latitude ?? null,
                    'lon' => $location->longitude ?? null,
                    'timezone' => $location->timezone ?? null,
                    'isp' => $location->isp ?? null,
                    'org' => $location->organization ?? null,
                    'as' => $location->asn ?? null,
                ];
            }
        } catch (Throwable $e) {
            // Location resolution failure is not fatal
        }

        return $default;
    }

    /**
     * Clear cached user IP logs.
     */
    public function clearUserIpLogsCache(int $userId, ?string $ip = null): void
    {
        try {
            Cache::forget("iplogs_user_{$userId}");
            try {
                Cache::store('redis')->forget("iplogs_user_{$userId}");
            } catch (Throwable) {}

            if ($ip) {
                Cache::forget("iplogs_ip_{$ip}");
                try {
                    Cache::store('redis')->forget("iplogs_ip_{$ip}");
                } catch (Throwable) {}
            }
        } catch (Throwable $e) {
            // Cache forget failure non-fatal
        }
    }
}
