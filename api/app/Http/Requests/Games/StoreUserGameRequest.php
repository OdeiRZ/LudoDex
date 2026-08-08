<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserGameRequest extends FormRequest
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
            'image_url' => ['nullable', 'url', 'max:2048'],
            'min_players' => ['nullable', 'integer', 'min:1', 'max:99'],
            'max_players' => ['nullable', 'integer', 'min:1', 'max:99', 'gte:min_players'],
            'min_playtime_minutes' => ['nullable', 'integer', 'min:1'],
            'max_playtime_minutes' => ['nullable', 'integer', 'min:1', 'gte:min_playtime_minutes'],
            'weight' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'is_cooperative' => ['boolean'],
            'is_competitive' => ['boolean'],
            'has_campaign' => ['boolean'],

            'mechanics' => ['array'],
            'mechanics.*' => ['string', 'max:255'],
            'categories' => ['array'],
            'categories.*' => ['string', 'max:255'],

            'status' => ['required', Rule::in(['owned', 'wishlist'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
