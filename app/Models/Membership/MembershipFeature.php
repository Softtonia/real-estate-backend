<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipFeature extends Model
{
    use HasFactory;

    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_NUMBER = 'number';
    public const TYPE_TEXT = 'text';
    public const TYPE_LIMIT = 'limit';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'feature_type',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function planFeatures(): HasMany
    {
        return $this->hasMany(MembershipPlanFeature::class, 'feature_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            MembershipPlan::class,
            'membership_plan_features',
            'feature_id',
            'plan_id'
        )->withPivot(['feature_value', 'is_unlimited', 'metadata'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}