<?php

namespace App\Http\Requests;

use App\Enums\PropertyAvailabilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePropertyAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Authorization is handled by route permission middleware
         * and PropertyAvailabilityService ownership checks.
         */
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('availability_status')) {
            $this->merge([
                'availability_status' => strtolower(trim(
                    (string) $this->input('availability_status')
                )),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'availability_status' => [
                'required',
                'string',
                Rule::in(PropertyAvailabilityStatus::VALUES),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'availability_status.required' =>
                'Availability status is required.',

            'availability_status.in' =>
                'Invalid property availability status.',
        ];
    }
}