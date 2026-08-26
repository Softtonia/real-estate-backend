<?php

namespace App\Services\Frontend;

use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\User;
use App\Models\UserRecentlyViewedPost;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecentlyViewedPostService
{
    public function __construct(
        private GuestDynamicPostService $guestPostService
    ) {}

    public function trackView(
        ?User $user,
        ?string $guestSessionId,
        int $dynamicPostId
    ): bool {
        if ($dynamicPostId <= 0 || !Schema::hasTable('user_recently_viewed_posts')) {
            return false;
        }

        $post = DynamicPost::query()->select(['id', 'post_type_id'])->find($dynamicPostId);
        if (!$post) {
            return false;
        }

        $userId = $user?->id;
        $guestSessionId = trim((string) $guestSessionId) ?: null;

        if (!$userId && !$guestSessionId) {
            return false;
        }

        $now = Carbon::now();

        if ($userId) {
            $existing = UserRecentlyViewedPost::query()
                ->where('user_id', $userId)
                ->where('dynamic_post_id', $post->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'view_count' => $existing->view_count + 1,
                    'viewed_at' => $now,
                    'post_type_id' => $post->post_type_id,
                ]);
            } else {
                UserRecentlyViewedPost::query()->create([
                    'user_id' => $userId,
                    'guest_session_id' => $guestSessionId,
                    'dynamic_post_id' => $post->id,
                    'post_type_id' => $post->post_type_id,
                    'view_count' => 1,
                    'viewed_at' => $now,
                ]);
            }
        } elseif ($guestSessionId) {
            $existing = UserRecentlyViewedPost::query()
                ->where('guest_session_id', $guestSessionId)
                ->whereNull('user_id')
                ->where('dynamic_post_id', $post->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'view_count' => $existing->view_count + 1,
                    'viewed_at' => $now,
                    'post_type_id' => $post->post_type_id,
                ]);
            } else {
                UserRecentlyViewedPost::query()->create([
                    'user_id' => null,
                    'guest_session_id' => $guestSessionId,
                    'dynamic_post_id' => $post->id,
                    'post_type_id' => $post->post_type_id,
                    'view_count' => 1,
                    'viewed_at' => $now,
                ]);
            }
        }

        return true;
    }

    public function getRecentlyViewed(
        ?User $user,
        ?string $guestSessionId,
        array $options = []
    ): LengthAwarePaginator {
        $limit = max(1, min((int) ($options['limit'] ?? $options['per_page'] ?? 6), 100));
        $page = max(1, (int) ($options['page'] ?? 1));
        $postTypeSlug = $options['post_type'] ?? $options['target_post_type'] ?? $options['type'] ?? null;
        $excludeId = (int) ($options['exclude_id'] ?? $options['current_post_id'] ?? 0);

        if (!Schema::hasTable('user_recently_viewed_posts')) {
            return new LengthAwarePaginator([], 0, $limit, $page);
        }

        $userId = $user?->id;
        $guestSessionId = trim((string) $guestSessionId) ?: null;

        if (!$userId && !$guestSessionId) {
            return new LengthAwarePaginator([], 0, $limit, $page);
        }

        $query = DB::table('user_recently_viewed_posts as rvp')
            ->join('dynamic_posts as dp', 'dp.id', '=', 'rvp.dynamic_post_id')
            ->leftJoin('countries as guest_countries', 'guest_countries.id', '=', 'dp.country_id')
            ->leftJoin('states as guest_states', 'guest_states.id', '=', 'dp.state_id')
            ->leftJoin('cities as guest_cities', 'guest_cities.id', '=', 'dp.city_id')
            ->select([
                'dp.id',
                'dp.post_type_id',
                'dp.title',
                'dp.slug',
                'dp.listing_code',
                'dp.excerpt',
                'dp.country_id',
                'dp.state_id',
                'dp.city_id',
                'dp.area_locality',
                'dp.featured_image_id',
                'dp.status',
                'dp.live_status',
                'dp.availability_status',
                'dp.availability_public_until',
                'dp.sold_at',
                'dp.published_at',
                'dp.created_at',
                'rvp.viewed_at as _recently_viewed_at',
                'rvp.view_count as _recently_view_count',
                'guest_countries.name as guest_country_name',
                'guest_states.name as guest_state_name',
                'guest_cities.name as guest_city_name',
            ])
            ->where('dp.status', 'published')
            ->where('dp.live_status', 'approve');

        if ($userId) {
            $query->where('rvp.user_id', $userId);
        } else {
            $query->where('rvp.guest_session_id', $guestSessionId);
        }

        if ($excludeId > 0) {
            $query->where('dp.id', '!=', $excludeId);
        }

        if (!empty($postTypeSlug)) {
            $pType = PostType::query()->where('slug', trim((string) $postTypeSlug))->first();
            if ($pType) {
                $query->where('dp.post_type_id', (int) $pType->id);
            }
        }

        // Maintain strict order: most recently viewed item first!
        $query->orderBy('rvp.viewed_at', 'desc');

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        // Convert query results to DynamicPost models with loaded media & promotions
        $postIds = collect($paginator->items())->pluck('id')->toArray();

        if (!empty($postIds)) {
            $postsMap = DynamicPost::query()
                ->with(['postType', 'taxonomyTerms.taxonomy'])
                ->whereIn('id', $postIds)
                ->get()
                ->keyBy('id');

            $hydratedItems = collect($paginator->items())->map(function ($rawRow) use ($postsMap) {
                $post = $postsMap->get($rawRow->id);
                if (!$post) {
                    return null;
                }
                $post->guest_country_name = $rawRow->guest_country_name ?? null;
                $post->guest_state_name = $rawRow->guest_state_name ?? null;
                $post->guest_city_name = $rawRow->guest_city_name ?? null;
                $post->setAttribute('_recently_viewed_at', $rawRow->_recently_viewed_at ?? null);
                $post->setAttribute('_recently_view_count', $rawRow->_recently_view_count ?? 1);
                return $post;
            })->filter()->values();

            // Attach media and featured promotions
            $this->guestPostService->attachCurrentFeaturedPromotions($hydratedItems);
            $this->guestPostService->attachFeaturedMedia($hydratedItems);

            $paginator->setCollection($hydratedItems);
        }

        return $paginator;
    }

    public function mergeGuestViewsToUser(User $user, string $guestSessionId): void
    {
        $guestSessionId = trim($guestSessionId);
        if (!$guestSessionId || !Schema::hasTable('user_recently_viewed_posts')) {
            return;
        }

        $guestViews = UserRecentlyViewedPost::query()
            ->where('guest_session_id', $guestSessionId)
            ->whereNull('user_id')
            ->get();

        foreach ($guestViews as $guestView) {
            $existing = UserRecentlyViewedPost::query()
                ->where('user_id', $user->id)
                ->where('dynamic_post_id', $guestView->dynamic_post_id)
                ->first();

            if ($existing) {
                $existing->update([
                    'view_count' => $existing->view_count + $guestView->view_count,
                    'viewed_at' => max($existing->viewed_at, $guestView->viewed_at),
                ]);
                $guestView->delete();
            } else {
                $guestView->update([
                    'user_id' => $user->id,
                ]);
            }
        }
    }

    public function clear(?User $user, ?string $guestSessionId, ?string $postTypeSlug = null): bool
    {
        if (!Schema::hasTable('user_recently_viewed_posts')) {
            return false;
        }

        $userId = $user?->id;
        $guestSessionId = trim((string) $guestSessionId) ?: null;

        if (!$userId && !$guestSessionId) {
            return false;
        }

        $query = UserRecentlyViewedPost::query();

        if ($userId) {
            $query->where('user_id', $userId);
        } else {
            $query->where('guest_session_id', $guestSessionId);
        }

        if ($postTypeSlug) {
            $pType = PostType::query()->where('slug', trim($postTypeSlug))->first();
            if ($pType) {
                $query->where('post_type_id', $pType->id);
            }
        }

        return (bool) $query->delete();
    }
}
