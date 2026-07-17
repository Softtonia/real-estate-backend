<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserPropertyListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'users_property_listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish']),
                ],
                'users-Property-listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish']),
                ],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $userId = Auth::id();

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            $filter = $request->query('users_property_listings')
                ?? $request->query('users-Property-listings')
                ?? 'all';

            $postType = PostType::query()
                ->where('slug', 'property-listing')
                ->first();

            if (!$postType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property listing post type not found.',
                ], 404);
            }

            $query = DynamicPost::query()
                ->with([
                    'postType',
                    'taxonomyTerms.taxonomy',
                    'meta.customField.options',
                    'meta.customField.repeaters.options',
                ])
                ->where('post_type_id', $postType->id)
                ->where(function ($userQuery) use ($userId) {
                    if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                        $userQuery->where('author_id', $userId);
                    }

                    if (Schema::hasTable('dynamic_post_user')) {
                        $userQuery->orWhereExists(function ($subQuery) use ($userId) {
                            $subQuery->select(DB::raw(1))
                                ->from('dynamic_post_user')
                                ->whereColumn('dynamic_post_user.dynamic_post_id', 'dynamic_posts.id')
                                ->where('dynamic_post_user.user_id', $userId);
                        });
                    }
                });

            $this->applyListingFilter($query, $filter);

            if (Schema::hasColumn('dynamic_posts', 'sort_order')) {
                $query->orderBy('sort_order', 'asc');
            }

            $query->latest();

            $perPage = (int) $request->get('per_page', 10);

            $listings = $query->paginate($perPage);

            $listings->getCollection()->transform(function ($listing) {
                return $this->formatListing($listing);
            });

            return response()->json([
                'status' => true,
                'message' => 'User property listings fetched successfully.',
                'filter' => $filter,
                'data' => $listings,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user property listings.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function applyListingFilter($query, string $filter): void
    {
        match ($filter) {
            'active' => $query
                ->where('status', 'published')
                ->where('live_status', 'approve'),

            'inactive' => $query
                ->where(function ($q) {
                    $q->whereIn('live_status', ['reject', 'disapprove'])
                        ->orWhereIn('status', ['private', 'archived']);
                }),

            'draft' => $query
                ->where('status', 'draft'),

            'publish' => $query
                ->where('status', 'published'),

            default => null,
        };
    }

    private function formatListing(DynamicPost $listing): array
    {
        $data = [
            'id' => (int) $listing->id,
            'post_type_id' => (int) $listing->post_type_id,
            'post_type' => [
                'id' => (int) $listing->postType?->id,
                'name' => $listing->postType?->name,
                'slug' => $listing->postType?->slug,
            ],

            'listing_code' => $listing->listing_code ?? null,
            'display_id' => $listing->listing_code ?? null,

            'title' => $listing->title ?? null,
            'slug' => $listing->slug ?? null,
            'excerpt' => $listing->excerpt ?? null,
            'content' => $listing->content ?? null,

            'status' => $listing->status ?? null,
            'live_status' => $listing->live_status ?? null,
            'review_status_label' => $this->reviewStatusLabel($listing->live_status ?? null),

            'is_active' => $listing->status === 'published' && $listing->live_status === 'approve',
            'is_rejected' => in_array($listing->live_status, ['reject', 'disapprove'], true),

            'country_id' => $listing->country_id ? (int) $listing->country_id : null,
            'state_id' => $listing->state_id ? (int) $listing->state_id : null,
            'city_id' => $listing->city_id ? (int) $listing->city_id : null,
            'area_locality' => $listing->area_locality ?? null,

            'created_at' => $listing->created_at,
            'updated_at' => $listing->updated_at,
        ];

        if (Schema::hasColumn('dynamic_posts', 'rejection_reason')) {
            $data['rejection_reason'] = $listing->rejection_reason ?? null;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_at')) {
            $data['rejected_at'] = $listing->rejected_at ?? null;
        }

        if (Schema::hasColumn('dynamic_posts', 'rejected_by')) {
            $data['rejected_by'] = $listing->rejected_by ?? null;
        }

        return $data;
    }

    private function reviewStatusLabel(?string $liveStatus): string
    {
        return match ($liveStatus) {
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'disapprove' => 'Disapproved',
            'under_review' => 'Under Review',
            'modify_review' => 'Modification Required',
            'submit' => 'Submitted',
            default => 'Pending',
        };
    }
}