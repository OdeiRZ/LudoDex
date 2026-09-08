<?php

namespace App\Http\Requests\Friends;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockRequest extends FormRequest
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
            'user_id' => ['required', 'integer'],
        ];
    }
}
