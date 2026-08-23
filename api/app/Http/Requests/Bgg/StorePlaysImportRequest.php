<?php

namespace App\Http\Requests\Bgg;

use Illuminate\Foundation\Http\FormRequest;

class StorePlaysImportRequest extends FormRequest
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
            // Opt-in escape hatch from the incremental fetch below - a play
            // logged on BGG with an old date (backfilling a game played
            // months ago) falls outside the incremental window no matter
            // how many times the same account reimports, since that window
            // is based on the latest already-stored play's date, not on
            // when the reimport itself runs.
            'full' => ['sometimes', 'boolean'],
        ];
    }
}
