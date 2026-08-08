<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserGameRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'min_players' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'max_players' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99', 'gte:min_players'],
            'min_playtime_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_playtime_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'gte:min_playtime_minutes'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:5'],
            'is_cooperative' => ['sometimes', 'boolean'],
            'is_competitive' => ['sometimes', 'boolean'],
            'has_campaign' => ['sometimes', 'boolean'],

            'mechanics' => ['sometimes', 'array'],
            'mechanics.*' => ['string', 'max:255'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'max:255'],

            'status' => ['sometimes', Rule::in(['owned', 'wishlist'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
