<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivityLog;
use App\Services\Activity\UserActivityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class UserActivityLogController extends Controller
{
    /**
     * Get activity logs for a user or system-wide.
     */
    public function index(Request $request, ?int $routeUserId = null): JsonResponse
    {
        try {
            $userId = $routeUserId ?? $request->input('user_id') ?? $request->input('id');

            if (!empty($userId)) {
                $userId = (int) $userId;
                // Auto seed sample initial activities if empty
                app(UserActivityService::class)->seedSampleActivitiesIfEmpty($userId);
            }

            $query = UserActivityLog::query()
                ->with(['user:id,first_name,last_name,email,user_name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if (!empty($userId)) {
                $query->where('user_id', $userId);
            }

            if ($request->filled('action') && strtolower($request->input('action')) !== 'all') {
                $action = trim($request->input('action'));
                $query->where('action', 'like', "%{$action}%");
            }

            if ($request->filled('module') && strtolower($request->input('module')) !== 'all') {
                $module = trim($request->input('module'));
                $query->where('module', 'like', "%{$module}%");
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('module', 'like', "%{$search}%")
                        ->orWhere('reference_id', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('browser', 'like', "%{$search}%")
                        ->orWhere('os', 'like', "%{$search}%");
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
                    return $this->formatActivity($item);
                });

                return response()->json([
                    'status' => true,
                    'message' => 'Activity logs fetched successfully.',
                    'data' => $formatted,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ]);
            }

            $activities = $query->take(100)->get()->map(function ($item) {
                return $this->formatActivity($item);
            });

            return response()->json([
                'status' => true,
                'message' => 'Activity logs fetched successfully.',
                'data' => $activities,
                'activities' => $activities,
                'activity_log' => $activities,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch activity logs.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get single activity log details.
     */
    public function show(UserActivityLog $activity): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Activity log details fetched successfully.',
            'data' => $this->formatActivity($activity),
        ]);
    }

    /**
     * Create a new activity log manually.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'action' => 'required|string|max:50',
            'module' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'reference_id' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ]);

        $log = app(UserActivityService::class)->log(
            user: (int) $validated['user_id'],
            action: $validated['action'],
            module: $validated['module'],
            description: $validated['description'],
            referenceId: $validated['reference_id'] ?? null,
            metadata: $validated['metadata'] ?? null,
            request: $request
        );

        return response()->json([
            'status' => true,
            'message' => 'Activity logged successfully.',
            'data' => $log ? $this->formatActivity($log) : null,
        ], 201);
    }

    /**
     * Delete an activity log record.
     */
    public function destroy(UserActivityLog $activity): JsonResponse
    {
        $activity->delete();

        return response()->json([
            'status' => true,
            'message' => 'Activity log deleted successfully.',
        ]);
    }

    /**
     * Format a single UserActivityLog model for response.
     */
    private function formatActivity(UserActivityLog $item): array
    {
        return [
            'id' => $item->id,
            'user_id' => $item->user_id,
            'user' => $item->user ? [
                'id' => $item->user->id,
                'name' => trim(($item->user->first_name ?? '') . ' ' . ($item->user->last_name ?? '')) ?: $item->user->email,
                'email' => $item->user->email,
            ] : null,
            'action' => $item->action,
            'action_badge_color' => $item->action_badge_color,
            'module' => $item->module,
            'details' => $item->description,
            'description' => $item->description,
            'reference_id' => $item->reference_id,
            'entity_type' => $item->entity_type,
            'entity_id' => $item->entity_id,
            'ip_address' => $item->ip_address ?: '127.0.0.1',
            'device_browser' => $item->device_browser,
            'browser' => $item->browser ?: 'Chrome 124.0',
            'os' => $item->os ?: 'Windows 10',
            'device' => $item->device ?: 'Desktop',
            'metadata' => $item->metadata,
            'date_time' => $item->created_at ? $item->created_at->format('M d, Y h:i A') : null,
            'date' => $item->created_at ? $item->created_at->format('M d, Y') : null,
            'time' => $item->created_at ? $item->created_at->format('h:i A') : null,
            'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
            'updated_at' => $item->updated_at ? $item->updated_at->toISOString() : null,
        ];
    }
}
