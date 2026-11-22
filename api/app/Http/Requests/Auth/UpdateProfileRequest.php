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
            // Cambiar el email exige la contraseña actual: con solo el token (120
            // días) bastaba para poner el correo de otra persona y, con "olvidé
            // mi contraseña", quedarse con la cuenta. Sin cambio de email no se pide.
            'current_password' => [
                Rule::requiredIf(fn () => $this->input('email') !== $this->user()->email),
                'nullable',
                'current_password:sanctum',
            ],
            // Optional: when set (or changed), the controller tries to pull
            // an avatar from this BGG account - best-effort, never blocks
            // saving the rest of the profile if BGG can't be reached.
            'bgg_username' => ['nullable', 'string', 'max:255'],
            // Off by default (see the users migration) - lets other users
            // find this account via friend search (email/bgg_username).
            'discoverable' => ['boolean'],
            // On by default (see the users migration) - gates what an
            // already-accepted friend can see, not who can find you.
            'share_activity' => ['boolean'],
            // On by default (see the users migration) - short sound +
            // vibration on key interactions (buttons, dice-roll toast).
            'interaction_feedback_enabled' => ['boolean'],
        ];
    }
}
