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
        'menu_name',
        'link_type',
        'url',
        'entity_type',
        'entity_id',
        'css_class',
        'icon',
        'badge',
        'depth',
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
        'depth'           => 'integer',
        'entity_id'       => 'integer',
        'parent_id'       => 'integer',
    ];

    /**
     * Booted: attach model events to invalidate cache and maintain slug.
     */
    protected static function booted()
    {
        static::saving(function (Menu $menu) {
            if (empty($menu->slug) && !empty($menu->title)) {
                $menu->slug = Str::slug($menu->title);
            }
        });

        $invalidate = function () {
            try {
                Cache::tags(['menus'])->flush();
            } catch (\Throwable $e) {
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

    public function dynamicPost()
    {
        return $this->belongsTo(DynamicPost::class, 'entity_id');
    }

    public function postType()
    {
        return $this->belongsTo(PostType::class, 'entity_id');
    }

    public function taxonomyTerm()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'entity_id');
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
     * Resolve final URL for this menu item depending on link_type & entity_type.
     */
    public function getResolvedUrlAttribute(): ?string
    {
        if ($this->link_type === 'url' || $this->link_type === 'custom') {
            if (empty($this->url)) {
                return '/';
            }
            if (filter_var($this->url, FILTER_VALIDATE_URL) || str_starts_with($this->url, '/') || str_starts_with($this->url, '#')) {
                return $this->url;
            }
            return '/' . ltrim($this->url, '/');
        }

        if ($this->link_type === 'entity' || !empty($this->entity_type)) {
            $type = strtolower(trim((string) $this->entity_type));
            $id = $this->entity_id;

            if ($type === 'post' || $type === 'dynamicpost' || $type === 'property' || $type === 'project' || $type === 'page' || $type === 'blog') {
                if ($id) {
                    $post = DynamicPost::with('postType')->find($id);
                    if ($post) {
                        $ptSlug = $post->postType?->slug;
                        if ($ptSlug === 'page' || empty($ptSlug)) {
                            return '/' . ltrim($post->slug, '/');
                        }
                        return '/' . trim($ptSlug, '/') . '/' . ltrim($post->slug, '/');
                    }
                }
            }

            if ($type === 'post_type' || $type === 'posttype') {
                if ($id) {
                    $pt = PostType::find($id);
                    if ($pt && !empty($pt->slug)) {
                        return '/' . ltrim($pt->slug, '/');
                    }
                }
            }

            if ($type === 'taxonomy_term' || $type === 'category' || $type === 'term') {
                if ($id) {
                    $term = TaxonomyTerm::with('taxonomy')->find($id);
                    if ($term) {
                        $taxSlug = $term->taxonomy?->slug ?? 'category';
                        return '/' . trim($taxSlug, '/') . '/' . ltrim($term->slug, '/');
                    }
                }
            }
        }

        if ($this->link_type === 'query') {
            $base = $this->url ?: '/';
            $query = is_array($this->query_params) ? http_build_query($this->query_params) : null;
            return $query ? ($base . (Str::contains($base, '?') ? '&' : '?') . $query) : $base;
        }

        return $this->url ?: '/';
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
     */
    public static function buildTree($items, int $maxDepth = 5): array
    {
        $collection = $items instanceof Collection ? $items->keyBy('id') : collect($items)->keyBy('id');

        $grouped = [];
        foreach ($collection as $item) {
            $parent = $item->parent_id ?? 0;
            $grouped[$parent][] = $item;
        }

        $build = function ($parentId, $depth) use (&$build, $grouped, $maxDepth) {
            $result = [];
            if ($depth > $maxDepth) {
                return $result;
            }
            $children = $grouped[$parentId] ?? [];
            foreach ($children as $child) {
                $node = [
                    'id'              => $child->id,
                    'title'           => $child->title,
                    'label'           => $child->title,
                    'slug'            => $child->slug,
                    'menu_type'       => $child->menu_type ?? 'normal',
                    'location'        => $child->location ?? 'Header',
                    'menu_name'       => $child->menu_name,
                    'link_type'       => $child->link_type ?? 'url',
                    'type'            => $child->link_type ?? 'url',
                    'url'             => $child->url,
                    'resolved_url'    => $child->resolved_url,
                    'entity_type'     => $child->entity_type,
                    'entity_id'       => $child->entity_id,
                    'css_class'       => $child->css_class,
                    'icon'            => $child->icon,
                    'badge'           => $child->badge,
                    'depth'           => $depth - 1,
                    'parent_id'       => $child->parent_id,
                    'position'        => $child->position ?? 0,
                    'is_active'       => (bool) ($child->is_active ?? true),
                    'open_in_new_tab' => (bool) ($child->open_in_new_tab ?? false),
                    'target'          => !empty($child->open_in_new_tab) ? '_blank' : '_self',
                    'query_params'    => $child->query_params,
                    'mega_settings'   => $child->mega_settings,
                    'structured_data' => $child->structured_data,
                    'meta_title'      => $child->meta_title,
                    'meta_description'=> $child->meta_description,
                    'children'        => $build($child->id, $depth + 1),
                ];
                $result[] = $node;
            }

            usort($result, function ($a, $b) {
                return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            });

            return $result;
        };

        return $build(0, 1);
    }

    /* -------------------------------
     | Reorder / Tree Saving helper (atomic)
     |--------------------------------*/
    /**
     * Apply reorder from nested array [{id, position, children: [...]}]
     * or flat structure [{id, parent_id, position, depth}]
     */
    public static function applyNestedReorder(array $items): void
    {
        DB::transaction(function () use ($items) {
            // Check if flat array with parent_id or nested with children
            $isFlat = !empty($items) && isset($items[0]['id']) && array_key_exists('parent_id', $items[0]);

            if ($isFlat) {
                foreach ($items as $index => $node) {
                    $id = $node['id'] ?? null;
                    if (!$id) continue;

                    self::where('id', $id)->update([
                        'parent_id' => !empty($node['parent_id']) ? (int) $node['parent_id'] : null,
                        'position'  => isset($node['position']) ? (int) $node['position'] : $index,
                        'depth'     => isset($node['depth']) ? (int) $node['depth'] : 0,
                    ]);
                }
            } else {
                $walk = function (array $nodes, $parentId = null, $depth = 0) use (&$walk) {
                    foreach ($nodes as $index => $node) {
                        $id = Arr::get($node, 'id');
                        if (!$id) continue;

                        $menu = self::lockForUpdate()->find($id);
                        if ($menu) {
                            $menu->parent_id = $parentId;
                            $menu->position  = Arr::get($node, 'position', $index);
                            $menu->depth     = $depth;
                            $menu->save();

                            if (!empty($node['children']) && is_array($node['children'])) {
                                $walk($node['children'], $menu->id, $depth + 1);
                            }
                        }
                    }
                };

                $walk($items, null, 0);
            }
        });
    }
}
