<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use Illuminate\Database\Eloquent\Collection;

class MembershipPlanFeatureAutoFetchService
{
    public function fetch(MembershipPlan $plan, array $filters = []): array
    {
        $plan->loadMissing([
            'category:id,name,slug',
            'planFeatures.feature',
        ]);

        $assignedFeatures = $plan->planFeatures->keyBy('feature_id');

        $features = MembershipFeature::query()
            ->select([
                'id',
                'name',
                'slug',
                'description',
                'feature_type',
                'status',
                'sort_order',
            ])
            ->when(isset($filters['active_only']) && $filters['active_only'], function ($query) {
                $query->where('status', true);
            })
            ->when(! empty($filters['feature_type']), function ($query) use ($filters) {
                $query->where('feature_type', $filters['feature_type']);
            })
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'plan' => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'category' => $plan->category ? [
                    'id' => (int) $plan->category->id,
                    'name' => $plan->category->name,
                    'slug' => $plan->category->slug,
                ] : null,
                'status' => (bool) $plan->status,
            ],

            'summary' => [
                'total_features' => $features->count(),
                'assigned_features' => $assignedFeatures->count(),
                'unassigned_features' => max($features->count() - $assignedFeatures->count(), 0),
            ],

            'features' => $this->formatFeatures($features, $assignedFeatures),
        ];
    }

    private function formatFeatures(Collection $features, Collection $assignedFeatures): array
    {
        return $features->map(function (MembershipFeature $feature) use ($assignedFeatures) {
            $planFeature = $assignedFeatures->get($feature->id);

            $type = strtolower((string) $feature->feature_type);
            $rawValue = $planFeature?->feature_value;
            $isUnlimited = (bool) ($planFeature?->is_unlimited ?? false);

            return [
                'feature_id' => (int) $feature->id,
                'plan_feature_id' => $planFeature?->id ? (int) $planFeature->id : null,

                'name' => $feature->name,
                'slug' => $feature->slug,
                'description' => $feature->description,

                'feature_type' => $type,
                'input_type' => $this->inputType($type),

                'assigned' => $planFeature !== null,

                'feature_value' => $rawValue,
                'value' => $this->castValue($type, $rawValue, $isUnlimited),
                'display_value' => $this->displayValue($type, $rawValue, $isUnlimited),

                'is_unlimited' => $isUnlimited,

                'feature_status' => (bool) $feature->status,
                'plan_feature_status' => $planFeature
                    ? (bool) ($planFeature->status ?? true)
                    : false,

                'sort_order' => (int) ($planFeature->sort_order ?? $feature->sort_order ?? 0),

                'sync_payload' => [
                    'feature_id' => (int) $feature->id,
                    'slug' => $feature->slug,
                    'feature_value' => $rawValue,
                    'is_unlimited' => $isUnlimited,
                    'status' => $planFeature
                        ? (bool) ($planFeature->status ?? true)
                        : false,
                    'sort_order' => (int) ($planFeature->sort_order ?? $feature->sort_order ?? 0),
                ],
            ];
        })->values()->all();
    }

    private function castValue(string $type, mixed $value, bool $isUnlimited): mixed
    {
        if ($type === 'limit') {
            return $isUnlimited ? null : (is_numeric($value) ? (int) $value : $value);
        }

        if ($type === 'number') {
            return is_numeric($value) ? (float) $value : null;
        }

        if (in_array($type, ['bool', 'boolean'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($type === 'text') {
            return $value !== null ? (string) $value : null;
        }

        return $value;
    }

    private function displayValue(string $type, mixed $value, bool $isUnlimited): ?string
    {
        if ($type === 'limit') {
            return $isUnlimited ? 'Unlimited' : (string) ($value ?? '0');
        }

        if ($type === 'number') {
            return $value !== null ? (string) $value : null;
        }

        if (in_array($type, ['bool', 'boolean'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        if ($type === 'text') {
            return $value !== null ? (string) $value : null;
        }

        return $value !== null ? (string) $value : null;
    }

    private function inputType(string $type): string
    {
        return match ($type) {
            'limit', 'number' => 'number',
            'text' => 'text',
            'bool', 'boolean' => 'checkbox',
            default => 'text',
        };
    }
}