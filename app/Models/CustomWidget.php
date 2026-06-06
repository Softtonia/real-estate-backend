<?php

namespace App\Models;

use App\Models\User;
use App\Models\WidgetConfiguration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomWidget extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'custom_widgets';

    public const POST_TYPE_BASIC = 'basic';
    public const POST_TYPE_PROPERTY_LISTING = 'property-listing';
    public const POST_TYPE_PROJECT_LISTING = 'project-listing';
    public const POST_TYPE_DEVELOPER_LISTING = 'developer-listing';

    protected $fillable = [
        'widget_name',
        'slug',
        'post_type',
        'created_by',
    ];

    public static function postTypes(): array
    {
        return [
            self::POST_TYPE_BASIC,
            self::POST_TYPE_PROPERTY_LISTING,
            self::POST_TYPE_PROJECT_LISTING,
            self::POST_TYPE_DEVELOPER_LISTING,
        ];
    }

    public static function isValidPostType(string $postType): bool
    {
        return in_array($postType, self::postTypes(), true);
    }

    public static function generateUniqueSlug(string $widgetName, $ignoreId = null): string
    {
        $baseSlug = Str::slug($widgetName);

        if (!$baseSlug) {
            $baseSlug = 'custom-widget';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            self::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($widget) {
            if (empty($widget->slug)) {
                $widget->slug = self::generateUniqueSlug($widget->widget_name);
            }

            if (!self::isValidPostType($widget->post_type)) {
                throw new \InvalidArgumentException('Invalid post type selected.');
            }
        });

        static::updating(function ($widget) {
            if ($widget->isDirty('widget_name')) {
                $widget->slug = self::generateUniqueSlug($widget->widget_name, $widget->id);
            }

            if ($widget->isDirty('post_type') && !self::isValidPostType($widget->post_type)) {
                throw new \InvalidArgumentException('Invalid post type selected.');
            }
        });
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function configurations()
    {
        return $this->hasMany(WidgetConfiguration::class, 'widget_id');
    }
}