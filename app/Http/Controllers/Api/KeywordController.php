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
        $query = Keyword::with([
            'keywordType:id,name,slug',
            'listing:id,post_type_id,title,slug,status,live_status',
        ]);

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;

            $q->where(function ($sub) use ($search) {
                $sub->where('slug', 'like', "%{$search}%")
                    ->orWhere('keyword_list', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('keyword_type'), fn ($q) => $q->where('keyword_type', $request->keyword_type));
        $query->when($request->filled('post_type'), fn ($q) => $q->where('post_type', $request->post_type));

        $perPage = min((int) $request->get('per_page', 15), 100);

        $keywords = $query->latest()->paginate($perPage);

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

        $relationError = $this->validateListingBelongsToPostType(
            (int) $validated['keyword_type'],
            (int) $validated['post_type']
        );

        if ($relationError) {
            return response()->json($relationError, 422);
        }

        $keyword = Keyword::create([
            'slug' => Str::slug($validated['slug']),
            'keyword_type' => (int) $validated['keyword_type'],
            'post_type' => (int) $validated['post_type'],
            'keyword_list' => $validated['keyword_list'],
        ]);

        $keyword->load(['keywordType:id,name,slug', 'listing:id,post_type_id,title,slug,status,live_status']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword created successfully.',
            'data' => $this->formatKeyword($keyword),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $keyword = Keyword::with([
            'keywordType:id,name,slug',
            'listing:id,post_type_id,title,slug,status,live_status',
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

        $validated = $this->validateKeyword($request, $keyword);

        $relationError = $this->validateListingBelongsToPostType(
            (int) $validated['keyword_type'],
            (int) $validated['post_type']
        );

        if ($relationError) {
            return response()->json($relationError, 422);
        }

        $keyword->update([
            'slug' => Str::slug($validated['slug']),
            'keyword_type' => (int) $validated['keyword_type'],
            'post_type' => (int) $validated['post_type'],
            'keyword_list' => $validated['keyword_list'],
        ]);

        $keyword->load(['keywordType:id,name,slug', 'listing:id,post_type_id,title,slug,status,live_status']);

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

    public function keywordTypes(): JsonResponse
    {
        $postTypes = PostType::query()
            ->where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'status' => true,
            'message' => 'Keyword types fetched successfully.',
            'data' => $postTypes,
        ]);
    }

    public function listings(int $keywordType): JsonResponse
    {
        $postType = PostType::find($keywordType);

        if (! $postType) {
            return response()->json([
                'status' => false,
                'message' => 'Post type not found.',
            ], 404);
        }

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

    private function validateKeyword(Request $request, ?Keyword $keyword = null): array
    {
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('keywords', 'slug')->ignore($keyword?->id),
            ],

            'keyword_type' => [
                'required',
                'integer',
                'exists:post_types,id',
            ],

            'post_type' => [
                'required',
                'integer',
                'exists:dynamic_posts,id',
            ],

            'keyword_list' => [
                'required',
            ],
        ]);
    }

    private function validateListingBelongsToPostType(int $keywordType, int $postType): ?array
    {
        $exists = DynamicPost::query()
            ->where('id', $postType)
            ->where('post_type_id', $keywordType)
            ->exists();

        if (! $exists) {
            return [
                'status' => false,
                'message' => 'Selected listing does not belong to selected keyword type.',
                'errors' => [
                    'post_type' => [
                        'Selected listing does not belong to selected keyword type.',
                    ],
                ],
            ];
        }

        return null;
    }

    private function formatKeyword(Keyword $keyword): array
    {
        return [
            'id' => $keyword->id,
            'slug' => $keyword->slug,

            'keyword_type' => $keyword->keyword_type,
            'post_type' => $keyword->post_type,

            'keyword_list' => $keyword->keyword_list ?? [],
            'keyword_list_text' => implode(', ', $keyword->keyword_list ?? []),

            'keyword_type_data' => $keyword->keywordType ? [
                'id' => $keyword->keywordType->id,
                'name' => $keyword->keywordType->name,
                'slug' => $keyword->keywordType->slug,
            ] : null,

            'post_type_data' => $keyword->listing ? [
                'id' => $keyword->listing->id,
                'title' => $keyword->listing->title,
                'slug' => $keyword->listing->slug,
                'post_type_id' => $keyword->listing->post_type_id,
                'status' => $keyword->listing->status,
                'live_status' => $keyword->listing->live_status,
            ] : null,

            'created_at' => $keyword->created_at,
            'updated_at' => $keyword->updated_at,
        ];
    }
}