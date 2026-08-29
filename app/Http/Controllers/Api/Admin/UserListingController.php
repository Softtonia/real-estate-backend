<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\PostType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserListingController extends Controller
{
    /**
     * Role to post-type slugs mapping according to business permissions.
     */
    private array $rolePostTypeMap = [
        'owner' => ['property-listing'],
        'agent' => ['property-listing'],
        'company' => ['project-listing', 'property-listing'],
        'developer' => ['property-listing', 'project-listing', 'developer-listing'],
        'dev' => ['property-listing', 'project-listing', 'developer-listing'],
        'builder' => ['property-listing', 'project-listing', 'developer-listing'],
        'consultancy' => ['property-listing', 'project-listing'],
    ];

    /**
     * Get allowed listing tabs and post-type counts for a user.
     */
    public function allowedTabs(Request $request, ?int $routeUserId = null): JsonResponse
    {
        try {
            $userId = $routeUserId ?? $request->input('user_id') ?? $request->input('id');
            if (empty($userId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.',
                ], 400);
            }

            $user = User::with('role')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $tabs = $this->resolveAllowedTabsForUser($user);

            return response()->json([
                'status' => true,
                'message' => 'Allowed listing tabs retrieved successfully.',
                'user' => [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name ?? 'No Role',
                ],
                'data' => $tabs,
                'allowed_tabs' => $tabs,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch allowed listing tabs.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Get listings for a specific user filtered by post_type and other parameters.
     */
    public function index(Request $request, ?int $routeUserId = null): JsonResponse
    {
        try {
            $userId = $routeUserId ?? $request->input('user_id') ?? $request->input('id');
            if (empty($userId)) {
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.',
                ], 400);
            }

            $user = User::with('role')->find($userId);
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $allowedTabs = $this->resolveAllowedTabsForUser($user);
            $allowedPostTypeIds = collect($allowedTabs)->pluck('post_type_id')->filter()->all();

            $query = DynamicPost::query()
                ->with(['postType', 'country', 'state', 'city', 'meta.customField'])
                ->where('author_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            // Determine active post type filter
            $requestedPostType = $request->input('post_type') ?? $request->input('post_type_id');
            $activeTabSlug = null;

            if (!empty($requestedPostType)) {
                if (is_numeric($requestedPostType)) {
                    $query->where('post_type_id', (int) $requestedPostType);
                    $matched = collect($allowedTabs)->firstWhere('post_type_id', (int) $requestedPostType);
                    $activeTabSlug = $matched['key'] ?? null;
                } else {
                    $slug = strtolower(trim($requestedPostType));
                    $postType = PostType::where('slug', $slug)->orWhere('name', 'like', "%{$slug}%")->first();
                    if ($postType) {
                        $query->where('post_type_id', $postType->id);
                        $activeTabSlug = $postType->slug;
                    }
                }
            } else {
                // If no specific post type requested, restrict to allowed post types
                if (!empty($allowedPostTypeIds)) {
                    $query->whereIn('post_type_id', $allowedPostTypeIds);
                }
                $activeTabSlug = $allowedTabs[0]['key'] ?? null;
            }

            // Status filter
            if ($request->filled('status') && strtolower($request->input('status')) !== 'all') {
                $status = strtolower(trim($request->input('status')));
                $query->where(function ($q) use ($status) {
                    $q->where('status', $status)
                      ->orWhere('live_status', $status);
                });
            }

            // Search filter
            if ($request->filled('search')) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%")
                      ->orWhere('listing_code', 'like', "%{$search}%")
                      ->orWhere('area_locality', 'like', "%{$search}%");
                });
            }

            // Date filters
            if ($request->filled('date_from')) {
                try {
                    $query->whereDate('created_at', '>=', Carbon::parse($request->input('date_from'))->toDateString());
                } catch (Throwable) {}
            }

            if ($request->filled('date_to')) {
                try {
                    $query->whereDate('created_at', '<=', Carbon::parse($request->input('date_to'))->toDateString());
                } catch (Throwable) {}
            }

            $perPage = min(100, max(1, (int) ($request->input('per_page') ?? 10)));
            $paginator = $query->paginate($perPage);

            $formattedItems = $paginator->getCollection()->map(function ($post) {
                return $this->formatPostItem($post);
            });

            return response()->json([
                'status' => true,
                'message' => 'User listings fetched successfully.',
                'user' => [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name ?? 'No Role',
                ],
                'allowed_tabs' => $allowedTabs,
                'active_tab' => $activeTabSlug,
                'data' => $formattedItems,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user listings.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Resolve the allowed listing tabs configuration for a given user.
     */
    public function resolveAllowedTabsForUser(User $user): array
    {
        $roleName = strtolower(trim($user->role?->name ?? ''));
        $allowedSlugs = $this->rolePostTypeMap[$roleName] ?? null;

        // If not strictly defined in map, load all active post types
        if ($allowedSlugs === null) {
            $postTypes = PostType::where('status', true)->orderBy('sort_order')->get();
        } else {
            $postTypes = PostType::whereIn('slug', $allowedSlugs)
                ->where('status', true)
                ->get()
                ->sortBy(function ($item) use ($allowedSlugs) {
                    $pos = array_search($item->slug, $allowedSlugs);
                    return $pos !== false ? $pos : 99;
                })
                ->values();
        }

        $tabs = [];
        foreach ($postTypes as $pt) {
            $count = DynamicPost::where('author_id', $user->id)
                ->where('post_type_id', $pt->id)
                ->count();

            $tabs[] = [
                'key' => $pt->slug,
                'title' => $pt->name,
                'post_type_id' => $pt->id,
                'post_type_slug' => $pt->slug,
                'count' => $count,
            ];
        }

        return $tabs;
    }

    /**
     * Format DynamicPost model to a clean array structure for admin UI.
     */
    private function formatPostItem(DynamicPost $post): array
    {
        $customMedia = $this->resolveMediaFromCustomFields($post);

        $locationParts = array_filter([
            $post->area_locality,
            $post->city?->name,
            $post->state?->name,
            $post->country?->name,
        ]);

        $customFields = [];
        if ($post->relationLoaded('meta')) {
            foreach ($post->meta as $meta) {
                if ($meta->customField) {
                    $slug = $meta->customField->slug ?? $meta->customField->name;
                    $value = $meta->value_string ?? $meta->value_text ?? $meta->value_number ?? $meta->value_json ?? null;
                    if ($slug) {
                        $customFields[$slug] = $value;
                    }
                }
            }
        }

        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'listing_code' => $post->listing_code,
            'post_type_id' => $post->post_type_id,
            'post_type_name' => $post->postType?->name,
            'post_type_slug' => $post->postType?->slug,
            'status' => $post->status,
            'live_status' => $post->live_status,
            'image' => $customMedia['image'],
            'featured_image' => $customMedia['image'],
            'gallery' => $customMedia['gallery'],
            'gallery_images' => $customMedia['gallery'],
            'custom_fields' => $customFields,
            'location' => implode(', ', $locationParts) ?: null,
            'city' => $post->city?->name,
            'state' => $post->state?->name,
            'country' => $post->country?->name,
            'area_locality' => $post->area_locality,
            'author_id' => $post->author_id,
            'published_at' => $post->published_at ? $post->published_at->format('M d, Y h:i A') : null,
            'date_time' => $post->created_at ? $post->created_at->format('M d, Y h:i A') : null,
            'created_at' => $post->created_at ? $post->created_at->toISOString() : null,
            'updated_at' => $post->updated_at ? $post->updated_at->toISOString() : null,
        ];
    }

    /**
     * Resolve image and gallery dynamically from custom fields without hardcoding.
     */
    private function resolveMediaFromCustomFields(DynamicPost $post): array
    {
        $primaryImage = null;
        $gallery = [];

        if ($post->relationLoaded('meta')) {
            foreach ($post->meta as $meta) {
                $cf = $meta->customField;
                if (!$cf) continue;

                $type = strtolower($cf->type ?? '');
                $slug = strtolower($cf->slug ?? '');
                $isMediaField = in_array($type, ['image', 'gallery', 'file', 'media'])
                    || str_contains($slug, 'image')
                    || str_contains($slug, 'photo')
                    || str_contains($slug, 'gallery')
                    || str_contains($slug, 'featured')
                    || str_contains($slug, 'cover')
                    || str_contains($slug, 'thumbnail');

                if ($isMediaField) {
                    $raw = $meta->value_json ?? $meta->value_string ?? $meta->value_text;
                    if (!empty($raw)) {
                        if (is_array($raw)) {
                            foreach ($raw as $val) {
                                $url = $this->resolveSingleMediaUrl($val);
                                $isItemFeatured = is_array($val) && (!empty($val['is_featured']) || !empty($val['featured']));
                                if ($url) {
                                    $gallery[] = $url;
                                    if ($isItemFeatured) {
                                        $primaryImage = $url;
                                    } elseif (!$primaryImage) {
                                        $primaryImage = $url;
                                    }
                                }
                            }
                        } else {
                            $url = $this->resolveSingleMediaUrl($raw);
                            if ($url) {
                                $gallery[] = $url;
                                if (!$primaryImage) {
                                    $primaryImage = $url;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fallback to post hardcoded column if custom field had no images
        if (!$primaryImage && !empty($post->featured_image_id)) {
            $primaryImage = $this->resolveSingleMediaUrl($post->featured_image_id);
        }

        return [
            'image' => $primaryImage,
            'gallery' => array_values(array_unique($gallery)),
        ];
    }

    /**
     * Resolve single media URL from ID, path, or direct URL.
     */
    private function resolveSingleMediaUrl(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            if (!empty($value['url'])) {
                return $this->resolveSingleMediaUrl($value['url']);
            }
            if (!empty($value['path'])) {
                return $this->resolveSingleMediaUrl($value['path']);
            }
            if (!empty($value['id'])) {
                return $this->resolveSingleMediaUrl($value['id']);
            }
            return null;
        }

        if (is_numeric($value)) {
            $media = MediaFile::find((int) $value);
            if ($media && !empty($media->file_path)) {
                $path = $media->file_path;
                if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                    return $path;
                }
                return Storage::disk('public')->url($path);
            }
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }
            if (!empty($value)) {
                return Storage::disk('public')->url($value);
            }
        }

        return null;
    }
}
