<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use App\Services\KeywordRelationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class KeywordController extends Controller
{
    public function __construct(
        protected KeywordRelationResolver $relationResolver
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Keyword::query()
            ->with([
                'postTypes:id,name,slug',
                'dynamicPosts:id,post_type_id,title,slug,status,live_status',
            ]);

        $this->applyFilters($query, $request);

        $perPage = min((int) $request->get('per_page', 15), 100);

        $keywords = $query->latest()->paginate($perPage);

        $keywords->getCollection()->transform(
            fn(Keyword $keyword) => $this->formatKeyword($keyword)
        );

        return response()->json([
            'status' => true,
            'message' => 'Keywords fetched successfully.',
            'data' => $keywords,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            if ($request->has('items')) {
                $validated = $request->validate([
                    'items' => ['required', 'array', 'min:1'],
                    'items.*.keyword' => ['required', 'string', 'max:255'],
                    'items.*.keyword_type' => ['required'],
                    'items.*.post_type' => ['required'],
                    'items.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
                    'items.*.avg_search_volume' => ['nullable', 'integer', 'min:0'],
                    'items.*.avg_ranking' => ['nullable', 'numeric', 'min:0'],
                ]);

                $saved = DB::transaction(function () use ($validated) {
                    return collect($validated['items'])
                        ->map(fn(array $item) => $this->upsertKeywordFromPayload($item))
                        ->values()
                        ->toArray();
                });

                return response()->json([
                    'status' => true,
                    'message' => 'Keywords saved successfully.',
                    'data' => $saved,
                ], 201);
            }

            $validated = $this->validateSingleKeyword($request);

            $keyword = DB::transaction(
                fn() => $this->upsertKeywordFromPayload($validated)
            );

            return response()->json([
                'status' => true,
                'message' => 'Keyword saved successfully.',
                'data' => $keyword,
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword save failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $keyword = Keyword::with([
            'postTypes:id,name,slug',
            'dynamicPosts:id,post_type_id,title,slug,status,live_status',
        ])->find($id);

        if (! $keyword) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Keyword fetched successfully.',
            'data' => $this->formatKeyword($keyword),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $keyword = Keyword::find($id);

        if (! $keyword) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword not found.',
            ], 404);
        }

        try {
            $validated = $this->validateSingleKeyword($request);

            $updated = DB::transaction(function () use ($keyword, $validated) {
                $keywordText = Keyword::normalizeKeyword($validated['keyword']);

                if ($keywordText === '') {
                    throw new RuntimeException('Keyword is required.');
                }

                $keywordType = $this->relationResolver->resolveKeywordType(
                    $validated['keyword_type']
                );

                $postType = $this->relationResolver->resolvePostType(
                    $validated['post_type'],
                    (int) $keywordType->id
                );

                $existing = $this->findExistingByNaturalKey(
                    keyword: $keywordText,
                    keywordTypeId: (int) $keywordType->id,
                    postTypeId: (int) $postType->id
                );

                if ($existing && (int) $existing->id !== (int) $keyword->id) {
                    throw new RuntimeException(
                        'Another keyword already exists with same keyword_type and post_type.'
                    );
                }

                $keyword->update([
                    'keyword' => $keywordText,
                    'status' => $validated['status'] ?? 'active',
                    'avg_search_volume' => $validated['avg_search_volume'] ?? null,
                    'avg_ranking' => $validated['avg_ranking'] ?? null,
                ]);

                $keyword->postTypes()->sync([$keywordType->id]);
                $keyword->dynamicPosts()->sync([$postType->id]);

                return $this->formatKeyword(
                    $keyword->fresh([
                        'postTypes:id,name,slug',
                        'dynamicPosts:id,post_type_id,title,slug,status,live_status',
                    ])
                );
            });

            return response()->json([
                'status' => true,
                'message' => 'Keyword updated successfully.',
                'data' => $updated,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $keyword = Keyword::find($id);

        if (! $keyword) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword not found.',
            ], 404);
        }

        $keyword->delete();

        return response()->json([
            'status' => true,
            'message' => 'Keyword deleted successfully.',
        ]);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $keyword = Keyword::find($id);

        if (! $keyword) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword not found.',
            ], 404);
        }

        $keyword->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Keyword status updated successfully.',
            'data' => $this->formatKeyword(
                $keyword->fresh([
                    'postTypes:id,name,slug',
                    'dynamicPosts:id,post_type_id,title,slug,status,live_status',
                ])
            ),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $query = Keyword::query();

        $this->applyFilters($query, $request);

        $keywords = $query->get([
            'id',
            'status',
            'avg_ranking',
            'avg_search_volume',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Keyword analytics fetched successfully.',
            'data' => [
                'total_keywords' => $keywords->count(),
                'active_keywords' => $keywords->where('status', 'active')->count(),
                'inactive_keywords' => $keywords->where('status', 'inactive')->count(),
                'avg_ranking' => round((float) $keywords->whereNotNull('avg_ranking')->avg('avg_ranking'), 2),
                'avg_search_volume' => round((float) $keywords->whereNotNull('avg_search_volume')->avg('avg_search_volume'), 2),
            ],
        ]);
    }

    public function keywordTypes(): JsonResponse
    {
        $postTypes = PostType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword types fetched successfully.',
            'data' => $postTypes,
        ]);
    }

    public function listings(string|int $keywordType): JsonResponse
    {
        $postType = $this->relationResolver->resolveKeywordType($keywordType);

        $listings = DynamicPost::query()
            ->where('post_type_id', $postType->id)
            ->orderBy('title')
            ->get(['id', 'post_type_id', 'title', 'slug', 'status', 'live_status']);

        return response()->json([
            'status' => true,
            'message' => 'Dependent listings fetched successfully.',
            'data' => [
                'keyword_type' => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                ],
                'listings' => $listings,
            ],
        ]);
    }

    private function validateSingleKeyword(Request $request): array
    {
        return $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'keyword_type' => ['required'],
            'post_type' => ['required'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'avg_search_volume' => ['nullable', 'integer', 'min:0'],
            'avg_ranking' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function upsertKeywordFromPayload(array $payload): array
    {
        $keywordText = Keyword::normalizeKeyword($payload['keyword'] ?? '');

        if ($keywordText === '') {
            throw new RuntimeException('Keyword is required.');
        }

        $keywordType = $this->relationResolver->resolveKeywordType(
            $payload['keyword_type'] ?? null
        );

        $postType = $this->relationResolver->resolvePostType(
            $payload['post_type'] ?? null,
            (int) $keywordType->id
        );

        $keyword = $this->findExistingByNaturalKey(
            keyword: $keywordText,
            keywordTypeId: (int) $keywordType->id,
            postTypeId: (int) $postType->id
        );

        $payloadForSave = [
            'keyword' => $keywordText,
            'status' => $payload['status'] ?? 'active',
            'avg_search_volume' => $payload['avg_search_volume'] ?? null,
            'avg_ranking' => $payload['avg_ranking'] ?? null,
        ];

        if ($keyword) {
            $keyword->update($payloadForSave);
        } else {
            $keyword = Keyword::create($payloadForSave);
        }

        $keyword->postTypes()->sync([$keywordType->id]);
        $keyword->dynamicPosts()->sync([$postType->id]);

        return $this->formatKeyword(
            $keyword->fresh([
                'postTypes:id,name,slug',
                'dynamicPosts:id,post_type_id,title,slug,status,live_status',
            ])
        );
    }

    private function findExistingByNaturalKey(
        string $keyword,
        int $keywordTypeId,
        int $postTypeId
    ): ?Keyword {
        return Keyword::query()
            ->where('keyword', $keyword)
            ->whereHas('postTypes', fn($query) => $query->where('post_types.id', $keywordTypeId))
            ->whereHas('dynamicPosts', fn($query) => $query->where('dynamic_posts.id', $postTypeId))
            ->first();
    }

    private function applyFilters($query, Request $request): void
    {
        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('keyword', 'like', '%' . $request->search . '%');
        });

        $query->when($request->filled('status'), fn($q) => $q->where('status', $request->status));

        $query->when($request->filled('keyword_type'), function ($q) use ($request) {
            $value = $request->keyword_type;

            $q->whereHas('postTypes', function ($sub) use ($value) {
                if (is_numeric($value)) {
                    $sub->where('post_types.id', (int) $value);
                } else {
                    $sub->where('post_types.slug', $value)
                        ->orWhere('post_types.name', $value);
                }
            });
        });

        $query->when($request->filled('post_type'), function ($q) use ($request) {
            $value = $request->post_type;

            $q->whereHas('dynamicPosts', function ($sub) use ($value) {
                if (is_numeric($value)) {
                    $sub->where('dynamic_posts.id', (int) $value);
                } else {
                    $sub->where('dynamic_posts.slug', $value)
                        ->orWhere('dynamic_posts.title', $value);
                }
            });
        });
    }

    private function formatKeyword(Keyword $keyword): array
    {
        $keywordType = $keyword->postTypes->first();
        $postType = $keyword->dynamicPosts->first();

        return [
            'id' => $keyword->id,
            'keyword' => $keyword->keyword,
            'status' => $keyword->status,
            'avg_search_volume' => $keyword->avg_search_volume,
            'avg_ranking' => $keyword->avg_ranking,

            // 'keyword_type' => $keywordType ? [
            //     'id' => $keywordType->id,
            //     'name' => $keywordType->name,
            //     'slug' => $keywordType->slug,
            // ] : null,

            'post_type' => $postType ? [
                'id' => $postType->id,
                'post_type_id' => $postType->post_type_id,
                'title' => $postType->title,
                'slug' => $postType->slug,
                'status' => $postType->status ?? null,
                'live_status' => $postType->live_status ?? null,
            ] : null,

            'created_at' => $keyword->created_at,
            'updated_at' => $keyword->updated_at,
        ];
    }
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:keywords,id'],
        ]);

        try {
            $ids = collect($validated['ids'])
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->toArray();

            $existingIds = Keyword::query()
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            $notFoundIds = array_values(array_diff($ids, $existingIds));

            DB::transaction(function () use ($existingIds) {
                // Safe cleanup even if FK cascade is not working on old DB
                DB::table('keyword_post_type')
                    ->whereIn('keyword_id', $existingIds)
                    ->delete();

                DB::table('keyword_dynamic_post')
                    ->whereIn('keyword_id', $existingIds)
                    ->delete();

                Keyword::query()
                    ->whereIn('id', $existingIds)
                    ->delete();
            });

            return response()->json([
                'status' => true,
                'message' => 'Keywords deleted successfully.',
                'data' => [
                    'requested_count' => count($ids),
                    'deleted_count' => count($existingIds),
                    'not_found_ids' => $notFoundIds,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Keyword bulk delete failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
