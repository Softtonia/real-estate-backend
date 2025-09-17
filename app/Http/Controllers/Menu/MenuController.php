<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
     /**
     * List menus optionally filtered by location or type
     */
    public function index(Request $request)
    {
        $menus = Menu::query()
            ->location($request->input('location'))
            ->menuType($request->input('menu_type'))
            ->orderBy('position')
            ->get();

        $tree = Menu::buildTree($menus);

        return response()->json($tree);
    }

    /**
     * Show single menu item
     */
    public function show($id)
    {
        $menu = Menu::with('children')->find($id);

        if (!$menu) {
            return response()->json(['message' => 'Menu not found'], 404);
        }

        return response()->json($menu);
    }

    /**
     * Create a new menu
     */
    public function store(Request $request)
    {
        $this->authorizeMenu();

        $data = $this->validateMenu($request);

        $menu = Menu::create($data);

        return response()->json($menu, 201);
    }

    /**
     * Update an existing menu
     */
    public function update(Request $request, $id)
    {
        $this->authorizeMenu();

        $menu = Menu::find($id);
        if (!$menu) {
            return response()->json(['message' => 'Menu not found'], 404);
        }

        $data = $this->validateMenu($request, $menu->id);

        $menu->update($data);

        return response()->json($menu);
    }

    /**
     * Delete a menu
     */
    public function destroy($id)
    {
        $this->authorizeMenu();

        $menu = Menu::find($id);
        if (!$menu) {
            return response()->json(['message' => 'Menu not found'], 404);
        }

        $menu->delete();

        return response()->json(['message' => 'Menu deleted successfully']);
    }

    /**
     * Reorder menus using nested payload
     */
    public function reorder(Request $request)
    {
        $this->authorizeMenu();

        $nested = $request->input('menus', []);

        try {
            Menu::applyNestedReorder($nested);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Menus reordered successfully']);
    }

    /* -------------------------------
     | Helper Methods
     |--------------------------------*/

    protected function authorizeMenu()
    {
        // if (!auth()->user() || !auth()->user()->can('manage_menus')) {
        //     abort(403, 'Unauthorized');
        // }

        return true;
    }

    protected function validateMenu(Request $request, ?int $menuId = null): array
    {
        $rules = [
            'title'            => ['required', 'string', 'max:191'],
            'slug'             => ['nullable', 'string', 'max:191'],
            'menu_type'        => ['required', Rule::in(['normal', 'mega'])],
            'location'         => ['required', Rule::in(['Header', 'Footer', 'Sidebar'])],
            'link_type'        => ['required', Rule::in(['none', 'url', 'query', 'entity'])],
            'url' => [
                        'nullable',
                        'string',
                        'max:2048',
                        function ($attribute, $value, $fail) {
                            if (!filter_var($value, FILTER_VALIDATE_URL) && !str_starts_with($value, '/')) {
                                $fail("The {$attribute} must be a valid URL or a relative path.");
                            }
                        },
                    ],

            'entity_type'      => ['nullable', Rule::in(['property', 'category', 'agent', 'null'])],
            'entity_id'        => ['nullable', 'integer'],
            'query_params'     => ['nullable', 'json'],
            'mega_settings'    => ['nullable', 'array'],
            'structured_data'  => ['nullable', 'json'],
            'meta_title'       => ['nullable', 'string', 'max:191'],
            'meta_description' => ['nullable', 'string'],
            'parent_id'        => ['nullable', 'integer', 'exists:menus,id'],
            'position'         => ['nullable', 'integer', 'min:0'],
            'is_active'        => ['nullable', 'boolean'],
            'open_in_new_tab'  => ['nullable', 'boolean'],
        ];

        $validator = Validator::make($request->all(), $rules);

        // Conditional rules
        $validator->sometimes('url', 'required', fn($input) => ($input->link_type ?? null) === 'url');
        $validator->sometimes('entity_id', 'required|integer|exists:'.$this->getEntityTable($request).',id',
            fn($input) => ($input->link_type ?? null) === 'entity' && !empty($input->entity_type) && $input->entity_type !== 'null'
        );

        // Parent constraints: cycles, max depth 3
        $validator->after(function ($v) use ($menuId, $request) {
            $this->checkParentConstraints($v, $menuId, $request->input('parent_id'));
            $this->checkMegaSettings($v, $request->input('mega_settings'));
        });

        if ($validator->fails()) {
            abort(response()->json(['errors' => $validator->errors()], 422));
        }

        $data = $request->all();
        $data['is_active'] = $request->boolean('is_active');
        $data['open_in_new_tab'] = $request->boolean('open_in_new_tab');

        return $data;
    }

    protected function getEntityTable(Request $request): string
    {
        switch ($request->input('entity_type')) {
            case 'property': return 'properties';
            case 'category': return 'categories';
            case 'agent': return 'agents';
            default: return 'users';
        }
    }

    protected function checkParentConstraints($validator, ?int $menuId, $parentId)
    {
        if (!$parentId) return;

        $parent = Menu::find($parentId);
        if (!$parent) {
            $validator->errors()->add('parent_id', 'Selected parent menu does not exist.');
            return;
        }

        if ($menuId && $parentId === $menuId) {
            $validator->errors()->add('parent_id', 'Item cannot be its own parent.');
            return;
        }

        // Prevent cycles and max depth
        $cursor = $parent;
        $ancDepth = 1;
        while ($cursor) {
            if ($menuId && $cursor->id === $menuId) {
                $validator->errors()->add('parent_id', 'Invalid parent: would create a cycle.');
                return;
            }
            if (!$cursor->parent_id) break;
            $cursor = Menu::find($cursor->parent_id);
            $ancDepth++;
            if ($ancDepth > 50) break;
        }

        $newDepth = $ancDepth + 1;
        if ($newDepth > 3) {
            $validator->errors()->add('parent_id', 'Assigning this parent would exceed max depth of 3.');
        }

        // Check descendants
        if ($menuId) {
            $descDepth = $this->getMaxDescendantDepth($menuId);
            if (($newDepth + $descDepth) > 3) {
                $validator->errors()->add('parent_id', 'Moving this item would cause children to exceed max depth of 3.');
            }
        }
    }

    protected function getMaxDescendantDepth(int $id): int
    {
        $maxDepth = 0;
        $queue = [['id' => $id, 'depth' => 0]];

        while (!empty($queue)) {
            $node = array_shift($queue);
            $children = Menu::where('parent_id', $node['id'])->pluck('id')->toArray();
            foreach ($children as $childId) {
                $childDepth = $node['depth'] + 1;
                $maxDepth = max($maxDepth, $childDepth);
                if ($childDepth < 50) {
                    $queue[] = ['id' => $childId, 'depth' => $childDepth];
                }
            }
        }

        return $maxDepth;
    }

    protected function checkMegaSettings($validator, $mega)
    {
        if (!is_array($mega)) return;

        if (isset($mega['layout']) && !in_array($mega['layout'], ['columns', 'tiles', 'carousel'], true)) {
            $validator->errors()->add('mega_settings.layout', 'Invalid layout.');
        }

        if (isset($mega['columns']) && (!is_int($mega['columns']) || $mega['columns'] < 1 || $mega['columns'] > 6)) {
            $validator->errors()->add('mega_settings.columns', 'Columns must be 1-6.');
        }

        if (isset($mega['items']) && is_array($mega['items'])) {
            foreach ($mega['items'] as $idx => $item) {
                if (!isset($item['type']) || !in_array($item['type'], ['entity', 'custom'], true)) {
                    $validator->errors()->add("mega_settings.items.{$idx}.type", 'Invalid type.');
                }
                if (($item['type'] ?? null) === 'entity' && empty($item['entity_type'])) {
                    $validator->errors()->add("mega_settings.items.{$idx}.entity_type", 'entity_type required.');
                }
            }
        }

        if (isset($mega['search_widget']['enabled']) && !is_bool($mega['search_widget']['enabled'])) {
            $validator->errors()->add('mega_settings.search_widget.enabled', 'Must be boolean.');
        }
    }
}
