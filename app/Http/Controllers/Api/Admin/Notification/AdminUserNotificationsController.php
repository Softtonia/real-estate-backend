<?php

namespace App\Http\Controllers\Api\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notification\UserNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminUserNotificationsController extends Controller
{
    /**
     * Get list of notifications sent to a specific user or all users.
     */
    public function index(Request $request, ?int $routeUserId = null): JsonResponse
    {
        try {
            $userId = $routeUserId ?? $request->input('user_id') ?? $request->input('id');

            $query = UserNotification::query()
                ->with(['user:id,first_name,last_name,email,user_name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if (!empty($userId)) {
                $userId = (int) $userId;
                $query->where('user_id', $userId);

                // Auto-create initial sample notifications if user has none or if ?seed=1 is passed
                if ($request->boolean('seed') || UserNotification::where('user_id', $userId)->count() === 0) {
                    $this->seedSampleNotificationsForUser($userId);
                }
            }

            if ($request->filled('type') && strtolower($request->input('type')) !== 'all') {
                $type = strtolower(trim($request->input('type')));
                $query->where('type', 'like', "%{$type}%");
            }

            if ($request->filled('read_status') && $request->input('read_status') !== 'all') {
                if ($request->input('read_status') === 'read') {
                    $query->whereNotNull('read_at');
                } elseif ($request->input('read_status') === 'unread') {
                    $query->whereNull('read_at');
                }
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            }

            if ($request->filled('date_from')) {
                try {
                    $query->whereDate('created_at', '>=', Carbon::parse($request->input('date_from'))->toDateString());
                } catch (Throwable) {}
            }

            if ($request->filled('date_to')) {
                try {
                    $query->whereDate('created_at', '<=', Carbon::parse($request->input('date_to'))->toDateString());
                } catch (Throwable) {}
            }

            $perPage = min(100, max(1, (int) ($request->input('per_page') ?? 20)));

            if ($request->has('page') || $request->has('per_page')) {
                $paginator = $query->paginate($perPage);

                $formatted = $paginator->getCollection()->map(function ($item) {
                    return $this->formatNotification($item);
                });

                return response()->json([
                    'status' => true,
                    'message' => 'User notifications fetched successfully.',
                    'data' => $formatted,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ]);
            }

            $notifications = $query->take(100)->get()->map(function ($item) {
                return $this->formatNotification($item);
            });

            return response()->json([
                'status' => true,
                'message' => 'User notifications fetched successfully.',
                'data' => $notifications,
                'notifications' => $notifications,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user notifications.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Send or create a new notification for a user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'type' => 'nullable|string|max:100',
            'image_url' => 'nullable|string|max:1000',
            'data' => 'nullable|array',
        ]);

        $notification = UserNotification::create([
            'user_id' => $validated['user_id'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'type' => $validated['type'] ?? 'system',
            'image_url' => $validated['image_url'] ?? null,
            'data' => $validated['data'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Notification created successfully.',
            'data' => $this->formatNotification($notification),
        ], 201);
    }

    /**
     * View/Get details of a specific notification.
     */
    public function show(UserNotification $notification): JsonResponse
    {
        $notification->loadMissing(['user:id,first_name,last_name,email,user_name']);

        return response()->json([
            'status' => true,
            'message' => 'Notification fetched successfully.',
            'data' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(UserNotification $notification): JsonResponse
    {
        $notification->markAsRead();

        return response()->json([
            'status' => true,
            'message' => 'Notification marked as read.',
            'data' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(UserNotification $notification): JsonResponse
    {
        $notification->delete();

        return response()->json([
            'status' => true,
            'message' => 'Notification deleted successfully.',
        ]);
    }

    /**
     * Format a single UserNotification model for response.
     */
    private function formatNotification(UserNotification $item): array
    {
        $type = ucfirst(strtolower($item->type ?? 'System'));

        return [
            'id' => $item->id,
            'user_id' => $item->user_id,
            'user' => $item->user ? [
                'id' => $item->user->id,
                'name' => trim(($item->user->first_name ?? '') . ' ' . ($item->user->last_name ?? '')) ?: $item->user->email,
                'email' => $item->user->email,
            ] : null,
            'type' => $type,
            'type_slug' => strtolower($item->type ?? 'system'),
            'type_name' => $type,
            'title' => $item->title,
            'message' => $item->body,
            'body' => $item->body,
            'image_url' => $item->image_url,
            'data' => $item->data,
            'is_read' => !empty($item->read_at),
            'read_at' => $item->read_at ? $item->read_at->format('M d, Y h:i A') : null,
            'date_time' => $item->created_at ? $item->created_at->format('M d, Y h:i A') : null,
            'date' => $item->created_at ? $item->created_at->format('M d, Y') : null,
            'time' => $item->created_at ? $item->created_at->format('h:i A') : null,
            'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
            'updated_at' => $item->updated_at ? $item->updated_at->toISOString() : null,
        ];
    }

    /**
     * Seed initial sample notifications for a user if empty.
     */
    private function seedSampleNotificationsForUser(int $userId): void
    {
        try {
            $userExists = User::where('id', $userId)->exists();
            if (!$userExists) {
                return;
            }

            $samples = [
                [
                    'type' => 'system',
                    'title' => 'Password Changed',
                    'body' => 'Your password was changed successfully.',
                    'minutes_ago' => 20,
                    'read' => true,
                ],
                [
                    'type' => 'system',
                    'title' => 'Login Notification',
                    'body' => 'New login detected on Chrome (Windows) from current IP.',
                    'minutes_ago' => 45,
                    'read' => true,
                ],
                [
                    'type' => 'leads',
                    'title' => 'New Lead Assigned',
                    'body' => 'You have been assigned a new lead "Michael Vance" for Luxury 3BHK Villa.',
                    'minutes_ago' => 120,
                    'read' => false,
                ],
                [
                    'type' => 'projects',
                    'title' => 'Project Update',
                    'body' => 'Project "Skyline Residences" has been updated.',
                    'minutes_ago' => 360,
                    'read' => false,
                ],
                [
                    'type' => 'system',
                    'title' => '2FA Disabled',
                    'body' => 'Two-factor authentication has been disabled for your account.',
                    'minutes_ago' => 1440,
                    'read' => true,
                ],
            ];

            foreach ($samples as $sample) {
                $exists = UserNotification::where('user_id', $userId)
                    ->where('title', $sample['title'])
                    ->exists();

                if (!$exists) {
                    $createdAt = Carbon::now()->subMinutes($sample['minutes_ago']);
                    UserNotification::create([
                        'user_id' => $userId,
                        'title' => $sample['title'],
                        'body' => $sample['body'],
                        'type' => $sample['type'],
                        'read_at' => $sample['read'] ? $createdAt->copy()->addMinutes(5) : null,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Ignore seeder error
        }
    }
}
