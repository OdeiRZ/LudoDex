<?php

namespace App\Http\Resources;

use App\Models\BggImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BggImport
 */
class BggImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bgg_username' => $this->bgg_username,
            'status' => $this->status,
            'imported_count' => $this->imported_count,
            'error_message' => $this->error_message,
        ];
    }
}
