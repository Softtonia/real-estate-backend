<?php

namespace App\Http\Controllers\Api\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Guest\GuestDynamicPostCardResource;
use App\Models\User;
use App\Services\Frontend\RecentlyViewedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RecentlyViewedPostController extends Controller
{
    public function __construct(
        private RecentlyViewedPostService $recentlyViewedService
    ) {}

    /**
     * GET /api/guest/recently-viewed
     * GET /api/recently-viewed
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);
            $guestSessionId = $this->resolveGuestSessionId($request);

            $options = [
                'limit' => (int) $request->input('limit', $request->input('per_page', 6)),
                'per_page' => (int) $request->input('per_page', $request->input('limit', 6)),
                'page' => (int) $request->input('page', 1),
                'post_type' => $request->input('post_type', $request->input('type', $request->input('target_post_type'))),
                'exclude_id' => $request->input('exclude_id', $request->input('current_post_id')),
            ];

            $paginator = $this->recentlyViewedService->getRecentlyViewed($user, $guestSessionId, $options);

            $items = GuestDynamicPostCardResource::collection(
                $paginator->getCollection()
            )->resolve($request);

            return response()->json([
                'status' => true,
                'message' => 'Recently viewed posts fetched successfully.',
                'data' => $items,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Fetch recently viewed posts failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch recently viewed posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/guest/recently-viewed
     * POST /api/recently-viewed
     */
    public function track(Request $request): JsonResponse
    {
        try {
            $postId = $request->integer('dynamic_post_id') ?: $request->integer('post_id') ?: $request->integer('id');

            if ($postId <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Valid dynamic_post_id is required.',
                ], 422);
            }

            $user = $this->resolveCurrentUser($request);
            $guestSessionId = $this->resolveGuestSessionId($request);

            $tracked = $this->recentlyViewedService->trackView($user, $guestSessionId, $postId);

            if (!$tracked) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post not found or invalid view tracking request.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Post view tracked successfully.',
            ], 200);
        } catch (Throwable $e) {
            Log::error('Track recently viewed post failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to track post view.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/guest/recently-viewed
     * DELETE /api/recently-viewed
     */
    public function clear(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);
            $guestSessionId = $this->resolveGuestSessionId($request);
            $postTypeSlug = $request->input('post_type', $request->input('type'));

            $cleared = $this->recentlyViewedService->clear($user, $guestSessionId, $postTypeSlug);

            return response()->json([
                'status' => true,
                'message' => 'Recently viewed posts cleared successfully.',
                'cleared' => $cleared,
            ]);
        } catch (Throwable $e) {
            Log::error('Clear recently viewed posts failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to clear recently viewed posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function resolveGuestSessionId(Request $request): string
    {
        $id = $request->header('X-Guest-Session-ID')
            ?: $request->header('guest-session-id')
            ?: $request->header('guest_session_id')
            ?: $request->header('X-Visitor-ID')
            ?: $request->header('visitor-id')
            ?: $request->input('guest_session_id')
            ?: $request->input('session_id')
            ?: $request->input('visitor_id');

        if (!empty($id)) {
            return trim((string) $id);
        }

        // Fallback: Generate consistent guest ID based on IP and User-Agent hash
        $ip = $request->ip() ?: '127.0.0.1';
        $ua = $request->userAgent() ?: 'unknown-agent';

        return 'guest_' . substr(md5($ip . '_' . $ua), 0, 24);
    }
}
