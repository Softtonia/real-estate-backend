<?php

namespace App\Http\Requests\Admin\Notification;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AdminInAppNotificationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'read_status' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'read',
                    'unread',
                ]),
            ],

            'type' => [
                'nullable',
                'string',
                'max:255',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ];
    }

    protected function failedValidation(
        Validator $validator
    ): void {
        throw new HttpResponseException(
            response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'error' => $validator->errors(),
            ], 422)
        );
    }
}