<?php

namespace App\Http\Resources\Membership;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,

            'category_id' => $this->category_id !== null
                ? (int) $this->category_id
                : null,

            'category' => $this->whenLoaded('category', function () {
                if (! $this->category) {
                    return null;
                }

                return [
                    'id' => (int) $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),

            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,

            'currency' => $this->currency ?: 'INR',
            'price' => (float) $this->price,
            'sale_price' => $this->sale_price !== null
                ? (float) $this->sale_price
                : null,

            'payable_amount' => method_exists($this->resource, 'payableAmount')
                ? (float) $this->payableAmount()
                : (float) ($this->sale_price ?? $this->price ?? 0),

            'duration' => (int) $this->duration,
            'duration_type' => $this->duration_type,
            'trial_days' => (int) $this->trial_days,

            'is_popular' => (bool) $this->is_popular,
            'status' => (bool) $this->status,
            'sort_order' => (int) $this->sort_order,

            'features' => $this->whenLoaded('planFeatures', function () {
                return $this->planFeatures
                    ->filter(fn ($planFeature) => $planFeature && $planFeature->feature)
                    ->map(function ($planFeature) {
                        return [
                            'id' => (int) $planFeature->feature->id,
                            'name' => $planFeature->feature->name,
                            'slug' => $planFeature->feature->slug,
                            'type' => $planFeature->feature->feature_type,
                            'value' => $planFeature->feature_value,
                            'is_unlimited' => (bool) $planFeature->is_unlimited,
                        ];
                    })
                    ->values();
            }),

            'counts' => [
                'plan_features_count' => $this->whenCounted('planFeatures'),
                'role_rules_count' => $this->whenCounted('roleRules'),
                'orders_count' => $this->whenCounted('orders'),
                'memberships_count' => $this->whenCounted('memberships'),
            ],

            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}