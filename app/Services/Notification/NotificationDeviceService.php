<?php

namespace App\Services\Notification;

use App\Models\Notification\NotificationDevice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationDeviceService
{
    public function register(User $user, array $data, Request $request): NotificationDevice
    {
        return DB::transaction(function () use ($user, $data, $request) {
            $fcmToken = $data['fcm_token'];

            /*
            |--------------------------------------------------------------------------
            | Same FCM token should belong to only one active row.
            | If token already exists under another user, move it safely.
            |--------------------------------------------------------------------------
            */
            $device = NotificationDevice::query()
                ->where('fcm_token', $fcmToken)
                ->lockForUpdate()
                ->first();

            if (! $device) {
                $device = new NotificationDevice();
                $device->fcm_token = $fcmToken;
            }

            /*
            |--------------------------------------------------------------------------
            | Avoid duplicate active devices for same user + device_id.
            | Example: same mobile device refreshes FCM token.
            |--------------------------------------------------------------------------
            */
            if (! empty($data['device_id'])) {
                NotificationDevice::query()
                    ->where('user_id', $user->id)
                    ->where('device_id', $data['device_id'])
                    ->where('id', '!=', $device->id ?? 0)
                    ->update([
                        'status' => false,
                        'revoked_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $device->forceFill([
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'app_type' => $data['app_type'] ?? $request->header('X-App-Type'),
                'device_id' => $data['device_id'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'browser' => $data['browser'] ?? null,
                'os' => $data['os'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'status' => true,
                'last_used_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $device->refresh();
        });
    }

    public function revoke(User $user, array $data): int
    {
        $query = NotificationDevice::query()
            ->where('user_id', $user->id)
            ->where('status', true);

        $query->where(function ($query) use ($data) {
            if (! empty($data['fcm_token'])) {
                $query->orWhere('fcm_token', $data['fcm_token']);
            }

            if (! empty($data['device_id'])) {
                $query->orWhere('device_id', $data['device_id']);
            }
        });

        $count = (clone $query)->count();

        if ($count === 0) {
            throw ValidationException::withMessages([
                'device' => ['Active notification device not found.'],
            ]);
        }

        $query->update([
            'status' => false,
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        return $count;
    }

    public function devices(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = NotificationDevice::query()
            ->where('user_id', $user->id)
            ->select([
                'id',
                'user_id',
                'platform',
                'app_type',
                'device_id',
                'device_name',
                'browser',
                'os',
                'status',
                'last_used_at',
                'revoked_at',
                'created_at',
                'updated_at',
            ])
            ->latest('id');

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['platform'])) {
            $query->where('platform', strtolower((string) $filters['platform']));
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function markInvalidToken(string $fcmToken): void
    {
        NotificationDevice::query()
            ->where('fcm_token', $fcmToken)
            ->update([
                'status' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }
}