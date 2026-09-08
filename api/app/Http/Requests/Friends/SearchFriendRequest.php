<?php

namespace App\Http\Requests\Friends;

use Illuminate\Foundation\Http\FormRequest;

class SearchFriendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Exactly one of the two is expected - if both are sent, the service
     * searches by email only (see FriendshipService::search()).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:bgg_username', 'nullable', 'email'],
            'bgg_username' => ['required_without:email', 'nullable', 'string'],
        ];
    }
}
