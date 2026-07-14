<?php

namespace App\Services;

use App\Models\CustomFieldValue;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FrontendListingService
{
    public function getFormOptions(
        int|string $postTypeIdentifier
    ): array {
        $postType = $this->findPostType($postTypeIdentifier);

        $taxonomies = $this->getAllowedTaxonomiesForPostType(
            $postType
        );

        return [
            'post_type' => [
                'id' => (int) $postType->id,
                'name' => $postType->name,
                'slug' => $postType->slug,
            ],

            'taxonomy_fields' => $taxonomies
                ->map(function (Taxonomy $taxonomy) {
                    return $this->formatTaxonomyField($taxonomy);
                })
                ->values()
                ->toArray(),
        ];
    }

    public function create(
        array $validated,
        User $user
    ): DynamicPost {
        $postType = PostType::query()
            ->findOrFail($validated['post_type_id']);

        $taxonomyTermIds = $this->validateAndResolveTaxonomyTerms(
            $postType,
            $validated['taxonomies'] ?? []
        );

        $slug = $this->generateUniqueSlug(
            postTypeId: (int) $postType->id,
            title: $validated['title'],
            requestedSlug: $validated['slug'] ?? null
        );

        return DB::transaction(function () use (
            $validated,
            $postType,
            $user,
            $slug,
            $taxonomyTermIds
        ) {
            $postData = [
                'post_type_id' => (int) $postType->id,
                'title' => $validated['title'],
                'slug' => $slug,
                'excerpt' => $validated['excerpt'] ?? null,
                'content' => $validated['content'] ?? null,

                /*
                 * Frontend user cannot publish directly.
                 */
                'status' => config(
                    'frontend_listing.default_status',
                    'draft'
                ),

                'live_status' => config(
                    'frontend_listing.default_live_status',
                    'submit'
                ),

                'author_id' => (int) $user->id,
                'user_id' => (int) $user->id,
                'published_at' => null,
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
                    $this->generateListingCode($postType);
            }

            if (
                Schema::hasColumn(
                    'dynamic_posts',
                    'sort_order'
                )
            ) {
                $postData['sort_order'] = 0;
            }

            $post = DynamicPost::create($postData);

            $post->taxonomyTerms()->sync(
                $taxonomyTermIds
            );

            $this->assignUser($post, $user);

            $this->saveCustomFields(
                $post,
                $validated['custom_fields'] ?? []
            );

            return $post->fresh()->load([
                'postType',
                'taxonomyTerms.taxonomy',
            ]);
        });
    }

    public function formatListing(
        DynamicPost $post
    ): array {
        $post->loadMissing([
            'postType',
            'taxonomyTerms.taxonomy',
        ]);

        $allowedSlugs = $this->allowedTaxonomySlugs();

        $selectedTaxonomies = $post->taxonomyTerms
            ->filter(function (TaxonomyTerm $term) use ($allowedSlugs) {
                return $term->taxonomy
                    && in_array(
                        $term->taxonomy->slug,
                        $allowedSlugs,
                        true
                    );
            })
            ->groupBy(function (TaxonomyTerm $term) {
                return $term->taxonomy->slug;
            })
            ->map(function (
                Collection $terms,
                string $taxonomySlug
            ) {
                $taxonomy = $terms->first()->taxonomy;

                return [
                    'taxonomy_id' => (int) $taxonomy->id,
                    'taxonomy_name' => $taxonomy->name,
                    'taxonomy_slug' => $taxonomySlug,

                    'terms' => $terms
                        ->map(function (TaxonomyTerm $term) {
                            return [
                                'id' => (int) $term->id,
                                'name' => $term->name,
                                'slug' => $term->slug,
                            ];
                        })
                        ->values()
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return [
            'id' => (int) $post->id,
            'listing_code' => $post->listing_code ?? null,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt ?? null,
            'content' => $post->content ?? null,
            'status' => $post->status,
            'live_status' => $post->live_status,

            'post_type' => [
                'id' => (int) $post->postType->id,
                'name' => $post->postType->name,
                'slug' => $post->postType->slug,
            ],

            'selected_taxonomies' => $selectedTaxonomies,

            'created_at' => optional(
                $post->created_at
            )->toISOString(),
        ];
    }

    private function findPostType(
        int|string $identifier
    ): PostType {
        $postType = PostType::query()
            ->where(function ($query) use ($identifier) {
                if (is_numeric($identifier)) {
                    $query->where(
                        'id',
                        (int) $identifier
                    );
                }

                $query->orWhere(
                    'slug',
                    (string) $identifier
                );

                $query->orWhere(
                    'name',
                    (string) $identifier
                );
            })
            ->first();

        if (!$postType) {
            throw ValidationException::withMessages([
                'post_type' => [
                    'Post type not found.',
                ],
            ]);
        }

        return $postType;
    }

    private function getAllowedTaxonomiesForPostType(
        PostType $postType
    ): Collection {
        $allowedSlugs = $this->allowedTaxonomySlugs();

        return $postType
            ->taxonomies()
            ->wherePivot('status', true)
            ->where('taxonomies.status', true)
            ->whereIn('taxonomies.slug', $allowedSlugs)
            ->with([
                'terms' => function ($query) {
                    $query
                        ->where('status', true)
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
            ])
            ->orderBy('post_type_taxonomies.sort_order')
            ->orderBy('taxonomies.sort_order')
            ->get();
    }

    private function formatTaxonomyField(
        Taxonomy $taxonomy
    ): array {
        return [
            'taxonomy_id' => (int) $taxonomy->id,
            'name' => $taxonomy->name,
            'label' => $taxonomy->name,
            'slug' => $taxonomy->slug,
            'request_key' => sprintf(
                'taxonomies.%s',
                $taxonomy->slug
            ),

            'type' => 'select',

            /*
             * Change to true when multiple values should be accepted.
             */
            'multiple' => false,

            'required' => true,

            'options' => $taxonomy->terms
                ->map(function (TaxonomyTerm $term) {
                    return [
                        'id' => (int) $term->id,
                        'value' => (int) $term->id,
                        'label' => $term->name,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    private function validateAndResolveTaxonomyTerms(
        PostType $postType,
        array $submittedTaxonomies
    ): array {
        $allowedTaxonomies = $this
            ->getAllowedTaxonomiesForPostType($postType)
            ->keyBy('slug');

        $allowedSlugs = $this->allowedTaxonomySlugs();

        /*
         * Reject unexpected taxonomy keys.
         */
        $unexpectedSlugs = array_diff(
            array_keys($submittedTaxonomies),
            $allowedSlugs
        );

        if (!empty($unexpectedSlugs)) {
            throw ValidationException::withMessages([
                'taxonomies' => [
                    'Only Property Type, Purpose and Property Status are allowed.',
                ],
            ]);
        }

        $allTermIds = [];

        foreach ($allowedSlugs as $slug) {
            $taxonomy = $allowedTaxonomies->get($slug);

            if (!$taxonomy) {
                throw ValidationException::withMessages([
                    "taxonomies.{$slug}" => [
                        "The {$slug} taxonomy is not attached to this post type.",
                    ],
                ]);
            }

            $submittedTermIds = collect(
                $submittedTaxonomies[$slug] ?? []
            )
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            if (empty($submittedTermIds)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$slug}" => [
                        "{$taxonomy->name} is required.",
                    ],
                ]);
            }

            $validTermIds = TaxonomyTerm::query()
                ->where(
                    'taxonomy_id',
                    $taxonomy->id
                )
                ->whereIn('id', $submittedTermIds)
                ->where('status', true)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $invalidTermIds = array_diff(
                $submittedTermIds,
                $validTermIds
            );

            if (!empty($invalidTermIds)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$slug}" => [
                        'One or more selected taxonomy terms are invalid.',
                    ],
                ]);
            }

            $allTermIds = array_merge(
                $allTermIds,
                $validTermIds
            );
        }

        return array_values(
            array_unique($allTermIds)
        );
    }

    private function generateUniqueSlug(
        int $postTypeId,
        string $title,
        ?string $requestedSlug = null
    ): string {
        $baseSlug = Str::slug(
            $requestedSlug ?: $title
        );

        if ($baseSlug === '') {
            $baseSlug = 'listing';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            DynamicPost::query()
                ->where('post_type_id', $postTypeId)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateListingCode(
        PostType $postType
    ): string {
        $prefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]/',
                    '',
                    $postType->slug ?: $postType->name
                ),
                0,
                3
            )
        );

        $prefix = $prefix ?: 'LST';

        do {
            $listingCode = sprintf(
                '%s-%s',
                $prefix,
                strtoupper(Str::random(8))
            );
        } while (
            DynamicPost::query()
                ->where(
                    'listing_code',
                    $listingCode
                )
                ->exists()
        );

        return $listingCode;
    }

    private function assignUser(
        DynamicPost $post,
        User $user
    ): void {
        if (!Schema::hasTable('dynamic_post_user')) {
            return;
        }

        DB::table('dynamic_post_user')
            ->updateOrInsert(
                [
                    'dynamic_post_id' => $post->id,
                    'user_id' => $user->id,
                ],
                [
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
    }

    private function saveCustomFields(
        DynamicPost $post,
        array $customFields
    ): void {
        if (
            empty($customFields)
            || !Schema::hasTable('custom_field_values')
        ) {
            return;
        }

        foreach ($customFields as $fieldId => $value) {
            if (!is_numeric($fieldId)) {
                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_id' => (int) $fieldId,
                    'entity_type' => 'post',
                    'entity_id' => (int) $post->id,
                ],
                [
                    'value' => is_array($value)
                        ? json_encode($value)
                        : $value,
                ]
            );
        }
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