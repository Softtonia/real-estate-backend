<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\CustomFieldRepeater;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\TaxonomyTerm;
use App\Services\CustomFieldValueService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Throwable;
use App\Models\SiteSetting;
use App\Models\Keyword;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\UniqueID;
use App\Models\UserDetail;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Services\Membership\MembershipCreditService;
use App\Models\PropertyListingRevision;
use App\Services\PropertyVerification\PropertyWorkflowService;

class DynamicPostController extends Controller
{
    private array $postRelations = [
        'postType',
        'parent:id,post_type_id,title,slug,status,live_status',
        'taxonomyTerms.taxonomy',
        'meta.customField.options',
        'meta.customField.repeaters.options',
        'latestVerificationRevision',
    ];

    private array $singlePostRelations = [
        'postType',
        'parent:id,post_type_id,title,slug,status,live_status',
        'children:id,post_type_id,parent_id,title,slug,status,live_status',
        'taxonomyTerms.taxonomy',
        'meta.customField.options',
        'meta.customField.repeaters.options',
        'latestVerificationRevision',
    ];

    private function successResponse(string $message, mixed $data = null, int $statusCode = 200, array $extra = []): JsonResponse
    {
        $response = array_merge([
            'status' => true,
            'message' => $message,
        ], $extra);

        if (!is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    private function errorResponse(string $message, int $statusCode = 500, mixed $error = null, array $extra = []): JsonResponse
    {
        $response = array_merge([
            'status' => false,
            'message' => $message,
        ], $extra);

        if (!is_null($error)) {
            $response['error'] = $error;
        }

        return response()->json($response, $statusCode);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return $this->errorResponse('Validation failed.', 422, null, [
            'errors' => $e->errors(),
        ]);
    }

    private function databaseErrorResponse(QueryException $e, string $message): JsonResponse
    {
        return $this->errorResponse($message, 500, $e->getMessage(), [
            'error_type' => 'database_error',
        ]);
    }

    private function findDynamicPost(int|string $id): ?DynamicPost
    {
        return DynamicPost::where('id', $id)->first();
    }
    public function dropdownByPostType(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string'],
                'post_type_slug' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', 'string'],
                'live_status' => ['nullable', 'string'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            if (
                !$request->filled('post_type_id')
                && !$request->filled('post_type')
                && !$request->filled('post_type_slug')
            ) {
                throw ValidationException::withMessages([
                    'post_type_id' => ['post_type_id or post_type slug is required.'],
                ]);
            }

            $postType = PostType::query()
                ->where(function ($q) use ($request) {
                    if ($request->filled('post_type_id')) {
                        $q->where('id', (int) $request->post_type_id);
                    }

                    if ($request->filled('post_type_slug')) {
                        $q->orWhere('slug', $request->post_type_slug);
                    }

                    if ($request->filled('post_type')) {
                        $q->orWhere('slug', $request->post_type)
                            ->orWhere('name', $request->post_type);
                    }
                })
                ->first();

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $limit = (int) $request->get('limit', 100);
            $limit = max(1, min($limit, 500));

            $columns = ['id', 'post_type_id'];

            foreach (
                [
                    'title',
                    'slug',
                    'status',
                    'live_status',
                    'listing_code',
                    'parent_id',
                    'created_at',
                    'updated_at',
                ] as $column
            ) {
                if (Schema::hasColumn('dynamic_posts', $column)) {
                    $columns[] = $column;
                }
            }

            $query = DynamicPost::query()
                ->select($columns)
                ->where('post_type_id', $postType->id);

            if ($request->filled('status') && !in_array($request->status, ['all', 'All', '*'], true)) {
                $query->where('status', $request->status);
            }

            if ($request->filled('live_status') && !in_array($request->live_status, ['all', 'All', '*'], true)) {
                $query->where('live_status', $request->live_status);
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    if (Schema::hasColumn('dynamic_posts', 'title')) {
                        $q->where('title', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'slug')) {
                        $q->orWhere('slug', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                        $q->orWhere('listing_code', 'like', "%{$search}%");
                    }
                });
            }

            if (Schema::hasColumn('dynamic_posts', 'sort_order')) {
                $query->orderBy('sort_order', 'asc');
            }

            if (Schema::hasColumn('dynamic_posts', 'title')) {
                $query->orderBy('title', 'asc');
            } else {
                $query->orderBy('id', 'desc');
            }

            $posts = $query
                ->limit($limit)
                ->get();

            $options = $posts->map(function ($post) {
                $label = $post->title
                    ?? $post->slug
                    ?? ('Post #' . $post->id);

                if (!empty($post->listing_code)) {
                    $label = $post->listing_code . ' - ' . $label;
                }

                return [
                    'id' => (int) $post->id,
                    'value' => (int) $post->id,
                    'label' => (string) $label,

                    'title' => $post->title ?? null,
                    'slug' => $post->slug ?? null,
                    'listing_code' => $post->listing_code ?? null,
                    'display_id' => $post->listing_code ?? null,
                    'post_type_id' => (int) $post->post_type_id,
                    'status' => $post->status ?? null,
                    'live_status' => $post->live_status ?? null,
                ];
            })->values();

            return $this->successResponse('Dynamic post dropdown options fetched successfully.', [
                'post_type' => [
                    'id' => (int) $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                ],
                'count' => $options->count(),
                'options' => $options,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic post dropdown options.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic post dropdown options.', 500, $e->getMessage());
        }
    }
    private function normalizeIds(array|string|null $ids): array
    {
        if (is_null($ids) || $ids === '') {
            return [];
        }

        if (is_string($ids)) {
            $ids = trim($ids);

            if ($ids === '') {
                return [];
            }

            $decoded = json_decode($ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = str_contains($ids, ',') ? explode(',', $ids) : [$ids];
            }
        }

        return collect($ids)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type_slug' => ['nullable', 'string', 'exists:post_types,slug'],
                'post_type' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
                'live_status' => ['nullable', 'string'],
                'parent_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
                'search' => ['nullable', 'string', 'max:255'],
                'taxonomy_term_ids' => ['nullable'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($termIds);
            }

            $query = DynamicPost::query()
                ->with($this->postRelations)
                ->when($request->filled('post_type_id'), function ($q) use ($request) {
                    $q->where('post_type_id', $request->post_type_id);
                })
                ->when($request->filled('post_type_slug') || $request->filled('post_type'), function ($q) use ($request) {
                    $postTypeValue = $request->post_type_slug ?: $request->post_type;

                    $q->whereHas('postType', function ($postTypeQuery) use ($postTypeValue) {
                        $postTypeQuery->where('slug', $postTypeValue)
                            ->orWhere('name', $postTypeValue);
                    });
                })
                ->when($request->filled('status') && !in_array($request->status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('status', $request->status);
                })
                ->when($request->filled('live_status') && !in_array($request->live_status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('live_status', $request->live_status);
                })
                ->when($request->filled('parent_id'), function ($q) use ($request) {
                    $q->where('parent_id', $request->parent_id);
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;

                    $q->where(function ($subQuery) use ($search) {
                        $subQuery->where('title', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%")
                            ->orWhere('excerpt', 'like', "%{$search}%");
                    });
                })
                ->when(!empty($termIds), function ($q) use ($termIds) {
                    $q->whereHas('taxonomyTerms', function ($termQuery) use ($termIds) {
                        $termQuery->whereIn('taxonomy_terms.id', $termIds);
                    });
                })
                ->orderBy('sort_order', 'asc')
                ->latest();

            $perPage = (int) $request->get('per_page', 15);
            $posts = $query->paginate($perPage);

            $posts->getCollection()->transform(fn($post) => $this->formatDynamicPostResponse($post));

            return $this->successResponse('Dynamic posts fetched successfully.', $posts);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic posts.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic posts.', 500, $e->getMessage());
        }
    }


    public function keywordSuggestions(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string'],
                'post_type_slug' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            if (
                !$request->filled('post_type_id')
                && !$request->filled('post_type')
                && !$request->filled('post_type_slug')
            ) {
                throw ValidationException::withMessages([
                    'post_type' => ['post_type_id or post_type slug is required.'],
                ]);
            }

            if (
                !Schema::hasTable('keywords')
                || !Schema::hasTable('keyword_post_type')
            ) {
                return $this->successResponse('Keyword suggestions fetched successfully.', [
                    'count' => 0,
                    'options' => [],
                ]);
            }

            $postType = PostType::query()
                ->where(function ($q) use ($request) {
                    if ($request->filled('post_type_id')) {
                        $q->where('id', (int) $request->post_type_id);
                    }

                    if ($request->filled('post_type')) {
                        $q->orWhere('slug', $request->post_type)
                            ->orWhere('name', $request->post_type);
                    }

                    if ($request->filled('post_type_slug')) {
                        $q->orWhere('slug', $request->post_type_slug);
                    }
                })
                ->first();

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $limit = min((int) $request->get('limit', 20), 100);

            $query = Keyword::query()
                ->join('keyword_post_type', 'keywords.id', '=', 'keyword_post_type.keyword_id')
                ->where('keyword_post_type.post_type_id', $postType->id)
                ->where('keywords.status', 'active')
                ->selectRaw('MIN(keywords.id) as id, keywords.keyword')
                ->groupBy('keywords.keyword')
                ->orderBy('keywords.keyword', 'asc');

            if ($request->filled('search')) {
                $search = Keyword::normalizeKeyword((string) $request->search);
                $search = mb_strtolower(trim($search));

                if ($search !== '') {
                    // Starting words only
                    // Example search "lux" => luxury flat, luxury home
                    // It will NOT return "best luxury flat"
                    $query->whereRaw('LOWER(keywords.keyword) LIKE ?', [$search . '%']);
                }
            }

            $keywords = $query
                ->limit($limit)
                ->get();

            $options = $keywords->map(fn($keyword) => [
                'id' => (int) $keyword->id,
                'value' => $keyword->keyword,
                'label' => $keyword->keyword,
                'keyword' => $keyword->keyword,
            ])->values();

            return $this->successResponse('Keyword suggestions fetched successfully.', [
                'post_type' => [
                    'id' => (int) $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                ],
                'count' => $options->count(),
                'options' => $options,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch keyword suggestions.', 500, $e->getMessage());
        }
    }
    public function show(int|string $dynamicPost): JsonResponse
    {
        try {
            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse('Dynamic post not found.', 404, 'No dynamic post exists with this id.', [
                    'id' => $dynamicPost,
                ]);
            }

            $post->load($this->singlePostRelations);

            return $this->successResponse(
                'Dynamic post fetched successfully.',
                $this->formatDynamicPostResponse($post)
            );
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic post.', 500, $e->getMessage());
        }
    }

    public function update(Request $request, int|string $dynamicPost): JsonResponse
    {
        try {
            $this->normalizeDynamicPostRequest($request);

            $contentType = strtolower((string) $request->header('Content-Type', ''));

            if (
                in_array(strtoupper($request->getRealMethod()), ['PUT', 'PATCH'], true)
                && str_contains($contentType, 'multipart/form-data')
                && empty($request->all())
                && empty($request->allFiles())
            ) {
                throw ValidationException::withMessages([
                    'request' => [
                        'Multipart PUT/PATCH body was not parsed. Send this request as POST with _method=PUT while keeping the same update URL.',
                    ],
                ]);
            }

            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse(
                    'Dynamic post not found.',
                    404,
                    'No dynamic post exists with this id.',
                    ['id' => $dynamicPost]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Property admin status override
            |--------------------------------------------------------------------------
            |
            | Requirement:
            | - Admin does NOT need verifier assignment.
            | - Existing assigned user/verifier must remain unchanged.
            | - Admin must NEVER become assigned_to / assigned user automatically.
            | - Approve/reject still goes through PropertyWorkflowService so the
            |   latest verification revision and dynamic_posts stay in sync.
            |
            */
            $workflowAction = null;
            $workflowActor = null;
            $workflowReason = null;
            $workflowNotes = null;

            $postTypeSlug = DB::table('post_types')
                ->where('id', (int) $post->post_type_id)
                ->value('slug');

            $isPropertyListing =
                Str::slug((string) $postTypeSlug) === 'property-listing';

            $hasPropertyStatusPayload =
                $isPropertyListing
                && $request->hasAny([
                    'status',
                    'live_status',
                    'rejection_reason',
                    'rejected_by',
                    'rejected_at',
                ]);

            if ($hasPropertyStatusPayload) {
                $workflowActor = $this->resolveDynamicPostActor($request);

                if (
                    !$workflowActor
                    || !$this->isAdminOrSuperAdminUser($workflowActor)
                ) {
                    throw ValidationException::withMessages([
                        'verification' => [
                            'Use the property verification APIs to approve, reject or publish a property.',
                        ],
                    ]);
                }

                $requestedStatus = $request->filled('status')
                    ? strtolower(trim((string) $request->input('status')))
                    : null;

                $requestedLiveStatus = $request->filled('live_status')
                    ? strtolower(trim((string) $request->input('live_status')))
                    : null;

                if (
                    $requestedStatus === 'published'
                    && in_array(
                        $requestedLiveStatus,
                        ['reject', 'disapprove'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'verification' => [
                            'A property cannot be published and rejected in the same request.',
                        ],
                    ]);
                }

                if (
                    in_array(
                        $requestedStatus,
                        ['draft', 'private', 'archived'],
                        true
                    )
                    && $requestedLiveStatus === 'approve'
                ) {
                    throw ValidationException::withMessages([
                        'verification' => [
                            'A property cannot be approved with a non-published status in the same request.',
                        ],
                    ]);
                }

                /*
                 * Publish / Approve
                 */
                if (
                    $requestedStatus === 'published'
                    || $requestedLiveStatus === 'approve'
                ) {
                    $workflowAction = 'approve';
                    $workflowNotes = $request->filled('notes')
                        ? trim((string) $request->input('notes'))
                        : null;
                }

                /*
                 * Reject / Disapprove
                 */
                if (
                    in_array(
                        $requestedLiveStatus,
                        ['reject', 'disapprove'],
                        true
                    )
                ) {
                    $workflowReason = trim(
                        (string) $request->input('rejection_reason', '')
                    );

                    if ($workflowReason === '') {
                        throw ValidationException::withMessages([
                            'rejection_reason' => [
                                'Rejection reason is required when property is rejected.',
                            ],
                        ]);
                    }

                    $workflowAction = 'reject';
                }

                /*
                 * These are workflow states, not direct admin CRUD statuses.
                 */
                if (
                    in_array(
                        $requestedLiveStatus,
                        ['under_review', 'modify_review', 'submit'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'live_status' => [
                            'Use the property verification workflow to change this verification status.',
                        ],
                    ]);
                }

                /*
                 * Client is not allowed to set audit fields.
                 */
                $request->request->remove('rejected_by');
                $request->request->remove('rejected_at');

                /*
                 * For approve/reject, PropertyWorkflowService owns these fields.
                 * Remove them before normal DynamicPost validation/save so there
                 * is only one source of truth.
                 */
                if ($workflowAction !== null) {
                    $request->replace(
                        $request->except([
                            'status',
                            'live_status',
                            'rejection_reason',
                            'rejected_by',
                            'rejected_at',
                            'published_at',
                        ])
                    );
                }
            }

            $validated = $this->validatePost($request, true);

            /*
             * Non-workflow updates keep the existing normal visibility logic.
             */
            if ($workflowAction === null) {
                $validated = $this->normalizeLiveVisibilityStatus(
                    $validated,
                    $post
                );
            }

            if (
                in_array(
                    ($validated['live_status'] ?? null),
                    ['reject', 'disapprove'],
                    true
                )
                && empty($validated['rejection_reason'])
            ) {
                throw ValidationException::withMessages([
                    'rejection_reason' => [
                        'Rejection reason is required when listing is rejected or disapproved.',
                    ],
                ]);
            }

            $postTypeId = $validated['post_type_id']
                ?? $post->post_type_id;

            $postType = PostType::with('taxonomies')
                ->find($postTypeId);

            if (!$postType) {
                return $this->errorResponse(
                    'Post type not found.',
                    404
                );
            }

            $hasLocationPayload =
                $this->hasLocationPayload($validated);

            if ($hasLocationPayload) {
                $this->validatePostTypeLocationSupport($postType);

                $validated =
                    $this->prepareLocationForSave($validated);
            }

            $hasBaseMediaPayload =
                $request->request->has('featured_image')
                || $request->files->has('featured_image')
                || array_key_exists('featured_image_id', $validated)
                || $request->request->has('gallery_images')
                || $request->files->has('gallery_images')
                || array_key_exists('gallery_image_ids', $validated);

            if ($hasBaseMediaPayload) {
                $validated = $this->prepareBaseMediaForSave(
                    $request,
                    $validated,
                    $postType,
                    $post
                );
            }

            $hasTaxonomyPayload =
                array_key_exists('taxonomies', $validated)
                || array_key_exists('taxonomy_term_ids', $validated);

            $rawRequestData = $request->all();

            $hasRelationshipPayload =
                $this->hasRelationshipPayload($rawRequestData);

            $submittedTaxonomies =
                $validated['taxonomies'] ?? [];

            $relationshipPostTypes =
                $hasRelationshipPayload
                    ? $this->normalizeRelationshipPostTypeInputs(
                        $rawRequestData
                    )
                    : null;

            $taxonomyTermIds =
                $hasTaxonomyPayload
                    ? $this->normalizeSubmittedTaxonomyTermIds(
                        $validated
                    )
                    : null;

            $customFields =
                array_key_exists('custom_fields', $validated)
                    ? $this->prepareCustomFieldsForSave(
                        $request,
                        $validated,
                        $postType,
                        $post
                    )
                    : null;

            if (is_array($customFields)) {
                $customFields =
                    $this->appendMissingMediaCustomFieldDeletes(
                        $post,
                        $customFields
                    );
            }

            if ($hasTaxonomyPayload) {
                $this->validateSubmittedTaxonomyGroups(
                    $postType,
                    $submittedTaxonomies
                );

                $this->validateTaxonomyTermsForPostType(
                    $postType,
                    $taxonomyTermIds
                );

                $this->validateDependentTaxonomySelections(
                    $taxonomyTermIds
                );
            }

            if (is_array($customFields)) {
                $effectiveTermIds =
                    is_array($taxonomyTermIds)
                        ? $taxonomyTermIds
                        : $post->taxonomyTerms()
                            ->pluck('taxonomy_terms.id')
                            ->map(fn ($id) => (int) $id)
                            ->toArray();

                $this->validateSubmittedCustomFieldsForPostType(
                    $postType,
                    $effectiveTermIds,
                    $customFields
                );
            }

            if (is_array($relationshipPostTypes)) {
                $this->validateSubmittedRelationshipPostTypes(
                    $postType,
                    $relationshipPostTypes,
                    $post->id
                );
            }

            $hasKeywordPayload =
                $this->hasKeywordPayload($validated);

            if ($hasKeywordPayload) {
                $this->validatePostTypeKeywordSupport($postType);
            }

            $newSlug = $post->slug;

            if (
                array_key_exists('slug', $validated)
                && !empty($validated['slug'])
            ) {
                $newSlug = Str::slug($validated['slug']);
            } elseif (
                array_key_exists('title', $validated)
            ) {
                $newSlug = Str::slug($validated['title']);
            }

            if (empty($newSlug)) {
                $newSlug =
                    $post->slug ?: ('post-' . $post->id);
            }

            $slugExists = DynamicPost::query()
                ->where('post_type_id', $postTypeId)
                ->where('slug', $newSlug)
                ->where('id', '!=', $post->id)
                ->exists();

            if ($slugExists) {
                return $this->errorResponse(
                    'Dynamic post slug already exists.',
                    422,
                    null,
                    [
                        'errors' => [
                            'slug' => [
                                $newSlug . ' already exists for this post type.',
                            ],
                        ],
                    ]
                );
            }

            /*
             * Critical:
             * Approve/reject must never change listing assignment even when
             * the frontend sends user_id / assigned_user_id in the full form.
             */
            $hasAssignedUserPayload =
                $workflowAction === null
                    ? $this->hasAssignedUserPayload($request->all())
                    : false;

            DB::transaction(function () use (
                $post,
                $validated,
                $newSlug,
                $taxonomyTermIds,
                $customFields,
                $relationshipPostTypes,
                $hasAssignedUserPayload,
                $postType,
                $hasKeywordPayload,
                $workflowAction,
                $workflowActor,
                $workflowReason,
                $workflowNotes
            ) {
                $postData =
                    $this->dynamicPostPayloadForDatabase(
                        $validated
                    );

                $postData['slug'] = $newSlug;

                if (
                    array_key_exists('live_status', $postData)
                    && in_array(
                        $postData['live_status'],
                        ['reject', 'disapprove'],
                        true
                    )
                ) {
                    $postData['rejected_by'] = Auth::id();
                    $postData['rejected_at'] = now();

                    if (empty($postData['status'])) {
                        $postData['status'] = 'draft';
                    }
                }

                if (
                    array_key_exists('live_status', $postData)
                    && $postData['live_status'] === 'approve'
                ) {
                    $postData['rejection_reason'] = null;
                    $postData['rejected_by'] = null;
                    $postData['rejected_at'] = null;

                    if (empty($postData['status'])) {
                        $postData['status'] = 'published';
                    }
                }

                if (array_key_exists('status', $postData)) {
                    if (
                        $postData['status'] === 'published'
                        && Schema::hasColumn(
                            'dynamic_posts',
                            'published_at'
                        )
                        && empty($post->published_at)
                    ) {
                        $postData['published_at'] = now();
                    }

                    if (
                        $postData['status'] !== 'published'
                        && Schema::hasColumn(
                            'dynamic_posts',
                            'published_at'
                        )
                    ) {
                        $postData['published_at'] = null;
                    }
                }

                $post->forceFill($postData);
                $post->save();

                if (is_array($taxonomyTermIds)) {
                    $this->syncTaxonomyTerms(
                        $post,
                        $taxonomyTermIds
                    );
                }

                if (is_array($relationshipPostTypes)) {
                    $this->syncDynamicPostRelationships(
                        $post,
                        $relationshipPostTypes
                    );
                }

                if (is_array($customFields)) {
                    $this->saveCustomFieldValues(
                        $post->id,
                        'post',
                        $customFields
                    );
                }

                if ($hasAssignedUserPayload) {
                    $this->syncDynamicPostAssignedUser(
                        $post,
                        $validated
                    );
                }

                if ($hasKeywordPayload) {
                    $this->syncKeywordsForDynamicPost(
                        post: $post,
                        postType: $postType,
                        input: $this->getKeywordPayload(
                            $validated
                        )
                    );
                }

                /*
                 * Workflow decision is deliberately last.
                 * It updates publication + revision status but never assignment.
                 */
                if ($workflowAction === 'approve') {
                    app(PropertyWorkflowService::class)->approve(
                        property: $post->fresh(),
                        actor: $workflowActor,
                        notes: $workflowNotes
                    );
                } elseif ($workflowAction === 'reject') {
                    app(PropertyWorkflowService::class)->reject(
                        property: $post->fresh(),
                        actor: $workflowActor,
                        reason: (string) $workflowReason
                    );
                }
            });

            $freshPost = $post->fresh()
                ->load($this->postRelations);

            $this->clearDynamicPostCaches($freshPost);

            return $this->successResponse(
                'Dynamic post updated successfully.',
                $this->formatDynamicPostResponse(
                    $freshPost
                )
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse(
                $e,
                'Database error while updating dynamic post.'
            );
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Unable to update dynamic post.',
                500,
                $e->getMessage()
            );
        }
    }

    private function resolveDynamicPostActor(Request $request): ?User
    {
        $actor = $request->user();

        if ($actor instanceof User) {
            return $actor;
        }

        $actor = Auth::user();

        if ($actor instanceof User) {
            return $actor;
        }

        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if (
            !$token
            || !Schema::hasColumn('users', 'api_token')
        ) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }


    public function destroy(int|string $dynamicPost): JsonResponse
    {
        try {
            $post = $this->findDynamicPost($dynamicPost);

            if (!$post) {
                return $this->errorResponse('Dynamic post not found.', 404, 'No dynamic post exists with this id.', [
                    'id' => $dynamicPost,
                ]);
            }

            DB::transaction(function () use ($post) {
                CustomFieldValue::where('entity_type', 'post')
                    ->where('entity_id', $post->id)
                    ->delete();

                if (Schema::hasTable('custom_field_repeater_values')) {
                    DB::table('custom_field_repeater_values')
                        ->where('entity_type', 'post')
                        ->where('entity_id', $post->id)
                        ->delete();
                }

                if (Schema::hasTable('dynamic_post_relationships')) {
                    DB::table('dynamic_post_relationships')
                        ->where('dynamic_post_id', $post->id)
                        ->orWhere('related_post_id', $post->id)
                        ->delete();
                }
                if (Schema::hasTable('keyword_dynamic_post')) {
                    DB::table('keyword_dynamic_post')
                        ->where('dynamic_post_id', $post->id)
                        ->delete();
                }
                if (Schema::hasTable('dynamic_post_user')) {
                    DB::table('dynamic_post_user')
                        ->where('dynamic_post_id', $post->id)
                        ->delete();
                }
                $post->taxonomyTerms()->detach();
                $post->delete();
            });

            return $this->successResponse('Dynamic post deleted successfully.');
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete dynamic post.', 500, $e->getMessage());
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer'],
            ]);

            $ids = array_values(array_unique(array_map('intval', $request->ids)));

            $existingIds = DynamicPost::whereIn('id', $ids)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $missingIds = array_values(array_diff($ids, $existingIds));

            if (!empty($missingIds)) {
                return $this->errorResponse('Some dynamic posts were not found.', 404, 'One or more ids do not exist.', [
                    'missing_ids' => $missingIds,
                ]);
            }

            $deleted = DB::transaction(function () use ($existingIds) {
                CustomFieldValue::where('entity_type', 'post')
                    ->whereIn('entity_id', $existingIds)
                    ->delete();

                if (Schema::hasTable('custom_field_repeater_values')) {
                    DB::table('custom_field_repeater_values')
                        ->where('entity_type', 'post')
                        ->whereIn('entity_id', $existingIds)
                        ->delete();
                }

                if (Schema::hasTable('dynamic_post_relationships')) {
                    DB::table('dynamic_post_relationships')
                        ->whereIn('dynamic_post_id', $existingIds)
                        ->orWhereIn('related_post_id', $existingIds)
                        ->delete();
                }
                if (Schema::hasTable('keyword_dynamic_post')) {
                    DB::table('keyword_dynamic_post')
                        ->whereIn('dynamic_post_id', $existingIds)
                        ->delete();
                }

                DB::table('post_taxonomy_terms')
                    ->whereIn('dynamic_post_id', $existingIds)
                    ->delete();
                if (Schema::hasTable('dynamic_post_user')) {
                    DB::table('dynamic_post_user')
                        ->whereIn('dynamic_post_id', $existingIds)
                        ->delete();
                }
                return DynamicPost::whereIn('id', $existingIds)->delete();
            });

            return $this->successResponse('Selected dynamic posts deleted successfully.', null, 200, [
                'deleted_count' => $deleted,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while deleting selected dynamic posts.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to delete selected dynamic posts.', 500, $e->getMessage());
        }
    }

    public function byPostType(string $slug, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'status' => ['nullable', 'string'],
                'live_status' => ['nullable', 'string'],
            ]);

            $postType = PostType::where('slug', $slug)->first();

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404, 'No post type exists with this slug.', [
                    'slug' => $slug,
                ]);
            }

            $perPage = (int) $request->get('per_page', 15);

            $posts = DynamicPost::where('post_type_id', $postType->id)
                ->with($this->postRelations)
                ->when($request->filled('status') && !in_array($request->status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('status', $request->status);
                })
                ->when($request->filled('live_status') && !in_array($request->live_status, ['all', 'All', '*'], true), function ($q) use ($request) {
                    $q->where('live_status', $request->live_status);
                })
                ->orderBy('sort_order', 'asc')
                ->latest()
                ->paginate($perPage);

            $posts->getCollection()->transform(fn($post) => $this->formatDynamicPostResponse($post));

            return $this->successResponse('Dynamic posts fetched by post type successfully.', $posts, 200, [
                'post_type' => $postType,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching dynamic posts by post type.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic posts by post type.', 500, $e->getMessage());
        }
    }

    public function formOptions(Request $request, int|string $postType): JsonResponse
    {
        try {
            $request->validate([
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $selectedTermIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($selectedTermIds);
            }

            $postTypeData = PostType::with([
                'taxonomies' => function ($taxonomyQuery) {
                    $taxonomyQuery
                        ->select(
                            'taxonomies.id',
                            'taxonomies.name',
                            'taxonomies.slug',
                            'taxonomies.status',
                            'taxonomies.sort_order'
                        )
                        ->wherePivot('status', true)
                        ->where('taxonomies.status', true)
                        ->orderBy('post_type_taxonomies.sort_order', 'asc')
                        ->orderBy('taxonomies.id', 'asc');
                },
            ])
                ->where(function ($q) use ($postType) {
                    if (is_numeric($postType)) {
                        $q->where('id', (int) $postType);
                    }

                    $q->orWhere('slug', $postType)
                        ->orWhere('name', $postType);
                })
                ->first();

            if (!$postTypeData) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $supports = $this->normalizePostTypeSupports($postTypeData->supports ?? []);

            $taxonomyFields = $supports['taxonomies']
                ? $this->buildTaxonomyFields($postTypeData, $selectedTermIds)
                : collect();

            $customFields = $supports['custom_fields']
                ? $this->resolveVisibleCustomFields($postTypeData->id, $selectedTermIds)->map(fn($field) => $this->formatCustomField($field))->values()
                : collect();

            $relationshipPostTypes = $this->buildRelationshipPostTypeFields($postTypeData);

            return $this->successResponse(
                'Dynamic post form options fetched successfully.',
                [
                    'post_type' => [
                        'id' => $postTypeData->id,
                        'name' => $postTypeData->name,
                        'slug' => $postTypeData->slug,
                    ],
                    'supports' => $supports,
                    'base_fields' => $this->baseFields($supports),
                    'assigned_user_field' => $this->assignedUserField($postTypeData),
                    'relationship_post_types' => $relationshipPostTypes,
                    'taxonomy_fields' => $taxonomyFields,
                    'custom_fields_count' => $customFields->count(),
                    'custom_fields' => $customFields,
                    'status_options' => $this->statusOptions(),
                    'live_status_options' => $this->liveStatusOptions(),
                ]
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch dynamic post form options.', 500, $e->getMessage());
        }
    }
    private function assignedUserField(?PostType $postType = null): array
    {
        $queryString = $postType
            ? '?post_type_id=' . (int) $postType->id
            : '';

        return [
            'key' => 'user_id',
            'label' => 'Assigned User',
            'type' => 'select',
            'multiple' => false,
            'request_key' => 'user_id',
            'nullable' => true,

            // This API will now return only matched role users.
            'user_dropdown_api' => 'dynamic-post-assignment/users' . $queryString,

            // Optional role dropdown also filtered by post type.
            'role_dropdown_api' => 'dynamic-post-assignment/roles' . $queryString,

            'auto_assign_anonymous_if_empty' => true,

            'allowed_roles' => $postType
                ? $this->assignmentRoleNamesForPostTypeSlug((string) $postType->slug)
                : [],
        ];
    }
    private function assignmentRoleNamesForPostTypeSlug(?string $postTypeSlug): array
    {
        $postTypeSlug = strtolower(trim((string) $postTypeSlug));

        return match ($postTypeSlug) {
            'property-listing' => ['owner', 'agent', 'consultancy'],
            'project-listing' => ['company'],
            'developer-listing' => ['developer'],
            default => [],
        };
    }

    private function resolveAssignmentPostType(Request $request): ?PostType
    {
        if (
            !$request->filled('post_type_id')
            && !$request->filled('post_type')
            && !$request->filled('post_type_slug')
        ) {
            return null;
        }

        return PostType::query()
            ->where(function ($q) use ($request) {
                if ($request->filled('post_type_id')) {
                    $q->where('id', (int) $request->post_type_id);
                }

                if ($request->filled('post_type')) {
                    $q->orWhere('slug', $request->post_type)
                        ->orWhere('name', $request->post_type);
                }

                if ($request->filled('post_type_slug')) {
                    $q->orWhere('slug', $request->post_type_slug);
                }
            })
            ->first();
    }

    private function assignmentAllowedRoleIdsForPostType(?PostType $postType): array
    {
        if (!$postType || !Schema::hasTable('roles')) {
            return [];
        }

        $allowedRoleNames = $this->assignmentRoleNamesForPostTypeSlug((string) $postType->slug);

        if (empty($allowedRoleNames)) {
            return [];
        }

        $roleColumns = collect(['name', 'slug', 'role_name'])
            ->filter(fn(string $column) => Schema::hasColumn('roles', $column))
            ->values()
            ->toArray();

        if (empty($roleColumns)) {
            return [];
        }

        return DB::table('roles')
            ->where(function ($q) use ($roleColumns, $allowedRoleNames) {
                foreach ($roleColumns as $column) {
                    $q->orWhereIn(DB::raw("LOWER({$column})"), $allowedRoleNames);
                }
            })
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function emptyAssignmentDropdownResponse(string $message = 'Users fetched successfully.'): JsonResponse
    {
        return $this->successResponse($message, [
            'count' => 0,
            'options' => [],
        ]);
    }

    private function isAnonymousAssignmentUser(User $user): bool
    {
        return ($user->email ?? null) === 'anonymous@system.local'
            || ($user->name ?? null) === 'Anonymous User'
            || ($user->first_name ?? null) === 'Anonymous';
    }
    private function hasRelationshipPayload(array $input): bool
    {
        if (array_key_exists('relationship_post_types', $input) || array_key_exists('related_posts', $input)) {
            return true;
        }

        foreach (array_keys($input) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'relationship_post_types') || str_starts_with($key, 'related_posts')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRelationshipPostTypeInputs(array $inputData): array
    {
        $input = $inputData['relationship_post_types'] ?? $inputData['related_posts'] ?? [];

        if (is_string($input)) {
            $decoded = json_decode($input, true);
            $input = is_array($decoded) ? $decoded : [];
        }

        $extractIds = function (mixed $value) use (&$extractIds): array {
            if (is_null($value) || $value === '') {
                return [];
            }

            if (is_numeric($value)) {
                return [(int) $value];
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    return $extractIds($decoded);
                }

                return str_contains($value, ',')
                    ? collect(explode(',', $value))
                    ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->toArray()
                    : [];
            }

            if (!is_array($value)) {
                return [];
            }

            // Dropdown selected option object: { id: 18, value: 18, label: "Developer" }
            if (isset($value['id']) && is_numeric($value['id']) && !isset($value['post_type_id']) && !isset($value['related_post_type_id'])) {
                return [(int) $value['id']];
            }

            if (isset($value['value']) && is_numeric($value['value']) && !isset($value['post_type_id']) && !isset($value['related_post_type_id'])) {
                return [(int) $value['value']];
            }

            $ids = [];

            foreach ($value as $childKey => $child) {
                // Never treat post_type_id as related post id.
                if (in_array((string) $childKey, ['post_type_id', 'related_post_type_id'], true)) {
                    continue;
                }

                foreach ($extractIds($child) as $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        };

        $rawPostIds = [];

        // Normal JSON / nested array payloads.
        if (is_array($input)) {
            foreach ($input as $key => $item) {
                if (is_string($item)) {
                    $decodedItem = json_decode($item, true);
                    $item = is_array($decodedItem) ? $decodedItem : $item;
                }

                if (is_array($item)) {
                    foreach (['post_ids', 'related_post_ids', 'ids', 'post_id', 'related_post_id', 'selected_post_ids', 'selected_posts'] as $idKey) {
                        if (array_key_exists($idKey, $item)) {
                            $rawPostIds = array_merge($rawPostIds, $extractIds($item[$idKey]));
                            continue 2;
                        }
                    }
                }

                if (is_numeric($key)) {
                    $rawPostIds = array_merge($rawPostIds, $extractIds($item));
                }
            }
        }

        // Flat form-data payloads using dot keys, for example:
        // relationship_post_types.3.post_ids = [18, 19]
        // relationship_post_types.3.post_ids.0 = 18
        foreach ($inputData as $flatKey => $flatValue) {
            $flatKey = (string) $flatKey;

            if (!str_starts_with($flatKey, 'relationship_post_types') && !str_starts_with($flatKey, 'related_posts')) {
                continue;
            }

            if (str_contains($flatKey, 'post_type_id') || str_contains($flatKey, 'related_post_type_id')) {
                continue;
            }

            $looksLikePostIdsKey = str_contains($flatKey, 'post_ids')
                || str_contains($flatKey, 'related_post_ids')
                || str_contains($flatKey, 'selected_post_ids')
                || str_ends_with($flatKey, '.post_id')
                || str_ends_with($flatKey, '[post_id]')
                || str_ends_with($flatKey, '.related_post_id')
                || str_ends_with($flatKey, '[related_post_id]');

            if ($looksLikePostIdsKey) {
                $rawPostIds = array_merge($rawPostIds, $extractIds($flatValue));
            }
        }

        $rawPostIds = collect($rawPostIds)
            ->filter(fn($id) => $id !== null && $id !== '' && is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (empty($rawPostIds)) {
            return [];
        }

        // Important: do not trust frontend's post_type_id for relationships.
        // Always detect actual related post type from dynamic_posts table.
        $posts = DynamicPost::query()
            ->select('id', 'post_type_id')
            ->whereIn('id', $rawPostIds)
            ->get();

        $existingPostIds = $posts->pluck('id')->map(fn($id) => (int) $id)->toArray();
        $missingPostIds = array_values(array_diff($rawPostIds, $existingPostIds));

        if (!empty($missingPostIds)) {
            throw ValidationException::withMessages([
                'relationship_post_types' => [
                    'Invalid related post IDs: ' . implode(', ', $missingPostIds),
                ],
            ]);
        }

        return $posts
            ->groupBy('post_type_id')
            ->map(function ($posts, $postTypeId) {
                return [
                    'post_type_id' => (int) $postTypeId,
                    'post_ids' => $posts
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->toArray(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function validateSubmittedRelationshipPostTypes(PostType $postType, array $relationships, ?int $currentPostId = null): void
    {
        if (empty($relationships)) {
            return;
        }

        if (!Schema::hasTable('dynamic_post_relationships')) {
            throw ValidationException::withMessages([
                'relationship_post_types' => [
                    'dynamic_post_relationships table does not exist. Please run migration first.',
                ],
            ]);
        }

        $allowedPostTypeIds = $postType->activeRelatedPostTypes()
            ->pluck('post_types.id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        foreach ($relationships as $index => $relationship) {
            $relatedPostTypeId = (int) ($relationship['post_type_id'] ?? 0);
            $postIds = collect($relationship['post_ids'] ?? [])
                ->filter()
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            if (!$relatedPostTypeId || empty($postIds)) {
                continue;
            }

            if (!in_array($relatedPostTypeId, $allowedPostTypeIds, true)) {
                $relatedPostType = PostType::find($relatedPostTypeId);

                throw ValidationException::withMessages([
                    "relationship_post_types.{$index}.post_type_id" => [
                        ($relatedPostType?->name ?? 'Post type ' . $relatedPostTypeId)
                            . ' is not associated as a related post type with ' . $postType->name . '.',
                    ],
                ]);
            }

            if ($currentPostId && in_array((int) $currentPostId, $postIds, true)) {
                throw ValidationException::withMessages([
                    "relationship_post_types.{$index}.post_ids" => [
                        'A post cannot be related with itself.',
                    ],
                ]);
            }

            $actualRows = DynamicPost::query()
                ->select('id', 'post_type_id')
                ->whereIn('id', $postIds)
                ->get();

            $actualPostIds = $actualRows->pluck('id')->map(fn($id) => (int) $id)->toArray();
            $missingPostIds = array_values(array_diff($postIds, $actualPostIds));

            if (!empty($missingPostIds)) {
                throw ValidationException::withMessages([
                    "relationship_post_types.{$index}.post_ids" => [
                        'Invalid related post IDs: ' . implode(', ', $missingPostIds),
                    ],
                ]);
            }

            $wrongTypePostIds = $actualRows
                ->filter(fn($row) => (int) $row->post_type_id !== $relatedPostTypeId)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            if (!empty($wrongTypePostIds)) {
                throw ValidationException::withMessages([
                    "relationship_post_types.{$index}.post_ids" => [
                        'These posts were detected under a different post type: ' . implode(', ', $wrongTypePostIds),
                    ],
                ]);
            }
        }
    }

    private function syncDynamicPostRelationships(DynamicPost $post, array $relationships): void
    {
        if (!Schema::hasTable('dynamic_post_relationships')) {
            return;
        }

        DB::table('dynamic_post_relationships')
            ->where('dynamic_post_id', $post->id)
            ->delete();

        $now = now()->format('Y-m-d H:i:s');
        $insertRows = [];

        foreach ($relationships as $relationship) {
            $relatedPostTypeId = (int) ($relationship['post_type_id'] ?? 0);
            $postIds = collect($relationship['post_ids'] ?? [])
                ->filter()
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            foreach ($postIds as $relatedPostId) {
                $insertRows[] = [
                    'dynamic_post_id' => (int) $post->id,
                    'related_post_type_id' => $relatedPostTypeId,
                    'related_post_id' => $relatedPostId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($insertRows)) {
            DB::table('dynamic_post_relationships')->insert($insertRows);
        }
    }

    private function formatRelationshipPostTypesForFrontend(DynamicPost $post): array
    {
        if (!Schema::hasTable('dynamic_post_relationships')) {
            return [];
        }

        $relationshipRows = DB::table('dynamic_post_relationships')
            ->where('dynamic_post_id', $post->id)
            ->orderBy('related_post_type_id')
            ->orderBy('related_post_id')
            ->get();

        if ($relationshipRows->isEmpty()) {
            return [];
        }

        $postTypeIds = $relationshipRows->pluck('related_post_type_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $postIds = $relationshipRows->pluck('related_post_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $postTypes = PostType::whereIn('id', $postTypeIds)->get()->keyBy('id');
        $posts = DynamicPost::query()
            ->select('id', 'post_type_id', 'title', 'slug', 'status', 'live_status')
            ->whereIn('id', $postIds)
            ->get()
            ->keyBy('id');

        return $relationshipRows
            ->groupBy('related_post_type_id')
            ->map(function ($rows, $postTypeId) use ($postTypes, $posts) {
                $postType = $postTypes->get((int) $postTypeId);
                $selectedPosts = $rows
                    ->map(fn($row) => $posts->get((int) $row->related_post_id))
                    ->filter()
                    ->values();

                return [
                    'post_type_id' => (int) $postTypeId,
                    'post_type_name' => $postType?->name,
                    'post_type_slug' => $postType?->slug,
                    'selected_post_ids' => $selectedPosts->pluck('id')->map(fn($id) => (int) $id)->values()->toArray(),
                    'selected_posts' => $selectedPosts->map(fn($relatedPost) => [
                        'id' => (int) $relatedPost->id,
                        'title' => $relatedPost->title,
                        'slug' => $relatedPost->slug,
                        'status' => $relatedPost->status,
                        'live_status' => $relatedPost->live_status,
                    ])->values()->toArray(),
                ];
            })
            ->values()
            ->toArray();
    }

    private function buildRelationshipPostTypeFields(PostType $postType)
    {
        $relatedPostTypes = $postType->activeRelatedPostTypes()->get();

        if ($relatedPostTypes->isEmpty()) {
            return collect();
        }

        return $relatedPostTypes->map(function ($relatedPostType) {
            $options = DynamicPost::query()
                ->select('id', 'post_type_id', 'title', 'slug', 'status', 'live_status')
                ->where('post_type_id', $relatedPostType->id)
                ->whereIn('status', ['draft', 'published', 'private'])
                ->orderBy('title', 'asc')
                ->get();

            return [
                'post_type_id' => (int) $relatedPostType->id,
                'name' => (string) $relatedPostType->name,
                'slug' => (string) $relatedPostType->slug,

                // UI compatibility for dropdown wrappers
                'id' => (int) $relatedPostType->id,
                'label' => (string) $relatedPostType->name,
                'value' => (int) $relatedPostType->id,

                'field_label' => (string) $relatedPostType->name,

                // Important: every relationship field must have a unique name.
                // Example: relationship_post_types.3.post_ids
                'field_name' => 'relationship_post_types[' . $relatedPostType->id . '][post_ids]',
                'field_name_dot' => 'relationship_post_types.' . $relatedPostType->id . '.post_ids',
                'post_type_field_name' => 'relationship_post_types[' . $relatedPostType->id . '][post_type_id]',
                'post_type_field_name_dot' => 'relationship_post_types.' . $relatedPostType->id . '.post_type_id',
                'selection_type' => 'multiple',
                'multiple' => true,

                'options' => $options->map(function ($post) {
                    $label = $post->title ?: $post->slug ?: ('Post #' . $post->id);

                    return [
                        'id' => (int) $post->id,
                        'value' => (int) $post->id,
                        'label' => (string) $label,
                        'name' => (string) $label,
                        'title' => (string) $label,
                        'slug' => (string) ($post->slug ?? ''),
                        'status' => (string) ($post->status ?? ''),
                        'live_status' => (string) ($post->live_status ?? ''),
                        'post_type_id' => (int) $post->post_type_id,
                    ];
                })->values(),
            ];
        })->values();
    }

    public function customFieldsByPostType(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_term_ids' => ['nullable'],
            ]);

            $postType = PostType::find($request->post_type_id);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

            if (!$supports['custom_fields']) {
                return $this->successResponse('Custom fields fetched successfully.', [
                    'post_type_id' => (int) $request->post_type_id,
                    'taxonomy_term_ids' => [],
                    'custom_fields_count' => 0,
                    'custom_fields' => [],
                ]);
            }

            $termIds = $this->normalizeIds($request->taxonomy_term_ids);

            if ($request->filled('taxonomy_term_ids')) {
                $this->assertTaxonomyTermsExist($termIds);
            }

            $fields = $this->resolveVisibleCustomFields((int) $request->post_type_id, $termIds)
                ->map(fn($field) => $this->formatCustomField($field))
                ->values();

            return $this->successResponse('Custom fields fetched successfully.', [
                'post_type_id' => (int) $request->post_type_id,
                'taxonomy_term_ids' => $termIds,
                'custom_fields_count' => $fields->count(),
                'custom_fields' => $fields,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while fetching custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch custom fields.', 500, $e->getMessage());
        }
    }

    public function resolveCustomFieldsForCreate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'post_type_id' => ['required', 'integer', 'exists:post_types,id'],
                'taxonomy_term_ids' => ['nullable'],
                'taxonomies' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_id' => ['required_with:taxonomies', 'integer', 'exists:taxonomies,id'],
                'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
                'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
                'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
                'custom_fields' => ['nullable', 'array'],
                'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
                'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
                'custom_fields.*.value_text' => ['nullable'],
                'custom_fields.*.value_string' => ['nullable'],
                'custom_fields.*.value_number' => ['nullable', 'numeric'],
                'custom_fields.*.value_date' => ['nullable', 'date'],
                'custom_fields.*.value_datetime' => ['nullable', 'date'],
                'custom_fields.*.value_json' => ['nullable'],
                'custom_fields.*.repeaters' => ['nullable', 'array'],
                'custom_fields.*.repeaters.*.custom_field_repeater_id' => ['nullable', 'integer', 'exists:custom_field_repeaters,id'],
                'custom_fields.*.repeaters.*.field_name_slug' => ['nullable', 'string'],
                'custom_fields.*.repeaters.*.rows' => ['nullable', 'array'],
                'custom_fields.*.repeaters.*.rows.*' => ['nullable', 'array'],
            ]);

            $postType = PostType::with('taxonomies')->find($validated['post_type_id']);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            $termIds = $this->normalizeSubmittedTaxonomyTermIds($validated);
            $submittedTaxonomies = $validated['taxonomies'] ?? [];

            $this->validateSubmittedTaxonomyGroups($postType, $submittedTaxonomies);
            $this->validateTaxonomyTermsForPostType($postType, $termIds);
            $this->validateDependentTaxonomySelections($termIds);

            $fields = $this->resolveVisibleCustomFields((int) $validated['post_type_id'], $termIds);

            $selectedValues = [];

            foreach ($validated['custom_fields'] ?? [] as $submittedField) {
                $fieldId = (int) $submittedField['custom_field_id'];
                $field = $fields->firstWhere('id', $fieldId);

                if (!$field) {
                    continue;
                }

                $value = $submittedField['value_string']
                    ?? $submittedField['value_text']
                    ?? $submittedField['value_number']
                    ?? $submittedField['value_date']
                    ?? $submittedField['value_datetime']
                    ?? $submittedField['value_json']
                    ?? null;

                if (!empty($submittedField['custom_field_option_id'])) {
                    $option = $field->options->firstWhere('id', (int) $submittedField['custom_field_option_id']);

                    if ($option) {
                        $value = $option->value;
                    }
                }

                $selectedValues[$field->field_name_slug] = $value;
                $selectedValues[(string) $field->id] = $value;
            }

            $visibleFields = [];
            $hiddenFields = [];

            foreach ($fields as $field) {
                $isVisible = $this->isConditionalFieldVisible($field->conditional_rules, $selectedValues);
                $fieldData = $this->formatCustomField($field);

                if ($isVisible) {
                    $visibleFields[] = $fieldData;
                } else {
                    $fieldData['hidden_reason'] = 'Conditional rule not matched.';
                    $hiddenFields[] = $fieldData;
                }
            }

            $allowedFieldIds = collect($visibleFields)->pluck('id')->values()->toArray();

            $submittedFieldIds = collect($validated['custom_fields'] ?? [])
                ->pluck('custom_field_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            $unsupportedFieldIds = array_values(array_diff($submittedFieldIds, $allowedFieldIds));

            return $this->successResponse('Custom fields resolved successfully.', [
                'post_type_id' => (int) $validated['post_type_id'],
                'taxonomy_term_ids' => $termIds,
                'selected_values' => $selectedValues,
                'visible_custom_fields_count' => count($visibleFields),
                'hidden_custom_fields_count' => count($hiddenFields),
                'allowed_custom_field_ids' => $allowedFieldIds,
                'submitted_custom_field_ids' => $submittedFieldIds,
                'unsupported_custom_field_ids' => $unsupportedFieldIds,
                'visible_custom_fields' => $visibleFields,
                'hidden_custom_fields' => $hiddenFields,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while resolving custom fields.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to resolve custom fields.', 500, $e->getMessage());
        }
    }
    private function generateDynamicPostListingCode(PostType $postType): string
    {
        $prefix = $this->getDynamicPostPrefix($postType);

        $lastCode = DynamicPost::where('post_type_id', $postType->id)
            ->whereNotNull('listing_code')
            ->where('listing_code', 'like', $prefix . '-%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('listing_code');

        $nextNumber = 1;

        if (!empty($lastCode) && preg_match('/-(\d+)$/', $lastCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        return $prefix . '-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    private function getDynamicPostPrefix(PostType $postType): string
    {
        $setting = SiteSetting::first();

        $slug = Str::slug($postType->slug ?? $postType->name ?? '', '-');
        $name = Str::slug($postType->name ?? '', '-');

        if (str_contains($slug, 'property') || str_contains($name, 'property')) {
            return $this->cleanPrefix($setting?->property_prefix ?: 'PRP');
        }

        if (str_contains($slug, 'developer') || str_contains($name, 'developer')) {
            return $this->cleanPrefix($setting?->developer_prefix ?: 'DEV');
        }

        if (str_contains($slug, 'project') || str_contains($name, 'project')) {
            return $this->cleanPrefix($setting?->project_prefix ?: 'PRJ');
        }

        return $this->cleanPrefix(strtoupper(substr(Str::slug($postType->name ?? 'DYN', ''), 0, 4)) ?: 'DYN');
    }

    private function cleanPrefix(?string $prefix): string
    {
        $prefix = strtoupper(trim((string) $prefix));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix);

        return $prefix ?: 'DYN';
    }
    /**
     * Normalize JSON/form-data update payloads before validation.
     *
     * Supports:
     * - normal JSON requests
     * - multipart form-data values encoded with JSON.stringify()
     * - payload/data/post wrapper objects
     * - common status aliases used by frontend forms
     */
    private function normalizeDynamicPostRequest(Request $request): void
    {
        foreach (['payload', 'data', 'post'] as $wrapperKey) {
            if (!$request->has($wrapperKey)) {
                continue;
            }

            $wrapper = $request->input($wrapperKey);

            if (is_string($wrapper)) {
                $decoded = json_decode($wrapper, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $wrapper = $decoded;
                }
            }

            if (is_array($wrapper)) {
                $request->merge(array_merge($wrapper, $request->except([
                    'payload',
                    'data',
                    'post',
                ])));
            }
        }

        foreach (
            [
                'taxonomies',
                'taxonomy_term_ids',
                'custom_fields',
                'gallery_image_ids',
                'relationship_post_types',
                'related_posts',
                'keywords',
            ] as $field
        ) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                if (in_array($field, [
                    'taxonomies',
                    'custom_fields',
                    'gallery_image_ids',
                    'relationship_post_types',
                    'related_posts',
                ], true)) {
                    $request->merge([$field => []]);
                }

                continue;
            }

            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge([$field => $decoded]);
            }
        }

        $status = $this->firstPresentRequestValue($request, [
            'status',
            'post_status',
            'listing_status',
        ]);

        if ($status['found']) {
            $request->merge([
                'status' => is_string($status['value'])
                    ? strtolower(trim($status['value']))
                    : $status['value'],
            ]);
        }

        $liveStatus = $this->firstPresentRequestValue($request, [
            'live_status',
            'liveStatus',
            'listing_live_status',
            'approval_status',
        ]);

        if ($liveStatus['found']) {
            $request->merge([
                'live_status' => is_string($liveStatus['value'])
                    ? strtolower(trim($liveStatus['value']))
                    : $liveStatus['value'],
            ]);
        }
    }

    private function firstPresentRequestValue(Request $request, array $keys): array
    {
        foreach ($keys as $key) {
            if ($request->exists($key)) {
                return [
                    'found' => true,
                    'value' => $request->input($key),
                ];
            }
        }

        return [
            'found' => false,
            'value' => null,
        ];
    }

    private function validatePost(Request $request, bool $isUpdate = false): array
    {
        $this->cleanEmptyUploadInputs($request);

        return $request->validate([
            'post_type_id' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:post_types,id'],
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'keyword' => ['nullable'],
            'keywords' => ['nullable'],
            'keywords.*' => ['nullable'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'featured_image_id' => ['nullable', 'integer'],
            'featured_image' => ['nullable'],
            'gallery_image_ids' => ['nullable', 'array'],
            'gallery_image_ids.*' => ['integer'],
            'gallery_images' => ['nullable'],
            'gallery_images.*' => ['nullable'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'private', 'archived'])],
            'live_status' => ['nullable', Rule::in(['approve', 'reject', 'under_review', 'disapprove', 'modify_review', 'submit'])],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'parent_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],

            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'area_locality' => ['nullable', 'string', 'max:255'],
            'relationship_post_types' => ['nullable', 'array'],
            'relationship_post_types.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'relationship_post_types.*.related_post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'relationship_post_types.*.post_id' => ['nullable'],
            'relationship_post_types.*.post_ids' => ['nullable', 'array'],
            'relationship_post_types.*.post_ids.*' => ['nullable'],
            'relationship_post_types.*.related_post_ids' => ['nullable', 'array'],
            'relationship_post_types.*.related_post_ids.*' => ['nullable'],
            'related_posts' => ['nullable', 'array'],
            'related_posts.*.post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'related_posts.*.related_post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'related_posts.*.post_id' => ['nullable'],
            'related_posts.*.post_ids' => ['nullable', 'array'],
            'related_posts.*.post_ids.*' => ['nullable'],
            'published_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'taxonomy_term_ids' => ['nullable'],
            'taxonomies' => ['nullable', 'array'],
            'taxonomies.*.taxonomy_id' => ['required_with:taxonomies', 'integer', 'exists:taxonomies,id'],
            'taxonomies.*.taxonomy_term_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'taxonomies.*.taxonomy_term_ids' => ['nullable', 'array'],
            'taxonomies.*.taxonomy_term_ids.*' => ['integer', 'exists:taxonomy_terms,id'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.custom_field_id' => ['required_with:custom_fields', 'integer', 'exists:custom_fields,id'],
            'custom_fields.*.custom_field_option_id' => ['nullable', 'integer', 'exists:custom_field_options,id'],
            'custom_fields.*.value_text' => ['nullable'],
            'custom_fields.*.value_string' => ['nullable'],
            'custom_fields.*.value_number' => ['nullable', 'numeric'],
            'custom_fields.*.value_date' => ['nullable', 'date'],
            'custom_fields.*.value_datetime' => ['nullable', 'date'],
            'custom_fields.*.value_json' => ['nullable'],
            'custom_fields.*.value_repeaters' => ['nullable'],
            'custom_fields.*.value_repeaters.*' => ['nullable'],
            'custom_fields.*.file' => ['nullable'],
            'custom_fields.*.files' => ['nullable'],
            'custom_fields.*.files.*' => ['nullable'],
            'custom_fields.*.repeaters' => ['nullable', 'array'],
            'custom_fields.*.repeaters.*' => ['nullable', 'array'],
            'custom_fields.*.repeaters.*.*' => ['nullable'],
            'custom_fields.*.repeaters.*.custom_field_repeater_id' => ['nullable', 'integer', 'exists:custom_field_repeaters,id'],
            'custom_fields.*.repeaters.*.field_name_slug' => ['nullable', 'string'],
            'custom_fields.*.repeaters.*.rows' => ['nullable', 'array'],
            'custom_fields.*.repeaters.*.rows.*' => ['nullable', 'array'],
            'rejection_reason' => ['nullable', 'string'],
        ]);
    }

    private function cleanEmptyUploadInputs(Request $request): void
    {
        if ($request->has('featured_image_id') && $request->input('featured_image_id') === '') {
            $request->merge(['featured_image_id' => null]);
        }

        if ($request->has('gallery_images') && $request->input('gallery_images') === '') {
            $request->merge(['gallery_images' => []]);
        }

        if ($request->has('gallery_image_ids') && $request->input('gallery_image_ids') === '') {
            $request->merge(['gallery_image_ids' => []]);
        }

        if ($request->has('custom_fields')) {
            $customFields = $request->input('custom_fields', []);

            if (is_array($customFields)) {
                foreach ($customFields as $index => $fieldData) {
                    if (array_key_exists('file', $fieldData) && $fieldData['file'] === '') {
                        unset($customFields[$index]['file']);
                    }

                    if (array_key_exists('files', $fieldData) && $fieldData['files'] === '') {
                        $customFields[$index]['files'] = [];
                    }

                    if (array_key_exists('value_json', $fieldData) && $fieldData['value_json'] === '') {
                        $customFields[$index]['value_json'] = [];
                    }

                    if (array_key_exists('value_string', $fieldData) && $fieldData['value_string'] === null) {
                        $customFields[$index]['value_string'] = '';
                    }

                    if (array_key_exists('value_text', $fieldData) && $fieldData['value_text'] === null) {
                        $customFields[$index]['value_text'] = '';
                    }

                    if (array_key_exists('repeaters', $fieldData) && $fieldData['repeaters'] === '') {
                        $customFields[$index]['repeaters'] = [];
                    }

                    if (array_key_exists('value_repeaters', $fieldData) && $fieldData['value_repeaters'] === '') {
                        $customFields[$index]['value_repeaters'] = [];
                    }
                }

                $request->merge(['custom_fields' => $customFields]);
            }
        }
    }

    private function normalizeSubmittedTaxonomyTermIds(array $validated): array
    {
        if (array_key_exists('taxonomies', $validated) && is_array($validated['taxonomies'])) {
            $termIds = [];

            foreach ($validated['taxonomies'] as $taxonomyData) {
                if (!empty($taxonomyData['taxonomy_term_id'])) {
                    $termIds[] = (int) $taxonomyData['taxonomy_term_id'];
                }

                if (!empty($taxonomyData['taxonomy_term_ids'])) {
                    foreach ($this->normalizeIds($taxonomyData['taxonomy_term_ids']) as $termId) {
                        $termIds[] = $termId;
                    }
                }
            }

            return collect($termIds)
                ->unique()
                ->values()
                ->toArray();
        }

        return $this->normalizeIds($validated['taxonomy_term_ids'] ?? []);
    }

    private function validateSubmittedTaxonomyGroups(PostType $postType, array $submittedTaxonomies): void
    {
        if (empty($submittedTaxonomies)) {
            return;
        }

        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['taxonomies']) {
            throw ValidationException::withMessages([
                'taxonomies' => ['This post type does not support taxonomies.'],
            ]);
        }

        $attachedTaxonomies = $postType->activeTaxonomies()->get();
        $attachedTaxonomiesById = $attachedTaxonomies->keyBy('id');

        foreach ($submittedTaxonomies as $index => $taxonomyData) {
            $taxonomyId = (int) ($taxonomyData['taxonomy_id'] ?? 0);

            if (!$attachedTaxonomiesById->has($taxonomyId)) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}.taxonomy_id" => ['This taxonomy is not attached with selected post type.'],
                ]);
            }

            $taxonomy = $attachedTaxonomiesById->get($taxonomyId);
            $selectionType = $this->getTaxonomySelectionType($taxonomy);

            $termIds = [];

            if (!empty($taxonomyData['taxonomy_term_id'])) {
                $termIds[] = (int) $taxonomyData['taxonomy_term_id'];
            }

            if (!empty($taxonomyData['taxonomy_term_ids'])) {
                foreach ($this->normalizeIds($taxonomyData['taxonomy_term_ids']) as $termId) {
                    $termIds[] = $termId;
                }
            }

            $termIds = collect($termIds)->unique()->values()->toArray();

            if ($selectionType === 'single' && count($termIds) > 1) {
                throw ValidationException::withMessages([
                    "taxonomies.{$index}" => [$taxonomy->name . ' allows only one term.'],
                ]);
            }

            if (!empty($termIds)) {
                $invalidTermExists = TaxonomyTerm::whereIn('id', $termIds)
                    ->where('taxonomy_id', '!=', $taxonomyId)
                    ->exists();

                if ($invalidTermExists) {
                    throw ValidationException::withMessages([
                        "taxonomies.{$index}.taxonomy_term_ids" => ['Some selected terms do not belong to this taxonomy.'],
                    ]);
                }
            }
        }
    }

    private function validateDependentTaxonomySelections(array $selectedTermIds): void
    {
        if (empty($selectedTermIds)) {
            return;
        }

        $terms = TaxonomyTerm::query()
            ->with([
                'relationValues' => function ($query) {
                    $query
                        ->select(
                            'taxonomy_terms.id',
                            'taxonomy_terms.taxonomy_id',
                            'taxonomy_terms.name',
                            'taxonomy_terms.slug'
                        )
                        ->where('taxonomy_terms.status', true)
                        ->wherePivot('status', true);
                },
            ])
            ->whereIn('id', $selectedTermIds)
            ->get();

        foreach ($terms as $term) {
            $relationValueIds = $term->relationValues
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->toArray();

            if (empty($relationValueIds)) {
                continue;
            }

            $hasMatchedParent = count(array_intersect($relationValueIds, $selectedTermIds)) > 0;

            if (!$hasMatchedParent) {
                $requiredValues = $term->relationValues
                    ->pluck('name')
                    ->values()
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'taxonomies' => [
                        $term->name . ' depends on selected parent term: ' . $requiredValues,
                    ],
                ]);
            }
        }
    }

    private function syncTaxonomyTerms(DynamicPost $post, array $taxonomyTermIds): void
    {
        if (empty($taxonomyTermIds)) {
            $post->taxonomyTerms()->sync([]);
            return;
        }

        $terms = TaxonomyTerm::whereIn('id', $taxonomyTermIds)->get();

        $syncData = [];

        foreach ($terms as $term) {
            $syncData[$term->id] = [
                'taxonomy_id' => $term->taxonomy_id,
            ];
        }

        $post->taxonomyTerms()->sync($syncData);
    }

    private function dynamicPostPayloadForDatabase(array $validated): array
    {
        $allowedColumns = [
            'post_type_id',
            'title',
            'slug',
            'excerpt',
            'content',
            'featured_image_id',
            'gallery_image_ids',
            'status',
            'live_status',
            'author_id',
            'parent_id',
            'published_at',
            'sort_order',
            'listing_code',
            'country_id',
            'state_id',
            'city_id',
            'area_locality',
            'rejection_reason',
            'rejected_by',
            'rejected_at',
        ];

        $payload = [];

        foreach ($allowedColumns as $column) {
            if (
                array_key_exists($column, $validated)
                && Schema::hasColumn('dynamic_posts', $column)
            ) {
                $payload[$column] = $validated[$column];
            }
        }

        if (array_key_exists('gallery_image_ids', $payload)) {
            $payload['gallery_image_ids'] = $this->normalizeIds($payload['gallery_image_ids']);

            if (!$this->dynamicPostHasArrayCast('gallery_image_ids')) {
                $payload['gallery_image_ids'] = json_encode($payload['gallery_image_ids']);
            }
        }

        return $payload;
    }

    private function dynamicPostHasArrayCast(string $field): bool
    {
        $casts = (new DynamicPost())->getCasts();

        return isset($casts[$field]) && in_array($casts[$field], ['array', 'json', 'collection', 'object'], true);
    }

    private function saveCustomFieldValues(int $entityId, string $entityType, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $fieldIds = collect($fields)
            ->pluck('custom_field_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $repeaterFieldIds = CustomField::whereIn('id', $fieldIds)
            ->where('field_type', 'repeater')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $normalFields = collect($fields)
            ->reject(function ($field) use ($repeaterFieldIds) {
                return in_array((int) ($field['custom_field_id'] ?? 0), $repeaterFieldIds, true);
            })
            ->map(function ($field) {
                unset($field['_repeater_values']);
                unset($field['repeaters']);
                unset($field['value_repeaters']);

                foreach (['value_string', 'value_text', 'value_number', 'value_date', 'value_datetime'] as $valueKey) {
                    if (array_key_exists($valueKey, $field) && (is_array($field[$valueKey]) || is_object($field[$valueKey]))) {
                        $field[$valueKey] = json_encode($field[$valueKey], JSON_UNESCAPED_SLASHES);
                    }
                }

                return $field;
            })
            ->values()
            ->toArray();

        if (!empty($normalFields)) {
            $service = app(CustomFieldValueService::class);
            $service->saveValues($entityId, $entityType, $normalFields);
        }

        foreach ($repeaterFieldIds as $repeaterFieldId) {
            CustomFieldValue::updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'custom_field_id' => $repeaterFieldId,
                ],
                [
                    'custom_field_option_id' => null,
                    'value_text' => null,
                    'value_string' => null,
                    'value_number' => null,
                    'value_date' => null,
                    'value_datetime' => null,
                    'value_json' => null,
                ]
            );
        }

        $this->saveCustomFieldRepeaterValues($entityId, $entityType, $fields);
    }

    private function saveCustomFieldRepeaterValues(int $entityId, string $entityType, array $fields): void
    {
        if (!Schema::hasTable('custom_field_repeater_values')) {
            throw ValidationException::withMessages([
                'custom_field_repeater_values' => [
                    'custom_field_repeater_values table does not exist. Please run migration first.',
                ],
            ]);
        }

        $columns = Schema::getColumnListing('custom_field_repeater_values');

        $fieldsWithRepeaters = collect($fields)
            ->filter(function ($field) {
                return !empty($field['_repeater_values'])
                    || !empty($field['repeaters'])
                    || !empty($field['value_repeaters'])
                    || !empty($field['value_json']['repeaters']);
            })
            ->values();

        if ($fieldsWithRepeaters->isEmpty()) {
            return;
        }

        $customFieldIds = $fieldsWithRepeaters
            ->pluck('custom_field_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        if (empty($customFieldIds)) {
            return;
        }

        /*
         |--------------------------------------------------------------------------
         | Important for update
         |--------------------------------------------------------------------------
         |
         | Frontend often sends existing file objects in FormData as "[object Object]".
         | Before deleting old repeater rows, keep old media/file values so we can
         | retain them when user has not selected a new file.
         |
         */
        $oldRepeaterValues = $this->getExistingRepeaterValueMap($entityId, $entityType, $customFieldIds);

        DB::table('custom_field_repeater_values')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('custom_field_id', $customFieldIds)
            ->delete();

        $now = now()->format('Y-m-d H:i:s');
        $insertRows = [];

        foreach ($fieldsWithRepeaters as $fieldIndex => $field) {
            $customFieldId = (int) ($field['custom_field_id'] ?? 0);

            if (!$customFieldId) {
                continue;
            }

            $repeaters = $field['_repeater_values']
                ?? $field['repeaters']
                ?? $field['value_repeaters']
                ?? ($field['value_json']['repeaters'] ?? []);

            $rows = $this->normalizeRepeaterRowsOnly($repeaters);

            if (empty($rows)) {
                throw ValidationException::withMessages([
                    "custom_fields.{$fieldIndex}.repeaters" => [
                        "Repeater rows are empty or invalid for custom_field_id {$customFieldId}.",
                    ],
                ]);
            }

            $repeaterDefinitions = CustomFieldRepeater::where('custom_field_id', $customFieldId)
                ->get();

            if ($repeaterDefinitions->isEmpty()) {
                throw ValidationException::withMessages([
                    "custom_fields.{$fieldIndex}.repeaters" => [
                        "No repeater fields found in custom_field_repeaters for custom_field_id {$customFieldId}.",
                    ],
                ]);
            }

            $definitionsBySlug = $repeaterDefinitions->keyBy('field_name_slug');
            $definitionsById = $repeaterDefinitions->keyBy('id');
            $definitionsByNormalizedSlug = $repeaterDefinitions->keyBy(function ($definition) {
                return $this->normalizeRepeaterFieldKey($definition->field_name_slug ?: $definition->field_label);
            });

            foreach ($rows as $rowIndex => $rowData) {
                if (!is_array($rowData)) {
                    continue;
                }

                foreach ($rowData as $fieldSlug => $value) {
                    if (str_starts_with((string) $fieldSlug, '_')) {
                        continue;
                    }

                    $fieldSlugString = (string) $fieldSlug;

                    $repeaterDefinition = $definitionsBySlug->get($fieldSlugString);

                    if (!$repeaterDefinition && is_numeric($fieldSlugString)) {
                        $repeaterDefinition = $definitionsById->get((int) $fieldSlugString);
                    }

                    if (!$repeaterDefinition) {
                        $repeaterDefinition = $definitionsByNormalizedSlug->get(
                            $this->normalizeRepeaterFieldKey($fieldSlugString)
                        );
                    }

                    if (!$repeaterDefinition) {
                        throw ValidationException::withMessages([
                            "custom_fields.{$fieldIndex}.repeaters.{$fieldSlugString}" => [
                                "Repeater field '{$fieldSlugString}' does not match any field_name_slug in custom_field_repeaters for custom_field_id {$customFieldId}.",
                            ],
                        ]);
                    }

                    $definitionSlug = (string) $repeaterDefinition->field_name_slug;
                    $oldValue = $oldRepeaterValues[$customFieldId][(int) $rowIndex][$definitionSlug] ?? null;

                    $value = $this->resolveRepeaterSubmittedValue(
                        $value,
                        $repeaterDefinition,
                        $oldValue
                    );

                    $insertRows[] = $this->buildCustomFieldRepeaterValueRow(
                        $columns,
                        $entityType,
                        $entityId,
                        $customFieldId,
                        $repeaterDefinition,
                        $value,
                        (int) $rowIndex,
                        0,
                        $now
                    );
                }
            }
        }

        if (empty($insertRows)) {
            throw ValidationException::withMessages([
                'repeaters' => [
                    'No repeater values prepared for insert. Please check repeater payload and field_name_slug.',
                ],
            ]);
        }

        DB::table('custom_field_repeater_values')->insert($insertRows);
    }

    private function buildCustomFieldRepeaterValueRow(
        array $columns,
        string $entityType,
        int $entityId,
        int $customFieldId,
        CustomFieldRepeater $repeaterDefinition,
        mixed $value,
        int $rowIndex,
        int $repeaterBlockIndex,
        mixed $now
    ): array {
        $row = [];

        $this->setIfColumnExists($row, $columns, 'entity_type', $entityType);
        $this->setIfColumnExists($row, $columns, 'entity_id', $entityId);
        $this->setIfColumnExists($row, $columns, 'custom_field_id', $customFieldId);
        $this->setIfColumnExists($row, $columns, 'custom_field_repeater_id', (int) $repeaterDefinition->id);
        $this->setIfColumnExists($row, $columns, 'field_type', $repeaterDefinition->field_type);
        $this->setIfColumnExists($row, $columns, 'field_name_slug', $repeaterDefinition->field_name_slug);
        $this->setIfColumnExists($row, $columns, 'field_label', $repeaterDefinition->field_label);
        $this->setIfColumnExists($row, $columns, 'row_index', $rowIndex);
        $this->setIfColumnExists($row, $columns, 'sort_order', $rowIndex);
        $this->setIfColumnExists($row, $columns, 'repeater_index', $repeaterBlockIndex);
        $this->setIfColumnExists($row, $columns, 'unique_id', $entityType . '_' . $entityId . '_' . $customFieldId . '_' . $rowIndex);

        $optionId = null;

        if (is_array($value) && array_key_exists('custom_field_repeater_option_id', $value)) {
            $optionId = $value['custom_field_repeater_option_id'];
            $value = $value['value'] ?? null;
        }

        $this->setIfColumnExists($row, $columns, 'custom_field_repeater_option_id', $optionId);
        $this->setIfColumnExists($row, $columns, 'custom_field_repeater_options_id', $optionId);

        $this->applyRepeaterValueColumns($row, $columns, $repeaterDefinition->field_type, $value);

        $this->setIfColumnExists($row, $columns, 'created_at', $now);
        $this->setIfColumnExists($row, $columns, 'updated_at', $now);

        return $row;
    }

    private function getExistingRepeaterValueMap(int $entityId, string $entityType, array $customFieldIds): array
    {
        if (empty($customFieldIds) || !Schema::hasTable('custom_field_repeater_values')) {
            return [];
        }

        $existingRows = DB::table('custom_field_repeater_values')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('custom_field_id', $customFieldIds)
            ->get();

        $map = [];

        foreach ($existingRows as $row) {
            $customFieldId = (int) ($row->custom_field_id ?? 0);
            $rowIndex = (int) ($row->row_index ?? 0);
            $fieldSlug = (string) ($row->field_name_slug ?? '');

            if (!$customFieldId || $fieldSlug === '') {
                continue;
            }

            $decodedValue = $this->decodeStoredRepeaterValue($row);

            if ($this->isInvalidObjectPlaceholder($decodedValue)) {
                continue;
            }

            $map[$customFieldId][$rowIndex][$fieldSlug] = $decodedValue;
        }

        return $map;
    }

    private function decodeStoredRepeaterValue(object $row): mixed
    {
        foreach (['value_json', 'field_meta_value', 'value_string', 'value_text', 'value_number', 'value_date', 'value_datetime'] as $column) {
            if (!property_exists($row, $column)) {
                continue;
            }

            $value = $row->{$column};

            if (is_null($value) || $value === '') {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '' || $this->isInvalidObjectPlaceholder($value)) {
                    continue;
                }

                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }

            return $value;
        }

        return null;
    }

    private function resolveRepeaterSubmittedValue(mixed $value, CustomFieldRepeater $repeaterDefinition, mixed $oldValue = null): mixed
    {
        $fieldType = (string) ($repeaterDefinition->field_type ?? '');

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($this->isInvalidObjectPlaceholder($trimmed)) {
                $value = null;
            } else {
                $decoded = json_decode($trimmed, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        if (in_array($fieldType, ['media', 'file'], true)) {
            if ($this->isEmptySubmittedRepeaterMediaValue($value)) {
                return $oldValue;
            }

            if (is_array($value) && array_key_exists('value', $value) && $this->isInvalidObjectPlaceholder($value['value'])) {
                return $oldValue;
            }
        }

        return $value;
    }

    private function isEmptySubmittedRepeaterMediaValue(mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($this->isInvalidObjectPlaceholder($value)) {
            return true;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return true;
            }

            // React Select / frontend can accidentally send objects without real file data.
            if (array_key_exists('value', $value) && $this->isInvalidObjectPlaceholder($value['value'])) {
                return true;
            }

            // A valid stored media object must have at least path/url/id/file_name.
            $hasMediaReference = !empty($value['path'])
                || !empty($value['url'])
                || !empty($value['id'])
                || !empty($value['file_name']);

            if (!$hasMediaReference && count($value) === 1 && isset($value[0])) {
                return $this->isEmptySubmittedRepeaterMediaValue($value[0]);
            }
        }

        return false;
    }

    private function isInvalidObjectPlaceholder(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $value = strtolower(trim($value));

        return in_array($value, [
            '[object object]',
            'object object',
            '[object file]',
            'undefined',
            'null',
            'nan',
        ], true);
    }

    private function applyRepeaterValueColumns(array &$row, array $columns, ?string $fieldType, mixed $value): void
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($this->isInvalidObjectPlaceholder($trimmed)) {
                $value = null;
            } else {
                $decoded = json_decode($trimmed, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        $jsonValue = (is_array($value) || is_object($value))
            ? json_encode($value, JSON_UNESCAPED_SLASHES)
            : null;

        $stringValue = $jsonValue ?? $value;
        $stringValue = is_null($stringValue) ? null : (string) $stringValue;

        // field_meta_value is longText, so it can safely store media/file JSON.
        $this->setIfColumnExists($row, $columns, 'field_meta_value', $stringValue);
        $this->setIfColumnExists($row, $columns, 'value', $stringValue);
        $this->setIfColumnExists($row, $columns, 'field_value', $stringValue);

        // value_string is usually VARCHAR(255). Never put media/file JSON here.
        $shortStringValue = (!is_null($stringValue) && mb_strlen($stringValue) <= 255)
            ? $stringValue
            : null;

        $stringFieldTypes = ['text', 'email', 'url', 'radio', 'select', 'boolean'];
        $textFieldTypes = ['textarea', 'texteditor'];

        $this->setIfColumnExists(
            $row,
            $columns,
            'value_string',
            in_array($fieldType, $stringFieldTypes, true) ? $shortStringValue : null
        );

        $this->setIfColumnExists(
            $row,
            $columns,
            'value_text',
            in_array($fieldType, $textFieldTypes, true) || ($fieldType === 'text' && is_null($shortStringValue))
                ? $stringValue
                : null
        );

        $this->setIfColumnExists($row, $columns, 'value_number', $fieldType === 'number' && is_numeric($value) ? $value : null);
        $this->setIfColumnExists($row, $columns, 'value_date', $fieldType === 'date' ? $value : null);
        $this->setIfColumnExists($row, $columns, 'value_datetime', $fieldType === 'datetime' ? $value : null);
        $this->setIfColumnExists($row, $columns, 'value_json', $jsonValue);
    }

    private function setIfColumnExists(array &$row, array $columns, string $column, mixed $value): void
    {
        if (!in_array($column, $columns, true)) {
            return;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        $row[$column] = $value;
    }

    private function prepareBaseMediaForSave(Request $request, array $validated, PostType $postType, ?DynamicPost $existingPost = null): array
    {
        $featuredProvided = $request->request->has('featured_image')
            || $request->files->has('featured_image')
            || array_key_exists('featured_image_id', $validated);

        $featuredFile = $request->file('featured_image');
        $featuredInput = $request->input('featured_image');

        if ($featuredFile) {
            if ($existingPost && !empty($existingPost->featured_image_id)) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
            }

            $featuredMedia = $this->storeDynamicPostMediaFile($featuredFile, $postType, 'featured-image');
            $validated['featured_image_id'] = $featuredMedia->id;
        } elseif ($existingPost && $request->request->has('featured_image')) {
            if (empty($featuredInput)) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = null;
            } else {
                $submittedFeaturedId = $this->resolveMediaFileIdFromUrl((string) $featuredInput);

                if ($submittedFeaturedId) {
                    if ((int) $submittedFeaturedId !== (int) $existingPost->featured_image_id) {
                        $this->deleteMediaFileById($existingPost->featured_image_id);
                    }

                    $validated['featured_image_id'] = $submittedFeaturedId;
                }
            }
        } elseif ($existingPost && array_key_exists('featured_image_id', $validated)) {
            if (empty($validated['featured_image_id'])) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = null;
            } elseif ((int) $validated['featured_image_id'] !== (int) $existingPost->featured_image_id) {
                $this->deleteMediaFileById($existingPost->featured_image_id);
                $validated['featured_image_id'] = (int) $validated['featured_image_id'];
            }
        }

        $currentGalleryIds = $existingPost
            ? $this->normalizeIds($existingPost->gallery_image_ids ?? [])
            : [];

        $galleryProvided = $request->request->has('gallery_images')
            || $request->files->has('gallery_images')
            || array_key_exists('gallery_image_ids', $validated);

        if ($existingPost && !$galleryProvided) {
            // Gallery was not part of this update. Keep the existing gallery.
            return $validated;
        }

        $galleryInputExists = $request->request->has('gallery_images') || $request->files->has('gallery_images');

        if ($galleryInputExists) {
            $submittedGalleryIds = [];
            $galleryInputs = $request->input('gallery_images', []);
            $galleryFiles = $request->file('gallery_images', []);

            if (!is_array($galleryInputs)) {
                $galleryInputs = [$galleryInputs];
            }

            if (!is_array($galleryFiles)) {
                $galleryFiles = $galleryFiles ? [$galleryFiles] : [];
            }

            $keys = collect(array_keys($galleryInputs))
                ->merge(array_keys($galleryFiles))
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            foreach ($keys as $key) {
                $file = $galleryFiles[$key] ?? null;

                if ($file) {
                    $galleryMedia = $this->storeDynamicPostMediaFile($file, $postType, 'gallery');
                    $submittedGalleryIds[] = (int) $galleryMedia->id;
                    continue;
                }

                $url = $galleryInputs[$key] ?? null;

                if (!empty($url) && is_string($url)) {
                    $mediaId = $this->resolveMediaFileIdFromUrl($url);

                    if ($mediaId) {
                        $submittedGalleryIds[] = (int) $mediaId;
                    }
                }
            }

            if ($existingPost) {
                $removedGalleryIds = array_values(array_diff($currentGalleryIds, $submittedGalleryIds));

                if (!empty($removedGalleryIds)) {
                    $this->deleteMediaFilesByIds($removedGalleryIds);
                }
            }

            $validated['gallery_image_ids'] = collect($submittedGalleryIds)
                ->unique()
                ->values()
                ->toArray();
        } elseif ($existingPost && array_key_exists('gallery_image_ids', $validated)) {
            $submittedGalleryIds = $this->normalizeIds($validated['gallery_image_ids']);
            $removedGalleryIds = array_values(array_diff($currentGalleryIds, $submittedGalleryIds));

            if (!empty($removedGalleryIds)) {
                $this->deleteMediaFilesByIds($removedGalleryIds);
            }

            $validated['gallery_image_ids'] = $submittedGalleryIds;
        }

        return $validated;
    }

    private function resolveMediaFileIdFromUrl(string $url): ?int
    {
        $path = $this->storagePathFromUrl($url);

        if (!$path) {
            return null;
        }

        return MediaFile::where('path', $path)->value('id');
    }

    private function storagePathFromUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        $storagePosition = strpos($path, '/storage/');

        if ($storagePosition !== false) {
            return ltrim(substr($path, $storagePosition + strlen('/storage/')), '/');
        }

        if (str_starts_with($path, 'storage/')) {
            return ltrim(substr($path, strlen('storage/')), '/');
        }

        if (str_starts_with($path, '/uploads/')) {
            return ltrim($path, '/');
        }

        if (str_starts_with($path, 'uploads/')) {
            return $path;
        }

        return null;
    }

    private function storeDynamicPostMediaFile($file, PostType $postType, string $type): MediaFile
    {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                $type === 'featured-image' ? 'featured_image' : 'gallery_images' => [
                    'Invalid image format. Allowed formats: ' . implode(', ', $allowedExtensions)
                ],
            ]);
        }

        $maxSizeKb = 5120;

        if (($file->getSize() / 1024) > $maxSizeKb) {
            throw ValidationException::withMessages([
                $type === 'featured-image' ? 'featured_image' : 'gallery_images' => [
                    'Image size must not be greater than 5MB.'
                ],
            ]);
        }

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
        $originalBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalBaseName ?: $type, '-');

        if (empty($safeBaseName)) {
            $safeBaseName = $type;
        }

        $shortUuid = substr((string) Str::uuid(), 0, 8);
        $fileName = $safeBaseName . '-' . $shortUuid . '.' . $extension;

        $directory = implode('/', [
            'uploads',
            'dynamic-posts',
            $postTypeSlug,
            $type,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $path = $file->storeAs($directory, $fileName, 'public');

        return MediaFile::create([
            'disk' => 'public',
            'context' => 'dynamic-posts',
            'post_type_slug' => $postTypeSlug,
            'field_slug' => $type,
            'directory' => $directory,
            'path' => $path,
            'file_name' => $fileName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);
    }

    private function deleteMediaFileById(null|int|string $mediaId): void
    {
        if (empty($mediaId)) {
            return;
        }

        $media = MediaFile::find((int) $mediaId);

        if (!$media) {
            return;
        }

        $disk = $media->disk ?? 'public';

        if (!empty($media->path) && Storage::disk($disk)->exists($media->path)) {
            Storage::disk($disk)->delete($media->path);
        }

        $media->delete();
    }

    private function deleteMediaFilesByIds(array $mediaIds): void
    {
        $mediaIds = collect($mediaIds)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        foreach ($mediaIds as $mediaId) {
            $this->deleteMediaFileById($mediaId);
        }
    }

    private function prepareCustomFieldsForSave(Request $request, array $validated, PostType $postType, ?DynamicPost $existingPost = null): array
    {
        $customFields = $validated['custom_fields'] ?? [];

        foreach ($customFields as $index => $fieldData) {
            $fieldId = (int) ($fieldData['custom_field_id'] ?? 0);

            if (!$fieldId) {
                continue;
            }

            $customField = CustomField::find($fieldId);

            if (!$customField) {
                continue;
            }

            $oldValueJson = $existingPost
                ? $this->getExistingCustomFieldValueJson($existingPost->id, $fieldId)
                : [];

            $isMediaField = in_array($customField->field_type, ['media', 'file'], true);

            if ($isMediaField) {
                $uploadedFiles = $this->extractCustomFieldUploadedFiles($request, $index);
                $mediaStateSubmitted = $this->customFieldMediaStateWasSubmitted($fieldData);

                if (!empty($uploadedFiles) || $mediaStateSubmitted) {
                    $retainedFiles = $mediaStateSubmitted
                        ? $this->submittedCustomFieldMediaItems($fieldData, $oldValueJson)
                        : [];

                    $uploaded = [];

                    if (!empty($uploadedFiles)) {
                        $uploaded = $this->storeCustomFieldUploadedFiles(
                            $uploadedFiles,
                            $customField,
                            $postType
                        );
                    }

                    $newValueJson = collect($retainedFiles)
                        ->merge($uploaded)
                        ->filter(fn($item) => is_array($item) && !empty($item['path']))
                        ->unique('path')
                        ->values()
                        ->toArray();

                    if ($this->containsTemporaryFilePath($newValueJson)) {
                        throw ValidationException::withMessages([
                            "custom_fields.{$index}.value_json" => [
                                'Temporary file path is not allowed. Please upload the file again.'
                            ],
                        ]);
                    }

                    $this->deleteRemovedCustomFieldFiles($oldValueJson, $newValueJson);
                    $customFields[$index]['value_json'] = $newValueJson;

                    unset(
                        $customFields[$index]['value_string'],
                        $customFields[$index]['value_text'],
                        $customFields[$index]['value_number'],
                        $customFields[$index]['value_date'],
                        $customFields[$index]['value_datetime']
                    );
                } else {
                    $valueJson = $fieldData['value_json'] ?? [];

                    if ($this->containsTemporaryFilePath($valueJson)) {
                        throw ValidationException::withMessages([
                            "custom_fields.{$index}.value_json" => [
                                'Temporary file path is not allowed. Please upload the file again.'
                            ],
                        ]);
                    }
                }
            }

            $repeaterInput = $this->extractRepeaterInputFromFieldData($fieldData, $customField);

            if (!is_null($repeaterInput)) {
                $repeatersWithUploadedFiles = $this->mergeRepeaterUploadedFilesIntoInput(
                    $request,
                    $index,
                    $repeaterInput,
                    $customField,
                    $postType
                );

                // API payload can be sent without rows:
                // "repeaters": [ {"floor_name": "vijay", "test": "kumar"} ]
                // For custom_field_values, store simple flat rows in value_json.
                // For custom_field_repeater_values, _repeater_values can still be normalized into blocks internally.
                $normalizedRepeaterRows = $this->normalizeRepeaterRowsForSave($repeatersWithUploadedFiles);

                $customFields[$index]['_repeater_values'] = $normalizedRepeaterRows;

                // Repeater values are stored only in custom_field_repeater_values.
                // Do not save repeater rows inside custom_field_values.value_json.
                unset(
                    $customFields[$index]['repeaters'],
                    $customFields[$index]['value_repeaters'],
                    $customFields[$index]['value_json'],
                    $customFields[$index]['value_string'],
                    $customFields[$index]['value_text'],
                    $customFields[$index]['value_number'],
                    $customFields[$index]['value_date'],
                    $customFields[$index]['value_datetime']
                );
            }

            unset(
                $customFields[$index]['file'],
                $customFields[$index]['files']
            );
        }

        return $customFields;
    }

    private function extractRepeaterInputFromFieldData(array $fieldData, CustomField $customField): mixed
    {
        if (array_key_exists('repeaters', $fieldData)) {
            return $fieldData['repeaters'];
        }

        if (array_key_exists('value_repeaters', $fieldData)) {
            return $fieldData['value_repeaters'];
        }

        if ($customField->field_type !== 'repeater') {
            return null;
        }

        foreach (['value_json', 'value_string', 'value_text'] as $key) {
            if (!array_key_exists($key, $fieldData)) {
                continue;
            }

            $value = $fieldData[$key];

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }

                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }

                continue;
            }

            if (is_array($value) && !empty($value)) {
                return $value;
            }
        }

        return null;
    }

    private function mergeRepeaterUploadedFilesIntoInput(
        Request $request,
        int $customFieldIndex,
        mixed $repeaters,
        CustomField $field,
        PostType $postType
    ): mixed {
        $uploadedFiles = $request->file("custom_fields.{$customFieldIndex}.repeaters");

        if (empty($uploadedFiles)) {
            return $repeaters;
        }

        if (is_string($repeaters)) {
            $decoded = json_decode($repeaters, true);
            $repeaters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($repeaters)) {
            $repeaters = [];
        }

        $this->mergeRepeaterUploadedFilesRecursive(
            $repeaters,
            $uploadedFiles,
            [],
            $field,
            $postType
        );

        return $repeaters;
    }

    private function mergeRepeaterUploadedFilesRecursive(
        array &$target,
        mixed $files,
        array $path,
        CustomField $field,
        PostType $postType
    ): void {
        if ($files instanceof UploadedFile) {
            $fieldSlug = (string) end($path);

            if ($fieldSlug === '') {
                return;
            }

            $repeaterDefinition = CustomFieldRepeater::where('custom_field_id', $field->id)
                ->where('field_name_slug', $fieldSlug)
                ->first();

            if (!$repeaterDefinition) {
                return;
            }

            $storedFile = $this->storeRepeaterUploadedFile($files, $field, $repeaterDefinition, $postType);
            $this->setNestedArrayValue($target, $path, $storedFile);

            return;
        }

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $key => $childFiles) {
            $childPath = array_merge($path, [$key]);
            $this->mergeRepeaterUploadedFilesRecursive($target, $childFiles, $childPath, $field, $postType);
        }
    }

    private function setNestedArrayValue(array &$array, array $path, mixed $value): void
    {
        $current = &$array;

        foreach ($path as $index => $key) {
            if ($index === count($path) - 1) {
                $current[$key] = $value;
                return;
            }

            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }
    }

    private function storeRepeaterUploadedFile(
        UploadedFile $file,
        CustomField $field,
        CustomFieldRepeater $repeater,
        PostType $postType
    ): array {
        $allowedExtensions = $this->allowedRepeaterFileExtensions($repeater);
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'custom_fields' => [
                    'Invalid file format for ' . $repeater->field_label . '. Allowed formats: ' . implode(', ', $allowedExtensions)
                ],
            ]);
        }

        $maxSizeKb = $this->parseCustomFieldMediaSizeToKb($repeater->media_size);

        if (($file->getSize() / 1024) > $maxSizeKb) {
            throw ValidationException::withMessages([
                'custom_fields' => [
                    'File size is too large for ' . $repeater->field_label . '. Maximum allowed size is ' . round($maxSizeKb / 1024, 2) . ' MB.'
                ],
            ]);
        }

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
        $fieldSlug = Str::slug($field->field_name_slug ?? $field->field_label, '-');
        $repeaterSlug = Str::slug($repeater->field_name_slug ?? $repeater->field_label, '-');

        $directory = implode('/', [
            'uploads',
            'custom-field-repeaters',
            $postTypeSlug,
            $fieldSlug,
            $repeaterSlug,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $fileName = Str::uuid()->toString() . '.' . $extension;
        $path = $file->storeAs($directory, $fileName, 'public');

        return [
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'file_name' => $fileName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size' => $file->getSize(),
            'size_kb' => round($file->getSize() / 1024, 2),
        ];
    }

    private function allowedRepeaterFileExtensions(CustomFieldRepeater $repeater): array
    {
        if (!empty($repeater->media_format)) {
            return collect(explode(',', $repeater->media_format))
                ->map(fn($format) => strtolower(trim($format)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        if ($repeater->field_type === 'media') {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }

        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    }

    private function mergeRepeaterValuesIntoValueJson(mixed $currentValueJson, mixed $repeaters): array
    {
        return $this->normalizeRepeaterRowsOnly($repeaters);
    }

    private function normalizeRepeaterValuesForSave(mixed $repeaters): array
    {
        return [
            [
                'custom_field_repeater_id' => null,
                'field_name_slug' => null,
                'rows' => $this->normalizeRepeaterRowsOnly($repeaters),
            ],
        ];
    }

    private function normalizeRepeaterRowsForSave(mixed $repeaters): array
    {
        return $this->normalizeRepeaterRowsOnly($repeaters);
    }

    private function normalizeRepeaterRowsOnly(mixed $repeaters): array
    {
        if (empty($repeaters)) {
            return [];
        }

        if (is_string($repeaters)) {
            $decoded = json_decode($repeaters, true);
            $repeaters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($repeaters)) {
            return [];
        }

        if (isset($repeaters['repeaters']) && is_array($repeaters['repeaters'])) {
            $repeaters = $repeaters['repeaters'];
        }

        if (isset($repeaters['rows']) && is_array($repeaters['rows'])) {
            $repeaters = $repeaters['rows'];
        }

        return $this->flattenRepeaterRowsOnly($repeaters);
    }

    private function flattenRepeaterRowsOnly(mixed $rows): array
    {
        if (is_string($rows)) {
            $decoded = json_decode($rows, true);
            $rows = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($rows)) {
            return [];
        }

        if ($this->looksLikeSingleRepeaterRow($rows)) {
            return [$rows];
        }

        $flattened = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row['rows']) && is_array($row['rows'])) {
                foreach ($this->flattenRepeaterRowsOnly($row['rows']) as $innerRow) {
                    $flattened[] = $innerRow;
                }

                continue;
            }

            if ($this->looksLikeSingleRepeaterRow($row)) {
                $flattened[] = $row;
                continue;
            }

            foreach ($this->flattenRepeaterRowsOnly($row) as $innerRow) {
                $flattened[] = $innerRow;
            }
        }

        return $flattened;
    }

    private function looksLikeRepeaterRows(array $repeaters): bool
    {
        if (empty($repeaters)) {
            return false;
        }

        return collect($repeaters)
            ->every(fn($row) => is_array($row) && !array_key_exists('rows', $row));
    }

    private function looksLikeSingleRepeaterRow(array $repeater): bool
    {
        if (empty($repeater)) {
            return false;
        }

        // IMPORTANT:
        // A repeater row must be an associative array like:
        // ["floor_name" => "Ground Floor", "test" => "1000 sqft"]
        //
        // It must NOT be a list array like:
        // [0 => ["floor_name" => "Ground Floor"]]
        // Otherwise numeric key 0 is treated as field slug "0" and insert fails.
        if ($this->isListArray($repeater)) {
            return false;
        }

        return !array_key_exists('rows', $repeater)
            && !array_key_exists('repeaters', $repeater)
            && collect(array_keys($repeater))->contains(fn($key) => !in_array($key, [
                'custom_field_repeater_id',
                'field_name_slug',
            ], true));
    }

    private function isListArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    private function normalizeRepeaterFieldKey(string $key): string
    {
        return Str::slug(trim($key), '_');
    }

    private function appendMissingMediaCustomFieldDeletes(DynamicPost $post, array $customFields): array
    {
        $submittedFieldIds = collect($customFields)
            ->pluck('custom_field_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $mediaFieldIds = CustomField::whereIn('field_type', ['media', 'file'])
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        if (empty($mediaFieldIds)) {
            return $customFields;
        }

        $existingMediaValues = CustomFieldValue::where('entity_type', 'post')
            ->where('entity_id', $post->id)
            ->whereIn('custom_field_id', $mediaFieldIds)
            ->get();

        foreach ($existingMediaValues as $existingValue) {
            $fieldId = (int) $existingValue->custom_field_id;

            if (in_array($fieldId, $submittedFieldIds, true)) {
                continue;
            }

            $oldValueJson = $this->normalizeCustomFieldValueJson($existingValue->value_json ?? []);

            if (!empty($oldValueJson)) {
                $this->deleteStoredCustomFieldFiles($oldValueJson);
            }

            $customFields[] = [
                'custom_field_id' => $fieldId,
                'value_json' => [],
            ];
        }

        return $customFields;
    }

    private function getExistingCustomFieldValueJson(int $entityId, int $customFieldId): array
    {
        $value = CustomFieldValue::where('entity_type', 'post')
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId)
            ->first();

        if (!$value) {
            return [];
        }

        return $this->normalizeCustomFieldValueJson($value->value_json ?? []);
    }

    private function customFieldMediaStateWasSubmitted(array $fieldData): bool
    {
        return array_key_exists('value_json', $fieldData)
            || array_key_exists('value_string', $fieldData)
            || array_key_exists('value_text', $fieldData)
            || array_key_exists('file', $fieldData)
            || array_key_exists('files', $fieldData);
    }

    private function submittedCustomFieldMediaItems(array $fieldData, array $oldValueJson): array
    {
        $references = [];

        if (array_key_exists('value_json', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_json']));
        }

        if (array_key_exists('value_string', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_string']));
        }

        if (array_key_exists('value_text', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['value_text']));
        }

        if (array_key_exists('file', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['file']));
        }

        if (array_key_exists('files', $fieldData)) {
            $references = array_merge($references, $this->normalizeSubmittedMediaReferences($fieldData['files']));
        }

        if (empty($references)) {
            return [];
        }

        $oldFilesByPath = collect($oldValueJson)
            ->filter(fn($item) => is_array($item) && !empty($item['path']))
            ->keyBy('path');

        $items = [];

        foreach ($references as $reference) {
            $path = null;
            $url = null;

            if (is_array($reference)) {
                $path = $reference['path'] ?? null;
                $url = $reference['url'] ?? null;

                if (!$path && $url) {
                    $path = $this->storagePathFromUrl((string) $url);
                }
            } elseif (is_string($reference)) {
                $url = $reference;
                $path = $this->storagePathFromUrl($reference);
            }

            if (!$path) {
                continue;
            }

            if ($oldFilesByPath->has($path)) {
                $items[] = $oldFilesByPath->get($path);
                continue;
            }

            $items[] = [
                'disk' => 'public',
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'file_name' => basename($path),
                'original_name' => basename($path),
                'mime_type' => null,
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'size' => null,
                'size_kb' => null,
            ];
        }

        return collect($items)
            ->unique('path')
            ->values()
            ->toArray();
    }
    private function normalizeSubmittedMediaReferences(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeSubmittedMediaReferences($decoded);
            }

            return [$value];
        }

        if (is_array($value)) {
            if (array_key_exists('path', $value) || array_key_exists('url', $value)) {
                return [$value];
            }

            $items = [];

            foreach ($value as $item) {
                $items = array_merge($items, $this->normalizeSubmittedMediaReferences($item));
            }

            return $items;
        }

        return [];
    }

    private function normalizeCustomFieldValueJson(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($value)) {
            return $value;
        }

        return [];
    }

    private function deleteRemovedCustomFieldFiles(array $oldFiles, array $newFiles): void
    {
        $newPaths = collect($newFiles)
            ->pluck('path')
            ->filter()
            ->values()
            ->toArray();

        foreach ($oldFiles as $oldFile) {
            $oldPath = $oldFile['path'] ?? null;

            if (!$oldPath || in_array($oldPath, $newPaths, true)) {
                continue;
            }

            $this->deleteStoredCustomFieldFileItem($oldFile);
        }
    }

    private function deleteStoredCustomFieldFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->deleteStoredCustomFieldFileItem($file);
        }
    }

    private function deleteStoredCustomFieldFileItem(array $file): void
    {
        $path = $file['path'] ?? null;
        $disk = $file['disk'] ?? 'public';

        if (!$path) {
            return;
        }

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function extractCustomFieldUploadedFiles(Request $request, int $index): array
    {
        $files = [];

        $singleFile = $request->file("custom_fields.{$index}.file");

        if ($singleFile) {
            $files[] = $singleFile;
        }

        $multipleFiles = $request->file("custom_fields.{$index}.files");

        if ($multipleFiles) {
            if (is_array($multipleFiles)) {
                foreach ($multipleFiles as $file) {
                    if ($file) {
                        $files[] = $file;
                    }
                }
            } else {
                $files[] = $multipleFiles;
            }
        }

        return $files;
    }
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $this->validatePost($request);

            $postType = PostType::with('taxonomies')->find($validated['post_type_id']);

            if (!$postType) {
                return $this->errorResponse('Post type not found.', 404);
            }

            /*
        |--------------------------------------------------------------------------
        | Location Support
        |--------------------------------------------------------------------------
        */
            $hasLocationPayload = $this->hasLocationPayload($validated);

            if ($hasLocationPayload) {
                $this->validatePostTypeLocationSupport($postType);
                $validated = $this->prepareLocationForSave($validated);
            }

            /*
        |--------------------------------------------------------------------------
        | Author
        |--------------------------------------------------------------------------
        */
            if (empty($validated['author_id']) && Auth::id()) {
                $validated['author_id'] = Auth::id();
            }

            $validated = $this->prepareBaseMediaForSave($request, $validated, $postType);

            $submittedTaxonomies = $validated['taxonomies'] ?? [];
            $taxonomyTermIds = $this->normalizeSubmittedTaxonomyTermIds($validated);
            $customFields = $this->prepareCustomFieldsForSave($request, $validated, $postType);

            $relationshipPayloadPresent = $this->hasRelationshipPayload($request->all());

            $relationshipPostTypes = $relationshipPayloadPresent
                ? $this->normalizeRelationshipPostTypeInputs($request->all())
                : [];

            $hasKeywordPayload = $this->hasKeywordPayload($validated);
            $hasAssignedUserPayload = $this->hasAssignedUserPayload($request->all());

            $this->validateSubmittedTaxonomyGroups($postType, $submittedTaxonomies);
            $this->validateTaxonomyTermsForPostType($postType, $taxonomyTermIds);
            $this->validateDependentTaxonomySelections($taxonomyTermIds);
            $this->validateSubmittedCustomFieldsForPostType($postType, $taxonomyTermIds, $customFields);

            if ($hasKeywordPayload) {
                $this->validatePostTypeKeywordSupport($postType);
            }

            if ($relationshipPayloadPresent && empty($relationshipPostTypes)) {
                throw ValidationException::withMessages([
                    'relationship_post_types' => [
                        'Relationship payload was received but no valid dynamic post IDs were found.',
                    ],
                ]);
            }

            $this->validateSubmittedRelationshipPostTypes($postType, $relationshipPostTypes);

            $slug = !empty($validated['slug'])
                ? Str::slug($validated['slug'])
                : Str::slug($validated['title']);

            $slugExists = DynamicPost::where('post_type_id', $validated['post_type_id'])
                ->where('slug', $slug)
                ->exists();

            if ($slugExists) {
                $slug = $slug . '-' . time();
            }

            $post = DB::transaction(function () use (
                $validated,
                $slug,
                $taxonomyTermIds,
                $customFields,
                $postType,
                $relationshipPostTypes,
                $hasKeywordPayload,
                $hasAssignedUserPayload
            ) {
                $postData = $this->dynamicPostPayloadForDatabase($validated);

                $postData['slug'] = $slug;
                $postData['listing_code'] = $this->generateDynamicPostListingCode($postType);
                $postData['status'] = $postData['status'] ?? 'draft';
                $postData['live_status'] = $postData['live_status'] ?? 'submit';

                if ($postData['status'] === 'published' && empty($postData['published_at'])) {
                    $postData['published_at'] = now();
                }

                $post = DynamicPost::create($postData);

                $this->syncTaxonomyTerms($post, $taxonomyTermIds);

                $this->syncDynamicPostRelationships($post, $relationshipPostTypes);

                if (!empty($customFields)) {
                    $this->saveCustomFieldValues($post->id, 'post', $customFields);
                }

                if ($hasAssignedUserPayload) {
                    $this->syncDynamicPostAssignedUser($post, $validated);
                }

                if ($hasKeywordPayload) {
                    $this->syncKeywordsForDynamicPost(
                        post: $post,
                        postType: $postType,
                        input: $this->getKeywordPayload($validated)
                    );
                }

                return $post;
            });

            $freshPost = $post->fresh()->load($this->postRelations);

            $this->clearDynamicPostCaches($freshPost);

            return $this->successResponse(
                'Dynamic post created successfully.',
                $this->formatDynamicPostResponse($freshPost),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (QueryException $e) {
            return $this->databaseErrorResponse($e, 'Database error while creating dynamic post.');
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to create dynamic post.', 500, $e->getMessage());
        }
    }
    private function storeCustomFieldUploadedFiles(array $files, CustomField $field, PostType $postType): array
    {
        $mediaLimit = (int) ($field->media_limit ?? 1);

        if ($mediaLimit < 1) {
            $mediaLimit = 1;
        }

        if (count($files) > $mediaLimit) {
            throw ValidationException::withMessages([
                'custom_fields' => [
                    'You can upload maximum ' . $mediaLimit . ' file(s) for ' . $field->field_label . '.'
                ],
            ]);
        }

        $allowedExtensions = $this->allowedCustomFieldFileExtensions($field);
        $maxSizeKb = $this->parseCustomFieldMediaSizeToKb($field->media_size);

        $postTypeSlug = Str::slug($postType->slug ?? $postType->name ?? 'common', '-');
        $fieldSlug = Str::slug($field->field_name_slug ?? $field->field_label, '-');

        $directory = implode('/', [
            'uploads',
            'custom-fields',
            $postTypeSlug,
            $fieldSlug,
            now()->format('Y'),
            now()->format('m'),
        ]);

        $uploaded = [];

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension());

            if (!in_array($extension, $allowedExtensions, true)) {
                throw ValidationException::withMessages([
                    'custom_fields' => [
                        'Invalid file format for ' . $field->field_label . '. Allowed formats: ' . implode(', ', $allowedExtensions)
                    ],
                ]);
            }

            if (($file->getSize() / 1024) > $maxSizeKb) {
                throw ValidationException::withMessages([
                    'custom_fields' => [
                        'File size is too large for ' . $field->field_label . '. Maximum allowed size is ' . round($maxSizeKb / 1024, 2) . ' MB.'
                    ],
                ]);
            }

            $fileName = Str::uuid()->toString() . '.' . $extension;
            $path = $file->storeAs($directory, $fileName, 'public');

            $uploaded[] = [
                'disk' => 'public',
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'file_name' => $fileName,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'extension' => $extension,
                'size' => $file->getSize(),
                'size_kb' => round($file->getSize() / 1024, 2),
            ];
        }

        return $uploaded;
    }

    private function allowedCustomFieldFileExtensions(CustomField $field): array
    {
        if (!empty($field->media_format)) {
            return collect(explode(',', $field->media_format))
                ->map(fn($format) => strtolower(trim($format)))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        }

        if ($field->field_type === 'media') {
            return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }

        return ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    }

    private function parseCustomFieldMediaSizeToKb(?string $size): int
    {
        if (empty($size)) {
            return 10240;
        }

        $size = strtolower(trim($size));
        $size = str_replace(' ', '', $size);

        if (is_numeric($size)) {
            return (int) $size * 1024;
        }

        if (preg_match('/^(\d+)(kb|mb|gb)$/', $size, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'gb' => $value * 1024 * 1024,
                'mb' => $value * 1024,
                'kb' => $value,
                default => 10240,
            };
        }

        return 10240;
    }

    private function containsTemporaryFilePath(mixed $value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (is_string($value)) {
            return str_contains($value, '/tmp/')
                || str_contains($value, '\\tmp\\')
                || str_starts_with($value, 'tmp/')
                || str_starts_with($value, '/private/var/tmp/');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsTemporaryFilePath($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertTaxonomyTermsExist(array $termIds): void
    {
        if (empty($termIds)) {
            return;
        }

        $existingTermIds = TaxonomyTerm::whereIn('id', $termIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $missingTermIds = array_values(array_diff($termIds, $existingTermIds));

        if (!empty($missingTermIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['One or more taxonomy term ids do not exist.'],
            ]);
        }
    }

    private function validateTaxonomyTermsForPostType(PostType $postType, array $termIds): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['taxonomies'] && !empty($termIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['This post type does not support taxonomies.'],
            ]);
        }

        if (empty($termIds)) {
            return;
        }

        $attachedTaxonomies = $postType->activeTaxonomies()->get();

        $allowedTaxonomyIds = $attachedTaxonomies
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $terms = TaxonomyTerm::whereIn('id', $termIds)->get(['id', 'taxonomy_id', 'name']);

        $invalidTaxonomyTermIds = $terms
            ->filter(fn($term) => !in_array((int) $term->taxonomy_id, $allowedTaxonomyIds, true))
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        if (!empty($invalidTaxonomyTermIds)) {
            throw ValidationException::withMessages([
                'taxonomy_term_ids' => ['Some taxonomy terms do not belong to this post type taxonomies.'],
            ]);
        }

        $termsByTaxonomy = $terms->groupBy('taxonomy_id');

        foreach ($attachedTaxonomies as $taxonomy) {
            $selectionType = $this->getTaxonomySelectionType($taxonomy);
            $selectedCount = $termsByTaxonomy->get($taxonomy->id, collect())->count();

            if ($selectionType === 'single' && $selectedCount > 1) {
                throw ValidationException::withMessages([
                    'taxonomy_term_ids' => [$taxonomy->name . ' allows only one term.'],
                ]);
            }
        }
    }

    private function validateSubmittedCustomFieldsForPostType(PostType $postType, array $termIds, array $customFields): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!$supports['custom_fields'] && !empty($customFields)) {
            throw ValidationException::withMessages([
                'custom_fields' => ['This post type does not support custom fields.'],
            ]);
        }

        if (empty($customFields)) {
            return;
        }

        $allowedFieldIds = $this->resolveVisibleCustomFields($postType->id, $termIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        $submittedFieldIds = collect($customFields)
            ->pluck('custom_field_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $unsupportedFieldIds = array_values(array_diff($submittedFieldIds, $allowedFieldIds));

        if (!empty($unsupportedFieldIds)) {
            throw ValidationException::withMessages([
                'custom_fields' => ['Some custom fields are not allowed for selected post type or selected taxonomy terms.'],
            ]);
        }
    }

    private function normalizePostTypeSupports(array|string|null $supports): array
    {
        if (is_string($supports)) {
            $decoded = json_decode($supports, true);
            $supports = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($supports)) {
            $supports = [];
        }

        $supports = collect($supports)
            ->map(fn($item) => Str::slug((string) $item, '_'))
            ->map(function ($item) {
                return match ($item) {
                    'editor' => 'content',
                    'keyword' => 'keywords',
                    'locations',
                    'location_field',
                    'location_fields' => 'location',
                    default => $item,
                };
            })
            ->values()
            ->toArray();

        return [
            'featured_image' => in_array('featured_image', $supports, true),
            'title' => in_array('title', $supports, true),
            'custom_fields' => in_array('custom_fields', $supports, true),
            'content' => in_array('content', $supports, true),
            'taxonomies' => in_array('taxonomies', $supports, true),
            'excerpt' => in_array('excerpt', $supports, true),
            'gallery' => in_array('gallery', $supports, true),
            'keywords' => in_array('keywords', $supports, true),
            'location' => in_array('location', $supports, true),
        ];
    }

    private function getTaxonomySelectionType($taxonomy): string
    {
        $pivotType = $taxonomy->pivot?->selection_type ?? null;

        if (in_array($pivotType, ['single', 'multiple'], true)) {
            return $pivotType;
        }

        $slug = Str::slug($taxonomy->slug ?? $taxonomy->name, '-');

        return in_array($slug, ['property-types', 'property-type', 'property-status'], true)
            ? 'multiple'
            : 'single';
    }

    private function buildTaxonomyFields(PostType $postType, array $selectedTermIds = [])
    {
        return $postType->taxonomies->map(function ($taxonomy) use ($selectedTermIds) {
            $selectionType = $this->getTaxonomySelectionType($taxonomy);

            $terms = TaxonomyTerm::query()
                ->with([
                    'relationValues' => function ($query) {
                        $query
                            ->select(
                                'taxonomy_terms.id',
                                'taxonomy_terms.taxonomy_id',
                                'taxonomy_terms.name',
                                'taxonomy_terms.slug'
                            )
                            ->where('taxonomy_terms.status', true)
                            ->wherePivot('status', true);
                    },
                ])
                ->select(
                    'id',
                    'taxonomy_id',
                    'parent_id',
                    'relation_with_taxonomy_id',
                    'name',
                    'slug'
                )
                ->where('taxonomy_id', $taxonomy->id)
                ->where('status', true)
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $dependencyMeta = $this->getTaxonomyDependencyMeta($terms, $selectedTermIds);
            $terms = $this->filterDependentTaxonomyTerms($terms, $selectedTermIds);

            return [
                'taxonomy_id' => $taxonomy->id,
                'taxonomy_name' => $taxonomy->name,
                'taxonomy_slug' => $taxonomy->slug,
                'field_label' => $taxonomy->name,
                'field_name' => 'taxonomies',
                'selection_type' => $selectionType,
                'multiple' => $selectionType === 'multiple',
                'request_key' => $selectionType === 'multiple' ? 'taxonomy_term_ids' : 'taxonomy_term_id',
                'is_dependent' => $dependencyMeta['is_dependent'],
                'depends_on_taxonomy_ids' => $dependencyMeta['depends_on_taxonomy_ids'],
                'depends_on_term_ids' => $dependencyMeta['depends_on_term_ids'],
                'depends_on_selected_term_ids' => $dependencyMeta['depends_on_selected_term_ids'],
                'terms' => $terms->map(fn($term) => [
                    'id' => $term->id,
                    'taxonomy_id' => $term->taxonomy_id,
                    'parent_id' => $term->parent_id,
                    'relation_with_taxonomy_id' => $term->relation_with_taxonomy_id,
                    'relation_value_term_ids' => $term->relationValues
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->values(),
                    'name' => $term->name,
                    'slug' => $term->slug,
                ])->values(),
            ];
        })->values();
    }

    private function getTaxonomyDependencyMeta($terms, array $selectedTermIds): array
    {
        $dependentTerms = $terms->filter(function ($term) {
            return !empty($term->relation_with_taxonomy_id) || $term->relationValues->isNotEmpty();
        });

        $dependsOnTaxonomyIds = $dependentTerms
            ->pluck('relation_with_taxonomy_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $dependsOnTermIds = $dependentTerms
            ->flatMap(fn($term) => $term->relationValues->pluck('id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $dependsOnSelectedTermIds = $dependsOnTermIds
            ->filter(fn($id) => in_array((int) $id, $selectedTermIds, true))
            ->values();

        return [
            'is_dependent' => $dependentTerms->isNotEmpty(),
            'depends_on_taxonomy_ids' => $dependsOnTaxonomyIds,
            'depends_on_term_ids' => $dependsOnTermIds,
            'depends_on_selected_term_ids' => $dependsOnSelectedTermIds,
        ];
    }

    private function filterDependentTaxonomyTerms($terms, array $selectedTermIds)
    {
        $hasDependentTerms = $terms->contains(function ($term) {
            return !empty($term->relation_with_taxonomy_id) || $term->relationValues->isNotEmpty();
        });

        if (!$hasDependentTerms) {
            return $terms->values();
        }

        if (empty($selectedTermIds)) {
            return collect();
        }

        return $terms
            ->filter(function ($term) use ($selectedTermIds) {
                $relationValueTermIds = $term->relationValues
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->toArray();

                if (empty($relationValueTermIds)) {
                    return false;
                }

                return count(array_intersect($relationValueTermIds, $selectedTermIds)) > 0;
            })
            ->values();
    }


    private function baseFields(array $supports): array
    {
        return [
            [
                'key' => 'featured_image_id',
                'field_name_slug' => 'featured_image_id',
                'request_key' => 'featured_image_id',
                'label' => 'Featured Image',
                'field_label' => 'Featured Image',
                'type' => 'media',
                'field_type' => 'media',
                'enabled' => $supports['featured_image'] ?? false,
                'is_base_field' => true,
            ],
            [
                'key' => 'title',
                'field_name_slug' => 'title',
                'request_key' => 'title',
                'label' => 'Title',
                'field_label' => 'Title',
                'type' => 'text',
                'field_type' => 'text',
                'enabled' => $supports['title'] ?? false,
                'is_base_field' => true,
            ],
            [
                'key' => 'content',
                'field_name_slug' => 'content',
                'request_key' => 'content',
                'label' => 'Content',
                'field_label' => 'Content',
                'type' => 'richtext',
                'field_type' => 'richtext',
                'enabled' => $supports['content'] ?? false,
                'is_base_field' => true,
            ],
            [
                'key' => 'excerpt',
                'field_name_slug' => 'excerpt',
                'request_key' => 'excerpt',
                'label' => 'Excerpt',
                'field_label' => 'Excerpt',
                'type' => 'textarea',
                'field_type' => 'textarea',
                'enabled' => $supports['excerpt'] ?? false,
                'is_base_field' => true,
            ],
            [
                'key' => 'gallery_image_ids',
                'field_name_slug' => 'gallery_image_ids',
                'request_key' => 'gallery_image_ids',
                'label' => 'Gallery',
                'field_label' => 'Gallery',
                'type' => 'gallery',
                'field_type' => 'gallery',
                'enabled' => $supports['gallery'] ?? false,
                'multiple' => true,
                'is_base_field' => true,
            ],
            [
                'key' => 'country_id',
                'field_name_slug' => 'country_id',
                'request_key' => 'country_id',
                'label' => 'Country',
                'field_label' => 'Country',
                'type' => 'select',
                'field_type' => 'select',
                'enabled' => $supports['location'] ?? false,
                'multiple' => false,
                'nullable' => true,
                'options_api' => 'countries',
                'is_base_field' => true,
            ],
            [
                'key' => 'state_id',
                'field_name_slug' => 'state_id',
                'request_key' => 'state_id',
                'label' => 'State',
                'field_label' => 'State',
                'type' => 'select',
                'field_type' => 'select',
                'enabled' => $supports['location'] ?? false,
                'multiple' => false,
                'nullable' => true,
                'depends_on' => 'country_id',
                'options_api' => 'states/{country_id}',
                'is_base_field' => true,
            ],
            [
                'key' => 'city_id',
                'field_name_slug' => 'city_id',
                'request_key' => 'city_id',
                'label' => 'City',
                'field_label' => 'City',
                'type' => 'select',
                'field_type' => 'select',
                'enabled' => $supports['location'] ?? false,
                'multiple' => false,
                'nullable' => true,
                'depends_on' => 'state_id',
                'options_api' => 'cities/{state_id}',
                'is_base_field' => true,
            ],
            [
                'key' => 'area_locality',
                'field_name_slug' => 'area_locality',
                'request_key' => 'area_locality',
                'label' => 'Area / Locality',
                'field_label' => 'Area / Locality',
                'type' => 'text',
                'field_type' => 'text',
                'enabled' => $supports['location'] ?? false,
                'nullable' => true,
                'is_base_field' => true,
            ],
            [
                'key' => 'keywords',
                'field_name_slug' => 'keywords',
                'request_key' => 'keywords',
                'label' => 'Keywords',
                'field_label' => 'Keywords',
                'type' => 'tag_autocomplete',
                'field_type' => 'tag_autocomplete',
                'enabled' => $supports['keywords'] ?? false,
                'multiple' => true,
                'suggestion_api' => 'dynamic-post-keyword-suggestions',
                'is_base_field' => true,
            ],
        ];
    }

    private function statusOptions(): array
    {
        return [
            [
                'label' => 'Ready for Publish',
                'value' => 'published',
            ],
            [
                'label' => 'Save as Draft',
                'value' => 'draft',
            ],
        ];
    }

    private function liveStatusOptions(): array
    {
        return [
            [
                'label' => 'Approve',
                'value' => 'approve',
            ],
            [
                'label' => 'Reject',
                'value' => 'reject',
            ],
            [
                'label' => 'Under Review',
                'value' => 'under_review',
            ],
            [
                'label' => 'Disapprove',
                'value' => 'disapprove',
            ],
            [
                'label' => 'Modify Review',
                'value' => 'modify_review',
            ],
            [
                'label' => 'Submit',
                'value' => 'submit',
            ],
        ];
    }

    private function resolveVisibleCustomFields(int $postTypeId, array $selectedTermIds)
    {
        $selectedTerms = TaxonomyTerm::whereIn('id', $selectedTermIds)
            ->get(['id', 'taxonomy_id']);

        $selectedTaxonomyIds = $selectedTerms
            ->pluck('taxonomy_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        return CustomField::query()
            ->with([
                'group.locationRules' => function ($query) {
                    $query->where('status', true)
                        ->whereNull('custom_field_id')
                        ->orderBy('rule_group', 'asc')
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'options' => function ($query) {
                    $query->where('status', true)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'repeaters' => function ($query) {
                    $query->where('status', true)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc')
                        ->with([
                            'options' => function ($optionQuery) {
                                $optionQuery->where('status', true)
                                    ->orderBy('sort_order', 'asc')
                                    ->orderBy('id', 'asc');
                            },
                        ]);
                },
                'locationRules' => function ($query) {
                    $query->where('status', true)
                        ->whereNotNull('custom_field_id')
                        ->orderBy('rule_group', 'asc')
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('id', 'asc');
                },
                'conditions.taxonomy',
                'conditions.taxonomyTerm',
            ])
            ->where('status', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->filter(function ($field) use ($postTypeId, $selectedTaxonomyIds, $selectedTermIds) {
                return $this->fieldLocationRulesMatch($field, $postTypeId, $selectedTaxonomyIds, $selectedTermIds)
                    && $this->fieldConditionsMatch($field, $selectedTermIds);
            })
            ->values();
    }
    private function fieldConditionsMatch($field, array $selectedTermIds): bool
    {
        $conditions = $field->conditions ?? collect();

        if ($conditions->isEmpty()) {
            return true;
        }

        $includeTermIds = $conditions
            ->filter(fn($condition) => ($condition->operator ?? 'include') === 'include')
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        $excludeTermIds = $conditions
            ->filter(fn($condition) => ($condition->operator ?? 'include') === 'exclude')
            ->pluck('taxonomy_term_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();

        if (!empty($excludeTermIds) && count(array_intersect($excludeTermIds, $selectedTermIds)) > 0) {
            return false;
        }

        if (!empty($includeTermIds)) {
            return count(array_intersect($includeTermIds, $selectedTermIds)) > 0;
        }

        return true;
    }
    private function fieldLocationRulesMatch($field, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        $groupRules = $field->group?->locationRules ?? collect();
        $fieldRules = $field->locationRules ?? collect();

        $groupMatched = $groupRules->isEmpty()
            ? true
            : $this->locationRulesCollectionMatch($groupRules, $postTypeId, $selectedTaxonomyIds, $selectedTermIds);

        if (!$groupMatched) {
            return false;
        }

        if ($fieldRules->isNotEmpty()) {
            return $this->locationRulesCollectionMatch($fieldRules, $postTypeId, $selectedTaxonomyIds, $selectedTermIds);
        }

        return true;
    }
    private function locationRulesCollectionMatch($rules, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        if ($rules->isEmpty()) {
            return true;
        }

        $groups = $rules->groupBy(fn($rule) => $rule->rule_group ?: 1);

        foreach ($groups as $groupRules) {
            $matched = true;

            foreach ($groupRules as $rule) {
                if (!$this->locationRuleMatches($rule, $postTypeId, $selectedTaxonomyIds, $selectedTermIds)) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }
    private function locationRuleMatches($rule, int $postTypeId, array $selectedTaxonomyIds, array $selectedTermIds): bool
    {
        $operator = $rule->operator ?: 'is_equal_to';
        $matchType = $rule->match_type ?: 'specific';

        if ($rule->show_if === 'post_type') {
            $matched = $matchType === 'all'
                ? true
                : (int) $rule->post_type_id === $postTypeId;

            return $operator === 'is_not_equal_to' ? !$matched : $matched;
        }

        if ($rule->show_if === 'taxonomy') {
            if ($matchType === 'all') {
                $matched = true;
            } else {
                $taxonomyMatched = empty($rule->taxonomy_id)
                    ? true
                    : in_array((int) $rule->taxonomy_id, $selectedTaxonomyIds, true);

                $ruleTermIds = $this->normalizeIds($rule->taxonomy_term_ids ?? []);

                $termMatched = empty($ruleTermIds)
                    ? $taxonomyMatched
                    : count(array_intersect($ruleTermIds, $selectedTermIds)) > 0;

                $matched = $taxonomyMatched && $termMatched;
            }

            return $operator === 'is_not_equal_to' ? !$matched : $matched;
        }

        return false;
    }

    private function formatCustomField($field): array
    {
        return [
            'id' => $field->id,
            'custom_field_group_id' => $field->custom_field_group_id,
            'field_label' => $field->field_label,
            'field_name_slug' => $field->field_name_slug,
            'field_placeholder' => $field->field_placeholder,
            'field_type' => $field->field_type,
            'required' => $field->required,
            'checkbox_type' => $field->checkbox_type,
            'default_value' => $field->default_value,
            'validation_rules' => $field->validation_rules,
            'conditional_rules' => $field->conditional_rules,
            'media_limit' => $field->media_limit,
            'media_size' => $field->media_size,
            'media_format' => $field->media_format,
            'sort_order' => $field->sort_order,
            'status' => $field->status,
            'location_rules' => ($field->locationRules ?? collect())->map(fn($rule) => [
                'id' => $rule->id,
                'logic_operator' => $rule->logic_operator,
                'rule_group' => $rule->rule_group,
                'show_if' => $rule->show_if,
                'operator' => $rule->operator,
                'match_type' => $rule->match_type,
                'post_type_id' => $rule->post_type_id,
                'taxonomy_id' => $rule->taxonomy_id,
                'taxonomy_term_ids' => $rule->taxonomy_term_ids ?? [],
                'status' => $rule->status,
                'sort_order' => $rule->sort_order,
            ])->values(),
            'options' => ($field->options ?? collect())->map(fn($option) => [
                'id' => $option->id,
                'name' => $option->name,
                'value' => $option->value,
                'type' => $option->type,
                'sort_order' => $option->sort_order,
            ])->values(),
            'repeaters' => ($field->repeaters ?? collect())->map(fn($repeater) => [
                'id' => $repeater->id,
                'field_label' => $repeater->field_label,
                'field_name_slug' => $repeater->field_name_slug,
                'field_placeholder' => $repeater->field_placeholder,
                'field_type' => $repeater->field_type,
                'media_limit' => $repeater->media_limit,
                'media_size' => $repeater->media_size,
                'media_format' => $repeater->media_format,
                'sort_order' => $repeater->sort_order,
                'options' => ($repeater->options ?? collect())->map(fn($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'value' => $option->value,
                    'type' => $option->type,
                    'sort_order' => $option->sort_order,
                ])->values(),
            ])->values(),
        ];
    }

    private function formatDynamicPostResponse(DynamicPost $post): array
    {
        $post->loadMissing([
            'postType',
            'parent:id,post_type_id,title,slug,status,live_status',
            'taxonomyTerms.taxonomy',
            'meta.customField.options',
            'meta.customField.repeaters.options',
            'latestVerificationRevision',
        ]);

        $data = $post->toArray();
        $latestRevision = $post->latestVerificationRevision;

        $workflowStatus = $latestRevision?->status
            ?: $this->workflowStatusFromLegacy(
                $post->status ?? null,
                $post->live_status ?? null
            );

        $data['workflow_status'] = $workflowStatus;
        $data['verification_status'] = $workflowStatus;

        $data['review_status_label'] = $this->reviewStatusLabel(
            $workflowStatus
        );

        $data['is_under_review'] = in_array(
            $workflowStatus,
            [
                'under_review',
                'resubmission',
                'assigned',
                'in_verification',
                'submit',
            ],
            true
        );

        $data['is_active'] =
            ($post->status ?? null) === 'published'
            && ($post->live_status ?? null) === 'approve'
            && in_array($workflowStatus, ['approved', 'approve'], true);

        $data['is_rejected'] = in_array(
            $workflowStatus,
            ['rejected', 'reject', 'disapprove'],
            true
        );

        $data['latest_verification_revision'] = $latestRevision
            ? [
                'id' => (int) $latestRevision->id,
                'dynamic_post_id' => (int) $latestRevision->dynamic_post_id,
                'version' => (int) $latestRevision->version,
                'source' => $latestRevision->source,
                'status' => $latestRevision->status,

                'submitted_by' => $latestRevision->submitted_by
                    ? (int) $latestRevision->submitted_by
                    : null,

                'assigned_to' => $latestRevision->assigned_to
                    ? (int) $latestRevision->assigned_to
                    : null,

                'assigned_by' => $latestRevision->assigned_by
                    ? (int) $latestRevision->assigned_by
                    : null,

                'decided_by' => $latestRevision->decided_by
                    ? (int) $latestRevision->decided_by
                    : null,

                'submitted_at' => optional(
                    $latestRevision->submitted_at
                )->toISOString(),

                'assigned_at' => optional(
                    $latestRevision->assigned_at
                )->toISOString(),

                'verification_started_at' => optional(
                    $latestRevision->verification_started_at
                )->toISOString(),

                'decided_at' => optional(
                    $latestRevision->decided_at
                )->toISOString(),

                'rejection_reason' => $latestRevision->rejection_reason,
            ]
            : null;
        $data['selected_taxonomies'] = $this->formatSelectedTaxonomies($post);
        $data['display_id'] = $post->listing_code ?? null;

        $featuredMedia = $this->formatMediaFileById($post->featured_image_id ?? null);
        $galleryMedia = $this->formatMediaFilesByIds($post->gallery_image_ids ?? []);

        $data['featured_image'] = $featuredMedia['url'] ?? null;
        $data['featured_image_media'] = $featuredMedia;

        $data['gallery_images'] = collect($galleryMedia)
            ->pluck('url')
            ->filter()
            ->values()
            ->toArray();

        $data['gallery_image_files'] = $galleryMedia;

        $data['meta'] = $this->formatMetaForFrontend($data['meta'] ?? []);

        $data['selected_relationship_post_types'] = $this->formatRelationshipPostTypesForFrontend($post);

        $data['selected_keywords'] = $this->formatSelectedKeywords($post);
        $data['keywords'] = $data['selected_keywords'];

        $assignedUser = $this->formatAssignedUser($post);

        $data['assigned_user'] = $assignedUser;
        $data['assigned_user_id'] = $assignedUser['id'] ?? null;

        // Keep user_id as integer for frontend edit form
        $data['user_id'] = $assignedUser['id'] ?? ($post->author_id ? (int) $post->author_id : null);

        $data['country_id'] = $post->country_id ? (int) $post->country_id : null;
        $data['state_id'] = $post->state_id ? (int) $post->state_id : null;
        $data['city_id'] = $post->city_id ? (int) $post->city_id : null;
        $data['area_locality'] = $post->area_locality ?? null;

        $data['location'] = $this->formatLocationForDynamicPost($post);

        return $data;
    }
    private function workflowStatusFromLegacy(
        ?string $status,
        ?string $liveStatus
    ): string {
        if (
            $status === 'published'
            && $liveStatus === 'approve'
        ) {
            return 'approved';
        }

        if (in_array(
            $liveStatus,
            ['reject', 'disapprove'],
            true
        )) {
            return 'rejected';
        }

        if (in_array(
            $liveStatus,
            ['under_review', 'submit'],
            true
        )) {
            return 'under_review';
        }

        return 'pending';
    }

    private function reviewStatusLabel(?string $status): string
    {
        return match ($status) {
            'approve', 'approved' => 'Approved',
            'reject', 'rejected' => 'Rejected',
            'disapprove' => 'Disapproved',
            'under_review' => 'Under Review',
            'resubmission' => 'Resubmitted',
            'assigned' => 'Assigned',
            'in_verification' => 'In Verification',
            'modify_review' => 'Modification Required',
            'submit' => 'Submitted',
            default => 'Pending',
        };
    }
    private function formatMediaFileById(null|int|string $mediaId): ?array
    {
        if (empty($mediaId)) {
            return null;
        }

        $media = MediaFile::find((int) $mediaId);

        return $media ? $this->formatMediaFile($media) : null;
    }

    private function formatMediaFilesByIds(array|string|null $mediaIds): array
    {
        $ids = $this->normalizeIds($mediaIds);

        if (empty($ids)) {
            return [];
        }

        $mediaFiles = MediaFile::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn($id) => $mediaFiles->get((int) $id))
            ->filter()
            ->map(fn($media) => $this->formatMediaFile($media))
            ->values()
            ->toArray();
    }

    private function formatMediaFile(MediaFile $media): array
    {
        return [
            'id' => (int) $media->id,
            'disk' => $media->disk ?: 'public',
            'context' => $media->context,
            'post_type_slug' => $media->post_type_slug,
            'field_slug' => $media->field_slug,
            'directory' => $media->directory,
            'path' => $media->path,
            'url' => $this->mediaFileUrl($media),
            'file_name' => $media->file_name,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'extension' => $media->extension,
            'size' => $media->size,
            'size_kb' => $media->size ? round($media->size / 1024, 2) : null,
            'created_at' => optional($media->created_at)->toISOString(),
            'updated_at' => optional($media->updated_at)->toISOString(),
        ];
    }

    private function getRepeaterValuesForMeta(int $entityId, int $customFieldId): array
    {
        if (!Schema::hasTable('custom_field_repeater_values')) {
            return [];
        }

        $values = DB::table('custom_field_repeater_values')
            ->where('entity_type', 'post')
            ->where('entity_id', $entityId)
            ->where('custom_field_id', $customFieldId)
            ->orderBy('row_index')
            ->orderBy('sort_order')
            ->get();

        if ($values->isEmpty()) {
            return [];
        }

        $repeaterIds = $values
            ->pluck('custom_field_repeater_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $slugByRepeaterId = CustomFieldRepeater::whereIn('id', $repeaterIds)
            ->pluck('field_name_slug', 'id')
            ->toArray();

        $rows = [];

        foreach ($values as $value) {
            $rowIndex = property_exists($value, 'row_index') ? (int) $value->row_index : 0;
            $fieldSlug = $value->field_name_slug ?? null;

            if (!$fieldSlug && !empty($value->custom_field_repeater_id)) {
                $fieldSlug = $slugByRepeaterId[(int) $value->custom_field_repeater_id] ?? null;
            }

            if (!$fieldSlug) {
                continue;
            }

            $fieldValue = $value->field_meta_value
                ?? $value->value_string
                ?? $value->value_text
                ?? $value->value_number
                ?? $value->value_date
                ?? $value->value_datetime
                ?? $value->value_json
                ?? null;

            $decoded = is_string($fieldValue) ? json_decode($fieldValue, true) : null;
            $rows[$rowIndex][$fieldSlug] = json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                ? $decoded
                : $fieldValue;
        }

        ksort($rows);

        return collect($rows)->values()->toArray();
    }

    private function formatMetaForFrontend(array $meta): array
    {
        return collect($meta)
            ->map(function ($item) {
                $fieldType = $item['custom_field']['field_type'] ?? null;
                $rawValueJson = $item['value_json'] ?? [];
                $normalizedValueJson = $this->normalizeCustomFieldValueJson($rawValueJson);

                if ($fieldType === 'repeater') {
                    $item['repeaters'] = $this->getRepeaterValuesForMeta(
                        (int) ($item['entity_id'] ?? 0),
                        (int) ($item['custom_field_id'] ?? 0)
                    );

                    $item['value_json'] = null;
                    return $item;
                }

                $item['repeaters'] = $normalizedValueJson['repeaters'] ?? [];

                if (!in_array($fieldType, ['media', 'file'], true)) {
                    return $item;
                }

                $mediaSource = $normalizedValueJson;

                if (isset($normalizedValueJson['media']) && is_array($normalizedValueJson['media'])) {
                    $mediaSource = $normalizedValueJson['media'];
                }

                if (isset($normalizedValueJson['repeaters'])) {
                    unset($mediaSource['repeaters']);
                }

                $mediaFiles = collect($mediaSource)
                    ->filter(fn($media) => is_array($media))
                    ->map(function ($media) {
                        $path = $media['path'] ?? null;
                        $url = $media['url'] ?? null;

                        if (!$url && $path) {
                            $url = Storage::disk($media['disk'] ?? 'public')->url($path);
                        }

                        return array_merge($media, [
                            'url' => $url,
                        ]);
                    })
                    ->filter(fn($media) => !empty($media['url']))
                    ->values()
                    ->toArray();

                $mediaUrls = collect($mediaFiles)
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->toArray();

                $firstUrl = $mediaUrls[0] ?? null;

                $item['media_files'] = $mediaFiles;
                $item['media_urls'] = $mediaUrls;
                $item['value_json_raw'] = $rawValueJson;

                $item['value_string'] = $firstUrl;
                $item['value_text'] = $firstUrl;
                $item['value_json'] = $firstUrl;

                return $item;
            })
            ->values()
            ->toArray();
    }

    private function formatSelectedTaxonomies(DynamicPost $post): array
    {
        $terms = $post->taxonomyTerms ?? collect();

        return $terms
            ->groupBy('taxonomy_id')
            ->map(function ($taxonomyTerms) {
                $firstTerm = $taxonomyTerms->first();
                $taxonomy = $firstTerm->taxonomy;

                if (!$taxonomy) {
                    return null;
                }

                $selectionType = $this->getTaxonomySelectionType($taxonomy);

                return [
                    'taxonomy_id' => $taxonomy->id,
                    'taxonomy_name' => $taxonomy->name,
                    'taxonomy_slug' => $taxonomy->slug,
                    'selection_type' => $selectionType,
                    'multiple' => $selectionType === 'multiple',
                    'selected_term_id' => $selectionType === 'single' ? $firstTerm->id : null,
                    'selected_term_ids' => $taxonomyTerms->pluck('id')->map(fn($id) => (int) $id)->values(),
                    'selected_terms' => $taxonomyTerms->map(fn($term) => [
                        'id' => $term->id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ])->values(),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function isConditionalFieldVisible(null|array|string $rules, array $selectedValues): bool
    {
        if (empty($rules)) {
            return true;
        }

        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules)) {
            return true;
        }

        $dependsOn = $rules['depends_on'] ?? $rules['field'] ?? null;
        $operator = $rules['operator'] ?? 'equals';
        $expectedValue = $rules['value'] ?? null;
        $action = $rules['action'] ?? 'show';

        if (!$dependsOn) {
            return true;
        }

        $actualValue = $selectedValues[$dependsOn] ?? null;

        $matched = match ($operator) {
            'equals' => $actualValue == $expectedValue,
            'not_equals' => $actualValue != $expectedValue,
            'in' => in_array($actualValue, (array) $expectedValue),
            'not_in' => !in_array($actualValue, (array) $expectedValue),
            'empty' => empty($actualValue),
            'not_empty' => !empty($actualValue),
            default => true,
        };

        return $action === 'hide' ? !$matched : $matched;
    }
    private function hasKeywordPayload(array $payload): bool
    {
        return array_key_exists('keyword', $payload)
            || array_key_exists('keywords', $payload);
    }

    private function getKeywordPayload(array $payload): mixed
    {
        if (array_key_exists('keywords', $payload)) {
            return $payload['keywords'];
        }

        return $payload['keyword'] ?? null;
    }

    private function validatePostTypeKeywordSupport(PostType $postType): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!($supports['keywords'] ?? false)) {
            throw ValidationException::withMessages([
                'keywords' => [
                    'This post type does not support keywords. Please enable Keywords in post type supports.',
                ],
            ]);
        }
    }

    private function syncKeywordsForDynamicPost(
        DynamicPost $post,
        PostType $postType,
        mixed $input
    ): void {
        if (
            !Schema::hasTable('keywords')
            || !Schema::hasTable('keyword_post_type')
            || !Schema::hasTable('keyword_dynamic_post')
        ) {
            throw ValidationException::withMessages([
                'keywords' => [
                    'Keyword tables are missing. Please run keyword migrations first.',
                ],
            ]);
        }

        $normalized = $this->normalizeSubmittedKeywords($input);

        if (empty($normalized)) {
            DB::table('keyword_dynamic_post')
                ->where('dynamic_post_id', $post->id)
                ->delete();

            return;
        }

        $keywordIds = [];

        foreach ($normalized as $item) {
            $keyword = null;

            /*
         |--------------------------------------------------------------------------
         | Existing keyword selected from dropdown
         |--------------------------------------------------------------------------
         | Payload example:
         | { "id": 5, "label": "luxury flat", "value": "luxury flat" }
         |--------------------------------------------------------------------------
         */
            if (!empty($item['id'])) {
                $keyword = Keyword::find((int) $item['id']);

                if (!$keyword) {
                    throw ValidationException::withMessages([
                        'keywords' => [
                            'Invalid keyword id: ' . $item['id'],
                        ],
                    ]);
                }

                /*
             | Existing keyword selected:
             | Do not remove old relations.
             | Just attach this listing also.
             */
                $keyword->postTypes()->syncWithoutDetaching([
                    (int) $postType->id,
                ]);

                $keyword->dynamicPosts()->syncWithoutDetaching([
                    (int) $post->id,
                ]);

                $keywordIds[] = (int) $keyword->id;

                continue;
            }

            /*
         |--------------------------------------------------------------------------
         | New typed keyword
         |--------------------------------------------------------------------------
         | Payload example:
         | "ready to move flat"
         |
         | This will save keyword with current:
         | - keyword_type = post_types.id
         | - listing/post_type = dynamic_posts.id
         |--------------------------------------------------------------------------
         */
            $keywordText = Keyword::normalizeKeyword($item['keyword'] ?? '');

            if ($keywordText === '') {
                continue;
            }

            $keyword = $this->findExistingKeywordForDynamicPost(
                keyword: $keywordText,
                postTypeId: (int) $postType->id,
                dynamicPostId: (int) $post->id
            );

            $payloadForSave = [
                'keyword' => $keywordText,
                'status' => $item['status'] ?? 'active',
                'avg_search_volume' => $item['avg_search_volume'] ?? null,
                'avg_ranking' => $item['avg_ranking'] ?? null,
            ];

            if ($keyword) {
                $keyword->update($payloadForSave);
            } else {
                $keyword = Keyword::create($payloadForSave);
            }

            /*
         | Same relation style as KeywordController store.
         | New keyword belongs to current post type and current listing.
         */
            $keyword->postTypes()->sync([
                (int) $postType->id,
            ]);

            $keyword->dynamicPosts()->sync([
                (int) $post->id,
            ]);

            $keywordIds[] = (int) $keyword->id;
        }

        $keywordIds = collect($keywordIds)
            ->unique()
            ->values()
            ->toArray();

        if (empty($keywordIds)) {
            DB::table('keyword_dynamic_post')
                ->where('dynamic_post_id', $post->id)
                ->delete();

            return;
        }

        /*
     | Remove only unselected keywords from this listing.
     | It will not delete keyword from keywords table.
     */
        DB::table('keyword_dynamic_post')
            ->where('dynamic_post_id', $post->id)
            ->whereNotIn('keyword_id', $keywordIds)
            ->delete();
    }
    private function findExistingKeywordByText(string $keyword): ?Keyword
    {
        $keyword = Keyword::normalizeKeyword($keyword);

        if ($keyword === '') {
            return null;
        }

        return Keyword::query()
            ->whereRaw('LOWER(keyword) = ?', [mb_strtolower($keyword)])
            ->orderBy('id', 'asc')
            ->first();
    }

    private function updateKeywordOptionalData(Keyword $keyword, array $item): void
    {
        $payload = [];

        if (
            array_key_exists('status', $item)
            && in_array($item['status'], ['active', 'inactive'], true)
        ) {
            $payload['status'] = $item['status'];
        }

        if (array_key_exists('avg_search_volume', $item)) {
            $payload['avg_search_volume'] = $item['avg_search_volume'];
        }

        if (array_key_exists('avg_ranking', $item)) {
            $payload['avg_ranking'] = $item['avg_ranking'];
        }

        if (!empty($payload)) {
            $keyword->update($payload);
        }
    }
    private function normalizeSubmittedKeywords(mixed $input): array
    {
        if (is_null($input) || $input === '') {
            return [];
        }

        if (is_string($input)) {
            $decoded = json_decode($input, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $input = $decoded;
            } else {
                $input = str_contains($input, ',')
                    ? explode(',', $input)
                    : [$input];
            }
        }

        if (!is_array($input)) {
            $input = [$input];
        }

        $items = [];

        foreach ($input as $item) {
            if (is_null($item) || $item === '') {
                continue;
            }

            if (is_numeric($item)) {
                $items[] = [
                    'id' => (int) $item,
                    'keyword' => null,
                    'status' => null,
                    'avg_search_volume' => null,
                    'avg_ranking' => null,
                ];

                continue;
            }

            if (is_string($item)) {
                $keyword = Keyword::normalizeKeyword($item);

                if ($keyword !== '') {
                    $items[] = [
                        'id' => null,
                        'keyword' => $keyword,
                        'status' => 'active',
                        'avg_search_volume' => null,
                        'avg_ranking' => null,
                    ];
                }

                continue;
            }

            if (is_array($item)) {
                $id = null;
                $keyword = null;

                if (!empty($item['id']) && is_numeric($item['id'])) {
                    $id = (int) $item['id'];
                } elseif (!empty($item['keyword_id']) && is_numeric($item['keyword_id'])) {
                    $id = (int) $item['keyword_id'];
                }

                foreach (['keyword', 'label', 'name', 'title', 'value'] as $key) {
                    if (!empty($item[$key]) && is_string($item[$key]) && !is_numeric($item[$key])) {
                        $keyword = Keyword::normalizeKeyword($item[$key]);
                        break;
                    }
                }

                $status = $item['status'] ?? 'active';

                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                $avgSearchVolume = null;

                if (
                    array_key_exists('avg_search_volume', $item)
                    && $item['avg_search_volume'] !== ''
                    && $item['avg_search_volume'] !== null
                ) {
                    $avgSearchVolume = is_numeric($item['avg_search_volume'])
                        ? (int) $item['avg_search_volume']
                        : null;
                }

                $avgRanking = null;

                if (
                    array_key_exists('avg_ranking', $item)
                    && $item['avg_ranking'] !== ''
                    && $item['avg_ranking'] !== null
                ) {
                    $avgRanking = is_numeric($item['avg_ranking'])
                        ? round((float) $item['avg_ranking'], 2)
                        : null;
                }

                if ($id || $keyword) {
                    $items[] = [
                        'id' => $id,
                        'keyword' => $keyword,
                        'status' => $status,
                        'avg_search_volume' => $avgSearchVolume,
                        'avg_ranking' => $avgRanking,
                    ];
                }
            }
        }

        return collect($items)
            ->filter(fn($item) => !empty($item['id']) || !empty($item['keyword']))
            ->unique(function ($item) {
                return !empty($item['id'])
                    ? 'id:' . $item['id']
                    : 'keyword:' . mb_strtolower(Keyword::normalizeKeyword($item['keyword']));
            })
            ->values()
            ->toArray();
    }

    private function findExistingKeywordForDynamicPost(
        string $keyword,
        int $postTypeId,
        int $dynamicPostId
    ): ?Keyword {
        $keyword = Keyword::normalizeKeyword($keyword);

        if ($keyword === '') {
            return null;
        }

        return Keyword::query()
            ->whereRaw('LOWER(keyword) = ?', [mb_strtolower($keyword)])
            ->whereHas('postTypes', function ($query) use ($postTypeId) {
                $query->where('post_types.id', $postTypeId);
            })
            ->whereHas('dynamicPosts', function ($query) use ($dynamicPostId) {
                $query->where('dynamic_posts.id', $dynamicPostId);
            })
            ->first();
    }

    private function formatSelectedKeywords(DynamicPost $post): array
    {
        if (!Schema::hasTable('keywords') || !Schema::hasTable('keyword_dynamic_post')) {
            return [];
        }

        $post->loadMissing('postType');

        return Keyword::query()
            ->join('keyword_dynamic_post', 'keywords.id', '=', 'keyword_dynamic_post.keyword_id')
            ->where('keyword_dynamic_post.dynamic_post_id', $post->id)
            ->select(
                'keywords.id',
                'keywords.keyword',
                'keywords.status',
                'keywords.avg_search_volume',
                'keywords.avg_ranking'
            )
            ->orderBy('keywords.keyword')
            ->get()
            ->map(fn($keyword) => [
                'id' => (int) $keyword->id,
                'value' => $keyword->keyword,
                'label' => $keyword->keyword,
                'keyword' => $keyword->keyword,
                'status' => $keyword->status,
                'avg_search_volume' => $keyword->avg_search_volume,
                'avg_ranking' => $keyword->avg_ranking,

                'keyword_type' => $post->postType ? [
                    'id' => (int) $post->postType->id,
                    'name' => $post->postType->name,
                    'slug' => $post->postType->slug,
                ] : null,

                'post_type' => [
                    'id' => (int) $post->id,
                    'post_type_id' => (int) $post->post_type_id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'status' => $post->status ?? null,
                    'live_status' => $post->live_status ?? null,
                ],
            ])
            ->values()
            ->toArray();
    }
    private function hasAssignedUserPayload(array $payload): bool
    {
        return array_key_exists('user_id', $payload)
            || array_key_exists('assigned_user_id', $payload);
    }

    private function getAssignedUserIdFromPayload(array $payload): ?int
    {
        $userId = $payload['user_id'] ?? $payload['assigned_user_id'] ?? null;

        if ($userId === '' || is_null($userId)) {
            return null;
        }

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function syncDynamicPostAssignedUser(DynamicPost $post, array $payload): void
    {
        if (!Schema::hasTable('dynamic_post_user')) {
            throw ValidationException::withMessages([
                'dynamic_post_user' => [
                    'dynamic_post_user pivot table does not exist. Please run migration first.',
                ],
            ]);
        }

        $userId = $this->getAssignedUserIdFromPayload($payload);
        $allowAnonymousUser = false;

        if (!$userId) {
            $anonymousUser = $this->getAnonymousUser();

            if (!$anonymousUser) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'No user selected and Anonymous User does not exist. Please create Anonymous User first.',
                    ],
                ]);
            }

            $userId = (int) $anonymousUser->id;
            $allowAnonymousUser = true;
        }

        $postType = $post->relationLoaded('postType')
            ? $post->postType
            : PostType::find($post->post_type_id);

        /*
     * Important:
     * No KYC check here.
     * Admin route can assign/create without selected user's KYC.
     * Frontend/mobile KYC is handled by kyc.publish middleware.
     */
        $this->assertAssignableUser($userId, $postType, $allowAnonymousUser);

        $now = now();

        $existing = DB::table('dynamic_post_user')
            ->where('dynamic_post_id', (int) $post->id)
            ->first();

        if ($existing) {
            DB::table('dynamic_post_user')
                ->where('dynamic_post_id', (int) $post->id)
                ->update([
                    'user_id' => (int) $userId,
                    'assigned_by' => Auth::id(),
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('dynamic_post_user')->insert([
            'dynamic_post_id' => (int) $post->id,
            'user_id' => (int) $userId,
            'assigned_by' => Auth::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    private function getAnonymousUser(): ?User
    {
        $query = User::query();

        $query->where(function ($q) {
            if (Schema::hasColumn('users', 'email')) {
                $q->where('email', 'anonymous@system.local');
            }

            if (Schema::hasColumn('users', 'name')) {
                $q->orWhere('name', 'Anonymous User');
            }

            if (Schema::hasColumn('users', 'first_name')) {
                $q->orWhere('first_name', 'Anonymous');
            }
        });

        return $query->first();
    }

    private function assertAssignableUser(
        int $userId,
        ?PostType $postType = null,
        bool $allowAnonymousUser = false
    ): void {
        $user = User::find($userId);

        if (!$user) {
            throw ValidationException::withMessages([
                'user_id' => ['Selected user does not exist.'],
            ]);
        }

        if ($this->isAdminOrSuperAdminUser($user)) {
            throw ValidationException::withMessages([
                'user_id' => ['Admin or Super Admin cannot be assigned to a listing.'],
            ]);
        }

        if ($allowAnonymousUser && $this->isAnonymousAssignmentUser($user)) {
            return;
        }

        if (!$postType) {
            return;
        }

        $allowedRoleIds = $this->assignmentAllowedRoleIdsForPostType($postType);

        if (empty($allowedRoleIds)) {
            throw ValidationException::withMessages([
                'user_id' => [
                    'No assignable roles are configured for this post type.',
                ],
            ]);
        }

        if (!Schema::hasColumn('users', 'role_id')) {
            throw ValidationException::withMessages([
                'user_id' => [
                    'User role_id column does not exist, so assignment cannot be validated.',
                ],
            ]);
        }

        if (empty($user->role_id) || !in_array((int) $user->role_id, $allowedRoleIds, true)) {
            throw ValidationException::withMessages([
                'user_id' => [
                    'Selected user role is not allowed for this listing type.',
                ],
            ]);
        }
    }
    private function isAdminOrSuperAdminUser(User $user): bool
    {
        $blockedRoles = $this->blockedAssignmentRoleValues();

        if (Schema::hasColumn('users', 'role')) {
            $role = strtolower(trim((string) ($user->role ?? '')));

            if (in_array($role, $blockedRoles, true)) {
                return true;
            }
        }

        if (
            Schema::hasColumn('users', 'role_id')
            && !empty($user->role_id)
            && Schema::hasTable('roles')
        ) {
            $role = DB::table('roles')->where('id', $user->role_id)->first();

            if ($role) {
                foreach (['name', 'slug', 'role_name'] as $column) {
                    if (property_exists($role, $column)) {
                        $value = strtolower(trim((string) $role->{$column}));

                        if (in_array($value, $blockedRoles, true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function blockedAssignmentRoleValues(): array
    {
        return [
            'admin',
            'administrator',
            'super_admin',
            'super admin',
            'super-admin',
        ];
    }

    private function formatAssignedUser(DynamicPost $post): ?array
    {
        if (!Schema::hasTable('dynamic_post_user')) {
            return null;
        }

        $assignment = DB::table('dynamic_post_user')
            ->where('dynamic_post_id', (int) $post->id)
            ->first();

        if (!$assignment) {
            return null;
        }

        $userSelect = ['id', 'first_name', 'last_name', 'email', 'role_id'];

        if (Schema::hasColumn('users', 'phone')) {
            $userSelect[] = 'phone';
        }

        if (Schema::hasColumn('users', 'name')) {
            $userSelect[] = 'name';
        }

        $user = User::query()
            ->select($userSelect)
            ->find((int) $assignment->user_id);

        if (!$user) {
            return null;
        }

        $assignedByUser = null;

        if (!empty($assignment->assigned_by)) {
            $assignedByUser = User::query()
                ->select($userSelect)
                ->find((int) $assignment->assigned_by);
        }

        $makeLabel = function (?User $user): ?string {
            if (!$user) {
                return null;
            }

            $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            if ($fullName !== '') {
                return $fullName;
            }

            if (Schema::hasColumn('users', 'name') && !empty($user->name)) {
                return $user->name;
            }

            return $user->email ?? ('User #' . $user->id);
        };

        $makeRoleData = function (?User $user): ?array {
            if (
                !$user
                || empty($user->role_id)
                || !Schema::hasTable('roles')
            ) {
                return null;
            }

            $role = DB::table('roles')
                ->where('id', (int) $user->role_id)
                ->first();

            if (!$role) {
                return null;
            }

            return [
                'id' => (int) $role->id,
                'name' => $role->name ?? $role->role_name ?? null,
                'slug' => $role->slug ?? null,
            ];
        };

        $label = $makeLabel($user);
        $assignedByLabel = $makeLabel($assignedByUser);

        return [
            'id' => (int) $user->id,
            'value' => (int) $user->id,
            'label' => $label,
            'name' => $label,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'role_id' => $user->role_id ? (int) $user->role_id : null,
            'role' => $makeRoleData($user),

            'assigned_by' => $assignment->assigned_by ? (int) $assignment->assigned_by : null,
            'assigned_by_name' => $assignedByLabel,
            'assigned_by_user' => $assignedByUser ? [
                'id' => (int) $assignedByUser->id,
                'value' => (int) $assignedByUser->id,
                'label' => $assignedByLabel,
                'name' => $assignedByLabel,
                'email' => $assignedByUser->email ?? null,
                'phone' => $assignedByUser->phone ?? null,
                'role_id' => $assignedByUser->role_id ? (int) $assignedByUser->role_id : null,
                'role' => $makeRoleData($assignedByUser),
            ] : null,

            'assigned_at' => $assignment->updated_at ?? $assignment->created_at ?? null,

            'is_anonymous_user' => ($user->email ?? null) === 'anonymous@system.local'
                || (Schema::hasColumn('users', 'name') && (($user->name ?? null) === 'Anonymous User'))
                || ($user->first_name ?? null) === 'Anonymous',
        ];
    }
    public function assignmentUserDropdown(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string', 'max:255'],
                'post_type_slug' => ['nullable', 'string', 'max:255'],
                'search' => ['nullable', 'string', 'max:255'],
                'role_id' => ['nullable', 'integer', 'exists:roles,id'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $postType = $this->resolveAssignmentPostType($request);

            /*
        |--------------------------------------------------------------------------
        | Safety
        |--------------------------------------------------------------------------
        | If frontend does not send post_type/post_type_id/role_id,
        | do not return all users.
        */
            if (!$postType && !$request->filled('role_id')) {
                return $this->emptyAssignmentDropdownResponse();
            }

            $limit = min((int) $request->get('limit', 100), 500);

            $columns = collect([
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'role_id',
                'role',
                'status',
            ])
                ->filter(fn(string $column) => Schema::hasColumn('users', $column))
                ->values()
                ->toArray();

            if (empty($columns)) {
                return $this->emptyAssignmentDropdownResponse();
            }

            $query = User::query()->select($columns);

            /*
        |--------------------------------------------------------------------------
        | Block Admin / Super Admin
        |--------------------------------------------------------------------------
        */
            $blockedRoleIds = $this->blockedAssignmentRoleIds();

            if (Schema::hasColumn('users', 'role_id') && !empty($blockedRoleIds)) {
                $query->whereNotIn('role_id', $blockedRoleIds);
            }

            if (Schema::hasColumn('users', 'role')) {
                $blockedRoles = $this->blockedAssignmentRoleValues();

                $query->where(function ($q) use ($blockedRoles) {
                    $q->whereNull('role');

                    foreach ($blockedRoles as $blockedRole) {
                        $q->whereRaw('LOWER(role) != ?', [$blockedRole]);
                    }
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Main Post Type Role Matching
        |--------------------------------------------------------------------------
        | property-listing   => Owner, Agent, Consultancy
        | project-listing    => Company
        | developer-listing  => Developer
        */
            if ($postType) {
                $allowedRoleIds = $this->assignmentAllowedRoleIdsForPostType($postType);

                if (empty($allowedRoleIds)) {
                    return $this->emptyAssignmentDropdownResponse();
                }

                if ($request->filled('role_id')) {
                    $roleId = (int) $request->role_id;

                    if (!in_array($roleId, $allowedRoleIds, true)) {
                        return $this->emptyAssignmentDropdownResponse();
                    }

                    $query->where('role_id', $roleId);
                } else {
                    $query->whereIn('role_id', $allowedRoleIds);
                }
            } elseif ($request->filled('role_id') && Schema::hasColumn('users', 'role_id')) {
                $roleId = (int) $request->role_id;

                if (in_array($roleId, $blockedRoleIds, true)) {
                    return $this->emptyAssignmentDropdownResponse();
                }

                $query->where('role_id', $roleId);
            }

            /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    if (Schema::hasColumn('users', 'first_name')) {
                        $q->orWhere('first_name', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('users', 'last_name')) {
                        $q->orWhere('last_name', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('users', 'name')) {
                        $q->orWhere('name', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('users', 'email')) {
                        $q->orWhere('email', 'like', "%{$search}%");
                    }

                    if (Schema::hasColumn('users', 'phone')) {
                        $q->orWhere('phone', 'like', "%{$search}%");
                    }
                });
            }

            /*
        |--------------------------------------------------------------------------
        | Active Users Only
        |--------------------------------------------------------------------------
        */
            if (Schema::hasColumn('users', 'status')) {
                $query->where(function ($q) {
                    $q->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', 'active');
                });
            }

            $users = $query
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();

            $options = $users->map(function ($user) {
                $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

                $label = $fullName
                    ?: ($user->name ?? null)
                    ?: ($user->email ?? null)
                    ?: ('User #' . $user->id);

                return [
                    'id' => (int) $user->id,
                    'value' => (int) $user->id,
                    'label' => $label,
                    'name' => $label,
                    'email' => $user->email ?? null,
                    'phone' => $user->phone ?? null,
                    'role_id' => isset($user->role_id) ? (int) $user->role_id : null,
                ];
            })->values();

            return $this->successResponse('Users fetched successfully.', [
                'post_type' => $postType ? [
                    'id' => (int) $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'allowed_roles' => $this->assignmentRoleNamesForPostTypeSlug((string) $postType->slug),
                ] : null,
                'count' => $options->count(),
                'options' => $options,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch users.', 500, $e->getMessage());
        }
    }

    private function blockedAssignmentRoleIds(): array
    {
        if (!Schema::hasTable('roles')) {
            return [];
        }

        $blockedRoles = $this->blockedAssignmentRoleValues();

        $query = DB::table('roles');

        $query->where(function ($q) use ($blockedRoles) {
            foreach (['name', 'slug', 'role_name'] as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    $q->orWhereIn(DB::raw("LOWER({$column})"), $blockedRoles);
                }
            }
        });

        return $query
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->toArray();
    }
    public function assignmentRoleDropdown(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
                'post_type' => ['nullable', 'string', 'max:255'],
                'post_type_slug' => ['nullable', 'string', 'max:255'],
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);

            if (!Schema::hasTable('roles')) {
                return $this->successResponse('Roles fetched successfully.', [
                    'count' => 0,
                    'options' => [],
                ]);
            }

            $postType = $this->resolveAssignmentPostType($request);
            $limit = min((int) $request->get('limit', 100), 200);
            $blockedRoles = $this->blockedAssignmentRoleValues();

            $query = DB::table('roles');

            /*
        |--------------------------------------------------------------------------
        | Block Admin / Super Admin roles
        |--------------------------------------------------------------------------
        */
            $query->where(function ($q) use ($blockedRoles) {
                foreach (['name', 'slug', 'role_name'] as $column) {
                    if (Schema::hasColumn('roles', $column)) {
                        $q->whereNotIn(DB::raw("LOWER({$column})"), $blockedRoles);
                    }
                }
            });

            /*
        |--------------------------------------------------------------------------
        | Filter roles by selected post type
        |--------------------------------------------------------------------------
        */
            if ($postType) {
                $allowedRoleIds = $this->assignmentAllowedRoleIdsForPostType($postType);

                if (empty($allowedRoleIds)) {
                    return $this->successResponse('Roles fetched successfully.', [
                        'post_type' => [
                            'id' => (int) $postType->id,
                            'name' => $postType->name,
                            'slug' => $postType->slug,
                            'allowed_roles' => [],
                        ],
                        'count' => 0,
                        'options' => [],
                    ]);
                }

                $query->whereIn('id', $allowedRoleIds);
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    foreach (['name', 'slug', 'role_name'] as $column) {
                        if (Schema::hasColumn('roles', $column)) {
                            $q->orWhere($column, 'like', "%{$search}%");
                        }
                    }
                });
            }

            if (Schema::hasColumn('roles', 'status')) {
                $query->where(function ($q) {
                    $q->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', 'active');
                });
            }

            $roles = $query
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->get();

            $options = $roles->map(function ($role) {
                $label = $role->name
                    ?? $role->role_name
                    ?? $role->slug
                    ?? ('Role #' . $role->id);

                return [
                    'id' => (int) $role->id,
                    'value' => (int) $role->id,
                    'label' => $label,
                    'name' => $label,
                    'slug' => $role->slug ?? null,
                ];
            })->values();

            return $this->successResponse('Roles fetched successfully.', [
                'post_type' => $postType ? [
                    'id' => (int) $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                    'allowed_roles' => $this->assignmentRoleNamesForPostTypeSlug((string) $postType->slug),
                ] : null,
                'count' => $options->count(),
                'options' => $options,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch roles.', 500, $e->getMessage());
        }
    }
    private function resolveOrCreateFrontendUser(Request $request): array
    {
        $loggedInUser = $this->resolveLoggedInFrontendUser($request);

        if ($loggedInUser) {
            return [
                'user' => $loggedInUser,
                'token' => null,
                'is_new_user' => false,
            ];
        }

        $user = $this->createFrontendUserForListing($request);

        return [
            'user' => $user,
            'token' => $user->api_token ?? null,
            'is_new_user' => true,
        ];
    }

    private function resolveLoggedInFrontendUser(Request $request): ?User
    {
        if ($request->user()) {
            return $request->user();
        }

        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->api_token;
        }

        if (!$token) {
            return null;
        }

        return User::where('api_token', $token)->first();
    }

    private function createFrontendUserForListing(Request $request): User
    {
        $validated = $request->validate([
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],

            'user' => ['required', 'array'],
            'user.first_name' => ['required', 'string', 'max:100'],
            'user.last_name' => ['nullable', 'string', 'max:100'],
            'user.phone' => [
                'required',
                'regex:/^[0-9]{10}$/',
                'unique:users,phone',
            ],
            'user.email' => [
                'required',
                'email',
                'unique:users,email',
            ],
            'user.password' => ['required', 'string', 'min:8'],
            'user.role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'user.user_name' => [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'unique:users,user_name',
                'regex:/^[a-zA-Z0-9._]+$/',
            ],
        ]);

        $userData = $validated['user'];

        $roleId = $validated['role_id']
            ?? $userData['role_id']
            ?? null;

        if (!$roleId) {
            throw ValidationException::withMessages([
                'role_id' => ['Please select a role.'],
            ]);
        }

        $role = Role::find((int) $roleId);

        if (!$role) {
            throw ValidationException::withMessages([
                'role_id' => ['Invalid role selected.'],
            ]);
        }

        $this->assertAssignableRole((int) $role->id);

        $token = Str::random(80);
        $uniqueId = $this->generateFrontendUserUniqueId($role);

        $user = new User();

        if (Schema::hasColumn('users', 'first_name')) {
            $user->first_name = $userData['first_name'];
        }

        if (Schema::hasColumn('users', 'last_name')) {
            $user->last_name = $userData['last_name'] ?? null;
        }

        if (Schema::hasColumn('users', 'name')) {
            $user->name = trim($userData['first_name'] . ' ' . ($userData['last_name'] ?? ''));
        }

        if (Schema::hasColumn('users', 'user_name')) {
            $user->user_name = $userData['user_name']
                ?? $this->generateUniqueFrontendUsername($userData['email']);
        }

        $user->phone = $userData['phone'];
        $user->email = $userData['email'];
        $user->password = Hash::make($userData['password']);
        $user->role_id = (int) $role->id;

        if (Schema::hasColumn('users', 'unique_id')) {
            $user->unique_id = $uniqueId;
        }

        if (Schema::hasColumn('users', 'isapproved')) {
            $user->isapproved = 1;
        }

        if (Schema::hasColumn('users', 'is_otp_verified')) {
            $user->is_otp_verified = false;
        }

        if (Schema::hasColumn('users', 'kyc')) {
            $user->kyc = 0;
        }

        if (Schema::hasColumn('users', 'api_token')) {
            $user->api_token = $token;
        }

        if (Schema::hasColumn('users', 'token_created_at')) {
            $user->token_created_at = now();
        }

        $user->save();

        if (Schema::hasTable('user_details')) {
            UserDetail::create([
                'user_id' => $user->id,
                'role_id' => $user->role_id,
            ]);
        }

        return $user;
    }

    private function assertAssignableRole(int $roleId): void
    {
        $blockedRoleIds = $this->blockedAssignmentRoleIds();

        if (in_array($roleId, $blockedRoleIds, true)) {
            throw ValidationException::withMessages([
                'user.role_id' => ['Admin or Super Admin role cannot create frontend listing.'],
            ]);
        }
    }

    private function generateFrontendUserUniqueId(Role $role): ?string
    {
        if (!Schema::hasTable('unique_i_d_s') && !Schema::hasTable('unique_ids')) {
            return null;
        }

        $prefix = $role->prefix
            ?? strtoupper(substr(Str::slug($role->name ?? 'USR', ''), 0, 3));

        $prefix = $prefix ?: 'USR';

        $lastUniqueID = UniqueID::where('unique_id', 'like', $prefix . '%')
            ->orderBy('unique_id', 'desc')
            ->first();

        $lastCount = $lastUniqueID
            ? (int) substr($lastUniqueID->unique_id, strlen($prefix))
            : 0;

        $newUniqueID = $prefix . str_pad((string) ($lastCount + 1), 3, '0', STR_PAD_LEFT);

        $uniqueIDModel = new UniqueID();
        $uniqueIDModel->unique_id = $newUniqueID;
        $uniqueIDModel->save();

        return $newUniqueID;
    }

    private function generateUniqueFrontendUsername(string $email): string
    {
        $base = Str::slug(strtok($email, '@'), '_');

        if ($base === '') {
            $base = 'user';
        }

        $base = substr($base, 0, 15);

        $username = $base;
        $counter = 1;

        while (User::where('user_name', $username)->exists()) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }
    public function frontendListingRoleDropdown(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);

            if (!Schema::hasTable('roles')) {
                return $this->successResponse('Roles fetched successfully.', [
                    'count' => 0,
                    'options' => [],
                ]);
            }

            $limit = min((int) $request->get('limit', 100), 200);
            $blockedRoleIds = $this->blockedAssignmentRoleIds();

            $query = DB::table('roles');

            if (!empty($blockedRoleIds)) {
                $query->whereNotIn('id', $blockedRoleIds);
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    foreach (['name', 'slug', 'role_name'] as $column) {
                        if (Schema::hasColumn('roles', $column)) {
                            $q->orWhere($column, 'like', "%{$search}%");
                        }
                    }
                });
            }

            if (Schema::hasColumn('roles', 'status')) {
                $query->where(function ($q) {
                    $q->where('status', true)
                        ->orWhere('status', 1)
                        ->orWhere('status', 'active');
                });
            }

            $roles = $query
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->get();

            $options = $roles->map(function ($role) {
                $label = $role->name
                    ?? $role->role_name
                    ?? $role->slug
                    ?? ('Role #' . $role->id);

                return [
                    'id' => (int) $role->id,
                    'value' => (int) $role->id,
                    'label' => $label,
                    'name' => $label,
                    'slug' => $role->slug ?? null,
                ];
            })->values();

            return $this->successResponse('Roles fetched successfully.', [
                'count' => $options->count(),
                'options' => $options,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->errorResponse('Unable to fetch roles.', 500, $e->getMessage());
        }
    }
    private function hasLocationPayload(array $payload): bool
    {
        return array_key_exists('country_id', $payload)
            || array_key_exists('state_id', $payload)
            || array_key_exists('city_id', $payload)
            || array_key_exists('area_locality', $payload);
    }

    private function validatePostTypeLocationSupport(PostType $postType): void
    {
        $supports = $this->normalizePostTypeSupports($postType->supports ?? []);

        if (!($supports['location'] ?? false)) {
            throw ValidationException::withMessages([
                'location' => [
                    'This post type does not support location. Please enable Location in post type supports.',
                ],
            ]);
        }
    }

    private function prepareLocationForSave(array $validated): array
    {
        $cityId = $validated['city_id'] ?? null;
        $stateId = $validated['state_id'] ?? null;
        $countryId = $validated['country_id'] ?? null;

        if (!empty($cityId)) {
            $city = City::query()
                ->with('state')
                ->find((int) $cityId);

            if (!$city) {
                throw ValidationException::withMessages([
                    'city_id' => ['Selected city does not exist.'],
                ]);
            }

            if (!empty($stateId) && (int) $stateId !== (int) $city->state_id) {
                throw ValidationException::withMessages([
                    'city_id' => ['Selected city does not belong to selected state.'],
                ]);
            }

            $validated['state_id'] = (int) $city->state_id;

            if ($city->state) {
                if (!empty($countryId) && (int) $countryId !== (int) $city->state->country_id) {
                    throw ValidationException::withMessages([
                        'city_id' => ['Selected city does not belong to selected country.'],
                    ]);
                }

                $validated['country_id'] = (int) $city->state->country_id;
            }

            return $validated;
        }

        if (!empty($stateId)) {
            $state = State::query()->find((int) $stateId);

            if (!$state) {
                throw ValidationException::withMessages([
                    'state_id' => ['Selected state does not exist.'],
                ]);
            }

            if (!empty($countryId) && (int) $countryId !== (int) $state->country_id) {
                throw ValidationException::withMessages([
                    'state_id' => ['Selected state does not belong to selected country.'],
                ]);
            }

            $validated['country_id'] = (int) $state->country_id;
        }

        return $validated;
    }

    private function formatLocationForDynamicPost(DynamicPost $post): array
    {
        $countryId = $post->country_id ?? null;
        $stateId = $post->state_id ?? null;
        $cityId = $post->city_id ?? null;

        $country = $countryId
            ? Country::query()->select('id', 'name')->find((int) $countryId)
            : null;

        $state = $stateId
            ? State::query()->select('id', 'name', 'country_id')->find((int) $stateId)
            : null;

        $city = $cityId
            ? City::query()->select('id', 'name', 'state_id')->find((int) $cityId)
            : null;

        $fullAddress = collect([
            $post->area_locality ?? null,
            $city?->name,
            $state?->name,
            $country?->name,
        ])
            ->filter()
            ->values()
            ->implode(', ');

        return [
            'country_id' => $country ? (int) $country->id : null,
            'country_name' => $country?->name,

            'state_id' => $state ? (int) $state->id : null,
            'state_name' => $state?->name,

            'city_id' => $city ? (int) $city->id : null,
            'city_name' => $city?->name,

            'area_locality' => $post->area_locality ?? null,
            'full_address' => $fullAddress ?: null,

            'country' => $country ? [
                'id' => (int) $country->id,
                'name' => $country->name,
            ] : null,

            'state' => $state ? [
                'id' => (int) $state->id,
                'name' => $state->name,
                'country_id' => (int) $state->country_id,
            ] : null,

            'city' => $city ? [
                'id' => (int) $city->id,
                'name' => $city->name,
                'state_id' => (int) $city->state_id,
            ] : null,
        ];
    }
    private function normalizeLiveVisibilityStatus(array $validated, DynamicPost $post): array
    {
        /*
    |--------------------------------------------------------------------------
    | Live visibility rule
    |--------------------------------------------------------------------------
    | Frontend/live listing ke liye:
    | status      = published
    | live_status = approve
    |--------------------------------------------------------------------------
    */

        if (
            array_key_exists('status', $validated)
            && $validated['status'] === 'published'
            && !array_key_exists('live_status', $validated)
        ) {
            $validated['live_status'] = 'approve';
        }

        if (
            array_key_exists('live_status', $validated)
            && $validated['live_status'] === 'approve'
            && !array_key_exists('status', $validated)
        ) {
            $validated['status'] = 'published';
        }

        if (
            array_key_exists('live_status', $validated)
            && in_array($validated['live_status'], ['reject', 'disapprove', 'under_review', 'modify_review', 'submit'], true)
            && !array_key_exists('status', $validated)
        ) {
            $validated['status'] = $post->status ?? 'draft';
        }

        return $validated;
    }

    private function clearDynamicPostCaches(?DynamicPost $post = null): void
    {
        try {
            Cache::forget('dynamic_posts');
            Cache::forget('frontend_dynamic_posts');
            Cache::forget('live_dynamic_posts');

            Cache::store('redis')->forget('dynamic_posts');
            Cache::store('redis')->forget('frontend_dynamic_posts');
            Cache::store('redis')->forget('live_dynamic_posts');

            if ($post) {
                Cache::forget('dynamic_post_' . $post->id);
                Cache::forget('dynamic_post_slug_' . $post->slug);
                Cache::forget('dynamic_post_type_' . $post->post_type_id);

                Cache::store('redis')->forget('dynamic_post_' . $post->id);
                Cache::store('redis')->forget('dynamic_post_slug_' . $post->slug);
                Cache::store('redis')->forget('dynamic_post_type_' . $post->post_type_id);
            }
        } catch (Throwable $e) {
            \Log::warning('Unable to clear dynamic post cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function mediaFileUrl(?MediaFile $media): ?string
    {
        if (!$media || empty($media->path)) {
            return null;
        }

        $path = trim((string) $media->path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk = $media->disk ?: 'public';

        try {
            return Storage::disk($disk)->url($path);
        } catch (Throwable $e) {
            return url($path);
        }
    }
    private function consumeMembershipListingCredit(Request $request, object|int $listing, string $referenceType): void
    {
        if (!$request->attributes->get('membership_should_consume_listing_credit')) {
            return;
        }

        $subjectUser = $request->attributes->get('membership_subject_user');

        if (!$subjectUser instanceof User) {
            return;
        }

        $listingId = is_object($listing) ? (int) ($listing->id ?? 0) : (int) $listing;

        if ($listingId <= 0) {
            return;
        }

        app(MembershipCreditService::class)->consumeListingCreditOnce(
            user: $subjectUser,
            referenceType: $referenceType,
            referenceId: $listingId,
            performedBy: $this->resolveCurrentUserForMembershipCredit($request),
            metadata: [
                'source' => 'dynamic_post_controller',
            ]
        );
    }

    private function resolveCurrentUserForMembershipCredit(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && \Illuminate\Support\Facades\Schema::hasColumn('users', 'api_token')) {
            $user = User::query()->where('api_token', $token)->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: \Illuminate\Support\Facades\Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }
}
