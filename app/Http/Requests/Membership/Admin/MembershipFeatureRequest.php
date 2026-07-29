<?php

namespace App\Http\Requests\Membership\Admin;

use App\Models\Membership\MembershipFeature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $featureId = $this->resolveFeatureId();

        return [
            'name' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
            ],
            'slug' => [
                $this->isMethod('post') ? 'nullable' : 'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('membership_features', 'slug')->ignore($featureId, 'id'),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'feature_type' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                Rule::in([
                    MembershipFeature::TYPE_BOOLEAN,
                    MembershipFeature::TYPE_NUMBER,
                    MembershipFeature::TYPE_TEXT,
                    MembershipFeature::TYPE_LIMIT,
                ]),
            ],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    private function resolveFeatureId(): ?int
    {
        $parameters = $this->route()?->parameters() ?? [];

        foreach (['feature', 'membershipFeature', 'membership_feature', 'id'] as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            if ($value instanceof MembershipFeature) {
                return (int) $value->getKey();
            }

            if (is_object($value) && method_exists($value, 'getKey')) {
                return (int) $value->getKey();
            }

            if (is_object($value) && isset($value->id)) {
                return (int) $value->id;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        $lastSegment = last($this->segments());

        return is_numeric($lastSegment) ? (int) $lastSegment : null;
    }
}