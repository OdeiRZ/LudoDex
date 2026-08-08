<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            // Optional: when set (or changed), the controller tries to pull
            // an avatar from this BGG account - best-effort, never blocks
            // saving the rest of the profile if BGG can't be reached.
            'bgg_username' => ['nullable', 'string', 'max:255'],
        ];
    }
}
