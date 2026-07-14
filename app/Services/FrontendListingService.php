<?php

namespace App\Services;

use App\Models\DynamicPost;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FrontendListingService
{
    /**
     * API 1:
     * Return only predefined active taxonomies.
     */
    public function getTaxonomies(): array
    {
        $allowedSlugs = $this->allowedTaxonomySlugs();

        $taxonomies = Taxonomy::query()
            ->select([
                'id',
                'name',
                'slug',
                'description',
                'hierarchical',
                'sort_order',
                'status',
            ])
            ->whereIn('slug', $allowedSlugs)
            ->where('status', true)
            ->get()
            ->sortBy(function (Taxonomy $taxonomy) use ($allowedSlugs) {
                $position = array_search(
                    $taxonomy->slug,
                    $allowedSlugs,
                    true
                );

                return $position === false
                    ? PHP_INT_MAX
                    : $position;
            })
            ->values();

        return [
            'count' => $taxonomies->count(),

            'taxonomies' => $taxonomies
                ->map(function (Taxonomy $taxonomy) {
                    return [
                        'id' => (int) $taxonomy->id,
                        'value' => (int) $taxonomy->id,
                        'name' => $taxonomy->name,
                        'label' => $taxonomy->name,
                        'slug' => $taxonomy->slug,
                        'description' => $taxonomy->description,
                        'hierarchical' => (bool) $taxonomy->hierarchical,
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    /**
     * API 2:
     * Return active terms for one selected allowed taxonomy.
     */
    public function getTerms(
        int|string $taxonomyIdentifier,
        ?string $search = null
    ): array {
        $allowedSlugs = $this->allowedTaxonomySlugs();

        $taxonomy = Taxonomy::query()
            ->select([
                'id',
                'name',
                'slug',
                'description',
                'hierarchical',
                'status',
            ])
            ->where('status', true)
            ->whereIn('slug', $allowedSlugs)
            ->where(function ($query) use ($taxonomyIdentifier) {
                if (is_numeric($taxonomyIdentifier)) {
                    $query->where(
                        'id',
                        (int) $taxonomyIdentifier
                    );
                } else {
                    $query->where(
                        'slug',
                        (string) $taxonomyIdentifier
                    );
                }
            })
            ->first();

        if (!$taxonomy) {
            throw ValidationException::withMessages([
                'taxonomy' => [
                    'Selected taxonomy is not available for frontend listings.',
                ],
            ]);
        }

        $terms = TaxonomyTerm::query()
            ->select([
                'id',
                'taxonomy_id',
                'parent_id',
                'relation_with_taxonomy_id',
                'name',
                'slug',
                'description',
                'sort_order',
                'status',
            ])
            ->where('taxonomy_id', $taxonomy->id)
            ->where('status', true)
            ->when(
                filled($search),
                function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                }
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'taxonomy' => [
                'id' => (int) $taxonomy->id,
                'name' => $taxonomy->name,
                'slug' => $taxonomy->slug,
                'hierarchical' => (bool) $taxonomy->hierarchical,
            ],

            'count' => $terms->count(),

            'terms' => $terms
                ->map(function (TaxonomyTerm $term) {
                    return [
                        'id' => (int) $term->id,
                        'value' => (int) $term->id,
                        'taxonomy_id' => (int) $term->taxonomy_id,
                        'parent_id' => $term->parent_id
                            ? (int) $term->parent_id
                            : null,
                        'relation_with_taxonomy_id' =>
                            $term->relation_with_taxonomy_id
                                ? (int) $term->relation_with_taxonomy_id
                                : null,
                        'name' => $term->name,
                        'label' => $term->name,
                        'slug' => $term->slug,
                        'description' => $term->description,
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    /**
     * API 3:
     * Create the listing without checking post-type taxonomy relationships.
     */
    public function createListing(
        array $validated,
        User $user
    ): DynamicPost {
        $selectedTerms = $this->validateAndResolveTerms(
            $validated['taxonomies']
        );

        $postTypeId = (int) config(
            'frontend_listing.post_type_id'
        );

        if ($postTypeId <= 0) {
            throw ValidationException::withMessages([
                'post_type_id' => [
                    'Frontend listing post type is not configured.',
                ],
            ]);
        }

        $slug = $this->generateUniqueSlug(
            $validated['slug'] ?? $validated['title'],
            $postTypeId
        );

        return DB::transaction(function () use (
            $validated,
            $user,
            $postTypeId,
            $slug,
            $selectedTerms
        ) {
            $postData = [
                'post_type_id' => $postTypeId,
                'title' => $validated['title'],
                'slug' => $slug,
                'excerpt' => $validated['excerpt'] ?? null,
                'content' => $validated['content'] ?? null,

                /*
                 * Listing will not be stored as draft.
                 */
                'status' => config(
                    'frontend_listing.status',
                    'published'
                ),

                'live_status' => config(
                    'frontend_listing.live_status',
                    'submit'
                ),

                'author_id' => (int) $user->id,
                'user_id' => (int) $user->id,
                'published_at' => now(),
            ];

            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    'featured_image_id'
                )
            ) {
                $postData['featured_image_id'] =
                    $validated['featured_image_id'] ?? null;
            }

            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    'gallery_image_ids'
                )
            ) {
                $postData['gallery_image_ids'] =
                    $validated['gallery_image_ids'] ?? [];
            }

            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    'listing_code'
                )
            ) {
                $postData['listing_code'] =
                    $this->generateListingCode();
            }

            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    'sort_order'
                )
            ) {
                $postData['sort_order'] = 0;
            }

            $post = DynamicPost::query()->create($postData);

            /*
             * Directly attach selected terms.
             *
             * No validation against:
             * post_type_taxonomies
             * PostType::taxonomies()
             * PostType::activeTaxonomies()
             */
            $this->syncTerms(
                $post,
                $selectedTerms
            );

            $this->assignListingUser(
                $post,
                $user
            );

            return $post->fresh()->load([
                'taxonomyTerms.taxonomy',
            ]);
        });
    }

    /**
     * Validate:
     *
     * - Taxonomy is one of allowed frontend taxonomies.
     * - Taxonomy is active.
     * - Term is active.
     * - Term belongs to the submitted taxonomy.
     *
     * It does not check post-type taxonomy assignments.
     */
    private function validateAndResolveTerms(
        array $submittedTaxonomies
    ): array {
        $allowedTaxonomies = Taxonomy::query()
            ->select([
                'id',
                'name',
                'slug',
            ])
            ->whereIn(
                'slug',
                $this->allowedTaxonomySlugs()
            )
            ->where('status', true)
            ->get()
            ->keyBy('id');

        if ($allowedTaxonomies->isEmpty()) {
            throw ValidationException::withMessages([
                'taxonomies' => [
                    'No frontend listing taxonomies are available.',
                ],
            ]);
        }

        $resolvedTerms = [];

        foreach ($submittedTaxonomies as $index => $item) {
            $taxonomyId = (int) (
                $item['taxonomy_id'] ?? 0
            );

            $taxonomy = $allowedTaxonomies->get(
                $taxonomyId
            );

            if (!$taxonomy) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_id" => [
                        'This taxonomy is not allowed for frontend listings.',
                    ],
                ]);
            }

            $termIds = [];

            if (!empty($item['taxonomy_term_id'])) {
                $termIds[] = (int) $item['taxonomy_term_id'];
            }

            if (!empty($item['taxonomy_term_ids'])) {
                foreach ($item['taxonomy_term_ids'] as $termId) {
                    if (is_numeric($termId)) {
                        $termIds[] = (int) $termId;
                    }
                }
            }

            $termIds = collect($termIds)
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            if (empty($termIds)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}" => [
                        "Please select a term for {$taxonomy->name}.",
                    ],
                ]);
            }

            $terms = TaxonomyTerm::query()
                ->select([
                    'id',
                    'taxonomy_id',
                    'name',
                    'slug',
                ])
                ->whereIn('id', $termIds)
                ->where(
                    'taxonomy_id',
                    $taxonomyId
                )
                ->where('status', true)
                ->get();

            $validTermIds = $terms
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $invalidTermIds = array_values(
                array_diff(
                    $termIds,
                    $validTermIds
                )
            );

            if (!empty($invalidTermIds)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_term_ids" => [
                        'Some selected terms are inactive or do not belong to the selected taxonomy.',
                    ],
                ]);
            }

            foreach ($terms as $term) {
                $resolvedTerms[] = [
                    'term_id' => (int) $term->id,
                    'taxonomy_id' => (int) $term->taxonomy_id,
                ];
            }
        }

        return collect($resolvedTerms)
            ->unique('term_id')
            ->values()
            ->toArray();
    }

    private function syncTerms(
        DynamicPost $post,
        array $selectedTerms
    ): void {
        $syncData = [];

        foreach ($selectedTerms as $selectedTerm) {
            $syncData[$selectedTerm['term_id']] = [
                'taxonomy_id' =>
                    $selectedTerm['taxonomy_id'],
            ];
        }

        $post->taxonomyTerms()->sync(
            $syncData
        );
    }

    private function assignListingUser(
        DynamicPost $post,
        User $user
    ): void {
        if (
            !Schema::hasTable(
                'dynamic_post_user'
            )
        ) {
            return;
        }

        DB::table('dynamic_post_user')
            ->updateOrInsert(
                [
                    'dynamic_post_id' => $post->id,
                    'user_id' => $user->id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
    }

    private function generateUniqueSlug(
        string $value,
        int $postTypeId
    ): string {
        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'listing';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            DynamicPost::query()
                ->where(
                    'post_type_id',
                    $postTypeId
                )
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateListingCode(): string
    {
        do {
            $code = 'LST-' . strtoupper(
                Str::random(8)
            );
        } while (
            DynamicPost::query()
                ->where('listing_code', $code)
                ->exists()
        );

        return $code;
    }

    private function allowedTaxonomySlugs(): array
    {
        return array_values(
            array_unique(
                config(
                    'frontend_listing.taxonomy_slugs',
                    [
                        'property-type',
                        'purpose',
                        'property-status',
                    ]
                )
            )
        );
    }
}