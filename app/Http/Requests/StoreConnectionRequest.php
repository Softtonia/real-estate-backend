<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;

class StoreConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return false;
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unique_id' => ['required', 'string', 'exists:users,unique_id'],
            'note' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function getReceiver(): ?User
    {
        return User::where('unique_id', $this->input('unique_id'))->first();
    }
}
