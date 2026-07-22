<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserDynamicPostListingController extends Controller
{
    public function getUserPosts(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired token.',
                ], 401);
            }

            $postType = $this->resolvePostType($request->input('post_type', 'property-listing'));

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $perPage = (int) $request->input('per_page', 12);
            $perPage = max(1, min($perPage, 100));

            $termIds = $this->resolveTaxonomyTermIds($request);

            $query = $this->basePostQuery($postType->id)
                ->where($this->dynamicPostUserColumn(), $user->id);

            $this->applyStatusFilters($query, $request);
            $this->applyTaxonomyFilter($query, $termIds);

            $posts = $query
                ->orderByDesc('dynamic_posts.id')
                ->paginate($perPage);

            $posts->getCollection()->transform(function ($post) {
                return $this->formatPost($post);
            });

            return response()->json([
                'status' => true,
                'message' => 'User posts fetched successfully.',
                'data' => $posts->items(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPostsByUserIdFilterByTaxonomy(Request $request, int|string $userId): JsonResponse
    {
        try {
            $user = User::find((int) $userId);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $postType = $this->resolvePostType($request->input('post_type', 'property-listing'));

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post type not found.',
                ], 404);
            }

            $termIds = $this->resolveTaxonomyTermIds($request);

            if (empty($termIds)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please provide taxonomy term filter.',
                    'accepted_params' => [
                        'taxonomy_term_id',
                        'term_id',
                        'taxonomy_term_ids',
                        'term_slug with taxonomy_slug',
                    ],
                ], 422);
            }

            $perPage = (int) $request->input('per_page', 12);
            $perPage = max(1, min($perPage, 100));

            $query = $this->basePostQuery($postType->id)
                ->where($this->dynamicPostUserColumn(), $user->id);

            $this->applyStatusFilters($query, $request);
            $this->applyTaxonomyFilter($query, $termIds);

            $posts = $query
                ->orderByDesc('dynamic_posts.id')
                ->paginate($perPage);

            $posts->getCollection()->transform(function ($post) {
                return $this->formatPost($post);
            });

            return response()->json([
                'status' => true,
                'message' => 'Posts fetched successfully.',
                'filters' => [
                    'user_id' => (int) $user->id,
                    'post_type' => [
                        'id' => (int) $postType->id,
                        'name' => $postType->name ?? null,
                        'slug' => $postType->slug ?? null,
                    ],
                    'taxonomy_term_ids' => $termIds,
                ],
                'data' => $posts->items(),
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getRelatedPostsByPostId(Request $request, int|string $postId): JsonResponse
    {
        try {
            $post = DB::table('dynamic_posts')
                ->where('id', (int) $postId)
                ->first();

            if (!$post) {
                return response()->json([
                    'status' => false,
                    'message' => 'Post not found.',
                ], 404);
            }

            $limit = (int) $request->input('limit', 6);
            $limit = max(1, min($limit, 50));

            $postTypeId = (int) ($post->post_type_id ?? 0);

            $relatedTermIds = $this->postTaxonomyTermIds((int) $post->id);

            $query = $this->basePostQuery($postTypeId)
                ->where('dynamic_posts.id', '!=', (int) $post->id);

            $this->applyPublicStatus($query);

            if (!empty($relatedTermIds)) {
                $this->applyTaxonomyFilter($query, $relatedTermIds);
            }

            $posts = $query
                ->orderByDesc('dynamic_posts.id')
                ->limit($limit)
                ->get()
                ->map(function ($item) {
                    return $this->formatPost($item);
                })
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Related posts fetched successfully.',
                'data' => $posts,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch related posts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function basePostQuery(int $postTypeId)
    {
        $select = [
            'dynamic_posts.id',
            'dynamic_posts.post_type_id',
        ];

        foreach ([
            'title',
            'slug',
            'content',
            'excerpt',
            'status',
            'live_status',
            'created_by',
            'user_id',
            'featured_image',
            'featured_image_id',
            'created_at',
            'updated_at',
        ] as $column) {
            if (Schema::hasColumn('dynamic_posts', $column)) {
                $select[] = 'dynamic_posts.' . $column;
            }
        }

        return DB::table('dynamic_posts')
            ->select($select)
            ->where('dynamic_posts.post_type_id', $postTypeId);
    }

    private function applyStatusFilters($query, Request $request): void
    {
        if ($request->filled('status') && Schema::hasColumn('dynamic_posts', 'status')) {
            $query->where('dynamic_posts.status', $request->input('status'));
        }

        if ($request->filled('live_status') && Schema::hasColumn('dynamic_posts', 'live_status')) {
            $query->where('dynamic_posts.live_status', $request->input('live_status'));
        }
    }

    private function applyPublicStatus($query): void
    {
        if (Schema::hasColumn('dynamic_posts', 'status')) {
            $query->where(function ($statusQuery) {
                $statusQuery
                    ->where('dynamic_posts.status', 'publish')
                    ->orWhere('dynamic_posts.status', 'published')
                    ->orWhere('dynamic_posts.status', 1);
            });
        }

        if (Schema::hasColumn('dynamic_posts', 'live_status')) {
            $query->where(function ($statusQuery) {
                $statusQuery
                    ->where('dynamic_posts.live_status', 'approve')
                    ->orWhere('dynamic_posts.live_status', 'approved')
                    ->orWhere('dynamic_posts.live_status', 'publish')
                    ->orWhere('dynamic_posts.live_status', 'published')
                    ->orWhere('dynamic_posts.live_status', 1);
            });
        }
    }

    private function applyTaxonomyFilter($query, array $termIds): void
    {
        if (empty($termIds)) {
            return;
        }

        $pivot = $this->taxonomyPivotMeta();

        if (!$pivot) {
            return;
        }

        $query->whereExists(function ($exists) use ($pivot, $termIds) {
            $exists->select(DB::raw(1))
                ->from($pivot['table'])
                ->whereColumn(
                    $pivot['table'] . '.' . $pivot['post_column'],
                    'dynamic_posts.id'
                )
                ->whereIn(
                    $pivot['table'] . '.' . $pivot['term_column'],
                    $termIds
                );
        });
    }

    private function postTaxonomyTermIds(int $postId): array
    {
        $pivot = $this->taxonomyPivotMeta();

        if (!$pivot) {
            return [];
        }

        return DB::table($pivot['table'])
            ->where($pivot['post_column'], $postId)
            ->pluck($pivot['term_column'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function resolveTaxonomyTermIds(Request $request): array
    {
        $ids = [];

        foreach ([
            'taxonomy_term_id',
            'term_id',
            'purpose_id',
        ] as $key) {
            if ($request->filled($key) && is_numeric($request->input($key))) {
                $ids[] = (int) $request->input($key);
            }
        }

        if ($request->filled('taxonomy_term_ids')) {
            $input = $request->input('taxonomy_term_ids');

            if (is_string($input)) {
                $decoded = json_decode($input, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $input = $decoded;
                } else {
                    $input = str_contains($input, ',') ? explode(',', $input) : [$input];
                }
            }

            if (!is_array($input)) {
                $input = [$input];
            }

            foreach ($input as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        if ($request->filled('term_slug')) {
            $termQuery = DB::table('taxonomy_terms');

            if (Schema::hasColumn('taxonomy_terms', 'slug')) {
                $termQuery->where('taxonomy_terms.slug', $request->input('term_slug'));
            } else {
                $termQuery->where('taxonomy_terms.name', $request->input('term_slug'));
            }

            if ($request->filled('taxonomy_slug') && Schema::hasTable('taxonomies')) {
                $termQuery
                    ->join('taxonomies', 'taxonomy_terms.taxonomy_id', '=', 'taxonomies.id')
                    ->where(function ($query) use ($request) {
                        $query->where('taxonomies.slug', $request->input('taxonomy_slug'))
                            ->orWhere('taxonomies.name', $request->input('taxonomy_slug'));
                    });
            }

            $termId = $termQuery->value('taxonomy_terms.id');

            if ($termId) {
                $ids[] = (int) $termId;
            }
        }

        return collect($ids)
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    private function resolvePostType(int|string|null $postType)
    {
        if (!$postType) {
            return null;
        }

        return DB::table('post_types')
            ->where(function ($query) use ($postType) {
                if (is_numeric($postType)) {
                    $query->where('id', (int) $postType);
                }

                if (Schema::hasColumn('post_types', 'slug')) {
                    $query->orWhere('slug', (string) $postType);
                }

                if (Schema::hasColumn('post_types', 'name')) {
                    $query->orWhere('name', (string) $postType);
                }
            })
            ->first();
    }

    private function taxonomyPivotMeta(): ?array
    {
        $tables = [
            'post_taxonomy_terms',
            'dynamic_post_taxonomy_terms',
            'dynamic_post_terms',
            'post_terms',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $postColumn = null;

            foreach (['dynamic_post_id', 'post_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $postColumn = $column;
                    break;
                }
            }

            $termColumn = null;

            foreach (['taxonomy_term_id', 'term_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $termColumn = $column;
                    break;
                }
            }

            if ($postColumn && $termColumn) {
                return [
                    'table' => $table,
                    'post_column' => $postColumn,
                    'term_column' => $termColumn,
                ];
            }
        }

        return null;
    }

    private function dynamicPostUserColumn(): string
    {
        if (Schema::hasColumn('dynamic_posts', 'user_id')) {
            return 'dynamic_posts.user_id';
        }

        if (Schema::hasColumn('dynamic_posts', 'created_by')) {
            return 'dynamic_posts.created_by';
        }

        throw new \Exception('dynamic_posts table must have user_id or created_by column.');
    }

    private function formatPost(object $post): array
    {
        $taxonomyTerms = $this->postTermsPayload((int) $post->id);

        return [
            'id' => (int) $post->id,
            'post_type_id' => (int) ($post->post_type_id ?? 0),
            'title' => $post->title ?? null,
            'slug' => $post->slug ?? null,
            'content' => $post->content ?? null,
            'excerpt' => $post->excerpt ?? null,
            'status' => $post->status ?? null,
            'live_status' => $post->live_status ?? null,
            'user_id' => $post->user_id ?? $post->created_by ?? null,
            'featured_image' => $this->featuredImageUrl($post),
            'taxonomy_terms' => $taxonomyTerms,
            'created_at' => $post->created_at ?? null,
            'updated_at' => $post->updated_at ?? null,
        ];
    }

    private function postTermsPayload(int $postId): array
    {
        $pivot = $this->taxonomyPivotMeta();

        if (!$pivot || !Schema::hasTable('taxonomy_terms')) {
            return [];
        }

        $query = DB::table($pivot['table'])
            ->join('taxonomy_terms', $pivot['table'] . '.' . $pivot['term_column'], '=', 'taxonomy_terms.id')
            ->leftJoin('taxonomies', 'taxonomy_terms.taxonomy_id', '=', 'taxonomies.id')
            ->where($pivot['table'] . '.' . $pivot['post_column'], $postId)
            ->select(
                'taxonomy_terms.id as term_id',
                'taxonomy_terms.name as term_name'
            );

        if (Schema::hasColumn('taxonomy_terms', 'slug')) {
            $query->addSelect('taxonomy_terms.slug as term_slug');
        }

        if (Schema::hasTable('taxonomies')) {
            $query->addSelect(
                'taxonomies.id as taxonomy_id',
                'taxonomies.name as taxonomy_name'
            );

            if (Schema::hasColumn('taxonomies', 'slug')) {
                $query->addSelect('taxonomies.slug as taxonomy_slug');
            }
        }

        return $query
            ->get()
            ->map(function ($term) {
                return [
                    'taxonomy_id' => $term->taxonomy_id ?? null,
                    'taxonomy_name' => $term->taxonomy_name ?? null,
                    'taxonomy_slug' => $term->taxonomy_slug ?? null,
                    'term_id' => (int) $term->term_id,
                    'term_name' => $term->term_name ?? null,
                    'term_slug' => $term->term_slug ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    private function featuredImageUrl(object $post): ?string
    {
        if (!empty($post->featured_image)) {
            return $this->fileUrl($post->featured_image);
        }

        return null;
    }

    private function fileUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $path = trim($path);
        $path = str_replace('\\/', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'public/uploads/')) {
            $path = str_replace('public/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'uploads/')) {
            $relativePath = substr($path, strlen('uploads/'));

            return Storage::disk('public_uploads')->url($relativePath);
        }

        return url($path);
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $user = Auth::user();

        if ($user) {
            return $user;
        }

        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->input('api_token');
        }

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }
}