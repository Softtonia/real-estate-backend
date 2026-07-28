<?php

namespace App\Models\Membership;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'addon_type',
        'currency',
        'price',
        'sale_price',
        'credit_type',
        'credit_quantity',
        'duration_days',
        'status',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'credit_quantity' => 'integer',
        'duration_days' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(MembershipAddonOrder::class, 'addon_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MembershipAddonUsage::class, 'addon_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function payableAmount(): float
    {
        return (float) ($this->sale_price ?? $this->price);
    }
}