<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class Menu extends Model
{
    use HasFactory, SoftDeletes;

     protected $table = 'menus';

    protected $fillable = [
        'title',
        'slug',
        'menu_type',
        'location',
        'link_type',
        'url',
        'entity_type',
        'entity_id',
        'query_params',
        'mega_settings',
        'structured_data',
        'meta_title',
        'meta_description',
        'parent_id',
        'position',
        'is_active',
        'open_in_new_tab',
        'created_by',
    ];


     /**
     * Casts for JSON and booleans.
     */
    protected $casts = [
        'query_params'    => 'array',
        'mega_settings'   => 'array',
        'structured_data' => 'array',
        'is_active'       => 'boolean',
        'open_in_new_tab' => 'boolean',
        'position'        => 'integer',
        'entity_id'       => 'integer',
    ];



     /**
     * Booted: attach model events to invalidate cache and maintain slug.
     */
    protected static function booted()
    {
        // Keep slug in sync if absent
        static::saving(function (Menu $menu) {
            if (empty($menu->slug) && !empty($menu->title)) {
                $menu->slug = Str::slug($menu->title);
            }
        });

        // Invalidate menus cache tags on create/update/delete/restore
        $invalidate = function () {
            // Use tags to allow fine-grained invalidation: menus + location tag pattern handled by callers.
            try {
                Cache::tags(['menus'])->flush();
            } catch (\Throwable $e) {
                // If cache driver doesn't support tags, fallback to full cache clear of known key
                Cache::forget('menus:all');
            }
        };

        static::created($invalidate);
        static::updated($invalidate);
        static::deleted($invalidate);
        static::restored($invalidate);
    }



    /* -------------------------------
     | Relationships
     |--------------------------------*/
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function createdBy()
    {
        return $this->belongsTo(config('auth.providers.users.model', \App\Models\User::class), 'created_by');
    }



    /* -------------------------------
     | Scopes
     |--------------------------------*/
    public function scopeActive($query, $only = true)
    {
        return $only ? $query->where('is_active', true) : $query;
    }

    public function scopeLocation($query, ?string $location)
    {
        return $location ? $query->where('location', $location) : $query;
    }

    public function scopeMenuType($query, ?string $type)
    {
        return $type ? $query->where('menu_type', $type) : $query;
    }


     /* -------------------------------
     | Accessors / Helpers
     |--------------------------------*/

    /**
     * Resolve final URL for this menu item depending on link_type.
     *
     * - url: returns url field
     * - entity: try to resolve based on entity_type/entity_id (best-effort, may return null)
     * - query: assemble query params into a URL (if url is set it is treated as base)
     * - none: null
     */
    public function getResolvedUrlAttribute(): ?string
    {
        switch ($this->link_type) {
            case 'url':
                return $this->url ? url($this->url) : null;

            case 'entity':
                // Best-effort resolution. Prefer named routes conventions; fall back to stored url.
                if ($this->entity_type && $this->entity_id) {
                    // Example resolution — adapt to your app's routes:
                    if ($this->entity_type === 'property') {
                        return route('properties.show', ['property' => $this->entity_id], false) ?: $this->url;
                    }
                    if ($this->entity_type === 'category') {
                        return route('categories.show', ['category' => $this->entity_id], false) ?: $this->url;
                    }
                    if ($this->entity_type === 'agent') {
                        return route('agents.show', ['agent' => $this->entity_id], false) ?: $this->url;
                    }
                }
                return $this->url;

            case 'query':
                $base = $this->url ?: '/';
                $query = is_array($this->query_params) ? http_build_query($this->query_params) : null;
                return $query ? ($base . (Str::contains($base, '?') ? '&' : '?') . $query) : $base;

            case 'none':
            default:
                return $this->url ?: null;
        }
    }

    /**
     * Convert title to string with fallback.
     */
    public function getLabelAttribute(): string
    {
        return (string) ($this->title ?? $this->meta_title ?? '—');
    }

    /* -------------------------------
     | Tree builder - O(n)
     |--------------------------------*/
    /**
     * Build a nested tree (array) of menu items from a flat collection or array.
     *
     * This is O(n): it groups by parent_id and iteratively attaches children.
     * It also enforces a maximum depth of 3.
     *
     * @param \Illuminate\Database\Eloquent\Collection|array $items
     * @param int $maxDepth
     * @return array
     */
    public static function buildTree($items, int $maxDepth = 3): array
    {
        $collection = $items instanceof Collection ? $items->keyBy('id') : collect($items)->keyBy('id');

        // Prepare buckets grouped by parent_id
        $grouped = [];
        foreach ($collection as $item) {
            $parent = $item->parent_id ?? 0;
            $grouped[$parent][] = $item;
        }

        // Recursive closure limited by depth but we will use iterative approach to avoid deep recursion risk.
        $build = function ($parentId, $depth) use (&$build, $grouped, $maxDepth) {
            $result = [];
            if ($depth > $maxDepth) {
                return $result;
            }
            $children = $grouped[$parentId] ?? [];
            foreach ($children as $child) {
                $node = [
                    'id'             => $child->id,
                    'title'          => $child->title,
                    'slug'           => $child->slug,
                    'menu_type'      => $child->menu_type,
                    'location'       => $child->location,
                    'link_type'      => $child->link_type,
                    'url'            => $child->url,
                    'entity_type'    => $child->entity_type,
                    'entity_id'      => $child->entity_id,
                    'query_params'   => $child->query_params,
                    'mega_settings'  => $child->mega_settings,
                    'structured_data'=> $child->structured_data,
                    'meta_title'     => $child->meta_title,
                    'meta_description'=> $child->meta_description,
                    'position'       => $child->position,
                    'is_active'      => $child->is_active,
                    'open_in_new_tab' => $child->open_in_new_tab,
                    'resolved_url'   => $child->resolved_url,
                    'children'       => $build($child->id, $depth + 1),
                ];
                $result[] = $node;
            }
            // Sort by position (already ordered in relation, but ensure here)
            usort($result, function ($a, $b) {
                return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            });
            return $result;
        };

        return $build(0, 1);
    }

    /* -------------------------------
     | Reorder helper (atomic)
     |--------------------------------*/
    /**
     * Reorder and re-parent items from a nested array payload.
     *
     * Payload format: [{id:1, position:0, children:[{id:2, position:0}, ...]}, ...]
     *
     * This runs inside a DB transaction (caller should wrap or this will wrap itself).
     *
     * @param array $nested
     * @throws \Throwable
     */
    public static function applyNestedReorder(array $nested): void
    {
        DB::transaction(function () use ($nested) {
            $walk = function (array $nodes, $parentId = null) use (&$walk) {
                foreach ($nodes as $index => $node) {
                    $id = Arr::get($node, 'id');
                    if (!$id) {
                        continue;
                    }
                    $menu = self::lockForUpdate()->find($id);
                    if (!$menu) {
                        throw new \RuntimeException("Menu item with ID {$id} not found during reorder.");
                    }
                    $menu->parent_id = $parentId;
                    $menu->position  = Arr::get($node, 'position', $index);
                    $menu->save();

                    if (!empty($node['children']) && is_array($node['children'])) {
                        $walk($node['children'], $menu->id);
                    }
                }
            };

            $walk($nested, null);
        });
    }



}
