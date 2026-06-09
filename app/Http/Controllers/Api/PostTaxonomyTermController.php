<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostTaxonomyTerm;
use App\Models\TaxonomyTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostTaxonomyTermController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PostTaxonomyTerm::query()
                ->with([
                    'dynamicPost',
                    'taxonomy',
                    'taxonomyTerm',
                ])
                ->when($request->filled('dynamic_post_id'), function ($q) use ($request) {
                    $q->where('dynamic_post_id', $request->dynamic_post_id);
                })
                ->when($request->filled('taxonomy_id'), function ($q) use ($request) {
                    $q->where('taxonomy_id', $request->taxonomy_id);
                })
                ->when($request->filled('taxonomy_term_id'), function ($q) use ($request) {
                    $q->where('taxonomy_term_id', $request->taxonomy_term_id);
                })
                ->latest();

            $perPage = (int) $request->get('per_page', 15);
            $perPage = $perPage > 100 ? 100 : $perPage;

            return response()->json([
                'status' => true,
                'message' => 'Post taxonomy terms fetched successfully.',
                'data' => $query->paginate($perPage),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'dynamic_post_id' => [
                    'required',
                    'integer',
                    'exists:dynamic_posts,id',
                ],
                'taxonomy_term_id' => [
                    'required',
                    'integer',
                    'exists:taxonomy_terms,id',
                ],
            ], [
                'dynamic_post_id.required' => 'Dynamic post is required.',
                'dynamic_post_id.exists' => 'Selected dynamic post does not exist.',
                'taxonomy_term_id.required' => 'Taxonomy term is required.',
                'taxonomy_term_id.exists' => 'Selected taxonomy term does not exist.',
            ]);

            $term = TaxonomyTerm::find($validated['taxonomy_term_id']);

            $alreadyExists = PostTaxonomyTerm::where('dynamic_post_id', $validated['dynamic_post_id'])
                ->where('taxonomy_term_id', $validated['taxonomy_term_id'])
                ->exists();

            if ($alreadyExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'This taxonomy term is already attached to this post.',
                    'errors' => [
                        'taxonomy_term_id' => [
                            'This taxonomy term is already attached to this dynamic post.',
                        ],
                    ],
                ], 422);
            }

            $postTaxonomyTerm = PostTaxonomyTerm::create([
                'dynamic_post_id' => $validated['dynamic_post_id'],
                'taxonomy_id' => $term->taxonomy_id,
                'taxonomy_term_id' => $validated['taxonomy_term_id'],
            ]);

            $postTaxonomyTerm->load([
                'dynamicPost',
                'taxonomy',
                'taxonomyTerm',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term attached to post successfully.',
                'data' => $postTaxonomyTerm,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to attach taxonomy term to post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(PostTaxonomyTerm $postTaxonomyTerm)
    {
        try {
            $postTaxonomyTerm->load([
                'dynamicPost',
                'taxonomy',
                'taxonomyTerm',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Post taxonomy term fetched successfully.',
                'data' => $postTaxonomyTerm,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch post taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, PostTaxonomyTerm $postTaxonomyTerm)
    {
        try {
            $validated = $request->validate([
                'dynamic_post_id' => [
                    'sometimes',
                    'required',
                    'integer',
                    'exists:dynamic_posts,id',
                ],
                'taxonomy_term_id' => [
                    'sometimes',
                    'required',
                    'integer',
                    'exists:taxonomy_terms,id',
                ],
            ]);

            $dynamicPostId = $validated['dynamic_post_id'] ?? $postTaxonomyTerm->dynamic_post_id;
            $taxonomyTermId = $validated['taxonomy_term_id'] ?? $postTaxonomyTerm->taxonomy_term_id;

            $term = TaxonomyTerm::find($taxonomyTermId);

            $alreadyExists = PostTaxonomyTerm::where('dynamic_post_id', $dynamicPostId)
                ->where('taxonomy_term_id', $taxonomyTermId)
                ->where('id', '!=', $postTaxonomyTerm->id)
                ->exists();

            if ($alreadyExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'This taxonomy term is already attached to this post.',
                    'errors' => [
                        'taxonomy_term_id' => [
                            'This taxonomy term is already attached to this dynamic post.',
                        ],
                    ],
                ], 422);
            }

            $postTaxonomyTerm->update([
                'dynamic_post_id' => $dynamicPostId,
                'taxonomy_id' => $term->taxonomy_id,
                'taxonomy_term_id' => $taxonomyTermId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Post taxonomy term updated successfully.',
                'data' => $postTaxonomyTerm->fresh()->load([
                    'dynamicPost',
                    'taxonomy',
                    'taxonomyTerm',
                ]),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update post taxonomy term.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(PostTaxonomyTerm $postTaxonomyTerm)
    {
        try {
            $postTaxonomyTerm->delete();

            return response()->json([
                'status' => true,
                'message' => 'Taxonomy term detached from post successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to detach taxonomy term from post.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'required',
                    'integer',
                    'exists:post_taxonomy_terms,id',
                ],
            ]);

            DB::beginTransaction();

            $deleted = PostTaxonomyTerm::whereIn('id', $request->ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Selected post taxonomy terms deleted successfully.',
                'deleted_count' => $deleted,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to delete selected post taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function sync(Request $request)
    {
        try {
            $validated = $request->validate([
                'dynamic_post_id' => [
                    'required',
                    'integer',
                    'exists:dynamic_posts,id',
                ],
                'taxonomy_term_ids' => [
                    'nullable',
                    'array',
                ],
                'taxonomy_term_ids.*' => [
                    'integer',
                    'exists:taxonomy_terms,id',
                ],
            ], [
                'dynamic_post_id.required' => 'Dynamic post is required.',
            ]);

            DB::beginTransaction();

            PostTaxonomyTerm::where('dynamic_post_id', $validated['dynamic_post_id'])->delete();

            $created = 0;

            if (!empty($validated['taxonomy_term_ids'])) {
                $terms = TaxonomyTerm::whereIn('id', $validated['taxonomy_term_ids'])->get();

                foreach ($terms as $term) {
                    PostTaxonomyTerm::create([
                        'dynamic_post_id' => $validated['dynamic_post_id'],
                        'taxonomy_id' => $term->taxonomy_id,
                        'taxonomy_term_id' => $term->id,
                    ]);

                    $created++;
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Post taxonomy terms synced successfully.',
                'attached_count' => $created,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to sync post taxonomy terms.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}