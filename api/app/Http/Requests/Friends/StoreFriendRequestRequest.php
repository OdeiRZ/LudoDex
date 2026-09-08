<?php

namespace App\Http\Requests\Friends;

use Illuminate\Foundation\Http\FormRequest;

class StoreFriendRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately no `exists:users,id` here - that would 422 with a
     * different message for "doesn't exist" than
     * FriendshipService::sendRequest() gives for "exists but not
     * discoverable", which would leak which user ids are in use. The
     * service is the only place that resolves/validates the target,
     * with one message for both cases.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
        ];
    }
}
