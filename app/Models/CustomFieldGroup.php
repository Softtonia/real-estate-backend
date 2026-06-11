<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFieldGroup extends Model
{
    protected $fillable = [
        'group_name',
        'group_slug',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($group) {
            if (empty($group->group_slug)) {
                $group->group_slug = self::generateUniqueSlug($group->group_name);
            }
        });

        static::updating(function ($group) {
            if (empty($group->group_slug)) {
                $group->group_slug = self::generateUniqueSlug($group->group_name, $group->id);
            }
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name, '_') ?: 'field_group';
        $slug = $baseSlug;
        $counter = 1;

        while (
            self::where('group_slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $baseSlug . '_' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function fields()
    {
        return $this->hasMany(CustomField::class, 'custom_field_group_id')
            ->orderBy('sort_order');
    }

    public function activeFields()
    {
        return $this->hasMany(CustomField::class, 'custom_field_group_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function locationRules()
    {
        return $this->hasMany(CustomFieldGroupLocationRule::class, 'custom_field_group_id')
            ->orderBy('sort_order');
    }

    public function activeLocationRules()
    {
        return $this->hasMany(CustomFieldGroupLocationRule::class, 'custom_field_group_id')
            ->where('status', true)
            ->orderBy('sort_order');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}