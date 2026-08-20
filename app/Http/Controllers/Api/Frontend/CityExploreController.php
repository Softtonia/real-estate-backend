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
        $hasSlugColumn = Schema::hasColumn('roles', 'slug');

        $roleIds = [];
        if (Schema::hasTable('roles')) {
            $roleIds = Role::query()
                ->where(function ($q) use ($roleNames, $hasSlugColumn) {
                    foreach ($roleNames as $name) {
                        $lName = strtolower($name);
                        $q->orWhereRaw('LOWER(name) LIKE ?', ["%{$lName}%"]);
                        if ($hasSlugColumn) {
                            $q->orWhereRaw('LOWER(slug) LIKE ?', ["%{$lName}%"]);
                        }
                    }
                })
                ->pluck('id')
                ->filter()
                ->all();
        }

        $hasUserPhoto = Schema::hasColumn('users', 'profile_photo');
        $hasUserAvatar = Schema::hasColumn('users', 'avatar');
        $hasUserImage = Schema::hasColumn('users', 'image');
        $hasUserUniqueId = Schema::hasColumn('users', 'unique_id');
        $hasUserIsApproved = Schema::hasColumn('users', 'isapproved');
        $hasUserCity = Schema::hasColumn('users', 'city_id');

        $hasDetailTable = Schema::hasTable('user_details');
        $hasDetailCity = $hasDetailTable && Schema::hasColumn('user_details', 'city_id');
        $hasDetailState = $hasDetailTable && Schema::hasColumn('user_details', 'state_id');
        $hasDetailCountry = $hasDetailTable && Schema::hasColumn('user_details', 'country_id');
        $hasDetailBName = $hasDetailTable && Schema::hasColumn('user_details', 'bussiness_name');
        $hasDetailBAddress = $hasDetailTable && Schema::hasColumn('user_details', 'bussiness_address');
        $hasDetailBEmail = $hasDetailTable && Schema::hasColumn('user_details', 'bussiness_email');
        $hasDetailBPhone = $hasDetailTable && Schema::hasColumn('user_details', 'business_phone');
        $hasDetailPhoto = $hasDetailTable && Schema::hasColumn('user_details', 'profile_photo');
        $hasDetailImage = $hasDetailTable && Schema::hasColumn('user_details', 'profile_image');
        $hasDetailLicense = $hasDetailTable && Schema::hasColumn('user_details', 'license_number');
        $hasDetailRera = $hasDetailTable && Schema::hasColumn('user_details', 'rera_number');
        $hasDetailAbout = $hasDetailTable && Schema::hasColumn('user_details', 'about_us');
        $hasDetailAddress = $hasDetailTable && Schema::hasColumn('user_details', 'address');
        $hasDetailPin = $hasDetailTable && Schema::hasColumn('user_details', 'pin_code');
        $hasDetailAltPhone = $hasDetailTable && Schema::hasColumn('user_details', 'alternate_number');

        $selects = [
            'users.id',
            'users.first_name',
            'users.last_name',
            'users.user_name',
            'users.email',
            'users.phone',
            'users.role_id',
            'users.created_at',
        ];

        $selects[] = $hasUserUniqueId ? 'users.unique_id' : DB::raw('NULL as unique_id');
        $selects[] = $hasUserIsApproved ? 'users.isapproved' : DB::raw('1 as isapproved');
        $selects[] = $hasUserCity ? 'users.city_id as user_city_id' : DB::raw('NULL as user_city_id');

        $selects[] = $hasDetailCity ? 'user_details.city_id as detail_city_id' : DB::raw('NULL as detail_city_id');
        $selects[] = $hasDetailState ? 'user_details.state_id as detail_state_id' : DB::raw('NULL as detail_state_id');
        $selects[] = $hasDetailCountry ? 'user_details.country_id as detail_country_id' : DB::raw('NULL as detail_country_id');
        $selects[] = $hasDetailBName ? 'user_details.bussiness_name' : DB::raw('NULL as bussiness_name');
        $selects[] = $hasDetailBAddress ? 'user_details.bussiness_address' : DB::raw('NULL as bussiness_address');
        $selects[] = $hasDetailBEmail ? 'user_details.bussiness_email' : DB::raw('NULL as bussiness_email');
        $selects[] = $hasDetailBPhone ? 'user_details.business_phone' : DB::raw('NULL as business_phone');
        $selects[] = $hasDetailPhoto ? 'user_details.profile_photo as detail_profile_photo' : DB::raw('NULL as detail_profile_photo');
        $selects[] = $hasDetailLicense ? 'user_details.license_number' : DB::raw('NULL as license_number');
        $selects[] = $hasDetailRera ? 'user_details.rera_number' : DB::raw('NULL as rera_number');
        $selects[] = $hasDetailAbout ? 'user_details.about_us' : DB::raw('NULL as about_us');
        $selects[] = $hasDetailAddress ? 'user_details.address' : DB::raw('NULL as address');
        $selects[] = $hasDetailPin ? 'user_details.pin_code' : DB::raw('NULL as pin_code');
        $selects[] = $hasDetailAltPhone ? 'user_details.alternate_number' : DB::raw('NULL as alternate_number');

        if ($hasUserPhoto) $selects[] = 'users.profile_photo as user_profile_photo';
        if ($hasUserAvatar) $selects[] = 'users.avatar as user_avatar';
        if ($hasUserImage) $selects[] = 'users.image as user_image';
        if ($hasDetailImage) $selects[] = 'user_details.profile_image as detail_profile_image';

        if (Schema::hasTable('roles')) {
            $selects[] = 'roles.name as role_name';
        } else {
            $selects[] = DB::raw('NULL as role_name');
        }

        $selects[] = DB::raw('COALESCE(user_cities.name, detail_cities.name) as city_name');
        $selects[] = DB::raw('user_states.name as state_name');

        $query = User::query();

        if ($hasDetailTable) {
            $query->leftJoin('user_details', 'user_details.user_id', '=', 'users.id');
        }

        if (Schema::hasTable('roles')) {
            $query->leftJoin('roles', 'roles.id', '=', 'users.role_id');
        }

        if (Schema::hasTable('cities')) {
            if ($hasUserCity) {
                $query->leftJoin('cities as user_cities', 'user_cities.id', '=', 'users.city_id');
            } else {
                $query->leftJoin(DB::raw('(SELECT 1 as id, NULL as name) as user_cities'), DB::raw('1'), '=', DB::raw('2'));
            }

            if ($hasDetailCity) {
                $query->leftJoin('cities as detail_cities', 'detail_cities.id', '=', 'user_details.city_id');
            } else {
                $query->leftJoin(DB::raw('(SELECT 1 as id, NULL as name) as detail_cities'), DB::raw('1'), '=', DB::raw('2'));
            }
        } else {
            $query->leftJoin(DB::raw('(SELECT 1 as id, NULL as name) as user_cities'), DB::raw('1'), '=', DB::raw('2'));
            $query->leftJoin(DB::raw('(SELECT 1 as id, NULL as name) as detail_cities'), DB::raw('1'), '=', DB::raw('2'));
        }

        if (Schema::hasTable('states')) {
            $query->leftJoin('states as user_states', 'user_states.id', '=', 'user_cities.state_id');
        } else {
            $query->leftJoin(DB::raw('(SELECT 1 as id, NULL as name) as user_states'), DB::raw('1'), '=', DB::raw('2'));
        }

        $query->select($selects)
            ->where(function ($cityQuery) use ($cityId, $hasUserCity, $hasDetailCity) {
                if ($hasUserCity) {
                    $cityQuery->where('users.city_id', $cityId);
                }
                if ($hasDetailCity) {
                    $cityQuery->orWhere('user_details.city_id', $cityId);
                }
                if (Schema::hasTable('dynamic_posts') && Schema::hasColumn('dynamic_posts', 'city_id')) {
                    $cityQuery->orWhereExists(function ($dpQuery) use ($cityId) {
                        $dpQuery->selectRaw('1')
                            ->from('dynamic_posts')
                            ->whereColumn('dynamic_posts.author_id', 'users.id')
                            ->where('dynamic_posts.city_id', $cityId);
                    });
                }
            })
            ->where(function ($roleQuery) use ($roleIds, $roleNames) {
                if (!empty($roleIds)) {
                    $roleQuery->whereIn('users.role_id', $roleIds);
                }
                if (Schema::hasTable('roles')) {
                    foreach ($roleNames as $rName) {
                        $roleQuery->orWhereRaw('LOWER(roles.name) LIKE ?', ['%' . strtolower($rName) . '%']);
                    }
                }
            })
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

        $rawPhoto = $user->detail_profile_photo
            ?? $user->user_profile_photo
            ?? $user->detail_profile_image
            ?? $user->user_avatar
            ?? $user->user_image
            ?? null;

        $photoUrl = null;

        if ($rawPhoto && is_string($rawPhoto) && trim($rawPhoto) !== '') {
            $trimmed = trim($rawPhoto);
            $trimmed = str_replace('\\/', '/', $trimmed);
            $trimmed = ltrim($trimmed, '/');

            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
                $photoUrl = $trimmed;
            } else {
                if (str_starts_with($trimmed, 'storage/uploads/')) {
                    $trimmed = str_replace('storage/uploads/', 'uploads/', $trimmed);
                }
                if (str_starts_with($trimmed, 'public/uploads/')) {
                    $trimmed = str_replace('public/uploads/', 'uploads/', $trimmed);
                }

                if (str_starts_with($trimmed, 'uploads/')) {
                    $relativePath = substr($trimmed, strlen('uploads/'));
                    if (class_exists('Illuminate\Support\Facades\Storage') && Storage::disk('public_uploads')->exists($relativePath)) {
                        $photoUrl = Storage::disk('public_uploads')->url($relativePath);
                    } else {
                        $photoUrl = url('uploads/' . ltrim($relativePath, '/'));
                    }
                } elseif (str_starts_with($trimmed, 'storage/')) {
                    $photoUrl = url($trimmed);
                } else {
                    $photoUrl = url('uploads/users/' . ltrim($trimmed, '/'));
                }
            }
        }

        if (!$photoUrl) {
            $photoUrl = url('images/default.png');
        }

        $propertiesCount = 0;
        $sellCount = 0;
        $rentCount = 0;

        if (Schema::hasTable('dynamic_posts')) {
            $propertiesCount = DB::table('dynamic_posts')
                ->where('author_id', $user->id)
                ->where('status', 'published')
                ->where('live_status', 'approve')
                ->count();

            if (Schema::hasTable('post_taxonomy_terms') && Schema::hasTable('taxonomy_terms')) {
                $purposeCounts = DB::table('dynamic_posts')
                    ->join('post_taxonomy_terms', 'post_taxonomy_terms.dynamic_post_id', '=', 'dynamic_posts.id')
                    ->join('taxonomy_terms', 'taxonomy_terms.id', '=', 'post_taxonomy_terms.taxonomy_term_id')
                    ->where('dynamic_posts.author_id', $user->id)
                    ->where('dynamic_posts.status', 'published')
                    ->where('dynamic_posts.live_status', 'approve')
                    ->select('taxonomy_terms.slug', 'taxonomy_terms.name', DB::raw('COUNT(DISTINCT dynamic_posts.id) as count'))
                    ->groupBy('taxonomy_terms.slug', 'taxonomy_terms.name')
                    ->get();

                foreach ($purposeCounts as $pc) {
                    $slug = strtolower((string) ($pc->slug ?? ''));
                    $name = strtolower((string) ($pc->name ?? ''));

                    if (in_array($slug, ['sell', 'sale', 'buy', 'for-sale', 'purchase'], true) || str_contains($name, 'sell') || str_contains($name, 'sale')) {
                        $sellCount += (int) $pc->count;
                    } elseif (in_array($slug, ['rent', 'rental', 'lease', 'for-rent'], true) || str_contains($name, 'rent') || str_contains($name, 'lease')) {
                        $rentCount += (int) $pc->count;
                    }
                }
            }

            if ($sellCount === 0 && $rentCount === 0 && Schema::hasColumn('dynamic_posts', 'purpose_id') && Schema::hasTable('taxonomy_terms')) {
                $directPurposes = DB::table('dynamic_posts')
                    ->leftJoin('taxonomy_terms', 'taxonomy_terms.id', '=', 'dynamic_posts.purpose_id')
                    ->where('dynamic_posts.author_id', $user->id)
                    ->where('dynamic_posts.status', 'published')
                    ->where('dynamic_posts.live_status', 'approve')
                    ->select('taxonomy_terms.slug', 'taxonomy_terms.name', DB::raw('COUNT(dynamic_posts.id) as count'))
                    ->groupBy('taxonomy_terms.slug', 'taxonomy_terms.name')
                    ->get();

                foreach ($directPurposes as $dp) {
                    $slug = strtolower((string) ($dp->slug ?? ''));
                    $name = strtolower((string) ($dp->name ?? ''));

                    if (in_array($slug, ['sell', 'sale', 'buy', 'for-sale', 'purchase'], true) || str_contains($name, 'sell') || str_contains($name, 'sale')) {
                        $sellCount += (int) $dp->count;
                    } elseif (in_array($slug, ['rent', 'rental', 'lease', 'for-rent'], true) || str_contains($name, 'rent') || str_contains($name, 'lease')) {
                        $rentCount += (int) $dp->count;
                    }
                }
            }
        }

        $cityId = $user->user_city_id ? (int) $user->user_city_id : ($user->detail_city_id ? (int) $user->detail_city_id : null);

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
            'role_name' => $user->role_name ?? 'Agent',
            'isapproved' => isset($user->isapproved) ? (int) $user->isapproved : 1,
            'business_name' => $user->bussiness_name ?? $fullName,
            'business_address' => $user->bussiness_address ?? $user->address,
            'business_email' => $user->bussiness_email ?? $user->email,
            'business_phone' => $user->business_phone ?? $user->phone,
            'license_number' => $user->license_number,
            'rera_number' => $user->rera_number ?? null,
            'about_us' => $user->about_us ?? null,
            'address' => $user->address ?? $user->bussiness_address,
            'pin_code' => $user->pin_code ?? null,
            'alternate_number' => $user->alternate_number ?? null,
            'city_id' => $cityId,
            'city_name' => $user->city_name ?? null,
            'state_id' => $user->detail_state_id ? (int) $user->detail_state_id : null,
            'state_name' => $user->state_name ?? null,
            'country_id' => $user->detail_country_id ? (int) $user->detail_country_id : null,
            'properties_count' => $propertiesCount,
            'listings_count' => $propertiesCount,
            'sell_listings_count' => $sellCount,
            'rent_listings_count' => $rentCount,
            'purpose_counts' => [
                'sell' => $sellCount,
                'rent' => $rentCount,
            ],
            'profile_photo' => $photoUrl,
            'profile_photo_url' => $photoUrl,
            'profile_image' => $photoUrl,
            'avatar' => $photoUrl,
            'image' => $photoUrl,
            'created_at' => $user->created_at ?? null,
        ];
    }
}
