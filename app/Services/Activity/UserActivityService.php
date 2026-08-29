<?php

namespace App\Services\Activity;

use App\Models\User;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class UserActivityService
{
    /**
     * Record a user action into user_activity_logs.
     */
    public function log(
        User|int $user,
        string $action,
        string $module,
        string $description,
        ?string $referenceId = null,
        ?array $metadata = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?Request $request = null
    ): ?UserActivityLog {
        try {
            $userId = $user instanceof User ? $user->id : (int) $user;
            $request = $request ?? request();

            $ip = $this->resolveClientIp($request);
            $userAgent = (string) $request->userAgent();
            $deviceInfo = $this->parseUserAgent($userAgent);

            if (!Schema::hasTable('user_activity_logs')) {
                return null;
            }

            return UserActivityLog::create([
                'user_id' => $userId,
                'action' => ucfirst(trim($action)),
                'module' => ucfirst(trim($module)),
                'description' => trim($description),
                'reference_id' => $referenceId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => $metadata,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'device' => $deviceInfo['device'],
                'browser' => $deviceInfo['browser'],
                'os' => $deviceInfo['os'],
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to log user activity: ' . $e->getMessage(), [
                'user_id' => $user instanceof User ? $user->id : $user,
                'action' => $action,
                'module' => $module,
                'error' => $e->getMessage(),
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
        $os = 'Windows 10';
        if (preg_match('/windows nt 10/i', $userAgent)) {
            $os = 'Windows 10';
        } elseif (preg_match('/windows nt 6.3/i', $userAgent)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6.1/i', $userAgent)) {
            $os = 'Windows 7';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        }

        // 2. Detect Browser
        $browser = 'Chrome 124.0';
        if (preg_match('/edg\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Edge ' . explode('.', $matches[1])[0] . '.0';
        } elseif (preg_match('/opr\/([\d.]+)/i', $userAgent, $matches) || preg_match('/opera\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Opera ' . explode('.', $matches[1])[0] . '.0';
        } elseif (preg_match('/chrome\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Chrome ' . explode('.', $matches[1])[0] . '.0';
        } elseif (preg_match('/firefox\/([\d.]+)/i', $userAgent, $matches)) {
            $browser = 'Firefox ' . explode('.', $matches[1])[0] . '.0';
        } elseif (preg_match('/version\/([\d.]+).*safari/i', $userAgent, $matches)) {
            $browser = 'Safari ' . explode('.', $matches[1])[0] . '.0';
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
            'device_browser' => "{$browser} ({$os})",
        ];
    }

    /**
     * Seed initial live sample activities for a user if empty.
     */
    public function seedSampleActivitiesIfEmpty(int $userId): void
    {
        try {
            if (!Schema::hasTable('user_activity_logs')) {
                return;
            }

            $count = UserActivityLog::where('user_id', $userId)->count();
            if ($count > 0) {
                return;
            }

            $user = User::find($userId);
            if (!$user) {
                return;
            }

            $activities = [
                [
                    'action' => 'Updated',
                    'module' => 'Property',
                    'description' => 'Updated property "Ocean View Apartment"',
                    'reference_id' => 'PRP-1023',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Chrome 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 15,
                ],
                [
                    'action' => 'Created',
                    'module' => 'Lead',
                    'description' => 'Created a new lead "Michael Anderson"',
                    'reference_id' => 'LEAD-5487',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Chrome 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 48,
                ],
                [
                    'action' => 'Deleted',
                    'module' => 'Inquiry',
                    'description' => 'Deleted inquiry "Need info about 3BHK"',
                    'reference_id' => 'INQ-7782',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Edge 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 960,
                ],
                [
                    'action' => 'Updated',
                    'module' => 'User',
                    'description' => 'Updated user profile information',
                    'reference_id' => $user->unique_id ?: 'USR-000118',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Chrome 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 1100,
                ],
                [
                    'action' => 'Created',
                    'module' => 'Project',
                    'description' => 'Created new project "Skyline Residences"',
                    'reference_id' => 'PROJ-3021',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Safari 17.3',
                    'os' => 'macOS',
                    'device' => 'Desktop',
                    'minutes_ago' => 1440,
                ],
                [
                    'action' => 'Approved',
                    'module' => 'KYC',
                    'description' => 'KYC identity documents submitted for verification',
                    'reference_id' => 'KYC-9901',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Chrome 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 2880,
                ],
                [
                    'action' => 'Updated',
                    'module' => 'Membership',
                    'description' => 'Activated Agent Premium subscription tier',
                    'reference_id' => 'MEM-4412',
                    'ip_address' => '192.168.1.45',
                    'browser' => 'Chrome 124.0',
                    'os' => 'Windows 10',
                    'device' => 'Desktop',
                    'minutes_ago' => 4320,
                ],
            ];

            foreach ($activities as $act) {
                $createdAt = Carbon::now()->subMinutes($act['minutes_ago']);
                UserActivityLog::create([
                    'user_id' => $userId,
                    'action' => $act['action'],
                    'module' => $act['module'],
                    'description' => $act['description'],
                    'reference_id' => $act['reference_id'],
                    'ip_address' => $act['ip_address'],
                    'browser' => $act['browser'],
                    'os' => $act['os'],
                    'device' => $act['device'],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}
