<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\Guest\GuestDynamicPostCardResource;
use App\Models\City;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\PropertyFeaturedPromotion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CityExploreController extends Controller
{
    /**
     * Get all city-related data (Agents, Developers, Featured Properties, Featured Projects).
     *
     * GET /api/frontend/city-explore?city_id=1
     * GET /api/frontend/city-explore?city_name=Chandigarh
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $city = $this->resolveCity($request);

            if (!$city) {
                return response()->json([
                    'status' => false,
                    'message' => 'City is required. Please provide city_id or city_name.',
                ], 422);
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 10)));

            $agents = $this->queryCityUsers($city->id, ['agent'], $perPage);
            $developers = $this->queryCityUsers($city->id, ['developer', 'company', 'consultancy', 'builder'], $perPage);
            $featuredProperties = $this->queryCityFeaturedPosts($city->id, ['property-listing'], $perPage);
            $featuredProjects = $this->queryCityFeaturedPosts($city->id, ['project', 'builder-project', 'consultancy-project', 'agent-project'], $perPage);

            return response()->json([
                'status' => true,
                'message' => 'City exploration data fetched successfully.',
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                    'state_id' => $city->state_id ? (int) $city->state_id : null,
                    'state_name' => $city->state?->name ?? null,
                    'country_id' => $city->state?->country_id ? (int) $city->state->country_id : null,
                    'country_name' => $city->state?->country?->name ?? null,
                ],
                'data' => [
                    'agents' => $agents,
                    'developers' => $developers,
                    'featured_properties' => $featuredProperties,
                    'featured_projects' => $featuredProjects,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch city exploration data.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get agents for a specific city.
     *
     * GET /api/frontend/city-explore/agents?city_id=1
     */
    public function agents(Request $request): JsonResponse
    {
        try {
            $city = $this->resolveCity($request);

            if (!$city) {
                return response()->json([
                    'status' => false,
                    'message' => 'City is required. Please provide city_id or city_name.',
                ], 422);
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
            $agents = $this->queryCityUsers($city->id, ['agent'], $perPage, true);

            return response()->json([
                'status' => true,
                'message' => 'City agents fetched successfully.',
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                ],
                'data' => $agents,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch city agents.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get developers/builders for a specific city.
     *
     * GET /api/frontend/city-explore/developers?city_id=1
     */
    public function developers(Request $request): JsonResponse
    {
        try {
            $city = $this->resolveCity($request);

            if (!$city) {
                return response()->json([
                    'status' => false,
                    'message' => 'City is required. Please provide city_id or city_name.',
                ], 422);
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
            $developers = $this->queryCityUsers($city->id, ['developer', 'company', 'consultancy', 'builder'], $perPage, true);

            return response()->json([
                'status' => true,
                'message' => 'City developers fetched successfully.',
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                ],
                'data' => $developers,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch city developers.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get featured properties for a specific city.
     *
     * GET /api/frontend/city-explore/featured-properties?city_id=1
     */
    public function featuredProperties(Request $request): JsonResponse
    {
        try {
            $city = $this->resolveCity($request);

            if (!$city) {
                return response()->json([
                    'status' => false,
                    'message' => 'City is required. Please provide city_id or city_name.',
                ], 422);
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
            $properties = $this->queryCityFeaturedPosts($city->id, ['property-listing'], $perPage, true);

            return response()->json([
                'status' => true,
                'message' => 'City featured properties fetched successfully.',
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                ],
                'data' => $properties,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch city featured properties.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get featured projects for a specific city.
     *
     * GET /api/frontend/city-explore/featured-projects?city_id=1
     */
    public function featuredProjects(Request $request): JsonResponse
    {
        try {
            $city = $this->resolveCity($request);

            if (!$city) {
                return response()->json([
                    'status' => false,
                    'message' => 'City is required. Please provide city_id or city_name.',
                ], 422);
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 15)));
            $projects = $this->queryCityFeaturedPosts($city->id, ['project', 'builder-project', 'consultancy-project', 'agent-project'], $perPage, true);

            return response()->json([
                'status' => true,
                'message' => 'City featured projects fetched successfully.',
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                ],
                'data' => $projects,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch city featured projects.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Resolve city model by city_id, city_name, or slug.
     */
    private function resolveCity(Request $request): ?City
    {
        $cityId = $request->input('city_id')
            ?? $request->input('city')
            ?? $request->query('city_id')
            ?? $request->query('city');

        if ($cityId && is_numeric($cityId)) {
            $city = City::with(['state.country'])->find((int) $cityId);
            if ($city) {
                return $city;
            }
        }

        $cityName = $request->input('city_name')
            ?? $request->input('name')
            ?? (is_string($cityId) ? $cityId : null);

        if ($cityName && is_string($cityName) && trim($cityName) !== '') {
            return City::with(['state.country'])
                ->where('name', 'like', trim($cityName))
                ->orWhere('slug', trim($cityName))
                ->first();
        }

        return null;
    }

    /**
     * Query users (Agents or Developers) matching a city.
     */
    private function queryCityUsers(int $cityId, array $roleNames, int $perPage, bool $paginated = false): mixed
    {
        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->filter()
            ->all();

        $query = User::query()
            ->leftJoin('user_details', 'user_details.user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.email',
                'users.phone',
                'users.unique_id',
                'users.role_id',
                'users.city_id as user_city_id',
                'user_details.city_id as detail_city_id',
                'user_details.bussiness_name',
                'user_details.bussiness_address',
                'user_details.bussiness_email',
                'user_details.business_phone',
                'user_details.profile_photo',
                'user_details.license_number',
                'roles.name as role_name',
            ])
            ->where(function ($cityQuery) use ($cityId) {
                if (Schema::hasColumn('users', 'city_id')) {
                    $cityQuery->where('users.city_id', $cityId);
                }
                if (Schema::hasTable('user_details') && Schema::hasColumn('user_details', 'city_id')) {
                    $cityQuery->orWhere('user_details.city_id', $cityId);
                }
            })
            ->where(function ($roleQuery) use ($roleIds, $roleNames) {
                if (!empty($roleIds)) {
                    $roleQuery->whereIn('users.role_id', $roleIds);
                }
                if (Schema::hasTable('roles')) {
                    $roleQuery->orWhereIn('roles.name', $roleNames);
                }
            })
            ->where('users.isapproved', 1)
            ->distinct();

        if ($paginated) {
            $paginator = $query->paginate($perPage);
            $paginator->getCollection()->transform(fn ($user) => $this->formatUserCard($user));
            return $paginator;
        }

        return $query->limit($perPage)->get()->map(fn ($user) => $this->formatUserCard($user))->all();
    }

    /**
     * Query Featured Posts (Properties or Projects) matching a city.
     */
    private function queryCityFeaturedPosts(int $cityId, array $postTypeSlugs, int $perPage, bool $paginated = false): mixed
    {
        $postTypeIds = PostType::query()
            ->whereIn('slug', $postTypeSlugs)
            ->pluck('id')
            ->all();

        $now = now();

        $query = DynamicPost::query()
            ->leftJoin('countries as guest_countries', 'guest_countries.id', '=', 'dynamic_posts.country_id')
            ->leftJoin('states as guest_states', 'guest_states.id', '=', 'dynamic_posts.state_id')
            ->leftJoin('cities as guest_cities', 'guest_cities.id', '=', 'dynamic_posts.city_id')
            ->select([
                'dynamic_posts.*',
                'guest_countries.name as guest_country_name',
                'guest_states.name as guest_state_name',
                'guest_cities.name as guest_city_name',
            ])
            ->with(['postType', 'taxonomyTerms.taxonomy'])
            ->where('dynamic_posts.city_id', $cityId)
            ->where('dynamic_posts.status', 'published')
            ->where('dynamic_posts.live_status', 'approve')
            ->where(function ($typeQuery) use ($postTypeIds) {
                if (!empty($postTypeIds)) {
                    $typeQuery->whereIn('dynamic_posts.post_type_id', $postTypeIds);
                }
            });

        // Filter featured promotions
        $query->whereExists(function ($promotionQuery) use ($now) {
            $promotionQuery
                ->selectRaw('1')
                ->from('property_featured_promotions as pfp')
                ->whereColumn('pfp.dynamic_post_id', 'dynamic_posts.id')
                ->whereNull('pfp.cancelled_at')
                ->where('pfp.status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(function ($startQuery) use ($now) {
                    $startQuery->whereNull('pfp.starts_at')->orWhere('pfp.starts_at', '<=', $now);
                })
                ->where(function ($endQuery) use ($now) {
                    $endQuery->whereNull('pfp.ends_at')->orWhere('pfp.ends_at', '>', $now);
                });
        });

        // Attach promotion relationship
        $posts = $paginated ? $query->paginate($perPage) : $query->limit($perPage)->get();

        $collection = $paginated ? $posts->getCollection() : $posts;

        if ($collection->isNotEmpty()) {
            $postIds = $collection->pluck('id')->all();

            $promotions = PropertyFeaturedPromotion::query()
                ->whereIn('dynamic_post_id', $postIds)
                ->whereNull('cancelled_at')
                ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(function ($startQuery) use ($now) {
                    $startQuery->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($endQuery) use ($now) {
                    $endQuery->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->get()
                ->keyBy('dynamic_post_id');

            $collection->each(function (DynamicPost $post) use ($promotions) {
                $post->setAttribute('_guest_featured_promotion', $promotions->get($post->id));
            });
        }

        if ($paginated) {
            $posts->getCollection()->transform(function ($post) {
                return (new GuestDynamicPostCardResource($post))->resolve(request());
            });
            return $posts;
        }

        return $collection->map(function ($post) {
            return (new GuestDynamicPostCardResource($post))->resolve(request());
        })->all();
    }

    /**
     * Format agent / developer user object for API.
     */
    private function formatUserCard(mixed $user): array
    {
        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($fullName === '') {
            $fullName = $user->user_name ?? $user->bussiness_name ?? $user->email ?? ('User #' . $user->id);
        }

        $photo = $user->profile_photo ?? null;
        if ($photo && !str_starts_with($photo, 'http://') && !str_starts_with($photo, 'https://')) {
            $photo = url($photo);
        }

        return [
            'id' => (int) $user->id,
            'user_id' => (int) $user->id,
            'name' => $fullName,
            'fullname' => $fullName,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_name' => $user->user_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'unique_id' => $user->unique_id,
            'role_id' => $user->role_id ? (int) $user->role_id : null,
            'role_name' => $user->role_name,
            'business_name' => $user->bussiness_name,
            'business_address' => $user->bussiness_address,
            'business_email' => $user->bussiness_email,
            'business_phone' => $user->business_phone,
            'license_number' => $user->license_number,
            'profile_photo' => $photo,
        ];
    }
}
