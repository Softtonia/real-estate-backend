<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\Menu;
use App\Models\PostType;
use App\Models\Taxonomy;
use App\Models\TaxonomyTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MenuController extends Controller
{
    /**
     * List registered menu locations.
     */
    public function locations(): JsonResponse
    {
        $locations = [
            ['key' => 'Header', 'name' => 'Primary Header Navigation', 'description' => 'Main navigation menu in website header'],
            ['key' => 'Footer', 'name' => 'Footer Navigation', 'description' => 'Navigation links displayed in footer'],
            ['key' => 'Footer2', 'name' => 'Footer Column 2', 'description' => 'Secondary footer menu'],
            ['key' => 'Sidebar', 'name' => 'Sidebar Navigation', 'description' => 'Navigation menu displayed in sidebar widget'],
            ['key' => 'TopBar', 'name' => 'Top Bar Navigation', 'description' => 'Top announcement / utility bar menu'],
            ['key' => 'Mobile', 'name' => 'Mobile Menu', 'description' => 'Navigation displayed on mobile off-canvas drawer'],
        ];

        // Also fetch any custom menu names or locations from database
        $existingLocations = Menu::select('location')->distinct()->pluck('location')->filter()->values();
        $existingNames = Menu::select('menu_name')->distinct()->pluck('menu_name')->filter()->values();

        return response()->json([
            'status' => true,
            'data' => [
                'locations' => $locations,
                'active_locations' => $existingLocations,
                'menu_names' => $existingNames,
            ],
        ]);
    }

    /**
     * WordPress Left Panel Sources API:
     * Returns Post Types, Dynamic Posts (Pages, Listings, Blogs), and Taxonomies for adding to menu.
     */
    public function sources(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->input('search', ''));

            // 1. Post Types
            $postTypes = PostType::where('status', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'description'])
                ->map(fn($pt) => [
                    'id' => $pt->id,
                    'title' => $pt->name,
                    'slug' => $pt->slug,
                    'type' => 'post_type',
                    'entity_type' => 'post_type',
                    'entity_id' => $pt->id,
                    'url' => '/' . ltrim($pt->slug, '/'),
                ]);

            // 2. Dynamic Posts grouped by Post Type (e.g. Pages, Properties, Projects, Blogs)
            $postsByPostType = [];
            foreach ($postTypes as $pt) {
                $postsQuery = DynamicPost::where('post_type_id', $pt['id'])
                    ->whereIn('status', ['published', 'active', 'approve'])
                    ->orderByDesc('id');

                if (!empty($search)) {
                    $postsQuery->where(function ($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                }

                $posts = $postsQuery->limit(20)->get(['id', 'title', 'slug', 'post_type_id'])->map(function ($post) use ($pt) {
                    $url = $pt['slug'] === 'page' ? '/' . ltrim($post->slug, '/') : '/' . trim($pt['slug'], '/') . '/' . ltrim($post->slug, '/');
                    return [
                        'id' => $post->id,
                        'title' => $post->title,
                        'slug' => $post->slug,
                        'type' => 'post',
                        'entity_type' => 'post',
                        'entity_id' => $post->id,
                        'post_type_slug' => $pt['slug'],
                        'url' => $url,
                    ];
                });

                $postsByPostType[] = [
                    'post_type_id' => $pt['id'],
                    'post_type_name' => $pt['title'],
                    'post_type_slug' => $pt['slug'],
                    'posts' => $posts,
                ];
            }

            // 3. Taxonomies & Terms
            $taxonomies = Taxonomy::with(['terms' => function ($q) use ($search) {
                $q->where('status', true)->orderBy('sort_order');
                if (!empty($search)) {
                    $q->where('name', 'like', "%{$search}%");
                }
            }])
                ->where('status', true)
                ->get()
                ->map(fn($tax) => [
                    'taxonomy_id' => $tax->id,
                    'taxonomy_name' => $tax->name,
                    'taxonomy_slug' => $tax->slug,
                    'terms' => $tax->terms->map(fn($term) => [
                        'id' => $term->id,
                        'title' => $term->name,
                        'slug' => $term->slug,
                        'type' => 'taxonomy_term',
                        'entity_type' => 'taxonomy_term',
                        'entity_id' => $term->id,
                        'taxonomy_slug' => $tax->slug,
                        'url' => '/' . trim($tax->slug, '/') . '/' . ltrim($term->slug, '/'),
                    ]),
                ]);

            return response()->json([
                'status' => true,
                'message' => 'Menu sources fetched successfully.',
                'data' => [
                    'post_types' => $postTypes,
                    'posts_by_post_type' => $postsByPostType,
                    'taxonomies' => $taxonomies,
                    'custom_link_template' => [
                        'type' => 'custom',
                        'entity_type' => 'custom',
                        'url' => 'https://',
                        'title' => '',
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch menu sources.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * List menus filtered by location, menu_name, or type.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Menu::query()->orderBy('position')->orderBy('id');

            if ($request->filled('location') && strtolower($request->input('location')) !== 'all') {
                $query->where('location', $request->input('location'));
            }

            if ($request->filled('menu_name')) {
                $query->where('menu_name', $request->input('menu_name'));
            }

            if ($request->filled('menu_type')) {
                $query->where('menu_type', $request->input('menu_type'));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $items = $query->get();

            if ($request->input('format') === 'flat') {
                return response()->json([
                    'status' => true,
                    'count' => $items->count(),
                    'data' => $items,
                ]);
            }

            $tree = Menu::buildTree($items);

            return response()->json([
                'status' => true,
                'location' => $request->input('location') ?? 'All',
                'count' => count($tree),
                'data' => $tree,
                'tree' => $tree,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch menus.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Show single menu item.
     */
    public function show($id): JsonResponse
    {
        $menu = Menu::with('children')->find($id);

        if (!$menu) {
            return response()->json([
                'status' => false,
                'message' => 'Menu item not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $menu,
        ]);
    }

    /**
     * Bulk Add items to menu (WordPress "Add to Menu" button action).
     */
    public function addItems(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'location' => ['nullable', 'string', 'max:100'],
            'menu_name' => ['nullable', 'string', 'max:191'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:191'],
            'items.*.link_type' => ['nullable', 'string', 'max:50'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.entity_type' => ['nullable', 'string', 'max:100'],
            'items.*.entity_id' => ['nullable', 'integer'],
            'items.*.parent_id' => ['nullable', 'integer'],
            'items.*.open_in_new_tab' => ['nullable', 'boolean'],
            'items.*.css_class' => ['nullable', 'string', 'max:191'],
            'items.*.icon' => ['nullable', 'string', 'max:191'],
            'items.*.badge' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $location = $request->input('location') ?: 'Header';
            $menuName = $request->input('menu_name') ?: null;
            $items = $request->input('items', []);

            $maxPosition = (int) Menu::where('location', $location)->max('position') ?: 0;
            $createdItems = [];

            DB::transaction(function () use ($items, $location, $menuName, $maxPosition, &$createdItems) {
                foreach ($items as $idx => $item) {
                    $title = trim($item['title'] ?? '');
                    $slug = Str::slug($title) ?: 'menu-item';
                    $url = $item['url'] ?? null;
                    $linkType = $item['link_type'] ?? (!empty($url) ? 'url' : 'entity');

                    $menu = Menu::create([
                        'title' => $title,
                        'slug' => $slug,
                        'menu_type' => $item['menu_type'] ?? 'normal',
                        'location' => $location,
                        'menu_name' => $menuName,
                        'link_type' => $linkType,
                        'url' => $url,
                        'entity_type' => $item['entity_type'] ?? 'custom',
                        'entity_id' => !empty($item['entity_id']) ? (int) $item['entity_id'] : null,
                        'css_class' => $item['css_class'] ?? null,
                        'icon' => $item['icon'] ?? null,
                        'badge' => $item['badge'] ?? null,
                        'parent_id' => !empty($item['parent_id']) ? (int) $item['parent_id'] : null,
                        'position' => $maxPosition + $idx + 1,
                        'depth' => !empty($item['parent_id']) ? 1 : 0,
                        'is_active' => true,
                        'open_in_new_tab' => !empty($item['open_in_new_tab']),
                        'created_by' => auth()->id(),
                    ]);

                    $createdItems[] = $menu;
                }
            });

            return response()->json([
                'status' => true,
                'message' => count($createdItems) . ' items added to menu successfully.',
                'data' => $createdItems,
            ], 201);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to add items to menu.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Create a single menu item.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->menuRules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->all();
            if (empty($data['slug']) && !empty($data['title'])) {
                $data['slug'] = Str::slug($data['title']);
            }
            $data['created_by'] = auth()->id();

            $menu = Menu::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Menu item created successfully.',
                'data' => $menu,
            ], 201);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to create menu item.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Update an existing menu item.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $menu = Menu::find($id);
        if (!$menu) {
            return response()->json([
                'status' => false,
                'message' => 'Menu item not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->menuRules($menu->id));

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->all();
            $menu->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Menu item updated successfully.',
                'data' => $menu,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to update menu item.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Delete a single menu item.
     */
    public function destroy($id): JsonResponse
    {
        $menu = Menu::find($id);
        if (!$menu) {
            return response()->json([
                'status' => false,
                'message' => 'Menu item not found',
            ], 404);
        }

        try {
            // Re-parent children to this menu's parent so they don't get orphaned
            Menu::where('parent_id', $menu->id)->update(['parent_id' => $menu->parent_id]);
            $menu->delete();

            return response()->json([
                'status' => true,
                'message' => 'Menu item deleted successfully.',
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to delete menu item.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Bulk Delete menu items.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:menus,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $ids = $request->input('ids');
            Menu::whereIn('id', $ids)->delete();

            return response()->json([
                'status' => true,
                'message' => count($ids) . ' menu items deleted successfully.',
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to bulk delete menu items.',
            ], 500);
        }
    }

    /**
     * Draggable Menu Save Tree API (WordPress "Save Menu" action).
     * Accepts nested structure or flat array with parent_id & position.
     */
    public function saveTree(Request $request): JsonResponse
    {
        $items = $request->input('menus') ?? $request->input('tree') ?? $request->input('items') ?? [];

        if (!is_array($items) || empty($items)) {
            return response()->json([
                'status' => false,
                'message' => 'Please provide a valid menus tree/array structure.',
            ], 422);
        }

        try {
            Menu::applyNestedReorder($items);

            $location = $request->input('location');
            $updatedTree = $location ? Menu::buildTree(Menu::where('location', $location)->orderBy('position')->get()) : null;

            return response()->json([
                'status' => true,
                'message' => 'Menu structure saved successfully.',
                'data' => $updatedTree,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'status' => false,
                'message' => 'Unable to save menu structure: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    /**
     * Alias for reorder.
     */
    public function reorder(Request $request): JsonResponse
    {
        return $this->saveTree($request);
    }

    /* -------------------------------
     | Validation Rules
     |--------------------------------*/
    private function menuRules(?int $id = null): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191'],
            'menu_type' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:100'],
            'menu_name' => ['nullable', 'string', 'max:191'],
            'link_type' => ['nullable', 'string', 'max:50'],
            'url' => ['nullable', 'string', 'max:2048'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'integer'],
            'css_class' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string', 'max:191'],
            'badge' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:menus,id'],
            'position' => ['nullable', 'integer', 'min:0'],
            'depth' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'open_in_new_tab' => ['nullable', 'boolean'],
            'query_params' => ['nullable', 'array'],
            'mega_settings' => ['nullable', 'array'],
            'structured_data' => ['nullable', 'array'],
            'meta_title' => ['nullable', 'string', 'max:191'],
            'meta_description' => ['nullable', 'string'],
        ];
    }
}
