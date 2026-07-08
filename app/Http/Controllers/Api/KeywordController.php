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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KeywordController extends Controller
{
    public function __construct(
        protected KeywordRelationResolver $resolver
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Keyword::query()
            ->with([
                'postTypes:id,name,slug',
                'dynamicPosts:id,post_type_id,title,slug',
            ]);

        $this->applyFilters($query, $request);

        $perPage = min((int) $request->get('per_page', 15), 100);

        $keywords = $query->latest()->paginate($perPage);

        $keywords->getCollection()->transform(fn (Keyword $keyword) => $this->formatKeyword($keyword));

        return response()->json([
            'status' => true,
            'message' => 'Keywords fetched successfully.',
            'data' => $keywords,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('items')) {
            $validated = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.slug' => ['required', 'string', 'max:255', 'distinct', 'unique:keywords,slug'],
                'items.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
                'items.*.keyword_type' => ['required'],
                'items.*.post_type' => ['required'],
                'items.*.keyword_list' => ['required'],
                'items.*.search_volume' => ['nullable', 'integer', 'min:0'],
                'items.*.ranking' => ['nullable', 'integer', 'min:0'],
            ]);

            $created = [];

            foreach ($validated['items'] as $item) {
                $created[] = DB::transaction(fn () => $this->createKeywordFromPayload($item));
            }

            return response()->json([
                'status' => true,
                'message' => 'Keywords created successfully.',
                'data' => $created,
            ], 201);
        }

        $validated = $this->validateSingleKeyword($request);

        $keyword = DB::transaction(fn () => $this->createKeywordFromPayload($validated));

        return response()->json([
            'status' => true,
            'message' => 'Keyword created successfully.',
            'data' => $keyword,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $keyword = Keyword::with([
            'postTypes:id,name,slug',
            'dynamicPosts:id,post_type_id,title,slug',
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

        $validated = $this->validateSingleKeyword($request, $keyword);

        $updated = DB::transaction(function () use ($keyword, $validated) {
            $postTypeIds = $this->resolver->resolvePostTypeIds($validated['keyword_type']);
            $dynamicPostIds = $this->resolver->resolveDynamicPostIds($validated['post_type'], $postTypeIds);

            $keyword->update([
                'slug' => Str::slug($validated['slug']),
                'status' => $validated['status'] ?? 'active',
                'keyword_list' => $validated['keyword_list'],
                'search_volume' => $validated['search_volume'] ?? null,
                'ranking' => $validated['ranking'] ?? null,
            ]);

            $keyword->postTypes()->sync($postTypeIds);
            $keyword->dynamicPosts()->sync($dynamicPostIds);

            return $this->formatKeyword(
                $keyword->fresh(['postTypes:id,name,slug', 'dynamicPosts:id,post_type_id,title,slug'])
            );
        });

        return response()->json([
            'status' => true,
            'message' => 'Keyword updated successfully.',
            'data' => $updated,
        ]);
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
                $keyword->fresh(['postTypes:id,name,slug', 'dynamicPosts:id,post_type_id,title,slug'])
            ),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $query = Keyword::query();

        $this->applyFilters($query, $request);

        $keywords = $query->get(['id', 'keyword_list', 'status', 'ranking', 'search_volume']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword analytics fetched successfully.',
            'data' => [
                'total_keywords' => $keywords->count(),
                'active_keywords' => $keywords->where('status', 'active')->count(),
                'inactive_keywords' => $keywords->where('status', 'inactive')->count(),
                'total_keyword_items' => $keywords->sum(fn ($keyword) => count($keyword->keyword_list ?? [])),
                'avg_ranking' => round((float) $keywords->whereNotNull('ranking')->avg('ranking'), 2),
                'avg_search_volume' => round((float) $keywords->whereNotNull('search_volume')->avg('search_volume'), 2),
            ],
        ]);
    }

    public function keywordTypes(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Keyword types fetched successfully.',
            'data' => PostType::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function listings(string|int $keywordType): JsonResponse
    {
        $postType = $this->resolver->resolvePostType($keywordType);

        if (! $postType) {
            return response()->json([
                'status' => false,
                'message' => 'Post type not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Dependent listings fetched successfully.',
            'data' => [
                'keyword_type' => [
                    'id' => $postType->id,
                    'name' => $postType->name,
                    'slug' => $postType->slug,
                ],
                'listings' => DynamicPost::query()
                    ->where('post_type_id', $postType->id)
                    ->orderBy('title')
                    ->get(['id', 'post_type_id', 'title', 'slug']),
            ],
        ]);
    }

    private function validateSingleKeyword(Request $request, ?Keyword $keyword = null): array
    {
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('keywords', 'slug')->ignore($keyword?->id),
            ],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'keyword_type' => ['required'],
            'post_type' => ['required'],
            'keyword_list' => ['required'],
            'search_volume' => ['nullable', 'integer', 'min:0'],
            'ranking' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function createKeywordFromPayload(array $payload): array
    {
        $postTypeIds = $this->resolver->resolvePostTypeIds($payload['keyword_type']);
        $dynamicPostIds = $this->resolver->resolveDynamicPostIds($payload['post_type'], $postTypeIds);

        $keyword = Keyword::create([
            'slug' => Str::slug($payload['slug']),
            'status' => $payload['status'] ?? 'active',
            'keyword_list' => $payload['keyword_list'],
            'search_volume' => $payload['search_volume'] ?? null,
            'ranking' => $payload['ranking'] ?? null,
        ]);

        $keyword->postTypes()->sync($postTypeIds);
        $keyword->dynamicPosts()->sync($dynamicPostIds);

        return $this->formatKeyword(
            $keyword->fresh(['postTypes:id,name,slug', 'dynamicPosts:id,post_type_id,title,slug'])
        );
    }

    private function applyFilters($query, Request $request): void
    {
        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;

            $q->where(function ($sub) use ($search) {
                $sub->where('slug', 'like', "%{$search}%")
                    ->orWhere('keyword_list', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));

        $query->when($request->filled('keyword_type'), function ($q) use ($request) {
            $postType = $this->resolver->resolvePostType($request->keyword_type);

            if ($postType) {
                $q->whereHas('postTypes', fn ($sub) => $sub->where('post_types.id', $postType->id));
            }
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
        $keywordList = $keyword->keyword_list ?? [];

        return [
            'id' => $keyword->id,
            'slug' => $keyword->slug,
            'status' => $keyword->status,
            'keyword_list' => $keywordList,
            'keyword_list_text' => implode(', ', $keywordList),
            'search_volume' => $keyword->search_volume,
            'ranking' => $keyword->ranking,

            'keyword_type' => $keyword->postTypes->map(fn ($postType) => [
                'id' => $postType->id,
                'name' => $postType->name,
                'slug' => $postType->slug,
            ])->values(),

            'post_type' => $keyword->dynamicPosts->map(fn ($dynamicPost) => [
                'id' => $dynamicPost->id,
                'post_type_id' => $dynamicPost->post_type_id,
                'title' => $dynamicPost->title,
                'slug' => $dynamicPost->slug,
            ])->values(),

            'created_at' => $keyword->created_at,
            'updated_at' => $keyword->updated_at,
        ];
    }
}