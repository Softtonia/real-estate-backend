<?php

namespace App\Services\Frontend;

use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\PropertyFeaturedPromotion;
use App\PageBuilder\Services\TemplateResolveService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GuestDynamicPostService
{
    public function __construct(
        private TemplateResolveService $templateResolveService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    |
    | GET /api/guest/posts/{postType}
    | GET /api/guest/posts/{postType}?featured=1
    |
    */

    public function paginate(
        string $postTypeSlug,
        array $filters = []
    ): array {
        $postType = $this->resolvePostType(
            $postTypeSlug
        );

        $featuredOnly = filter_var(
            $filters['featured'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $query = DynamicPost::query()
            ->leftJoin(
                'countries as guest_countries',
                'guest_countries.id',
                '=',
                'dynamic_posts.country_id'
            )
            ->leftJoin(
                'states as guest_states',
                'guest_states.id',
                '=',
                'dynamic_posts.state_id'
            )
            ->leftJoin(
                'cities as guest_cities',
                'guest_cities.id',
                '=',
                'dynamic_posts.city_id'
            )
            ->select(
                $this->listingSelectColumns()
            )
            ->addSelect([
                'guest_countries.name as guest_country_name',
                'guest_states.name as guest_state_name',
                'guest_cities.name as guest_city_name',
            ])
            ->with([
                'taxonomyTerms.taxonomy',
            ])
            ->where(
                'dynamic_posts.post_type_id',
                (int) $postType->id
            );

        /*
        |--------------------------------------------------------------------------
        | Public Visibility
        |--------------------------------------------------------------------------
        */

        $this->applyPublicVisibility(
            $query
        );

        /*
        |--------------------------------------------------------------------------
        | Property Availability
        |--------------------------------------------------------------------------
        |
        | Only property-listing gets property-specific availability rules.
        |
        */

        if (
            (string) $postType->slug
            === 'property-listing'
        ) {
            $this->applyPropertyPublicAvailability(
                $query
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (!empty($filters['country_id'])) {
            $query->where('dynamic_posts.country_id', (int) $filters['country_id']);
        }

        if (!empty($filters['state_id'])) {
            $query->where('dynamic_posts.state_id', (int) $filters['state_id']);
        }

        if (!empty($filters['city_id'])) {
            $query->where('dynamic_posts.city_id', (int) $filters['city_id']);
        }

        if (!empty($filters['search'])) {
            $this->applySearch(
                $query,
                trim(
                    (string) $filters['search']
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Featured Only
        |--------------------------------------------------------------------------
        */

        if ($featuredOnly) {
            $this->applyCurrentlyFeaturedFilter(
                $query
            );

            /*
             * Add current promotion priority as a scalar
             * subquery so featured results can be sorted
             * without duplicating DynamicPost rows.
             */
            $this->addFeaturedPrioritySelect(
                $query
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        if ($featuredOnly) {
            $query->orderByDesc(
                'guest_featured_priority'
            );
        }

        $sortBy = $filters['sort_by']
            ?? 'latest';

        match ($sortBy) {
            'oldest' =>
            $query
                ->orderBy(
                    'dynamic_posts.published_at',
                    'asc'
                )
                ->orderBy(
                    'dynamic_posts.id',
                    'asc'
                ),

            'title_asc' =>
            $query
                ->orderBy(
                    'dynamic_posts.title',
                    'asc'
                )
                ->orderByDesc(
                    'dynamic_posts.id'
                ),

            'title_desc' =>
            $query
                ->orderBy(
                    'dynamic_posts.title',
                    'desc'
                )
                ->orderByDesc(
                    'dynamic_posts.id'
                ),

            default =>
            $query
                ->orderByDesc(
                    'dynamic_posts.published_at'
                )
                ->orderByDesc(
                    'dynamic_posts.id'
                ),
        };

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = min(
            50,
            max(
                1,
                (int) (
                    $filters['per_page']
                    ?? 20
                )
            )
        );

        $paginator = $query->paginate(
            $perPage
        );

        /*
         * PostType already resolved once.
         *
         * Reuse the model for every listing instead
         * of querying post_types for each item.
         */
        $paginator
            ->getCollection()
            ->each(
                function (
                    DynamicPost $post
                ) use (
                    $postType
                ) {
                    $post->setRelation(
                        'postType',
                        $postType
                    );
                }
            );

        /*
         * Batch load promotion + image.
         *
         * No database queries inside Resources.
         */
        $this->attachCurrentFeaturedPromotions(
            $paginator
        );

        $this->attachFeaturedMedia(
            $paginator
        );

        return [
            'post_type' =>
            $postType,

            'featured_only' =>
            $featuredOnly,

            'paginator' =>
            $paginator,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Detail
    |--------------------------------------------------------------------------
    |
    | GET /api/guest/posts/{postType}/{dynamicPostId}
    |
    */

    public function detail(
        string $postTypeSlug,
        int $dynamicPostId
    ): array {
        $postType = $this->resolvePostType(
            $postTypeSlug
        );

        $query = DynamicPost::query()
            ->leftJoin(
                'countries as guest_countries',
                'guest_countries.id',
                '=',
                'dynamic_posts.country_id'
            )
            ->leftJoin(
                'states as guest_states',
                'guest_states.id',
                '=',
                'dynamic_posts.state_id'
            )
            ->leftJoin(
                'cities as guest_cities',
                'guest_cities.id',
                '=',
                'dynamic_posts.city_id'
            )
            ->select([
                'dynamic_posts.*',
                'guest_countries.name as guest_country_name',
                'guest_states.name as guest_state_name',
                'guest_cities.name as guest_city_name',
            ])
            ->where(
                'dynamic_posts.id',
                $dynamicPostId
            )
            ->where(
                'dynamic_posts.post_type_id',
                (int) $postType->id
            )
            ->with([
                'taxonomyTerms.taxonomy',

                'meta.customField.options',

                'meta.customField.repeaters.options',

                'parent',

                'children' => function ($childQuery) {
                    $childQuery
                        ->where(
                            'status',
                            'published'
                        )
                        ->where(
                            'live_status',
                            'approve'
                        );
                },
            ]);

        /*
        |--------------------------------------------------------------------------
        | Public visibility
        |--------------------------------------------------------------------------
        */

        $this->applyPublicVisibility(
            $query
        );

        /*
        |--------------------------------------------------------------------------
        | Property availability
        |--------------------------------------------------------------------------
        */

        if (
            (string) $postType->slug
            === 'property-listing'
        ) {
            $this->applyPropertyPublicAvailability(
                $query
            );
        }

        $post = $query->first();

        if (!$post) {
            abort(
                404,
                'Dynamic post not found.'
            );
        }

        /*
         * Prevent another PostType query.
         */
        $post->setRelation(
            'postType',
            $postType
        );

        /*
        |--------------------------------------------------------------------------
        | Hide non-public parent
        |--------------------------------------------------------------------------
        */

        if (
            $post->relationLoaded('parent')
            && $post->parent
            && (
                ($post->parent->status ?? null)
                !== 'published'
                || ($post->parent->live_status ?? null)
                !== 'approve'
            )
        ) {
            $post->setRelation(
                'parent',
                null
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Current Featured Promotion
        |--------------------------------------------------------------------------
        */

        $this->attachCurrentFeaturedPromotionToPost(
            $post
        );

        /*
        |--------------------------------------------------------------------------
        | Media
        |--------------------------------------------------------------------------
        */

        $this->attachDetailMedia(
            $post
        );

        /*
        |--------------------------------------------------------------------------
        | Repeater custom fields
        |--------------------------------------------------------------------------
        */

        $this->attachRepeaterValues(
            $post
        );

        /*
        |--------------------------------------------------------------------------
        | Template Builder
        |--------------------------------------------------------------------------
        */

        $template = $this->resolveFrontendTemplate(
            $post
        );

        return [
            'post' =>
            $post,

            'template' =>
            $template,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Post Type
    |--------------------------------------------------------------------------
    */

    private function resolvePostType(
        string $slug
    ): PostType {
        $slug = trim($slug);

        if ($slug === '') {
            abort(
                404,
                'Post type not found.'
            );
        }

        $postType = PostType::query()
            ->where(
                'slug',
                $slug
            )
            ->first();

        if (!$postType) {
            abort(
                404,
                'Post type not found.'
            );
        }

        return $postType;
    }

    /*
    |--------------------------------------------------------------------------
    | Listing columns
    |--------------------------------------------------------------------------
    */

    private function listingSelectColumns(): array
    {
        $columns = [
            'dynamic_posts.id',
            'dynamic_posts.post_type_id',
            'dynamic_posts.title',
            'dynamic_posts.slug',
            'dynamic_posts.created_at',
        ];

        foreach (
            [
                'listing_code',
                'excerpt',
                'country_id',
                'state_id',
                'city_id',
                'area_locality',
                'featured_image_id',
                'status',
                'live_status',
                'availability_status',
                'availability_public_until',
                'availability_hidden_at',
                'sold_at',
                'published_at',
            ] as $column
        ) {
            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    $column
                )
            ) {
                $columns[] =
                    'dynamic_posts.'
                    . $column;
            }
        }

        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | Public Visibility
    |--------------------------------------------------------------------------
    */

    private function applyPublicVisibility(
        Builder $query
    ): void {
        $query
            ->where(
                'dynamic_posts.status',
                'published'
            )
            ->where(
                'dynamic_posts.live_status',
                'approve'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Property-specific Availability
    |--------------------------------------------------------------------------
    */

    private function applyPropertyPublicAvailability(
        Builder $query
    ): void {
        if (
            !Schema::hasColumn(
                'dynamic_posts',
                'availability_status'
            )
        ) {
            return;
        }

        $now = now();

        $soldVisibleFrom = $now
            ->copy()
            ->subDays(7);

        $hasHiddenAt =
            Schema::hasColumn(
                'dynamic_posts',
                'availability_hidden_at'
            );

        $hasPublicUntil =
            Schema::hasColumn(
                'dynamic_posts',
                'availability_public_until'
            );

        $hasSoldAt =
            Schema::hasColumn(
                'dynamic_posts',
                'sold_at'
            );

        if ($hasHiddenAt) {
            $query->whereNull(
                'dynamic_posts.availability_hidden_at'
            );
        }

        $query->where(
            function (
                Builder $availabilityQuery
            ) use (
                $now,
                $soldVisibleFrom,
                $hasPublicUntil,
                $hasSoldAt
            ) {
                /*
                 * Available / Reserved
                 */
                $availabilityQuery->whereIn(
                    'dynamic_posts.availability_status',
                    [
                        'available',
                        'reserved',
                    ]
                );

                /*
                 * Sold listings are publicly visible
                 * for their configured window / 7 day fallback.
                 */
                if (
                    $hasPublicUntil
                    || $hasSoldAt
                ) {
                    $availabilityQuery->orWhere(
                        function (
                            Builder $soldQuery
                        ) use (
                            $now,
                            $soldVisibleFrom,
                            $hasPublicUntil,
                            $hasSoldAt
                        ) {
                            $soldQuery->where(
                                'dynamic_posts.availability_status',
                                'sold'
                            );

                            if (
                                $hasPublicUntil
                                && $hasSoldAt
                            ) {
                                $soldQuery->where(
                                    function (
                                        Builder $windowQuery
                                    ) use (
                                        $now,
                                        $soldVisibleFrom
                                    ) {
                                        $windowQuery
                                            ->where(
                                                'dynamic_posts.availability_public_until',
                                                '>',
                                                $now
                                            )
                                            ->orWhere(
                                                function (
                                                    Builder $fallbackQuery
                                                ) use (
                                                    $soldVisibleFrom
                                                ) {
                                                    $fallbackQuery
                                                        ->whereNull(
                                                            'dynamic_posts.availability_public_until'
                                                        )
                                                        ->whereNotNull(
                                                            'dynamic_posts.sold_at'
                                                        )
                                                        ->where(
                                                            'dynamic_posts.sold_at',
                                                            '>=',
                                                            $soldVisibleFrom
                                                        );
                                                }
                                            );
                                    }
                                );

                                return;
                            }

                            if ($hasPublicUntil) {
                                $soldQuery->where(
                                    'dynamic_posts.availability_public_until',
                                    '>',
                                    $now
                                );

                                return;
                            }

                            $soldQuery
                                ->whereNotNull(
                                    'dynamic_posts.sold_at'
                                )
                                ->where(
                                    'dynamic_posts.sold_at',
                                    '>=',
                                    $soldVisibleFrom
                                );
                        }
                    );
                }
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    private function applySearch(
        Builder $query,
        string $search
    ): void {
        if ($search === '') {
            return;
        }

        $query->where(
            function (
                Builder $searchQuery
            ) use (
                $search
            ) {
                $searchQuery
                    ->where(
                        'dynamic_posts.title',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'dynamic_posts.slug',
                        'like',
                        "%{$search}%"
                    );

                if (
                    Schema::hasColumn(
                        'dynamic_posts',
                        'listing_code'
                    )
                ) {
                    $searchQuery->orWhere(
                        'dynamic_posts.listing_code',
                        'like',
                        "%{$search}%"
                    );
                }

                if (
                    Schema::hasColumn(
                        'dynamic_posts',
                        'excerpt'
                    )
                ) {
                    $searchQuery->orWhere(
                        'dynamic_posts.excerpt',
                        'like',
                        "%{$search}%"
                    );
                }
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Featured Filter
    |--------------------------------------------------------------------------
    */

    private function applyCurrentlyFeaturedFilter(
        Builder $query
    ): void {
        $now = now();

        $query->whereExists(
            function ($promotionQuery) use ($now) {
                $promotionQuery
                    ->selectRaw('1')
                    ->from('property_featured_promotions as pfp')
                    ->whereColumn(
                        'pfp.dynamic_post_id',
                        'dynamic_posts.id'
                    )
                    ->whereNull(
                        'pfp.cancelled_at'
                    )
                    ->where(
                        'pfp.status',
                        PropertyFeaturedPromotion::STATUS_ACTIVE
                    )
                    ->where(
                        function ($startQuery) use ($now) {
                            $startQuery
                                ->whereNull('pfp.starts_at')
                                ->orWhere(
                                    'pfp.starts_at',
                                    '<=',
                                    $now
                                );
                        }
                    )
                    ->where(
                        function ($endQuery) use ($now) {
                            $endQuery
                                ->whereNull('pfp.ends_at')
                                ->orWhere(
                                    'pfp.ends_at',
                                    '>',
                                    $now
                                );
                        }
                    );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Featured priority
    |--------------------------------------------------------------------------
    */

    private function addFeaturedPrioritySelect(
        Builder $query
    ): void {
        $now = now();

        $priorityQuery =
            PropertyFeaturedPromotion::query()
            ->select('priority')
            ->whereColumn(
                'dynamic_post_id',
                'dynamic_posts.id'
            )
            ->whereNull(
                'cancelled_at'
            )
            ->where(
                'status',
                PropertyFeaturedPromotion::STATUS_ACTIVE
            )
            ->where(
                function ($startQuery) use ($now) {
                    $startQuery
                        ->whereNull('starts_at')
                        ->orWhere(
                            'starts_at',
                            '<=',
                            $now
                        );
                }
            )
            ->where(
                function ($endQuery) use ($now) {
                    $endQuery
                        ->whereNull('ends_at')
                        ->orWhere(
                            'ends_at',
                            '>',
                            $now
                        );
                }
            )
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit(1);

        $query->addSelect([
            'guest_featured_priority' =>
            $priorityQuery,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing Featured Promotions
    |--------------------------------------------------------------------------
    */

    private function attachCurrentFeaturedPromotions(
        LengthAwarePaginator $paginator
    ): void {
        $posts = $paginator->getCollection();

        if ($posts->isEmpty()) {
            return;
        }

        $postIds = $posts
            ->pluck('id')
            ->map(
                fn($id) => (int) $id
            )
            ->values()
            ->all();

        $now = now();

        $promotions =
            PropertyFeaturedPromotion::query()
            ->whereIn(
                'dynamic_post_id',
                $postIds
            )
            ->whereNull(
                'cancelled_at'
            )
            ->where(
                'status',
                PropertyFeaturedPromotion::STATUS_ACTIVE
            )
            ->where(
                function ($startQuery) use ($now) {
                    $startQuery
                        ->whereNull('starts_at')
                        ->orWhere(
                            'starts_at',
                            '<=',
                            $now
                        );
                }
            )
            ->where(
                function ($endQuery) use ($now) {
                    $endQuery
                        ->whereNull('ends_at')
                        ->orWhere(
                            'ends_at',
                            '>',
                            $now
                        );
                }
            )
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get([
                'id',
                'dynamic_post_id',
                'source',
                'status',
                'starts_at',
                'ends_at',
                'priority',
            ])
            ->groupBy('dynamic_post_id')
            ->map(
                fn($items) => $items->first()
            );

        $posts->each(
            function (
                DynamicPost $post
            ) use ($promotions) {
                $post->setAttribute(
                    '_guest_featured_promotion',
                    $promotions->get(
                        (int) $post->id
                    )
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Single Featured Promotion
    |--------------------------------------------------------------------------
    */

    private function attachCurrentFeaturedPromotionToPost(
        DynamicPost $post
    ): void {
        $now = now();

        $promotion =
            PropertyFeaturedPromotion::query()
            ->where(
                'dynamic_post_id',
                (int) $post->id
            )
            ->whereIn(
                'status',
                [
                    PropertyFeaturedPromotion::STATUS_ACTIVE,
                    PropertyFeaturedPromotion::STATUS_SCHEDULED,
                ]
            )
            ->where(
                'starts_at',
                '<=',
                $now
            )
            ->where(
                'ends_at',
                '>',
                $now
            )
            ->orderByDesc(
                'priority'
            )
            ->orderByDesc(
                'starts_at'
            )
            ->orderByDesc(
                'id'
            )
            ->first([
                'id',
                'dynamic_post_id',
                'source',
                'status',
                'starts_at',
                'ends_at',
                'priority',
            ]);

        $post->setAttribute(
            '_guest_featured_promotion',
            $promotion
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Listing Featured Image
    |--------------------------------------------------------------------------
    */

    private function attachFeaturedMedia(
        LengthAwarePaginator $paginator
    ): void {
        $posts =
            $paginator->getCollection();

        if ($posts->isEmpty()) {
            return;
        }

        $mediaIds = $posts
            ->pluck(
                'featured_image_id'
            )
            ->filter()
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->values()
            ->all();

        $mediaMap = empty($mediaIds)
            ? collect()
            : MediaFile::query()
            ->whereIn(
                'id',
                $mediaIds
            )
            ->get()
            ->keyBy(
                'id'
            );

        $posts->each(
            function (
                DynamicPost $post
            ) use (
                $mediaMap
            ) {
                $post->setAttribute(
                    '_guest_featured_media',
                    !empty($post->featured_image_id)
                        ? $mediaMap->get(
                            (int) $post
                                ->featured_image_id
                        )
                        : null
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Detail Media
    |--------------------------------------------------------------------------
    */

    private function attachDetailMedia(
        DynamicPost $post
    ): void {
        $featuredImageId =
            !empty($post->featured_image_id)
            ? (int) $post->featured_image_id
            : null;

        $galleryIds =
            $this->normalizeMediaIds(
                $post->gallery_image_ids
                    ?? null
            );

        $customFieldMediaIds = [];

        foreach (
            $post->meta ?? collect()
            as $meta
        ) {
            $fieldType =
                $meta->customField
                ?->field_type;

            if (
                !in_array(
                    $fieldType,
                    [
                        'media',
                        'file',
                    ],
                    true
                )
            ) {
                continue;
            }

            $customFieldMediaIds =
                array_merge(
                    $customFieldMediaIds,
                    $this->extractMediaIds(
                        $meta->value_json
                            ?? null
                    )
                );
        }

        $allMediaIds =
            collect(
                array_merge(
                    $featuredImageId
                        ? [$featuredImageId]
                        : [],
                    $galleryIds,
                    $customFieldMediaIds
                )
            )
            ->filter()
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->values()
            ->all();

        $mediaMap =
            empty($allMediaIds)
            ? collect()
            : MediaFile::query()
            ->whereIn(
                'id',
                $allMediaIds
            )
            ->get()
            ->keyBy(
                'id'
            );

        $post->setAttribute(
            '_guest_media_map',
            $mediaMap
        );

        $post->setAttribute(
            '_guest_featured_media',
            $featuredImageId
                ? $mediaMap->get(
                    $featuredImageId
                )
                : null
        );

        $post->setAttribute(
            '_guest_gallery_media',
            collect(
                $galleryIds
            )
                ->map(
                    fn($id) =>
                    $mediaMap->get(
                        (int) $id
                    )
                )
                ->filter()
                ->values()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Media IDs
    |--------------------------------------------------------------------------
    */

    private function normalizeMediaIds(
        mixed $value
    ): array {
        if (
            $value === null
            || $value === ''
        ) {
            return [];
        }

        if (is_string($value)) {
            $decoded =
                json_decode(
                    $value,
                    true
                );

            if (
                json_last_error()
                === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $value = $decoded;
            } else {
                $value =
                    str_contains(
                        $value,
                        ','
                    )
                    ? explode(
                        ',',
                        $value
                    )
                    : [$value];
            }
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->flatten()
            ->filter(
                fn($id) =>
                is_numeric($id)
            )
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->values()
            ->all();
    }

    private function extractMediaIds(
        mixed $value
    ): array {
        if (
            $value === null
            || $value === ''
        ) {
            return [];
        }

        if (is_string($value)) {
            $decoded =
                json_decode(
                    $value,
                    true
                );

            if (
                json_last_error()
                !== JSON_ERROR_NONE
            ) {
                return is_numeric($value)
                    ? [(int) $value]
                    : [];
            }

            $value = $decoded;
        }

        if (!is_array($value)) {
            return is_numeric($value)
                ? [(int) $value]
                : [];
        }

        if (
            isset($value['media'])
            && is_array(
                $value['media']
            )
        ) {
            $value =
                $value['media'];
        }

        return collect($value)
            ->flatMap(function ($item) {
                if (is_numeric($item)) {
                    return [
                        (int) $item,
                    ];
                }

                if (!is_array($item)) {
                    return [];
                }

                $mediaId =
                    $item['id']
                    ?? $item['media_id']
                    ?? null;

                return is_numeric(
                    $mediaId
                )
                    ? [(int) $mediaId]
                    : [];
            })
            ->unique()
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Repeater Fields
    |--------------------------------------------------------------------------
    */

    private function attachRepeaterValues(
        DynamicPost $post
    ): void {
        $table =
            'custom_field_repeater_values';

        if (
            !Schema::hasTable($table)
            || !Schema::hasColumn(
                $table,
                'entity_id'
            )
            || !Schema::hasColumn(
                $table,
                'custom_field_id'
            )
        ) {
            $post->setAttribute(
                '_guest_repeater_values',
                collect()
            );

            return;
        }

        $query = DB::table(
            $table
        )
            ->where(
                'entity_id',
                (int) $post->id
            );

        if (
            Schema::hasColumn(
                $table,
                'entity_type'
            )
        ) {
            $query->where(
                'entity_type',
                'post'
            );
        }

        if (
            Schema::hasColumn(
                $table,
                'custom_field_id'
            )
        ) {
            $query->orderBy(
                'custom_field_id'
            );
        }

        if (
            Schema::hasColumn(
                $table,
                'row_index'
            )
        ) {
            $query->orderBy(
                'row_index'
            );
        }

        if (
            Schema::hasColumn(
                $table,
                'sort_order'
            )
        ) {
            $query->orderBy(
                'sort_order'
            );
        }

        $rows =
            $query->get();

        if ($rows->isEmpty()) {
            $post->setAttribute(
                '_guest_repeater_values',
                collect()
            );

            return;
        }

        $grouped = [];

        foreach ($rows as $row) {
            $customFieldId =
                (int) (
                    $row->custom_field_id
                    ?? 0
                );

            if ($customFieldId <= 0) {
                continue;
            }

            $rowIndex =
                (int) (
                    $row->row_index
                    ?? 0
                );

            $fieldSlug =
                $row->field_name_slug
                ?? null;

            if (!$fieldSlug) {
                continue;
            }

            $fieldValue =
                $row->field_meta_value
                ?? $row->value_string
                ?? $row->value_text
                ?? $row->value_number
                ?? $row->value_date
                ?? $row->value_datetime
                ?? $row->value_json
                ?? null;

            $grouped[$customFieldId][$rowIndex][$fieldSlug] =
                $this->decodeDatabaseValue(
                    $fieldValue
                );
        }

        $result =
            collect(
                $grouped
            )->map(
                function (
                    array $repeaterRows
                ) {
                    ksort(
                        $repeaterRows
                    );

                    return array_values(
                        $repeaterRows
                    );
                }
            );

        $post->setAttribute(
            '_guest_repeater_values',
            $result
        );
    }

    private function decodeDatabaseValue(
        mixed $value
    ): mixed {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed =
            trim($value);

        if ($trimmed === '') {
            return $value;
        }

        $decoded =
            json_decode(
                $trimmed,
                true
            );

        return json_last_error()
            === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Template Builder Resolution
    |--------------------------------------------------------------------------
    */

    private function resolveFrontendTemplate(
        DynamicPost $post
    ): mixed {
        try {
            return $this
                ->templateResolveService
                ->resolve([
                    'template_type' =>
                    'single_post',

                    'post_type_id' =>
                    (int) $post->post_type_id,

                    'post_type' =>
                    $post->postType?->slug,

                    'post_id' =>
                    (int) $post->id,

                    'dynamic_post_id' =>
                    (int) $post->id,

                    'id' =>
                    (int) $post->id,

                    'title' =>
                    $post->title
                        ?? null,

                    'slug' =>
                    $post->slug
                        ?? null,

                    'status' =>
                    $post->status
                        ?? null,

                    'taxonomy_term_ids' =>
                    $post->taxonomyTerms
                        ->pluck('id')
                        ->map(
                            fn($id) =>
                            (int) $id
                        )
                        ->values()
                        ->all(),

                    'render_for' =>
                    'frontend',
                ]);
        } catch (Throwable $e) {
            /*
             * Template failure must not make
             * a valid public DynamicPost disappear.
             */
            Log::warning(
                'Guest DynamicPost template resolution failed.',
                [
                    'dynamic_post_id' =>
                    (int) $post->id,

                    'post_type_id' =>
                    (int) $post->post_type_id,

                    'error' =>
                    $e->getMessage(),
                ]
            );

            return null;
        }
    }
}
