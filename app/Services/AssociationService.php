<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AssociationService
{
    // protected int $ttl = 300; // seconds (5 minutes)

    // /**
    //  * Return ordered array of associated user IDs (optionally filtered by role).
    //  * We cache IDs (compact) instead of full user arrays to keep memory usage low.
    //  *
    //  * Note: Uses cache tags ['connections', "user_{id}"] for targeted invalidation.
    //  */
    // public function getAssociatedIds(int $userId, ?string $role = null): array
    // {
    //     $cacheKey = "assoc:ids:user:{$userId}:role:" . ($role ?: 'all');

    //     // If your cache driver doesn't support tags, fall back to simple remember
    //     $cacheTagsSupported = $this->cacheSupportsTags();

    //     $closure = function () use ($userId, $role) {
    //         // Use a single query to normalize "other_id" using CASE
    //         $bindings = [$userId, $userId];
    //         $rows = DB::table('connections')
    //             ->selectRaw("CASE WHEN requester_id = ? THEN receiver_id ELSE requester_id END as other_id", $bindings)
    //             ->where('state', 'accepted')
    //             ->where(function ($q) use ($userId) {
    //                 $q->where('requester_id', $userId)->orWhere('receiver_id', $userId);
    //             })
    //             ->orderBy('created_at', 'desc')
    //             ->pluck('other_id')
    //             ->unique()
    //             ->values()
    //             ->all();

    //         if (!$role) {
    //             return $rows;
    //         }

    //         if (empty($rows)) {
    //             return [];
    //         }

    //         // Filter by role in users table while preserving order
    //         $orderedIdsCsv = implode(',', $rows);
    //         // MySQL specific ordering using FIELD() — if your DB isn't MySQL, we fall back to a PHP filter below.
    //         if (DB::getDriverName() === 'mysql') {
    //             $filtered = User::whereIn('id', $rows)
    //                 ->where('role', $role)
    //                 ->orderByRaw("FIELD(id, {$orderedIdsCsv})")
    //                 ->pluck('id')
    //                 ->all();

    //             return $filtered;
    //         }

    //         // portable fallback: fetch and filter, then re-order in PHP
    //         $users = User::whereIn('id', $rows)->where('role', $role)->get()->keyBy('id');
    //         $ordered = array_values(array_filter($rows, fn($id) => isset($users[$id])));
    //         return $ordered;
    //     };

    //     if ($cacheTagsSupported) {
    //         return Cache::tags(['connections', "user_{$userId}"])->remember($cacheKey, $this->ttl, $closure);
    //     }

    //     return Cache::remember($cacheKey, $this->ttl, $closure);
    // }

    // public function flushForUsers(int $a, int $b): void
    // {
    //     if ($this->cacheSupportsTags()) {
    //         Cache::tags(['connections', "user_{$a}", "user_{$b}"])->flush();
    //     } else {
    //         // Best-effort: clear common keys for both users
    //         Cache::forget("assoc:ids:user:{$a}:role:all");
    //         Cache::forget("assoc:ids:user:{$b}:role:all");
    //         // and other role-specific keys if necessary (or just adopt a small TTL)
    //     }
    // }

    // protected function cacheSupportsTags(): bool
    // {
    //     try {
    //         // memcached/redis support tags on Laravel; array file etc. do not.
    //         return method_exists(Cache::getStore(), 'tags');
    //     } catch (\Throwable $e) {
    //         return false;
    //     }
    // }

     protected int $ttl = 300; // seconds (5 minutes)

    /**
     * Return ordered array of associated user IDs (optionally filtered by role).
     */
    public function getAssociatedIds(int $userId, ?string $role = null): array
    {
        $cacheKey = "assoc:ids:user:{$userId}:role:" . ($role ?: 'all');
        $cacheTagsSupported = $this->cacheSupportsTags();

        $closure = function () use ($userId, $role) {
            // ✅ Fix bindings (only 1 placeholder, 1 value)
            $rows = DB::table('connections')
                ->selectRaw(
                    "CASE WHEN requester_id = ? THEN receiver_id ELSE requester_id END as other_id",
                    [$userId]
                )
                ->where('state', 'accepted')
                ->where(function ($q) use ($userId) {
                    $q->where('requester_id', $userId)
                      ->orWhere('receiver_id', $userId);
                })
                ->orderBy('created_at', 'desc')
                ->pluck('other_id')
                ->unique()
                ->values()
                ->all();

            if (!$role) {
                return $rows;
            }

            if (empty($rows)) {
                return [];
            }

            // preserve order with role filtering
            $orderedIdsCsv = implode(',', $rows);

            if (DB::getDriverName() === 'mysql') {
                return User::whereIn('id', $rows)
                    ->where('role', $role)
                    ->orderByRaw("FIELD(id, {$orderedIdsCsv})")
                    ->pluck('id')
                    ->all();
            }

            // fallback for non-MySQL
            $users = User::whereIn('id', $rows)
                ->where('role', $role)
                ->get()
                ->keyBy('id');

            return array_values(array_filter($rows, fn($id) => isset($users[$id])));
        };

        if ($cacheTagsSupported) {
            return Cache::tags(['connections', "user_{$userId}"])
                ->remember($cacheKey, $this->ttl, $closure);
        }

        return Cache::remember($cacheKey, $this->ttl, $closure);
    }

    public function flushForUsers(int $a, int $b): void
    {
        if ($this->cacheSupportsTags()) {
            Cache::tags(['connections', "user_{$a}", "user_{$b}"])->flush();
        } else {
            Cache::forget("assoc:ids:user:{$a}:role:all");
            Cache::forget("assoc:ids:user:{$b}:role:all");
        }
    }

    protected function cacheSupportsTags(): bool
    {
        try {
            return method_exists(Cache::getStore(), 'tags');
        } catch (\Throwable $e) {
            return false;
        }
    }
}