<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PostType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserPropertyListingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'users_property_listings' => [
                    'nullable',
                    'string',
                    Rule::in(['all', 'active', 'inactive', 'draft', 'publish']),
                ],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }

            if (!Schema::hasColumn('dynamic_posts', 'author_id')) {
                return response()->json([
                    'status' => false,
                    'message' => 'author_id column is required in dynamic_posts table to fetch own uploaded listings.',
                ], 500);
            }

            $filter = $request->query('users_property_listings', 'all');

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

                // IMPORTANT:
                // Only own uploaded listings.
                // No dynamic_post_user pivot here.
                ->where('author_id', (int) $user->id);

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
                'current_user' => [
                    'id' => (int) $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->name ?? null),
                    'email' => $user->email ?? null,
                ],
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

    private function resolveCurrentUser(Request $request): ?User
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

        if (Schema::hasColumn('users', 'api_token')) {
            return User::where('api_token', $token)->first();
        }

        return null;
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
                'id' => $listing->postType ? (int) $listing->postType->id : null,
                'name' => $listing->postType?->name,
                'slug' => $listing->postType?->slug,
            ],

            'author_id' => $listing->author_id ? (int) $listing->author_id : null,

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
            $data['rejected_by'] = $listing->rejected_by ? (int) $listing->rejected_by : null;
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