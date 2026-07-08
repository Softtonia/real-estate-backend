<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\Keyword;
use App\Models\PostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KeywordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Keyword::with(['postType:id,name,slug', 'dynamicPost:id,post_type_id,title,slug']);

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;

            $q->where(function ($sub) use ($search) {
                $sub->where('slug', 'like', "%{$search}%")
                    ->orWhere('keyword_list', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('keyword_type'), fn ($q) => $q->where('keyword_type', $request->keyword_type));
        $query->when($request->filled('post_type_id'), fn ($q) => $q->where('post_type_id', $request->post_type_id));
        $query->when($request->filled('dynamic_post_id'), fn ($q) => $q->where('dynamic_post_id', $request->dynamic_post_id));

        $perPage = min((int) $request->get('per_page', 15), 100);

        $keywords = $query
            ->latest()
            ->paginate($perPage);

        $keywords->getCollection()->transform(fn ($keyword) => $this->formatKeyword($keyword));

        return response()->json([
            'status' => true,
            'message' => 'Keywords fetched successfully.',
            'data' => $keywords,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateKeyword($request);

        $resolved = $this->resolveRelations($validated);

        $keyword = Keyword::create([
            'slug' => Str::slug($validated['slug']),
            'keyword_type' => $validated['keyword_type'],
            'post_type_id' => $resolved['post_type_id'],
            'dynamic_post_id' => $resolved['dynamic_post_id'],
            'keyword_list' => $validated['keyword_list'] ?? [],
            'import_uid' => (string) Str::uuid(),
        ]);

        $keyword->load(['postType:id,name,slug', 'dynamicPost:id,post_type_id,title,slug']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword created successfully.',
            'data' => $this->formatKeyword($keyword),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $keyword = Keyword::with(['postType:id,name,slug', 'dynamicPost:id,post_type_id,title,slug'])->find($id);

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

        $validated = $this->validateKeyword($request, $keyword);

        $resolved = $this->resolveRelations($validated);

        $keyword->update([
            'slug' => Str::slug($validated['slug']),
            'keyword_type' => $validated['keyword_type'],
            'post_type_id' => $resolved['post_type_id'],
            'dynamic_post_id' => $resolved['dynamic_post_id'],
            'keyword_list' => $validated['keyword_list'] ?? [],
        ]);

        $keyword->load(['postType:id,name,slug', 'dynamicPost:id,post_type_id,title,slug']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword updated successfully.',
            'data' => $this->formatKeyword($keyword),
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

    private function validateKeyword(Request $request, ?Keyword $keyword = null): array
    {
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('keywords', 'slug')->ignore($keyword?->id),
            ],
            'keyword_type' => ['required', Rule::in(['post_type', 'dynamic_post'])],

            'post_type_id' => ['nullable', 'integer', 'exists:post_types,id'],
            'post_type_slug' => ['nullable', 'string', 'exists:post_types,slug'],

            'dynamic_post_id' => ['nullable', 'integer', 'exists:dynamic_posts,id'],
            'dynamic_post_slug' => ['nullable', 'string'],

            'keyword_list' => ['required'],
        ]);
    }

    private function resolveRelations(array $data): array
    {
        $postType = null;

        if (! empty($data['post_type_id'])) {
            $postType = PostType::find((int) $data['post_type_id']);
        }

        if (! $postType && ! empty($data['post_type_slug'])) {
            $postType = PostType::where('slug', $data['post_type_slug'])->first();
        }

        if (! $postType) {
            abort(response()->json([
                'status' => false,
                'message' => 'Post type is required.',
            ], 422));
        }

        $dynamicPostId = null;

        if (($data['keyword_type'] ?? null) === 'dynamic_post') {
            $dynamicPost = null;

            if (! empty($data['dynamic_post_id'])) {
                $dynamicPost = DynamicPost::where('id', (int) $data['dynamic_post_id'])
                    ->where('post_type_id', $postType->id)
                    ->first();
            }

            if (! $dynamicPost && ! empty($data['dynamic_post_slug'])) {
                $dynamicPost = DynamicPost::where('slug', $data['dynamic_post_slug'])
                    ->where('post_type_id', $postType->id)
                    ->first();
            }

            if (! $dynamicPost) {
                abort(response()->json([
                    'status' => false,
                    'message' => 'Dynamic post is required and must belong to selected post type.',
                ], 422));
            }

            $dynamicPostId = $dynamicPost->id;
        }

        return [
            'post_type_id' => $postType->id,
            'dynamic_post_id' => $dynamicPostId,
        ];
    }

    private function formatKeyword(Keyword $keyword): array
    {
        return [
            'id' => $keyword->id,
            'slug' => $keyword->slug,
            'keyword_type' => $keyword->keyword_type,
            'post_type_id' => $keyword->post_type_id,
            'dynamic_post_id' => $keyword->dynamic_post_id,
            'keyword_list' => $keyword->keyword_list ?? [],
            'keyword_list_text' => implode(', ', $keyword->keyword_list ?? []),

            'post_type' => $keyword->postType ? [
                'id' => $keyword->postType->id,
                'name' => $keyword->postType->name,
                'slug' => $keyword->postType->slug,
            ] : null,

            'dynamic_post' => $keyword->dynamicPost ? [
                'id' => $keyword->dynamicPost->id,
                'title' => $keyword->dynamicPost->title,
                'slug' => $keyword->dynamicPost->slug,
            ] : null,

            'created_at' => $keyword->created_at,
            'updated_at' => $keyword->updated_at,
        ];
    }
}