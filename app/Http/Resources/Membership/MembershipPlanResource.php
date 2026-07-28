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
            'category_id' => (int) $this->category_id,

            'category' => $this->whenLoaded('category', function () {
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

            'currency' => $this->currency,
            'price' => (float) $this->price,
            'sale_price' => $this->sale_price !== null ? (float) $this->sale_price : null,
            'payable_amount' => $this->payableAmount(),

            'duration' => (int) $this->duration,
            'duration_type' => $this->duration_type,
            'trial_days' => (int) $this->trial_days,

            'is_popular' => (bool) $this->is_popular,
            'status' => (bool) $this->status,
            'sort_order' => (int) $this->sort_order,

            'features' => $this->whenLoaded('planFeatures', function () {
                return $this->planFeatures
                    ->filter(fn ($planFeature) => $planFeature->feature)
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
        ];
    }
}