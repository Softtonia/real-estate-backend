<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'status' => ['nullable', 'in:all,read,unread'],
                'search' => ['nullable', 'string', 'max:255'],
                'date_from' => ['nullable', 'date'],
                'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $query = $this->baseQuery($user->id);

            if ($request->get('status') === 'read' && Schema::hasColumn('user_notifications', 'read_at')) {
                $query->whereNotNull('read_at');
            }

            if ($request->get('status') === 'unread' && Schema::hasColumn('user_notifications', 'read_at')) {
                $query->whereNull('read_at');
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    if (Schema::hasColumn('user_notifications', 'title')) {
                        $q->where('title', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('user_notifications', 'body')) {
                        $q->orWhere('body', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('user_notifications', 'message')) {
                        $q->orWhere('message', 'like', "%{$search}%");
                    }
                });
            }

            if ($request->filled('date_from') && Schema::hasColumn('user_notifications', 'created_at')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to') && Schema::hasColumn('user_notifications', 'created_at')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $notifications = $query
                ->orderByDesc(Schema::hasColumn('user_notifications', 'created_at') ? 'created_at' : 'id')
                ->paginate($perPage);

            $notifications->getCollection()->transform(fn ($row) => $this->formatNotification($row));

            return response()->json([
                'status' => true,
                'message' => 'Notifications fetched successfully.',
                'data' => $notifications->getCollection(),
                'meta' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'unread_count' => $this->unreadTotal($user->id),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch notifications.', $e);
        }
    }

    public function unreadCount(Request $request): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            return response()->json([
                'status' => true,
                'message' => 'Unread notification count fetched successfully.',
                'data' => [
                    'unread_count' => $this->unreadTotal($user->id),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch unread notification count.', $e);
        }
    }

    public function show(Request $request, int|string $notification): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            $row = $this->baseQuery($user->id)
                ->where('id', (int) $notification)
                ->first();

            if (! $row) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Notification fetched successfully.',
                'data' => $this->formatNotification($row),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch notification.', $e);
        }
    }

    public function markAsRead(Request $request, int|string $notification): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            if (! Schema::hasColumn('user_notifications', 'read_at')) {
                return response()->json([
                    'status' => false,
                    'message' => 'read_at column not found in user_notifications table.',
                ], 500);
            }

            $row = $this->baseQuery($user->id)
                ->where('id', (int) $notification)
                ->first();

            if (! $row) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found.',
                ], 404);
            }

            DB::table('user_notifications')
                ->where('id', (int) $notification)
                ->where('user_id', $user->id)
                ->update([
                    'read_at' => now(),
                    'updated_at' => Schema::hasColumn('user_notifications', 'updated_at') ? now() : DB::raw('updated_at'),
                ]);

            $fresh = $this->baseQuery($user->id)
                ->where('id', (int) $notification)
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read successfully.',
                'data' => $this->formatNotification($fresh),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark notification as read.', $e);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            if (! Schema::hasColumn('user_notifications', 'read_at')) {
                return response()->json([
                    'status' => false,
                    'message' => 'read_at column not found in user_notifications table.',
                ], 500);
            }

            $query = DB::table('user_notifications')
                ->where('user_id', $user->id)
                ->whereNull('read_at');

            if (Schema::hasColumn('user_notifications', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $updated = $query->update(array_filter([
                'read_at' => now(),
                'updated_at' => Schema::hasColumn('user_notifications', 'updated_at') ? now() : null,
            ]));

            return response()->json([
                'status' => true,
                'message' => 'All notifications marked as read successfully.',
                'data' => [
                    'updated_count' => $updated,
                    'unread_count' => 0,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to mark all notifications as read.', $e);
        }
    }

    public function destroy(Request $request, int|string $notification): JsonResponse
    {
        try {
            $user = $this->currentUser($request);

            if (! $user) {
                return $this->unauthenticated();
            }

            $this->ensureTable();

            $row = $this->baseQuery($user->id)
                ->where('id', (int) $notification)
                ->first();

            if (! $row) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found.',
                ], 404);
            }

            $query = DB::table('user_notifications')
                ->where('id', (int) $notification)
                ->where('user_id', $user->id);

            if (Schema::hasColumn('user_notifications', 'deleted_at')) {
                $query->update(array_filter([
                    'deleted_at' => now(),
                    'updated_at' => Schema::hasColumn('user_notifications', 'updated_at') ? now() : null,
                ]));
            } else {
                $query->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Notification deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete notification.', $e);
        }
    }

    private function baseQuery(int $userId)
    {
        $columns = ['id', 'user_id'];

        foreach ([
            'notification_batch_id',
            'notification_log_id',
            'title',
            'body',
            'message',
            'image_url',
            'icon',
            'data',
            'read_at',
            'clicked_at',
            'created_at',
            'updated_at',
        ] as $column) {
            if (Schema::hasColumn('user_notifications', $column)) {
                $columns[] = $column;
            }
        }

        $query = DB::table('user_notifications')
            ->select($columns)
            ->where('user_id', $userId);

        if (Schema::hasColumn('user_notifications', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function unreadTotal(int $userId): int
    {
        if (! Schema::hasColumn('user_notifications', 'read_at')) {
            return 0;
        }

        $query = DB::table('user_notifications')
            ->where('user_id', $userId)
            ->whereNull('read_at');

        if (Schema::hasColumn('user_notifications', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function formatNotification(object $row): array
    {
        $data = [];

        if (property_exists($row, 'data') && $row->data) {
            $decoded = json_decode((string) $row->data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,

            'notification_batch_id' => property_exists($row, 'notification_batch_id') && $row->notification_batch_id
                ? (int) $row->notification_batch_id
                : null,

            'notification_log_id' => property_exists($row, 'notification_log_id') && $row->notification_log_id
                ? (int) $row->notification_log_id
                : null,

            'title' => $row->title ?? null,
            'body' => $row->body ?? $row->message ?? null,
            'message' => $row->message ?? $row->body ?? null,

            'image_url' => $row->image_url ?? null,
            'icon' => $row->icon ?? null,

            'data' => $data,

            'is_read' => property_exists($row, 'read_at') && $row->read_at !== null,
            'read_at' => $row->read_at ?? null,
            'clicked_at' => $row->clicked_at ?? null,

            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function ensureTable(): void
    {
        if (! Schema::hasTable('user_notifications')) {
            abort(response()->json([
                'status' => false,
                'message' => 'user_notifications table not found.',
            ], 500));
        }
    }

    private function currentUser(Request $request): ?User
    {
        $token = $this->extractBearerToken($request);

        if ($token) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = (string) $request->header('Authorization');

        if ($authorization !== '') {
            if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match('/Token\s+(.+)/i', $authorization, $matches)) {
                return trim($matches[1]);
            }
        }

        $token = $request->bearerToken()
            ?: $request->header('X-User-Token')
            ?: $request->header('X-Api-Token')
            ?: $request->header('Api-Token')
            ?: $request->header('api-token')
            ?: $request->header('token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        return $token !== '' ? $token : null;
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        report($e);

        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}