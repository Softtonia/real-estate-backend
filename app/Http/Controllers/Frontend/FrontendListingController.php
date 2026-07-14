<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FrontendListingController extends Controller
{
    private const LISTING_POST_TYPE_ID = 1;

    private const ALLOWED_TAXONOMY_SLUGS = [
        'property',
        'property-type',
        'purpose',
        'property-status',
    ];

    public function taxonomies(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'selected_term_ids' => [
                    'nullable',
                    'array',
                ],
                'selected_term_ids.*' => [
                    'integer',
                    'exists:taxonomy_terms,id',
                ],
            ]);

            $definedSlugs = self::ALLOWED_TAXONOMY_SLUGS;

            $selectedTermIds = collect(
                $request->input('selected_term_ids', [])
            )
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            $taxonomies = Taxonomy::query()
                ->select([
                    'id',
                    'name',
                    'slug',
                    'description',
                    'hierarchical',
                    'sort_order',
                ])
                ->whereIn('slug', $definedSlugs)
                ->where('status', true)
                ->with([
                    'terms' => function ($query) {
                        $query
                            ->select([
                                'id',
                                'taxonomy_id',
                                'name',
                                'slug',
                                'description',
                                'parent_id',
                                'relation_with_taxonomy_id',
                                'sort_order',
                            ])
                            ->where('status', true)
                            ->with([
                                'relationValues' => function ($relationQuery) {
                                    $relationQuery
                                        ->select([
                                            'taxonomy_terms.id',
                                            'taxonomy_terms.taxonomy_id',
                                            'taxonomy_terms.name',
                                            'taxonomy_terms.slug',
                                        ])
                                        ->where('taxonomy_terms.status', true)
                                        ->wherePivot('status', true);
                                },
                            ])
                            ->orderBy('sort_order')
                            ->orderBy('name');
                    },
                ])
                ->get()
                ->sortBy(function ($taxonomy) use ($definedSlugs) {
                    $position = array_search(
                        $taxonomy->slug,
                        $definedSlugs,
                        true
                    );

                    return $position === false
                        ? PHP_INT_MAX
                        : $position;
                })
                ->values()
                ->map(function ($taxonomy) use ($selectedTermIds) {
                    $terms = $taxonomy->terms;

                    $hasDependentTerms = $terms->contains(
                        function ($term) {
                            return !empty($term->relation_with_taxonomy_id) || $term->relationValues->isNotEmpty();
                        }
                    );

                    if ($hasDependentTerms) {
                        if (empty($selectedTermIds)) {
                            $terms = collect();
                        } else {
                            $terms = $terms
                                ->filter(function ($term) use (
                                    $selectedTermIds
                                ) {
                                    $relatedTermIds = $term
                                        ->relationValues
                                        ->pluck('id')
                                        ->map(fn($id) => (int) $id)
                                        ->values()
                                        ->toArray();

                                    if (empty($relatedTermIds)) {
                                        return false;
                                    }

                                    return count(
                                        array_intersect(
                                            $relatedTermIds,
                                            $selectedTermIds
                                        )
                                    ) > 0;
                                })
                                ->values();
                        }
                    }

                    $dependsOnTaxonomyIds = $taxonomy->terms
                        ->pluck('relation_with_taxonomy_id')
                        ->filter()
                        ->map(fn($id) => (int) $id)
                        ->unique()
                        ->values();

                    return [
                        'id' => (int) $taxonomy->id,
                        'name' => $taxonomy->name,
                        'label' => $taxonomy->name,
                        'slug' => $taxonomy->slug,
                        'description' => $taxonomy->description,
                        'hierarchical' => (bool) $taxonomy->hierarchical,

                        'is_dependent' => $hasDependentTerms,

                        'depends_on_taxonomy_ids' =>
                        $dependsOnTaxonomyIds,

                        'selected_term_ids' => $selectedTermIds,

                        'terms' => $terms
                            ->map(function ($term) {
                                return [
                                    'id' => (int) $term->id,
                                    'taxonomy_id' =>
                                    (int) $term->taxonomy_id,
                                    'name' => $term->name,
                                    'label' => $term->name,
                                    'value' => (int) $term->id,
                                    'slug' => $term->slug,
                                    'description' =>
                                    $term->description,

                                    'parent_id' => $term->parent_id
                                        ? (int) $term->parent_id
                                        : null,

                                    'relation_with_taxonomy_id' =>
                                    $term->relation_with_taxonomy_id
                                        ? (int) $term
                                            ->relation_with_taxonomy_id
                                        : null,

                                    'relation_value_term_ids' =>
                                    $term->relationValues
                                        ->pluck('id')
                                        ->map(
                                            fn($id) => (int) $id
                                        )
                                        ->values(),
                                ];
                            })
                            ->values(),
                    ];
                });

            return response()->json([
                'status' => true,
                'message' =>
                'Listing taxonomies fetched successfully.',
                'data' => $taxonomies,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' =>
                'Unable to fetch listing taxonomies.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'slug' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'excerpt' => [
                    'nullable',
                    'string',
                ],

                'content' => [
                    'nullable',
                    'string',
                ],

                'featured_image_id' => [
                    'nullable',
                    'integer',
                ],

                'gallery_image_ids' => [
                    'nullable',
                    'array',
                ],

                'gallery_image_ids.*' => [
                    'integer',
                ],

                'taxonomies' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'taxonomies.*.taxonomy_id' => [
                    'required',
                    'integer',
                    'exists:taxonomies,id',
                ],

                'taxonomies.*.taxonomy_term_id' => [
                    'required',
                    'integer',
                    'exists:taxonomy_terms,id',
                ],
            ]);

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Authentication is required.',
                ], 401);
            }

            $selectedTerms = $this->validateSelectedTaxonomies(
                $validated['taxonomies']
            );

            $slug = $this->generateUniqueSlug(
                $validated['slug'] ?? $validated['title']
            );

            $post = DB::transaction(function () use (
                $validated,
                $user,
                $slug,
                $selectedTerms
            ) {
                $postData = [
                    'post_type_id' => self::LISTING_POST_TYPE_ID,
                    'title' => $validated['title'],
                    'slug' => $slug,
                    'excerpt' => $validated['excerpt'] ?? null,
                    'content' => $validated['content'] ?? null,
                    'status' => 'published',
                    'live_status' => 'published',
                    'published_at' => now(),
                    'author_id' => (int) $user->id,
                    'user_id' => (int) $user->id,
                ];

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
                        'sort_order'
                    )
                ) {
                    $postData['sort_order'] = 0;
                }

                $post = DynamicPost::query()->create($postData);

                $syncData = [];

                foreach ($selectedTerms as $selectedTerm) {
                    $syncData[$selectedTerm['term_id']] = [
                        'taxonomy_id' => $selectedTerm['taxonomy_id'],
                    ];
                }

                $post->taxonomyTerms()->sync($syncData);

                if (Schema::hasTable('dynamic_post_user')) {
                    DB::table('dynamic_post_user')->updateOrInsert(
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

                return $post;
            });

            $post->load([
                'taxonomyTerms.taxonomy',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Listing created successfully.',
                'data' => [
                    'id' => (int) $post->id,
                    'listing_code' => $post->listing_code ?? null,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'status' => $post->status,
                    'live_status' => $post->live_status,
                    'published_at' => $post->published_at,

                    'taxonomies' => $post->taxonomyTerms
                        ->groupBy('taxonomy_id')
                        ->map(function ($terms) {
                            $taxonomy = $terms->first()->taxonomy;

                            return [
                                'taxonomy_id' => (int) $taxonomy->id,
                                'taxonomy_name' => $taxonomy->name,
                                'taxonomy_slug' => $taxonomy->slug,

                                'terms' => $terms
                                    ->map(function ($term) {
                                        return [
                                            'id' => (int) $term->id,
                                            'name' => $term->name,
                                            'slug' => $term->slug,
                                        ];
                                    })
                                    ->values(),
                            ];
                        })
                        ->values(),
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create listing.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function validateSelectedTaxonomies(
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
                self::ALLOWED_TAXONOMY_SLUGS
            )
            ->where('status', true)
            ->get()
            ->keyBy('id');

        $selectedTerms = [];
        $submittedTaxonomyIds = [];

        foreach ($submittedTaxonomies as $index => $item) {
            $taxonomyId = (int) $item['taxonomy_id'];
            $termId = (int) $item['taxonomy_term_id'];

            if (in_array($taxonomyId, $submittedTaxonomyIds, true)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_id" => [
                        'This taxonomy has already been selected.',
                    ],
                ]);
            }

            $taxonomy = $allowedTaxonomies->get($taxonomyId);

            if (!$taxonomy) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_id" => [
                        'This taxonomy is not allowed for frontend listings.',
                    ],
                ]);
            }

            $term = TaxonomyTerm::query()
                ->select([
                    'id',
                    'taxonomy_id',
                    'name',
                    'slug',
                ])
                ->where('id', $termId)
                ->where('taxonomy_id', $taxonomyId)
                ->where('status', true)
                ->first();

            if (!$term) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_term_id" => [
                        'The selected term is inactive or does not belong to the selected taxonomy.',
                    ],
                ]);
            }

            $submittedTaxonomyIds[] = $taxonomyId;

            $selectedTerms[] = [
                'taxonomy_id' => $taxonomyId,
                'term_id' => (int) $term->id,
            ];
        }

        $requiredTaxonomyIds = $allowedTaxonomies
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        $missingTaxonomyIds = array_diff(
            $requiredTaxonomyIds,
            $submittedTaxonomyIds
        );

        if (!empty($missingTaxonomyIds)) {
            $missingNames = $allowedTaxonomies
                ->whereIn('id', $missingTaxonomyIds)
                ->pluck('name')
                ->implode(', ');

            throw ValidationException::withMessages([
                'taxonomies' => [
                    'Please select: ' . $missingNames . '.',
                ],
            ]);
        }

        return $selectedTerms;
    }

    private function generateUniqueSlug(string $value): string
    {
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
                self::LISTING_POST_TYPE_ID
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
}
