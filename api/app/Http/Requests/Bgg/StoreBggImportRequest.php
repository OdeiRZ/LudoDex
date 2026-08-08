<?php

namespace App\Http\Requests\Bgg;

use Illuminate\Foundation\Http\FormRequest;

class StoreBggImportRequest extends FormRequest
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
            'bgg_username' => ['required', 'string', 'max:255'],
        ];
    }
}
