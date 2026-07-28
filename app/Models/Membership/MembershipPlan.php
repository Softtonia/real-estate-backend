<?php

namespace App\Models\Membership;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use HasFactory;

    public const DURATION_DAYS = 'days';
    public const DURATION_MONTHS = 'months';
    public const DURATION_YEARS = 'years';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'short_description',
        'description',
        'currency',
        'price',
        'sale_price',
        'duration',
        'duration_type',
        'trial_days',
        'is_popular',
        'status',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'duration' => 'integer',
        'trial_days' => 'integer',
        'is_popular' => 'boolean',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'category_id');
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(MembershipPlanFeature::class, 'plan_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            MembershipFeature::class,
            'membership_plan_features',
            'plan_id',
            'feature_id'
        )->withPivot(['feature_value', 'is_unlimited', 'metadata'])
            ->withTimestamps();
    }

    public function roleRules(): HasMany
    {
        return $this->hasMany(MembershipPlanRoleRule::class, 'plan_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'membership_plan_role_rules',
            'plan_id',
            'role_id'
        )->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MembershipOrder::class, 'plan_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserMembership::class, 'plan_id');
    }

    public function activeFeatures(): HasMany
    {
        return $this->planFeatures()
            ->whereHas('feature', fn ($query) => $query->where('status', true));
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price')->orderBy('id');
    }

    public function payableAmount(): float
    {
        return (float) ($this->sale_price ?? $this->price);
    }

    public function amountInPaise(): int
    {
        return (int) round($this->payableAmount() * 100);
    }
}