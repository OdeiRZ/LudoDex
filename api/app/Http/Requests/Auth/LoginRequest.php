<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Free-text label for the token (e.g. "iPhone de Odei"), so a user
            // could eventually see and revoke individual device sessions from
            // a "sesiones activas" screen without this needing to change.
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
