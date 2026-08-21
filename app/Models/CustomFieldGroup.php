<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomFieldGroup extends Model
{
    protected $fillable = [
        'group_name',
        'group_slug',
        'created_by',
    ];

    protected $casts = [
        'created_by' => 'integer',
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

        static::deleting(function ($group) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($group) {
                $group->locationRules()->delete();

                foreach ($group->fields as $field) {
                    $field->locationRules()->delete();
                    $field->options()->delete();

                    $repeaterIds = $field->repeaters()->pluck('id')->toArray();
                    if (!empty($repeaterIds)) {
                        CustomFieldRepeaterOption::whereIn('custom_field_repeater_id', $repeaterIds)->delete();
                        CustomFieldRepeaterValues::whereIn('custom_field_repeater_id', $repeaterIds)->delete();
                    }
                    $field->repeaters()->delete();
                    $field->conditions()->delete();

                    if (method_exists($field, 'values')) {
                        $field->values()->delete();
                    }
                    $field->delete();
                }
            });
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
        return $this->hasMany(CustomFieldGroupLocationRule::class, 'custom_field_group_id');
    }

    public function activeLocationRules()
    {
        return $this->hasMany(CustomFieldGroupLocationRule::class, 'custom_field_group_id')
            ->where('status', true);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}