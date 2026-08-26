<?php

namespace App\Http\Controllers\Api\Guest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Guest\GuestDynamicPostCardResource;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PropertyFeaturedPromotion;
use App\Models\Role;
use App\Models\User;
use App\Services\Frontend\GuestDynamicPostService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GuestAgentController extends Controller
{
    public function __construct(
        private readonly GuestDynamicPostService $dynamicPostService
    ) {}

    /**
     * Get all agents listing with filters & pagination (Guest/Public).
     *
     * GET /api/guest/agents
     * GET /api/frontend/agents
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min(50, max(1, (int) $request->input('per_page', 12)));
            $search = trim((string) $request->input('search', ''));
            $cityId = $request->input('city_id');
            $cityName = $request->input('city_name') ?? $request->input('city');
            $stateId = $request->input('state_id');

            $agentRoleIds = $this->resolveAgentRoleIds();

            $query = User::query()
                ->with([
                    'role',
                    'personalDetail.city',
                    'personalDetail.state',
                    'personalDetail.country',
                    'businessDetail.city',
                    'businessDetail.state',
                    'businessDetail.country',
                    'city',
                    'state',
                    'country',
                ]);

            if (!empty($agentRoleIds)) {
                $query->whereIn('role_id', $agentRoleIds);
            }

            // Optional search filter
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('unique_id', 'like', "%{$search}%");

                    if (Schema::hasTable('user_business_details')) {
                        $q->orWhereHas('businessDetail', function ($bq) use ($search) {
                            $bq->where('business_name', 'like', "%{$search}%");
                        });
                    }

                    if (Schema::hasTable('user_details')) {
                        $q->orWhereHas('userDetail', function ($dq) use ($search) {
                            $dq->where('bussiness_name', 'like', "%{$search}%");
                        });
                    }
                });
            }

            // Location filters
            if ($cityId) {
                $query->where(function ($cq) use ($cityId) {
                    $cq->where('city_id', (int) $cityId);
                    if (Schema::hasTable('user_personal_details')) {
                        $cq->orWhereHas('personalDetail', fn($pq) => $pq->where('city_id', (int) $cityId));
                    }
                    if (Schema::hasTable('user_details')) {
                        $cq->orWhereHas('userDetail', fn($dq) => $dq->where('city_id', (int) $cityId));
                    }
                });
            } elseif ($cityName && is_string($cityName) && trim($cityName) !== '') {
                $cityName = trim($cityName);
                $query->where(function ($cq) use ($cityName) {
                    $cq->whereHas('city', fn($q) => $q->where('name', 'like', "%{$cityName}%"))
                        ->orWhereHas('personalDetail.city', fn($q) => $q->where('name', 'like', "%{$cityName}%"));
                });
            }

            if ($stateId) {
                $query->where(function ($sq) use ($stateId) {
                    $sq->where('state_id', (int) $stateId);
                    if (Schema::hasTable('user_personal_details')) {
                        $sq->orWhereHas('personalDetail', fn($pq) => $pq->where('state_id', (int) $stateId));
                    }
                });
            }

            // Add listing count
            if (Schema::hasTable('dynamic_posts')) {
                $query->withCount(['assignedListings as total_listings' => function ($q) {
                    $q->where('status', 'published');
                }]);
            }

            $paginator = $query->latest('id')->paginate($perPage);

            $items = $paginator->getCollection()->map(fn(User $user) => $this->formatAgentCard($user));

            return response()->json([
                'status' => true,
                'message' => 'Agents fetched successfully.',
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                        'from' => $paginator->firstItem(),
                        'to' => $paginator->lastItem(),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch agents.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single agent details + active property listings (Guest/Public).
     *
     * GET /api/guest/agents/{agentId}
     * GET /api/frontend/agents/{agentId}
     *
     * @param string|int $agentId (Can be numeric user ID or unique_id like AGT001)
     */
    public function show(Request $request, string $agentId): JsonResponse
    {
        try {
            $agent = $this->resolveAgent($agentId);

            if (!$agent) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agent not found.',
                ], 404);
            }

            // 1. Format Agent Profile
            $agentProfile = $this->formatAgentDetails($agent);

            // 2. Fetch Agent's Property Listings
            $perPage = min(50, max(1, (int) $request->input('per_page', 9)));
            $listingsData = $this->fetchAgentListings($agent, $perPage);

            return response()->json([
                'status' => true,
                'message' => 'Agent details fetched successfully.',
                'data' => [
                    'agent' => $agentProfile,
                    'listings' => $listingsData,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch agent details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve agent User model by ID or unique_id.
     */
    private function resolveAgent(string $agentId): ?User
    {
        $query = User::query()
            ->with([
                'role',
                'personalDetail.city',
                'personalDetail.state',
                'personalDetail.country',
                'businessDetail.city',
                'businessDetail.state',
                'businessDetail.country',
                'userDetail.city',
                'userDetail.state',
                'userDetail.country',
                'city',
                'state',
                'country',
            ]);

        if (is_numeric($agentId)) {
            $user = (clone $query)->where('id', (int) $agentId)->first();
            if ($user) {
                return $user;
            }
        }

        // Try unique_id or user_name
        return $query->where('unique_id', $agentId)
            ->orWhere('user_name', $agentId)
            ->first();
    }

    /**
     * Format agent detailed profile.
     */
    private function formatAgentDetails(User $agent): array
    {
        $personal = $agent->personalDetail ?? $agent->userDetail;
        $business = $agent->businessDetail ?? $agent->userDetail;

        $profilePhoto = $this->resolveProfilePhoto($agent, $personal);
        $companyLogo = $this->resolveMediaUrl($business?->company_logo ?? null);

        $countryName = $personal?->country?->name ?? $agent->country?->name ?? null;
        $stateName = $personal?->state?->name ?? $agent->state?->name ?? null;
        $cityName = $personal?->city?->name ?? $agent->city?->name ?? null;

        $address = $personal?->address
            ?? $personal?->street_address
            ?? $business?->business_address
            ?? $agent->userDetail?->address
            ?? null;

        $fullAddress = implode(', ', array_filter([
            $personal?->area_locality,
            $cityName,
            $stateName,
            $countryName,
        ]));

        // Calculate Listing Stats
        $stats = $this->calculateAgentStats($agent);

        return [
            'id' => (int) $agent->id,
            'unique_id' => $agent->unique_id ?? null,
            'first_name' => $agent->first_name ?? '',
            'last_name' => $agent->last_name ?? '',
            'fullname' => $agent->fullname ?: trim($agent->first_name . ' ' . $agent->last_name),
            'email' => $agent->email ?? '',
            'phone' => $agent->phone ?? '',
            'alternate_number' => $personal?->alternate_number ?? null,
            'role_id' => $agent->role_id ? (int) $agent->role_id : null,
            'role_name' => $agent->role?->name ?? 'agent',
            'profile_photo' => $profilePhoto,
            'about' => $personal?->about_us ?? $business?->about_business ?? null,
            'is_approved' => (bool) ($agent->isapproved ?? 1),
            'is_verified' => (int) ($agent->kyc ?? 0) === 2, // 2 = Approved KYC
            'created_at' => $agent->created_at?->toIso8601String(),

            'business' => [
                'name' => $business?->business_name ?? $agent->userDetail?->bussiness_name ?? null,
                'address' => $business?->business_address ?? $agent->userDetail?->bussiness_address ?? null,
                'email' => $business?->business_email ?? $agent->userDetail?->bussiness_email ?? null,
                'phone' => $business?->business_phone ?? $agent->userDetail?->business_phone ?? null,
                'logo' => $companyLogo,
                'license_number' => $business?->license_number ?? $agent->userDetail?->license_number ?? null,
                'rera_number' => $business?->rera_number ?? $agent->userDetail?->rera_number ?? null,
                'about' => $business?->about_business ?? null,
            ],

            'location' => [
                'country_id' => $personal?->country_id ? (int) $personal->country_id : ($agent->country_id ? (int) $agent->country_id : null),
                'country_name' => $countryName,
                'state_id' => $personal?->state_id ? (int) $personal->state_id : ($agent->state_id ? (int) $agent->state_id : null),
                'state_name' => $stateName,
                'city_id' => $personal?->city_id ? (int) $personal->city_id : ($agent->city_id ? (int) $agent->city_id : null),
                'city_name' => $cityName,
                'area_locality' => $personal?->area_locality ?? null,
                'address' => $address,
                'full_address' => $fullAddress ?: $address,
                'pin_code' => $personal?->pin_code ?? $business?->business_pin_code ?? null,
            ],

            'stats' => $stats,
        ];
    }

    /**
     * Format lightweight agent card for listing.
     */
    private function formatAgentCard(User $agent): array
    {
        $personal = $agent->personalDetail ?? $agent->userDetail;
        $business = $agent->businessDetail ?? $agent->userDetail;

        return [
            'id' => (int) $agent->id,
            'unique_id' => $agent->unique_id ?? null,
            'first_name' => $agent->first_name ?? '',
            'last_name' => $agent->last_name ?? '',
            'fullname' => $agent->fullname ?: trim($agent->first_name . ' ' . $agent->last_name),
            'email' => $agent->email ?? '',
            'phone' => $agent->phone ?? '',
            'role_name' => $agent->role?->name ?? 'agent',
            'business_name' => $business?->business_name ?? $agent->userDetail?->bussiness_name ?? null,
            'profile_photo' => $this->resolveProfilePhoto($agent, $personal),
            'city_name' => $personal?->city?->name ?? $agent->city?->name ?? null,
            'state_name' => $personal?->state?->name ?? $agent->state?->name ?? null,
            'is_verified' => (int) ($agent->kyc ?? 0) === 2,
            'total_listings' => (int) ($agent->total_listings ?? 0),
        ];
    }

    /**
     * Fetch active property listings for the agent.
     */
    private function fetchAgentListings(User $agent, int $perPage): array
    {
        if (!Schema::hasTable('dynamic_posts')) {
            return [
                'items' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        $query = DynamicPost::query()
            ->leftJoin('countries as guest_countries', 'guest_countries.id', '=', 'dynamic_posts.country_id')
            ->leftJoin('states as guest_states', 'guest_states.id', '=', 'dynamic_posts.state_id')
            ->leftJoin('cities as guest_cities', 'guest_cities.id', '=', 'dynamic_posts.city_id')
            ->select([
                'dynamic_posts.id',
                'dynamic_posts.post_type_id',
                'dynamic_posts.listing_code',
                'dynamic_posts.country_id',
                'dynamic_posts.state_id',
                'dynamic_posts.city_id',
                'dynamic_posts.area_locality',
                'dynamic_posts.title',
                'dynamic_posts.slug',
                'dynamic_posts.excerpt',
                'dynamic_posts.content',
                'dynamic_posts.featured_image_id',
                'dynamic_posts.gallery_image_ids',
                'dynamic_posts.status',
                'dynamic_posts.live_status',
                'dynamic_posts.availability_status',
                'dynamic_posts.author_id',
                'dynamic_posts.published_at',
                'dynamic_posts.created_at',
                'dynamic_posts.updated_at',
            ])
            ->addSelect([
                'guest_countries.name as guest_country_name',
                'guest_states.name as guest_state_name',
                'guest_cities.name as guest_city_name',
            ])
            ->with([
                'postType',
                'taxonomyTerms.taxonomy',
            ])
            ->where('dynamic_posts.author_id', (int) $agent->id)
            ->where('dynamic_posts.status', 'published');

        if (Schema::hasColumn('dynamic_posts', 'live_status')) {
            $query->where(function ($lq) {
                $lq->where('dynamic_posts.live_status', 'approve')
                    ->orWhere('dynamic_posts.live_status', 'approved')
                    ->orWhereNull('dynamic_posts.live_status');
            });
        }

        $paginator = $query->latest('dynamic_posts.published_at')
            ->latest('dynamic_posts.id')
            ->paginate($perPage);

        // Attach media & promotions for cards
        $this->attachMediaAndPromotions($paginator);

        $items = GuestDynamicPostCardResource::collection($paginator->getCollection())->resolve(request());

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Batch attach featured media and promotions so GuestDynamicPostCardResource renders smoothly.
     */
    private function attachMediaAndPromotions($paginator): void
    {
        $posts = $paginator->getCollection();
        if ($posts->isEmpty()) {
            return;
        }

        // 1. Featured Media
        $mediaIds = $posts->pluck('featured_image_id')->filter()->map(fn($id) => (int) $id)->unique()->values()->all();

        $mediaMap = empty($mediaIds) ? collect() : MediaFile::query()->whereIn('id', $mediaIds)->get()->keyBy('id');

        $posts->each(function (DynamicPost $post) use ($mediaMap) {
            $post->setAttribute(
                '_guest_featured_media',
                !empty($post->featured_image_id) ? $mediaMap->get((int) $post->featured_image_id) : null
            );
        });

        // 2. Featured Promotions
        if (Schema::hasTable('property_featured_promotions')) {
            $now = Carbon::now();
            $postIds = $posts->pluck('id')->all();

            $promotions = PropertyFeaturedPromotion::query()
                ->whereIn('dynamic_post_id', $postIds)
                ->whereNull('cancelled_at')
                ->where('status', PropertyFeaturedPromotion::STATUS_ACTIVE)
                ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->orderByDesc('priority')
                ->get()
                ->groupBy('dynamic_post_id')
                ->map(fn($items) => $items->first());

            $posts->each(function (DynamicPost $post) use ($promotions) {
                $post->setAttribute('_guest_featured_promotion', $promotions->get((int) $post->id));
            });
        }
    }

    /**
     * Calculate listing stats for the agent.
     */
    private function calculateAgentStats(User $agent): array
    {
        if (!Schema::hasTable('dynamic_posts')) {
            return ['total_listings' => 0, 'for_sale_count' => 0, 'for_rent_count' => 0];
        }

        $baseQuery = DynamicPost::query()
            ->where('author_id', (int) $agent->id)
            ->where('status', 'published');

        $totalListings = (clone $baseQuery)->count();

        // Check rent vs sale taxonomies
        $forSaleCount = (clone $baseQuery)
            ->whereHas('taxonomyTerms', fn($q) => $q->whereIn('slug', ['sale', 'buy', 'for-sale', 'resale']))
            ->count();

        $forRentCount = (clone $baseQuery)
            ->whereHas('taxonomyTerms', fn($q) => $q->whereIn('slug', ['rent', 'lease', 'for-rent']))
            ->count();

        return [
            'total_listings' => $totalListings,
            'for_sale_count' => $forSaleCount,
            'for_rent_count' => $forRentCount,
        ];
    }

    /**
     * Resolve agent role IDs.
     */
    private function resolveAgentRoleIds(): array
    {
        if (!Schema::hasTable('roles')) {
            return [];
        }

        return Role::query()
            ->where(function ($q) {
                $q->where('name', 'agent')
                    ->orWhere('name', 'like', '%agent%')
                    ->orWhere('role_name', 'like', '%agent%')
                    ->orWhere('slug', 'like', '%agent%');
            })
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }

    /**
     * Helper to resolve profile photo URL.
     */
    private function resolveProfilePhoto(User $user, mixed $detail): ?string
    {
        $raw = $detail?->profile_photo
            ?? $detail?->photo
            ?? $user->profile_photo
            ?? $user->avatar
            ?? $user->image
            ?? null;

        return $this->resolveMediaUrl($raw);
    }

    /**
     * Helper to ensure absolute media URL.
     */
    private function resolveMediaUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $cleanPath = ltrim($path, '/');

        if (str_starts_with($cleanPath, 'storage/')) {
            return url($cleanPath);
        }

        if (Storage::disk('public')->exists($cleanPath)) {
            return Storage::disk('public')->url($cleanPath);
        }

        return url('storage/' . $cleanPath);
    }
}
