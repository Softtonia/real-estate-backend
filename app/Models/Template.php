<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'template_type',
        'post_type_id',
        'post_type_slug',
        'template_name',
        'slug',
        'shortcode',
        'created_by',
        'status',
        'priority',
    ];
    public function postType()
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }
    public function conditions()
    {
        return $this->hasMany(TemplateDisplayCondition::class);
    }

    public function layout()
    {
        return $this->hasOne(TemplateLayout::class);
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base ?: 'template';
        $count = 1;

        while (
            self::where('slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base . '-' . $count;
            $count++;
        }

        return $slug;
    }

    public function generateShortcode(): string
    {
        return '[vk_template id="' . $this->id . '"]';
    }
    public function revisions()
    {
        return $this->hasMany(TemplateRevision::class);
    }
}
